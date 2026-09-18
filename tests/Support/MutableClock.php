<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Support;

/**
 * A clock a test can move.
 *
 * Every timeout in the panel — the login window, the session idle limit, the
 * cache TTL — is a subtraction against "now". Sleeping through those windows
 * would make the suite slow and flaky; moving this clock makes them exact.
 */
final class MutableClock
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /** Moves the clock forward (or back, with a negative value). */
    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
