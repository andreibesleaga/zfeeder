<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Fetch;

use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\CacheStoreInterface;

/**
 * A cache store that lives in an array, so the fetcher tests observe exactly
 * what was written without a temp directory or a database in the way.
 */
final class InMemoryCacheStore implements CacheStoreInterface
{
    /** @var array<string, CacheEntry> */
    private array $entries = [];

    /** @var list<string> every URL passed to put(), in order */
    public array $writes = [];

    public function get(string $url): ?CacheEntry
    {
        return $this->entries[$url] ?? null;
    }

    public function put(CacheEntry $entry): void
    {
        $this->entries[$entry->url] = $entry;
        $this->writes[] = $entry->url;
    }

    public function delete(string $url): void
    {
        unset($this->entries[$url]);
    }

    public function purge(int $olderThanSeconds): int
    {
        $removed = 0;
        $cutoff = time() - $olderThanSeconds;
        foreach ($this->entries as $url => $entry) {
            if ($entry->fetchedAt->getTimestamp() < $cutoff) {
                unset($this->entries[$url]);
                ++$removed;
            }
        }

        return $removed;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_keys($this->entries);
    }
}
