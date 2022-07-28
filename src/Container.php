<?php

namespace FrameworkX;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @final
 */
class Container
{
    /** @var array<string,object|callable():(object|scalar)|scalar>|ContainerInterface */
    private $container;

    /** @var array<string,callable():(object|scalar) | object | scalar>|ContainerInterface $loader */
    public function __construct($loader = [])
    {
        if (!\is_array($loader) && !$loader instanceof ContainerInterface) {
            throw new \TypeError(
                'Argument #1 ($loader) must be of type array|Psr\Container\ContainerInterface, ' . (\is_object($loader) ? get_class($loader) : gettype($loader)) . ' given'
            );
        }

        foreach (($loader instanceof ContainerInterface ? [] : $loader) as $name => $value) {
            if (!\is_scalar($value) && !$value instanceof \Closure && !$value instanceof $name) {
                throw new \BadMethodCallException('Map for ' . $name . ' contains unexpected ' . (is_object($value) ? get_class($value) : gettype($value)));
            }
        }
        $this->container = $loader;
    }

    public function __invoke(ServerRequestInterface $request, callable $next = null)
    {
        if ($next === null) {
            // You don't want to end up here. This only happens if you use the
            // container as a final request handler instead of as a middleware.
            // In this case, you should omit the container or add another final
            // request handler behind the container in the middleware chain.
            throw new \BadMethodCallException('Container should not be used as final request handler');
        }

        // If the container is used as a middleware, simply forward to the next
        // request handler. As an additional optimization, the container would
        // usually be filtered out from a middleware chain as this is a NO-OP.
        return $next($request);
    }

