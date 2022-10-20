<?php

namespace FrameworkX\Io;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 */
class MiddlewareHandler
{
    /** @var non-empty-list<callable> */
    private $handlers = [];

    /**
     * @param array<callable|RequestHandlerInterface> $handlers
     * @throws \TypeError
     */
    public function __construct(array $handlers)
    {
        assert(count($handlers) >= 2);
        foreach ($handlers as $handler) {
            if (\is_callable($handler)) {
                $this->handlers[] = $handler;
            } elseif ($handler instanceof RequestHandlerInterface) {
                $this->handlers[] = function (ServerRequestInterface $request) use ($handler) {
                    return $handler->handle($request);
                };
            } else {
                throw new \TypeError();
            }
        }
    }

    public function __invoke(ServerRequestInterface $request)
    {
        return $this->call($request, 0);
    }

    private function call(ServerRequestInterface $request, int $position)
    {
        if (!isset($this->handlers[$position + 2])) {
            return $this->handlers[$position]($request, $this->handlers[$position + 1]);
        }

        return $this->handlers[$position]($request, function (ServerRequestInterface $request) use ($position) {
            return $this->call($request, $position + 1);
        });
    }
}
