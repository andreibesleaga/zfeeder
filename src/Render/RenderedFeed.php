<?php

declare(strict_types=1);

namespace Zfeeder\Render;

use Zfeeder\Parse\Model\Channel;
use Zfeeder\Parse\Model\Item;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Feed;

/**
 * One subscription with its already-resolved content.
 *
 * The renderer never fetches or parses: fetching is a different layer with
 * different failure modes (network, timeouts, redirects) and mixing the two is
 * what made 1.6's `parseRssFile()` impossible to test. A caller resolves each
 * feed however it likes — live, from cache, or from a fixture — and hands the
 * result over.
 *
 * `$channel` is null when the feed could not be parsed and `$cache` is null
 * when nothing has ever been stored for it; both cases render as an empty
 * channel rather than a fatal error.
 */
final readonly class RenderedFeed
{
    public function __construct(
        public Feed $feed,
        public ?Channel $channel = null,
        public ?CacheEntry $cache = null,
    ) {
    }

    /** @param array{Feed, Channel|null, CacheEntry|null} $triple */
    public static function fromTriple(array $triple): self
    {
        return new self($triple[0], $triple[1], $triple[2]);
    }

    /** @return list<Item> */
    public function items(): array
    {
        return $this->channel === null ? [] : $this->channel->items;
    }
}