    /**
     * @param class-string $class
     * @return callable(ServerRequestInterface,?callable=null)
     * @internal
     */
    public function callable(string $class): callable
    {
        return function (ServerRequestInterface $request, callable $next = null) use ($class) {
            // Check `$class` references a valid class name that can be autoloaded
            if (\is_array($this->container) && !\class_exists($class, true) && !interface_exists($class, false) && !trait_exists($class, false)) {
                throw new \BadMethodCallException('Request handler class ' . $class . ' not found');
            }

            try {
                if ($this->container instanceof ContainerInterface) {
                    $handler = $this->container->get($class);
                } else {
                    $handler = $this->loadObject($class);
                }
            } catch (\Throwable $e) {
                throw new \BadMethodCallException(
                    'Request handler class ' . $class . ' failed to load: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            // Check `$handler` references a class name that is callable, i.e. has an `__invoke()` method.
            // This initial version is intentionally limited to checking the method name only.
            // A follow-up version will likely use reflection to check request handler argument types.
            if (!is_callable($handler)) {
                throw new \BadMethodCallException('Request handler class "' . $class . '" has no public __invoke() method');
            }

            // invoke request handler as middleware handler or final controller
            if ($next === null) {
                return $handler($request);
            }
            return $handler($request, $next);
        };
    }

    /** @internal */
    public function getAccessLogHandler(): AccessLogHandler
    {
        if ($this->container instanceof ContainerInterface) {
            if ($this->container->has(AccessLogHandler::class)) {
                return $this->container->get(AccessLogHandler::class);
            } else {
                return new AccessLogHandler();
            }
        }
        return $this->loadObject(AccessLogHandler::class);
    }

    /** @internal */
    public function getErrorHandler(): ErrorHandler
    {
        if ($this->container instanceof ContainerInterface) {
            if ($this->container->has(ErrorHandler::class)) {
                return $this->container->get(ErrorHandler::class);
            } else {
                return new ErrorHandler();
            }
        }
        return $this->loadObject(ErrorHandler::class);
    }

    /**
     * @template T
     * @param class-string<T> $name
     * @return T
     * @throws \BadMethodCallException if object of type $name can not be loaded
     */
    private function loadObject(string $name, int $depth = 64) /*: object (PHP 7.2+) */
    {
        if (isset($this->container[$name])) {
            if (\is_string($this->container[$name])) {
                if ($depth < 1) {
                    throw new \BadMethodCallException('Factory for ' . $name . ' is recursive');
                }

                $value = $this->loadObject($this->container[$name], $depth - 1);
                if (!$value instanceof $name) {
                    throw new \BadMethodCallException('Factory for ' . $name . ' returned unexpected ' . (is_object($value) ? get_class($value) : gettype($value)));
                }

                $this->container[$name] = $value;
            } elseif ($this->container[$name] instanceof \Closure) {
                // build list of factory parameters based on parameter types
                $closure = new \ReflectionFunction($this->container[$name]);
                $params = $this->loadFunctionParams($closure, $depth, true);

                // invoke factory with list of parameters
                $value = $params === [] ? ($this->container[$name])() : ($this->container[$name])(...$params);

                if (\is_string($value)) {
                    if ($depth < 1) {
                        throw new \BadMethodCallException('Factory for ' . $name . ' is recursive');
                    }

                    $value = $this->loadObject($value, $depth - 1);
                }
                if (!$value instanceof $name) {
                    throw new \BadMethodCallException('Factory for ' . $name . ' returned unexpected ' . (is_object($value) ? get_class($value) : gettype($value)));
                }

                $this->container[$name] = $value;
            } elseif (\is_scalar($this->container[$name])) {
                throw new \BadMethodCallException('Map for ' . $name . ' contains unexpected ' . \gettype($this->container[$name]));
            }

            assert($this->container[$name] instanceof $name);

            return $this->container[$name];
        }

        // Check `$name` references a valid class name that can be autoloaded
        if (!\class_exists($name, true) && !interface_exists($name, false) && !trait_exists($name, false)) {
            throw new \BadMethodCallException('Class ' . $name . ' not found');
        }

        $class = new \ReflectionClass($name);
        if (!$class->isInstantiable()) {
            $modifier = 'class';
            if ($class->isInterface()) {
                $modifier = 'interface';
            } elseif ($class->isAbstract()) {
                $modifier = 'abstract class';
            } elseif ($class->isTrait()) {
                $modifier = 'trait';
            }
            throw new \BadMethodCallException('Cannot instantiate ' . $modifier . ' '. $name);
        }

        // build list of constructor parameters based on parameter types
        $ctor = $class->getConstructor();
        $params = $ctor === null ? [] : $this->loadFunctionParams($ctor, $depth, false);

        // instantiate with list of parameters
        return $this->container[$name] = $params === [] ? new $name() : $class->newInstance(...$params);
    }

    /** @throws \BadMethodCallException if either parameter can not be loaded */
    private function loadFunctionParams(\ReflectionFunctionAbstract $function, int $depth, bool $allowVariables): array
    {
        $params = [];
        foreach ($function->getParameters() as $parameter) {
            assert($parameter instanceof \ReflectionParameter);

            // stop building parameters when encountering first optional parameter
            if ($parameter->isOptional()) {
                break;
            }

            // ensure parameter is typed
            $type = $parameter->getType();
            if ($type === null) {
                throw new \BadMethodCallException(self::parameterError($parameter) . ' has no type');
            }

            // if allowed, use null value without injecting any instances
            assert($type instanceof \ReflectionType);
            if ($type->allowsNull()) {
                $params[] = null;
                continue;
            }

            // abort for union types (PHP 8.0+) and intersection types (PHP 8.1+)
            if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                throw new \BadMethodCallException(self::parameterError($parameter) . ' expects unsupported type ' . $type); // @codeCoverageIgnore
            }

            assert($type instanceof \ReflectionNamedType);

            // load variables from container for primitive/scalar types
            if ($allowVariables && \in_array($type->getName(), ['string', 'int', 'float', 'bool'])) {
                $params[] = $this->loadVariable($parameter->getName(), $type->getName(), $depth);
                continue;
            }

            // abort for other primitive types (array etc.)
            if ($type->isBuiltin()) {
                throw new \BadMethodCallException(self::parameterError($parameter) . ' expects unsupported type ' . $type->getName());
            }

            // abort for unreasonably deep nesting or recursive types
            if ($depth < 1) {
                throw new \BadMethodCallException(self::parameterError($parameter) . ' is recursive');
            }

            $params[] = $this->loadObject($type->getName(), $depth - 1);
        }

        return $params;
    }

    /**
     * @return string|int|float|bool
     * @throws \BadMethodCallException if $name is not a valid scalar variable
     */
    private function loadVariable(string $name, string $type, int $depth) /*: string|int|float|bool (PHP 8.0+) */
    {
        if (!isset($this->container[$name])) {
            throw new \BadMethodCallException('Container variable $' . $name . ' is not defined');
        }

        if ($this->container[$name] instanceof \Closure) {
            if ($depth < 1) {
                throw new \BadMethodCallException('Container variable $' . $name . ' is recursive');
            }

            // build list of factory parameters based on parameter types
            $closure = new \ReflectionFunction($this->container[$name]);
            $params = $this->loadFunctionParams($closure, $depth - 1, true);

            // invoke factory with list of parameters
            $value = $params === [] ? ($this->container[$name])() : ($this->container[$name])(...$params);

            if (!\is_scalar($value)) {
                throw new \BadMethodCallException('Container variable $' . $name . ' expected scalar type from factory, but got ' . (\is_object($value) ? \get_class($value) : \gettype($value)));
            }

            $this->container[$name] = $value;
        }

        $value = $this->container[$name];
        if (!\is_scalar($value)) {
            throw new \BadMethodCallException('Container variable $' . $name . ' expected scalar type, but got ' . (\is_object($value) ? \get_class($value) : \gettype($value)));
        }

        if ($type === 'string' && !\is_string($value)) {
            $value = \json_encode($value, \JSON_PRESERVE_ZERO_FRACTION);
        } elseif ($type === 'int' && (\is_bool($value) || !\is_int($value) && (string) (int) $value === (string) $value)) {
            $value = (int) $value;
        } elseif ($type === 'float' && (!\is_string($value) || \json_encode((float) $value) === $value || \json_encode((float) $value, \JSON_PRESERVE_ZERO_FRACTION) === $value)) {
            $value = (float) $value;
        } elseif ($type === 'bool' && ($value === 0 || \is_string($value) && \in_array(\strtolower($value), ['false', '0', 'no', 'off', ''], true))) {
            $value = false;
        } elseif ($type === 'bool' && ($value === 1 || \is_string($value) && \in_array(\strtolower($value), ['true', '1', 'yes', 'on'], true))) {
            $value = true;
        }

        if (($type === 'string' && !\is_string($value)) || ($type === 'int' && !\is_int($value)) || ($type === 'float' && !\is_float($value)) || ($type === 'bool' && !\is_bool($value))) {
            throw new \BadMethodCallException('Container variable $' . $name . ' expected type ' . $type . ', but got ' . \gettype($value));
        }

        return $value;
    }

    /** @throws void */
    private static function parameterError(\ReflectionParameter $parameter): string
    {
        $name = $parameter->getDeclaringFunction()->getShortName();
        if (!$parameter->getDeclaringFunction()->isClosure() && ($class = $parameter->getDeclaringClass()) !== null) {
            $name = explode("\0", $class->getName())[0] . '::' . $name;
        }

        return 'Argument ' . ($parameter->getPosition() + 1) . ' ($' . $parameter->getName() . ') of ' . $name . '()';
    }
}
