<?php

declare(strict_types=1);

namespace Zfeeder\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A router sized for this application: a list of routes, matched in order.
 *
 * There are about twenty routes. A compiled dispatcher would be faster and
 * much harder to read, and the difference is not measurable next to a single
 * feed fetch, so the simple version is the right one here.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param list<string> $methods */
    public function add(array $methods, string $pattern, string $handler, string $name = ''): self
    {
        $this->routes[] = new Route($methods, $pattern, $handler, $name);

        return $this;
    }

    public function get(string $pattern, string $handler, string $name = ''): self
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler, $name);
    }

    public function post(string $pattern, string $handler, string $name = ''): self
    {
        return $this->add(['POST'], $pattern, $handler, $name);
    }

    public function any(string $pattern, string $handler, string $name = ''): self
    {
        return $this->add(['GET', 'HEAD', 'POST'], $pattern, $handler, $name);
    }

    /**
     * @return array{handler: string, params: array<string, string>}|null null = 404,
     *         or an array with handler `405` when the path matched but the method did not
     */
    public function dispatch(ServerRequestInterface $request): ?array
    {
        $path = '/' . trim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $params = $route->match($path);
            if ($params === null) {
                continue;
            }
            $pathMatched = true;
            if (in_array($method, $route->methods, true)) {
                return ['handler' => $route->handler, 'params' => $params];
            }
        }

        if ($pathMatched) {
            return ['handler' => '405', 'params' => []];
        }

        return null;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
