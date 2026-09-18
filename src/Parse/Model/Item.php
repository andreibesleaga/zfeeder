<?php

declare(strict_types=1);

namespace Zfeeder\Parse\Model;

/**
 * One normalised entry of a feed, whatever the source format was.
 *
 * `summaryHtml` and `contentHtml` hold the feed's own markup, unsanitised;
 * sanitising happens in the render layer so that callers can choose a policy.
 */
final readonly class Item
{
    /** @param list<Enclosure> $enclosures */
    public function __construct(
        public string $id,
        public string $title,
        public string $link,
        public string $summaryHtml = '',
        public string $contentHtml = '',
        public ?\DateTimeImmutable $published = null,
        public string $author = '',
        public array $enclosures = [],
        /** The date exactly as the feed wrote it, for byte-identical legacy rendering. */
        public string $rawDate = '',
    ) {
    }

    /** Body preferred for display: the richer of content and summary. */
    public function bodyHtml(): string
    {
        return $this->contentHtml !== '' ? $this->contentHtml : $this->summaryHtml;
    }
}
