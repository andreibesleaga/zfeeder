<?php

declare(strict_types=1);

namespace Zfeeder\Fetch;

use Zfeeder\Storage\CacheEntry;

/** Outcome of one feed fetch attempt, including the 1.6-compatible report line. */
final readonly class FetchResult
{
    public const string CACHED = 'cached';
    public const string NOT_MODIFIED = 'not-modified';
    public const string NOT_EXPIRED = 'not-expired';
    public const string FAILED = 'failed';

    public function __construct(
        public string $url,
        public string $outcome,
        public ?CacheEntry $entry = null,
        public string $message = '',
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->outcome !== self::FAILED;
    }

    /** The wording the 1.6 offline refresh printed, kept for compatibility. */
    public function legacyLine(): string
    {
        return match ($this->outcome) {
            self::CACHED, self::NOT_MODIFIED => $this->url . ' - cached',
            self::NOT_EXPIRED => $this->url . ' - not expired yet',
            default => $this->url . ' - NOT cached; check connection',
        };
    }
}
