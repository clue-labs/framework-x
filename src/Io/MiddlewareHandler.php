<?php

namespace FrameworkX\Io;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
class MiddlewareHandler
{
    /** @var list<callable> $handlers */
    private $handlers;

    /** @param list<callable> $handlers */
    public function __construct(array $handlers)
    {
        assert(count($handlers) >= 2);

        $this->handlers = $handlers;
    }

    /** @return ResponseInterface|\React\Promise\PromiseInterface<ResponseInterface>|\Generator */
    public function __invoke(ServerRequestInterface $request)
    {
        return $this->call($request, 0);
    }

    /** @return ResponseInterface|\React\Promise\PromiseInterface<ResponseInterface>|\Generator */
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
