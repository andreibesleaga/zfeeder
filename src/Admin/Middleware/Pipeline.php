<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs a list of PSR-15 middleware and then the controller.
 *
 * Thirty lines instead of a middleware library, for the same reason the router
 * is hand written: there are three middleware and one handler, and a
 * dependency would buy nothing but indirection.
 */
final class Pipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $queue;

    /** @var callable(ServerRequestInterface): ResponseInterface */
    private $last;

    /**
     * @param list<MiddlewareInterface>                          $middleware
     * @param callable(ServerRequestInterface): ResponseInterface $last
     */
    public function __construct(array $middleware, callable $last)
    {
        $this->queue = $middleware;
        $this->last = $last;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_shift($this->queue);
        if ($middleware === null) {
            return ($this->last)($request);
        }

        return $middleware->process($request, $this);
    }
}
