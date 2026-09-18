<?php

declare(strict_types=1);

namespace Zfeeder\Storage\Sqlite;

use Zfeeder\Exception\StorageException;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\CacheStoreInterface;

/**
 * Fetched feed bodies in SQLite, keyed by the URL itself.
 *
 * Unlike the file cache there is no hashing and no sidecar: the URL is the
 * primary key and the metadata are columns, so `purge()` is one indexed DELETE
 * instead of a directory scan. Prepared statements only.
 */
final class SqliteCacheStore implements CacheStoreInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function get(string $url): ?CacheEntry
    {
        $statement = $this->pdo()->prepare(
            'SELECT url, body, fetched_at, etag, last_modified, status, error FROM cache WHERE url = ?',
        );
        $statement->execute([$url]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new CacheEntry(
            is_string($row['url'] ?? null) ? $row['url'] : $url,
            is_string($row['body'] ?? null) ? $row['body'] : '',
            (new \DateTimeImmutable('@' . (string) (int) ($row['fetched_at'] ?? 0)))->setTimezone(new \DateTimeZone('UTC')),
            is_string($row['etag'] ?? null) ? $row['etag'] : null,
            is_string($row['last_modified'] ?? null) ? $row['last_modified'] : null,
            (int) ($row['status'] ?? 0),
            is_string($row['error'] ?? null) ? $row['error'] : '',
        );
    }

    public function put(CacheEntry $entry): void
    {
        try {
            $statement = $this->pdo()->prepare(
                'INSERT INTO cache (url, body, fetched_at, etag, last_modified, status, error)'
                . ' VALUES (:url, :body, :fetched_at, :etag, :last_modified, :status, :error)'
                . ' ON CONFLICT(url) DO UPDATE SET body = excluded.body, fetched_at = excluded.fetched_at,'
                . ' etag = excluded.etag, last_modified = excluded.last_modified, status = excluded.status,'
                . ' error = excluded.error',
            );
            $statement->execute([
                'url' => $entry->url,
                'body' => $entry->body,
                'fetched_at' => $entry->fetchedAt->getTimestamp(),
                'etag' => $entry->etag,
                'last_modified' => $entry->lastModified,
                'status' => $entry->status,
                'error' => $entry->error,
            ]);
        } catch (\PDOException $e) {
            throw new StorageException('Cannot cache ' . $entry->url . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $url): void
    {
        $statement = $this->pdo()->prepare('DELETE FROM cache WHERE url = ?');
        $statement->execute([$url]);
    }

    public function purge(int $olderThanSeconds): int
    {
        $cutoff = time() - max(0, $olderThanSeconds);
        $statement = $this->pdo()->prepare('DELETE FROM cache WHERE fetched_at < ?');
        $statement->execute([$cutoff]);

        return $statement->rowCount();
    }

    /** @return list<string> */
    public function urls(): array
    {
        $statement = $this->pdo()->query('SELECT url FROM cache ORDER BY url');
        if ($statement === false) {
            throw new StorageException('Cannot list the cache.');
        }

        $urls = [];
        /** @var mixed $row */
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row) && is_string($row['url'] ?? null)) {
                $urls[] = $row['url'];
            }
        }

        return $urls;
    }

    private function pdo(): \PDO
    {
        $this->database->ensureMigrated();

        return $this->database->pdo();
    }
}
