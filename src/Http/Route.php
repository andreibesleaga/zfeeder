<?php

declare(strict_types=1);

namespace Zfeeder\Http;

/** One route: a method, a path pattern with {placeholders}, and a handler name. */
final readonly class Route
{
    /** @param list<string> $methods */
    public function __construct(
        public array $methods,
        public string $pattern,
        public string $handler,
        public string $name = '',
    ) {
    }

    /**
     * @return array<string, string>|null captured parameters, or null when the path does not match
     */
    public function match(string $path): ?array
    {
        $regex = preg_replace('/\{([a-z_][a-z0-9_]*)\}/i', '(?P<$1>[^/]+)', $this->pattern);
        if ($regex === null) {
            return null;
        }
        if (preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode($value);
            }
        }

        return $params;
    }
}
