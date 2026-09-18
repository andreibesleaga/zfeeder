<?php

declare(strict_types=1);

namespace Zfeeder\Legacy;

/**
 * Finds a feed body in a zFeeder 1.6 cache directory.
 *
 * 1.6 named cache files with
 *
 *     ereg_replace("[^[:alnum:]]", "_", $url) . '.xml'
 *
 * (`url2file()` in `includes/zfuncs.php`). The POSIX class `[:alnum:]` under the
 * C locale that 2004 PHP ran in is exactly `a-zA-Z0-9`, so the modern
 * equivalent is `preg_replace('/[^a-zA-Z0-9]/', '_', $url)`.
 *
 * The scheme is kept for reading only: it is not injective — `http://a.test/x`
 * and `http://a.test_x` map to the same file — and it puts the URL into the file
 * name, which is why 2.0 writes `sha256(url)` instead. This class exists so that
 * an upgrade over an existing installation starts with a warm cache instead of
 * re-fetching every subscription.
 */
final class LegacyCacheLocator
{
    /** The 1.6 file name for a feed URL. */
    public function filename(string $url): string
    {
        $mangled = preg_replace('/[^a-zA-Z0-9]/', '_', $url);

        return ($mangled ?? '') . '.xml';
    }

    /** @return string|null the readable path, or null when 1.6 never cached this feed */
    public function find(string $cacheDir, string $url): ?string
    {
        $path = rtrim($cacheDir, '/\\') . '/' . $this->filename($url);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /** @return string|null the cached body, or null when there is none */
    public function read(string $cacheDir, string $url): ?string
    {
        $path = $this->find($cacheDir, $url);
        if ($path === null) {
            return null;
        }
        $body = file_get_contents($path);

        return $body === false ? null : $body;
    }

    /**
     * When 1.6 last wrote the file. 1.6 had no metadata sidecar: the modification
     * time *was* the freshness record (`timeExpired()` compared `filemtime()`).
     */
    public function fetchedAt(string $cacheDir, string $url): ?\DateTimeImmutable
    {
        $path = $this->find($cacheDir, $url);
        if ($path === null) {
            return null;
        }
        $mtime = filemtime($path);
        if ($mtime === false) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $mtime))->setTimezone(new \DateTimeZone('UTC'));
    }
}
