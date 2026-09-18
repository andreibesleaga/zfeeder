<?php

declare(strict_types=1);

namespace Zfeeder\Storage;

/** Where fetched feed bodies are kept between requests. */
interface CacheStoreInterface
{
    public function get(string $url): ?CacheEntry;

    public function put(CacheEntry $entry): void;

    public function delete(string $url): void;

    /** @return int entries removed */
    public function purge(int $olderThanSeconds): int;

    /** @return list<string> every cached feed URL */
    public function urls(): array;
}
