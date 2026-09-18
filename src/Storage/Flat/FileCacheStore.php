<?php

declare(strict_types=1);

namespace Zfeeder\Storage\Flat;

use Zfeeder\Exception\StorageException;
use Zfeeder\Storage\AtomicFile;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\CacheStoreInterface;

/**
 * Fetched feed bodies as files, the 1.6 idea with the 2004 flaw removed.
 *
 * 1.6 named cache files after the URL with every non-alphanumeric character
 * turned into `_`, which is neither injective (two URLs could share a file) nor
 * safe (the URL decided the file name). 2.0 uses `sha256(url)` for the body and
 * a JSON sidecar for the metadata that conditional requests need. Old 1.6 cache
 * directories are still readable through `Zfeeder\Legacy\LegacyCacheLocator`.
 */
final class FileCacheStore implements CacheStoreInterface
{
    private const string BODY_SUFFIX = '.xml';
    private const string META_SUFFIX = '.json';

    public function __construct(private readonly string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function get(string $url): ?CacheEntry
    {
        $meta = $this->readMeta($this->metaPath($url));
        if ($meta === null) {
            return null;
        }
        $bodyPath = $this->bodyPath($url);
        if (!is_file($bodyPath)) {
            // Metadata without a body is a half-finished write; treat it as a miss.
            return null;
        }

        return new CacheEntry(
            is_string($meta['url'] ?? null) ? $meta['url'] : $url,
            AtomicFile::read($bodyPath),
            (new \DateTimeImmutable('@' . (string) (int) ($meta['fetchedAt'] ?? 0)))->setTimezone(new \DateTimeZone('UTC')),
            is_string($meta['etag'] ?? null) ? $meta['etag'] : null,
            is_string($meta['lastModified'] ?? null) ? $meta['lastModified'] : null,
            (int) ($meta['status'] ?? 0),
            is_string($meta['error'] ?? null) ? $meta['error'] : '',
        );
    }

    public function put(CacheEntry $entry): void
    {
        AtomicFile::ensureDirectory($this->directory);
        AtomicFile::write($this->bodyPath($entry->url), $entry->body);

        $meta = [
            'url' => $entry->url,
            'fetchedAt' => $entry->fetchedAt->getTimestamp(),
            'etag' => $entry->etag,
            'lastModified' => $entry->lastModified,
            'status' => $entry->status,
            'error' => $entry->error,
        ];
        try {
            $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException('Cannot encode cache metadata for ' . $entry->url, 0, $e);
        }
        AtomicFile::write($this->metaPath($entry->url), $json . "\n");
    }

    public function delete(string $url): void
    {
        $this->forget($this->bodyPath($url), $this->metaPath($url));
    }

    public function purge(int $olderThanSeconds): int
    {
        $cutoff = time() - max(0, $olderThanSeconds);
        $removed = 0;
        foreach ($this->metaFiles() as $metaPath) {
            $meta = $this->readMeta($metaPath);
            $fetchedAt = $meta === null ? 0 : (int) ($meta['fetchedAt'] ?? 0);
            if ($fetchedAt >= $cutoff) {
                continue;
            }
            $body = substr($metaPath, 0, -\strlen(self::META_SUFFIX)) . self::BODY_SUFFIX;
            $this->forget($body, $metaPath);
            ++$removed;
        }

        return $removed;
    }

    /** @return list<string> */
    public function urls(): array
    {
        $urls = [];
        foreach ($this->metaFiles() as $metaPath) {
            $meta = $this->readMeta($metaPath);
            if ($meta !== null && is_string($meta['url'] ?? null) && $meta['url'] !== '') {
                $urls[] = $meta['url'];
            }
        }
        sort($urls, SORT_STRING);

        return $urls;
    }

    public function bodyPath(string $url): string
    {
        return rtrim($this->directory, '/') . '/' . $this->key($url) . self::BODY_SUFFIX;
    }

    public function metaPath(string $url): string
    {
        return rtrim($this->directory, '/') . '/' . $this->key($url) . self::META_SUFFIX;
    }

    private function key(string $url): string
    {
        return hash('sha256', $url);
    }

    private function forget(string $bodyPath, string $metaPath): void
    {
        AtomicFile::delete($metaPath);
        AtomicFile::delete($bodyPath);
        AtomicFile::delete($metaPath . '.lock');
        AtomicFile::delete($bodyPath . '.lock');
    }

    /** @return list<string> */
    private function metaFiles(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $found = glob(rtrim($this->directory, '/') . '/*' . self::META_SUFFIX);
        if ($found === false) {
            throw new StorageException('Cannot list the cache directory: ' . $this->directory);
        }

        return array_values(array_filter($found, 'is_file'));
    }

    /** @return array<array-key, mixed>|null */
    private function readMeta(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A corrupt sidecar is a cache miss, never a fatal error: the feed
            // is simply fetched again and the file overwritten.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
