<?php

declare(strict_types=1);

namespace Zfeeder\Storage;

use Zfeeder\Exception\StorageException;
use Zfeeder\Subscription\Category;

/**
 * Copies everything from one storage backend into another: flat to SQLite when
 * an installation grows, SQLite back to flat when the owner wants their OPML
 * files back. Switching storage must never be a one-way door.
 *
 * The copy is followed by a verification pass that re-reads the destination and
 * compares what actually landed, because "the writes did not throw" is not the
 * same as "the data is there".
 */
final class Migrator
{
    /**
     * @return array{categories: int, feeds: int, cache: int} what was copied
     *
     * @throws StorageException when the destination does not match the source afterwards
     */
    public function migrate(
        SubscriptionStoreInterface $from,
        SubscriptionStoreInterface $to,
        ?CacheStoreInterface $fromCache = null,
        ?CacheStoreInterface $toCache = null,
    ): array {
        $counts = ['categories' => 0, 'feeds' => 0, 'cache' => 0];

        foreach ($from->categories() as $name) {
            $category = $from->category($name);
            if (!$to->has($name)) {
                $to->createCategory($name);
            }
            $to->saveCategory(new Category(
                $name,
                $category->feeds,
                $category->dateModified,
                $category->ownerName,
                $category->ownerEmail,
            ));
            ++$counts['categories'];
            $counts['feeds'] += \count($category->feeds);
        }

        if ($fromCache !== null && $toCache !== null) {
            foreach ($fromCache->urls() as $url) {
                $entry = $fromCache->get($url);
                if ($entry === null) {
                    // Purged between listing and reading: nothing to copy.
                    continue;
                }
                $toCache->put($entry);
                ++$counts['cache'];
            }
        }

        $this->verify($from, $to, $fromCache, $toCache);

        return $counts;
    }

    /** @throws StorageException */
    private function verify(
        SubscriptionStoreInterface $from,
        SubscriptionStoreInterface $to,
        ?CacheStoreInterface $fromCache,
        ?CacheStoreInterface $toCache,
    ): void {
        foreach ($from->categories() as $name) {
            if (!$to->has($name)) {
                throw new StorageException('Migration lost the category: ' . $name);
            }
            $source = $from->category($name);
            $copy = $to->category($name);
            if (\count($source->feeds) !== \count($copy->feeds)) {
                throw new StorageException(sprintf(
                    'Migration of category "%s" copied %d of %d subscriptions.',
                    $name,
                    \count($copy->feeds),
                    \count($source->feeds),
                ));
            }
            $sourceUrls = array_map(static fn ($feed): string => $feed->xmlUrl, $source->feeds);
            $copyUrls = array_map(static fn ($feed): string => $feed->xmlUrl, $copy->feeds);
            if ($sourceUrls !== $copyUrls) {
                throw new StorageException('Migration changed the subscriptions of category: ' . $name);
            }
        }

        if ($fromCache === null || $toCache === null) {
            return;
        }
        $sourceUrls = $fromCache->urls();
        $copyUrls = $toCache->urls();
        foreach ($sourceUrls as $url) {
            if (!\in_array($url, $copyUrls, true)) {
                throw new StorageException('Migration lost the cached feed: ' . $url);
            }
        }
    }
}
