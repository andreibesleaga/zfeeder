<?php

declare(strict_types=1);

namespace Zfeeder\Storage;

use Zfeeder\Exception\StorageException;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Subscription\Opml\Reader;
use Zfeeder\Subscription\Opml\Writer;

/**
 * The half of the subscription contract that is the same whatever the backend.
 *
 * OPML import and export are defined purely in terms of `category()` and
 * `saveCategory()`, so the flat and SQLite stores cannot drift apart in the one
 * area where 1.6 compatibility matters most.
 */
abstract class AbstractSubscriptionStore implements SubscriptionStoreInterface
{
    public function __construct(
        protected readonly Reader $reader = new Reader(),
        protected readonly Writer $writer = new Writer(),
    ) {
    }

    public function exportOpml(string $name): string
    {
        return $this->writer->write($this->category($name));
    }

    /**
     * @param bool $replace true replaces the subscription list, false appends
     *                      the incoming feeds after the existing ones
     *
     * @return int subscriptions taken from the document
     *
     * @throws StorageException
     */
    public function importOpml(string $name, string $opml, bool $replace): int
    {
        Category::assertValidName($name);
        $incoming = $this->reader->readFeeds($opml);

        if (!$this->has($name)) {
            $this->createCategory($name);
        }
        $existing = $this->category($name);

        if ($replace) {
            $feeds = $incoming;
        } else {
            // Appended feeds continue the numbering, so an import can never
            // collide with, or silently reorder, what is already subscribed.
            $next = 0;
            foreach ($existing->feeds as $feed) {
                $next = max($next, $feed->position);
            }
            $feeds = $existing->feeds;
            foreach ($incoming as $feed) {
                ++$next;
                $feeds[] = new Feed(
                    $feed->xmlUrl,
                    $feed->title,
                    $feed->description,
                    $feed->htmlUrl,
                    $next,
                    $feed->refreshMinutes,
                    $feed->showedItems,
                    $feed->subscribed,
                    $feed->language,
                );
            }
        }

        $this->saveCategory(new Category(
            $name,
            $feeds,
            $existing->dateModified,
            $existing->ownerName,
            $existing->ownerEmail,
        ));

        return \count($incoming);
    }

    /**
     * Position order, ties broken by the stored order — the order 1.6 rendered
     * a category in, and the same for both backends.
     *
     * @param list<Feed> $feeds
     *
     * @return list<Feed>
     */
    protected function sortByPosition(array $feeds): array
    {
        usort($feeds, static fn (Feed $a, Feed $b): int => $a->position <=> $b->position);

        return $feeds;
    }
}
