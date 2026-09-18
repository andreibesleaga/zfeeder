<?php

declare(strict_types=1);

namespace Zfeeder\Subscription;

use Zfeeder\Exception\ConfigException;

/** A named set of subscriptions; one OPML file, or one row set in SQLite. */
final class Category
{
    public const string NAME_PATTERN = '/^[a-z0-9_-]{1,40}$/';

    /** @param list<Feed> $feeds */
    public function __construct(
        public string $name,
        public array $feeds = [],
        public ?\DateTimeImmutable $dateModified = null,
        public string $ownerName = '',
        public string $ownerEmail = '',
    ) {
        self::assertValidName($name);
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public static function assertValidName(string $name): void
    {
        if (!self::isValidName($name)) {
            throw new ConfigException(sprintf(
                'Invalid category name "%s": use 1-40 characters from a-z, 0-9, hyphen or underscore.',
                $name,
            ));
        }
    }

    /**
     * Feeds that 1.6 would render, ordered by position then by original order.
     *
     * @return list<Feed>
     */
    public function renderableFeeds(): array
    {
        $feeds = array_values(array_filter($this->feeds, static fn (Feed $f): bool => $f->isRenderable()));
        usort($feeds, static fn (Feed $a, Feed $b): int => $a->position <=> $b->position);

        return $feeds;
    }

    public function count(): int
    {
        return count($this->feeds);
    }
}
