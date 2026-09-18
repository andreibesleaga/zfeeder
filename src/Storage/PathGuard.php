<?php

declare(strict_types=1);

namespace Zfeeder\Storage;

use Zfeeder\Exception\StorageException;

/**
 * Containment check for every path built from user input.
 *
 * The category name pattern already forbids slashes and dots, so traversal
 * should be impossible; this is the second lock on the same door, for the day
 * someone loosens the pattern or a symlink appears inside the data directory.
 * `realpath()` is used where the path exists, so symlinks are followed before
 * the comparison rather than after it.
 */
final class PathGuard
{
    /** @throws StorageException when $path would escape $base */
    public static function assertInside(string $base, string $path): void
    {
        if (!self::isInside($base, $path)) {
            throw new StorageException(sprintf('Refusing to use "%s": it is outside "%s".', $path, $base));
        }
    }

    public static function isInside(string $base, string $path): bool
    {
        $baseReal = self::canonical($base);
        $pathReal = self::canonical($path);

        return $pathReal === $baseReal || str_starts_with($pathReal, $baseReal . '/');
    }

    /** Absolute and free of `.`/`..`, resolving symlinks for the part that exists. */
    public static function canonical(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $real = realpath($path);
        if (is_string($real)) {
            return rtrim(str_replace('\\', '/', $real), '/');
        }

        $parentReal = realpath(\dirname($path));
        if (is_string($parentReal)) {
            return rtrim(str_replace('\\', '/', $parentReal), '/') . '/' . basename($path);
        }

        return self::lexical($path);
    }

    private static function lexical(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $parts);
    }
}
