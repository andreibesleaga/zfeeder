<?php

declare(strict_types=1);

namespace Zfeeder\Parse\Model;

/** One normalised feed: its channel metadata plus its items. */
final readonly class Channel
{
    /** @param list<Item> $items */
    public function __construct(
        public string $title = '',
        public string $link = '',
        public string $description = '',
        public string $language = '',
        public string $copyright = '',
        public string $logoUrl = '',
        public string $logoTitle = '',
        public string $logoLink = '',
        public ?\DateTimeImmutable $lastBuild = null,
        public string $format = '',
        public array $items = [],
    ) {
    }

    /** @param list<Item> $items */
    public function withItems(array $items): self
    {
        return new self(
            $this->title,
            $this->link,
            $this->description,
            $this->language,
            $this->copyright,
            $this->logoUrl,
            $this->logoTitle,
            $this->logoLink,
            $this->lastBuild,
            $this->format,
            $items,
        );
    }
}
