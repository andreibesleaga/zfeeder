<?php

declare(strict_types=1);

namespace Zfeeder\Subscription;

use Zfeeder\Exception\ConfigException;

/**
 * One subscription inside a category: an OPML `<outline>` row.
 *
 * Field names mirror the 1.6 OPML attributes so that 2004 subscription files
 * load unchanged and files written here stay readable by the original script.
 */
final class Feed
{
    public function __construct(
        public string $xmlUrl,
        public string $title = '',
        public string $description = '',
        public string $htmlUrl = '',
        public int $position = 1,
        public int $refreshMinutes = 60,
        public int $showedItems = 3,
        public bool $subscribed = true,
        public string $language = '',
    ) {
        if (trim($xmlUrl) === '') {
            throw new ConfigException('A subscription needs a feed URL.');
        }
        $this->position = max(0, $position);
        $this->refreshMinutes = max(1, $refreshMinutes);
        $this->showedItems = max(0, $showedItems);
    }

    /** True when this feed should be rendered at all (1.6 rule). */
    public function isRenderable(): bool
    {
        return $this->subscribed && $this->showedItems > 0 && trim($this->xmlUrl) !== '';
    }

    public function label(): string
    {
        return $this->title !== '' ? $this->title : $this->xmlUrl;
    }
}
