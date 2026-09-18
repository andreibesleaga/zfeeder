<?php

declare(strict_types=1);

namespace Zfeeder\Storage;

/** One cached feed body plus the metadata needed for conditional requests. */
final readonly class CacheEntry
{
    public function __construct(
        public string $url,
        public string $body,
        public \DateTimeImmutable $fetchedAt,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public int $status = 200,
        public string $error = '',
    ) {
    }

    /** 1.6 rule: a cache entry expires `refreshMinutes` after it was written. */
    public function isExpired(int $refreshMinutes, \DateTimeImmutable $now): bool
    {
        $refreshMinutes = max(1, $refreshMinutes);

        return $now->getTimestamp() > $this->fetchedAt->getTimestamp() + ($refreshMinutes * 60);
    }

    public function withFetchedAt(\DateTimeImmutable $at): self
    {
        return new self($this->url, $this->body, $at, $this->etag, $this->lastModified, $this->status, $this->error);
    }

    public function withError(string $error): self
    {
        return new self($this->url, $this->body, $this->fetchedAt, $this->etag, $this->lastModified, $this->status, $error);
    }
}
