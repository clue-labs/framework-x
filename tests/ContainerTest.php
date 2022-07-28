<?php

namespace FrameworkX\Tests;

use FrameworkX\AccessLogHandler;
use FrameworkX\Container;
use FrameworkX\ErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

class ContainerTest extends TestCase
{
    public function testCallableReturnsCallableForClassNameViaAutowiring()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class {
            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200);
            }
        };

        $container = new Container();

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testCallableReturnsCallableForClassNameViaAutowiringWithConfigurationForDependency()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => (object)['name' => 'Alice']
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"Alice"}', (string) $response->getBody());
    }

    public function testCallableReturnsCallableForClassNameViaAutowiringWithFactoryFunctionForDependency()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function () {
                return (object)['name' => 'Alice'];
            }
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"Alice"}', (string) $response->getBody());
    }

    public function testCallableTwiceReturnsCallableForClassNameViaAutowiringWithFactoryFunctionForDependencyWillCallFactoryOnlyOnce()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function () {
                static $called = 0;
                return (object)['num' => ++$called];
            }
        ]);

        $callable = $container->callable(get_class($controller));

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"num":1}', (string) $response->getBody());
    }

    public function testCallableReturnsCallableForClassNameWithDependencyMappedToSubclassExplicitly()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $dto = new class extends \stdClass { };
        $dto->name = 'Alice';

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => get_class($dto),
            get_class($dto) => $dto
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"Alice"}', (string) $response->getBody());
    }

    public function testCallableReturnsCallableForClassNameWithDependencyMappedToSubclassFromFactory()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $dto = new class extends \stdClass { };
        $dto->name = 'Alice';

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function () use ($dto) { return get_class($dto); },
            get_class($dto) => function () use ($dto) { return $dto; }
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"Alice"}', (string) $response->getBody());
    }

    public function testCallableReturnsCallableForClassNameWithDependencyMappedWithFactoryThatRequiresOtherClassWithFactory()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new Response()) {
            private $response;

            public function __construct(ResponseInterface $response)
            {
                $this->response = $response;
            }

            public function __invoke()
            {
                return $this->response;
            }
        };

        $container = new Container([
            ResponseInterface::class => function (\stdClass $dto) {
                return new Response(200, [], json_encode($dto));
            },
            \stdClass::class => function () { return (object)['name' => 'Alice']; }
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"Alice"}', (string) $response->getBody());
    }

    public function testCallableReturnsCallableForClassNameWithDependencyMappedWithFactoryThatRequiresScalarVariables()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (string $username, int $age, bool $admin, float $percent) {
                return (object) ['name' => $username, 'age' => $age, 'admin' => $admin, 'percent' => $percent];
            },
            'username' => 'Alice',
            'age' => 42,
            'admin' => true,
            'percent' => 0.5
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"Alice","age":42,"admin":true,"percent":0.5}', (string) $response->getBody());
    }

    public function testCallableReturnsCallableForClassNameMappedFromFactoryWithScalarVariablesMappedFromFactory()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (string $username, int $age, bool $admin, float $percent) {
                return (object) ['name' => $username, 'age' => $age, 'admin' => $admin, 'percent' => $percent];
            },
            'username' => function () { return 'Alice'; },
            'age' => function () { return 42; },
            'admin' => function () { return true; },
            'percent' => function () { return 0.5; }
        ]);

        $callable = $container->callable(get_class($controller));

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"Alice","age":42,"admin":true,"percent":0.5}', (string) $response->getBody());
    }

    public function testCallableReturnsCallableForClassNameReferencingVariableMappedFromFactoryReferencingVariable()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (string $username) {
                return (object) ['name' => $username];
            },
            'username' => function (string $role) {
                return strtoupper($role);
            },
            'role' => 'admin'
        ]);

        $callable = $container->callable(get_class($controller));

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"ADMIN"}', (string) $response->getBody());
    }

    /** @dataProvider provideCastToString */
    public function testCallableReturnsCallableForClassNameWithDependencyMappedWithFactoryThatRequiresStringVariableCastedFromOtherType($value, string $expected)
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (string $value) {
                return (object) ['name' => $value];
            },
            'value' => $value
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":"' . $expected . '"}', (string) $response->getBody());
    }

    public function provideCastToString()
    {
        return [
            [
                0,
                '0'
            ],
            [
                42,
                '42'
            ],
            [
                1.5,
                '1.5'
            ],
            [
                1.0,
                '1.0'
            ],
            [
                0.0,
                '0.0'
            ],
            [
                true,
                'true'
            ],
            [
                false,
                'false'
            ]
        ];
    }

    /** @dataProvider provideCastToInt */
    public function testCallableReturnsCallableForClassNameWithDependencyMappedWithFactoryThatRequiresIntVariableCastedFromOtherType($value, int $expected)
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (int $value) {
                return (object) ['name' => $value];
            },
            'value' => $value
            ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":' . $expected . '}', (string) $response->getBody());
    }

    public function provideCastToInt()
    {
        return [
            [
                '0',
                0
            ],
            [
                '1',
                1
            ],
            [
                1.0,
                1
            ],
            [
                0.0,
                0
            ],
            [
                true,
                1
            ],
            [
                false,
                0
            ]
        ];
    }

    /** @dataProvider provideCastToFloat */
    public function testCallableReturnsCallableForClassNameWithDependencyMappedWithFactoryThatRequiresFloatVariableCastedFromOtherType($value, float $expected)
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (float $value) {
                return (object) ['name' => $value];
            },
            'value' => $value
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":' . $expected . '}', (string) $response->getBody());
    }

    public function provideCastToFloat()
    {
        return [
            [
                '0',
                0.0
            ],
            [
                '1',
                1.0
            ],
            [
                '1.0',
                1.0
            ],
            [
                '1.5',
                1.5
            ],
            [
                0,
                0.0
            ],
            [
                42,
                42.0
            ],
            [
                true,
                1.0
            ],
            [
                false,
                0.0
            ]
        ];
    }

    /** @dataProvider provideCastToBool */
    public function testCallableReturnsCallableForClassNameWithDependencyMappedWithFactoryThatRequiresBoolVariableCastedFromOtherType($value, bool $expected)
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (bool $value) {
                return (object) ['name' => $value];
            },
            'value' => $value
        ]);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"name":' . ($expected ? 'true' : 'false') . '}', (string) $response->getBody());
    }

    public function provideCastToBool()
    {
        return [
            [
                0,
                false
            ],
            [
                'false',
                false
            ],
            [
                'off',
                false
            ],
            [
                'no',
                false
            ],
            [
                '0',
                false
            ],
            [
                '',
                false
            ],
            [
                1,
                true
            ],
            [
                'true',
                true
            ],
            [
                'on',
                true
            ],
            [
                'yes',
                true
            ],
            [
                '1',
                true
            ]
        ];
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesUnknownVariable()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (string $username) {
                return (object) ['name' => $username];
            }
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Container variable $username is not defined');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesRecursiveVariable()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (string $stdClass) {
                return (object) ['name' => $stdClass];
            }
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Container variable $stdClass is recursive');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesVariableMappedWithUnexpectedType()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class('') {
            private $data;

            public function __construct(string $stdClass)
            {
                $this->data = $stdClass;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            get_class($controller) => function (string $stdClass) use ($controller) {
                $class = get_class($controller);
                return new $class($stdClass);
            },
            \stdClass::class => (object) []
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Container variable $stdClass expected scalar type, but got stdClass');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesVariableMappedFromFactoryWithUnexpectedReturnType()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (string $http) {
                return (object) ['name' => $http];
            },
            'http' => function () {
                return tmpfile();
            }
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Container variable $http expected scalar type from factory, but got resource');
        $callable($request);
    }

    /** @dataProvider provideCastToIntFails */
    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesIntVariableMappedFromFactoryWithReturnsUnexpectedType($value, string $type)
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (int $value) {
                return (object) ['value' => $value];
            },
            'value' => $value
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Container variable $value expected type int, but got ' . $type);
        $callable($request);
    }

    public function provideCastToIntFails()
    {
        return [
            [
                'foo',
                'string'
            ],
            [
                '00',
                'string'
            ],
            [
                ' 0',
                'string'
            ],
            [
                '0 ',
                'string'
            ],
            [
                1.1,
                'double'
            ],
            [
                '1.1',
                'string'
            ]
        ];
    }

    /** @dataProvider provideCastToFloatFails */
    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesFloatVariableMappedFromFactoryWithReturnsUnexpectedType($value)
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (float $value) {
                return (object) ['value' => $value];
            },
            'value' => $value
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Container variable $value expected type float, but got string');
        $callable($request);
    }

    public function provideCastToFloatFails()
    {
        return [
            [
                'foo'
            ],
            [
                '00'
            ],
            [
                ' 0'
            ],
            [
                '0 '
            ]
        ];
    }

    /** @dataProvider provideCastToBoolFails */
    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesBoolVariableMappedFromFactoryWithReturnsUnexpectedType($value, string $type)
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => function (bool $value) {
                return (object) ['value' => $value];
            },
            'value' => $value
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Container variable $value expected type bool, but got ' . $type);
        $callable($request);
    }

    public function provideCastToBoolFails()
    {
        return [
            [
                'foo',
                'string'
            ],
            [
                '00',
                'string'
            ],
            [
                ' 0',
                'string'
            ],
            [
                '0 ',
                'string'
            ],
            [
                42,
                'integer'
            ],
            [
                0.0,
                'double'
            ],
            [
                1.0,
                'double'
            ]
        ];
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesClassNameButGetsStringVariable()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => 'Yes'
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Class Yes not found');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReferencesClassNameButGetsIntVariable()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class(new \stdClass()) {
            private $data;

            public function __construct(\stdClass $data)
            {
                $this->data = $data;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            \stdClass::class => 42
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Map for stdClass contains unexpected integer');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenConstructorWithoutFactoryFunctionReferencesStringVariable()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class('Alice') {
            private $data;

            public function __construct(string $name)
            {
                $this->data = $name;
            }

            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200, [], json_encode($this->data));
            }
        };

        $container = new Container([
            'name' => 'Alice'
        ]);

        $callable = $container->callable(get_class($controller));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Argument 1 ($name) of class@anonymous::__construct() expects unsupported type string');
        $callable($request);
    }

    public function testCtorThrowsWhenMapContainsInvalidResource()
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Map for stdClass contains unexpected resource');

        new Container([
            \stdClass::class => tmpfile()
        ]);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReturnsInvalidClassName()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => function () { return 'invalid'; }
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Class invalid not found');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReturnsInvalidInteger()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => function () { return 42; }
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Factory for stdClass returned unexpected integer');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenMapReferencesClassNameThatDoesNotMatchType()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => Response::class
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Factory for stdClass returned unexpected React\Http\Message\Response');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReturnsClassNameThatDoesNotMatchType()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => function () { return Response::class; }
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Factory for stdClass returned unexpected React\Http\Message\Response');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryRequiresInvalidClassName()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => function (self $instance) { return $instance; }
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Class self not found');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryRequiresUntypedArgument()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => function ($data) { return $data; }
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Argument 1 ($data) of {closure}() has no type');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryRequiresRecursiveClass()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => function (\stdClass $data) { return $data; }
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Argument 1 ($data) of {closure}() is recursive');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryIsRecursive()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => \stdClass::class
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Factory for stdClass is recursive');
        $callable($request);
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryIsRecursiveClassName()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container([
            \stdClass::class => function (): string {
                return \stdClass::class;
            }
        ]);

        $callable = $container->callable(\stdClass::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Factory for stdClass is recursive');
        $callable($request);
    }

    public function testCallableReturnsCallableForClassNameViaPsrContainer()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $controller = new class {
            public function __invoke(ServerRequestInterface $request)
            {
                return new Response(200);
            }
        };

        $psr = $this->createMock(ContainerInterface::class);
        $psr->expects($this->never())->method('has');
        $psr->expects($this->once())->method('get')->with(get_class($controller))->willReturn($controller);

        $container = new Container($psr);

        $callable = $container->callable(get_class($controller));
        $this->assertInstanceOf(\Closure::class, $callable);

        $response = $callable($request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testCallableReturnsCallableThatThrowsWhenFactoryReturnsInvalidClassNameViaPsrContainer()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $exception = new class('Unable to load class') extends \RuntimeException implements NotFoundExceptionInterface { };

        $psr = $this->createMock(ContainerInterface::class);
        $psr->expects($this->never())->method('has');
        $psr->expects($this->once())->method('get')->with('FooBar')->willThrowException($exception);

        $container = new Container($psr);

        $callable = $container->callable('FooBar');

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Request handler class FooBar failed to load: Unable to load class');
        $callable($request);
    }

    public function testGetAccessLogHandlerReturnsDefaultAccessLogHandlerInstance()
    {
        $container = new Container([]);

        $accessLogHandler = $container->getAccessLogHandler();

        $this->assertInstanceOf(AccessLogHandler::class, $accessLogHandler);
    }

    public function testGetAccessLogHandlerReturnsAccessLogHandlerInstanceFromMap()
    {
        $accessLogHandler = new AccessLogHandler();

        $container = new Container([
            AccessLogHandler::class => $accessLogHandler
        ]);

        $ret = $container->getAccessLogHandler();

        $this->assertSame($accessLogHandler, $ret);
    }

    public function testGetAccessLogHandlerReturnsAccessLogHandlerInstanceFromPsrContainer()
    {
        $accessLogHandler = new AccessLogHandler();

        $psr = $this->createMock(ContainerInterface::class);
        $psr->expects($this->once())->method('has')->with(AccessLogHandler::class)->willReturn(true);
        $psr->expects($this->once())->method('get')->with(AccessLogHandler::class)->willReturn($accessLogHandler);

        $container = new Container($psr);

        $ret = $container->getAccessLogHandler();

        $this->assertSame($accessLogHandler, $ret);
    }

    public function testGetAccessLogHandlerReturnsDefaultAccessLogHandlerInstanceIfPsrContainerHasNoEntry()
    {
        $psr = $this->createMock(ContainerInterface::class);
        $psr->expects($this->once())->method('has')->with(AccessLogHandler::class)->willReturn(false);
        $psr->expects($this->never())->method('get');

        $container = new Container($psr);

        $accessLogHandler = $container->getAccessLogHandler();

        $this->assertInstanceOf(AccessLogHandler::class, $accessLogHandler);
    }

    public function testGetErrorHandlerReturnsDefaultErrorHandlerInstance()
    {
        $container = new Container([]);

        $errorHandler = $container->getErrorHandler();

        $this->assertInstanceOf(ErrorHandler::class, $errorHandler);
    }

    public function testGetErrorHandlerReturnsErrorHandlerInstanceFromMap()
    {
        $errorHandler = new ErrorHandler();

        $container = new Container([
            ErrorHandler::class => $errorHandler
        ]);

        $ret = $container->getErrorHandler();

        $this->assertSame($errorHandler, $ret);
    }

    public function testGetErrorHandlerReturnsErrorHandlerInstanceFromPsrContainer()
    {
        $errorHandler = new ErrorHandler();

        $psr = $this->createMock(ContainerInterface::class);
        $psr->expects($this->once())->method('has')->with(ErrorHandler::class)->willReturn(true);
        $psr->expects($this->once())->method('get')->with(ErrorHandler::class)->willReturn($errorHandler);

        $container = new Container($psr);

        $ret = $container->getErrorHandler();

        $this->assertSame($errorHandler, $ret);
    }

    public function testGetErrorHandlerReturnsDefaultErrorHandlerInstanceIfPsrContainerHasNoEntry()
    {
        $psr = $this->createMock(ContainerInterface::class);
        $psr->expects($this->once())->method('has')->with(ErrorHandler::class)->willReturn(false);
        $psr->expects($this->never())->method('get');

        $container = new Container($psr);

        $errorHandler = $container->getErrorHandler();

        $this->assertInstanceOf(ErrorHandler::class, $errorHandler);
    }

    public function testInvokeContainerAsMiddlewareReturnsFromNextRequestHandler()
    {
        $request = new ServerRequest('GET', 'http://example.com/');
        $response = new Response(200, [], '');

        $container = new Container();
        $ret = $container($request, function () use ($response) { return $response; });

        $this->assertSame($response, $ret);
    }

    public function testInvokeContainerAsFinalRequestHandlerThrows()
    {
        $request = new ServerRequest('GET', 'http://example.com/');

        $container = new Container();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Container should not be used as final request handler');
        $container($request);
    }

    public function testCtorWithInvalidValueThrows()
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Argument #1 ($loader) must be of type array|Psr\Container\ContainerInterface, stdClass given');
        new Container((object) []);
    }
}
