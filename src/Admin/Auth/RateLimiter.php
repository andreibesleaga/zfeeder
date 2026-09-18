<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Auth;

/**
 * A sliding window limiter for the login form, backed by one small JSON file
 * per key.
 *
 * Files rather than a shared store because zFeeder has to run on the same
 * shared hosting it ran on in 2004: no Redis, no APCu guaranteed, often no
 * database. The cost is a couple of filesystem operations per failed login,
 * which is nothing next to an Argon2id verification.
 *
 * The key is hashed before it becomes a file name so that an address or a
 * user name can never escape the directory or leak through a directory
 * listing, and the window is pruned on every read, so the directory cannot
 * grow without bound while the panel is in use.
 */
final class RateLimiter
{
    private const int DIR_MODE = 0o750;
    private const int FILE_MODE = 0o600;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /** @param (callable(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly string $directory,
        private readonly int $limit,
        private readonly int $windowSeconds,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** True when another attempt is allowed right now. */
    public function check(string $key): bool
    {
        return count($this->attempts($key)) < max(1, $this->limit);
    }

    /** Remembers one failed attempt. */
    public function record(string $key): void
    {
        $attempts = $this->attempts($key);
        $attempts[] = ($this->clock)()->getTimestamp();
        $this->save($key, $attempts);
    }

    /** Seconds until the oldest remembered attempt leaves the window, or 0. */
    public function retryAfter(string $key): int
    {
        $attempts = $this->attempts($key);
        if (count($attempts) < max(1, $this->limit)) {
            return 0;
        }

        $oldest = min($attempts);
        $free = $oldest + max(1, $this->windowSeconds) - ($this->clock)()->getTimestamp();

        return max(1, $free);
    }

    /** Forgets this key; called after a successful sign-in. */
    public function clear(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * The attempts still inside the window, oldest first.
     *
     * @return list<int>
     */
    private function attempts(string $key): array
    {
        $path = $this->pathFor($key);
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A corrupted counter must not lock anyone out for ever.
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $cutoff = ($this->clock)()->getTimestamp() - max(1, $this->windowSeconds);
        $attempts = [];
        foreach ($decoded as $value) {
            if ((is_int($value) || is_float($value)) && (int) $value > $cutoff) {
                $attempts[] = (int) $value;
            }
        }
        sort($attempts);

        return $attempts;
    }

    /** @param list<int> $attempts */
    private function save(string $key, array $attempts): void
    {
        $this->ensureDirectory();
        $path = $this->pathFor($key);

        try {
            $json = json_encode(array_values($attempts), JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (file_put_contents($path, $json, LOCK_EX) !== false) {
            @chmod($path, self::FILE_MODE);
        }
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }
        if (!@mkdir($this->directory, self::DIR_MODE, true) && !is_dir($this->directory)) {
            return;
        }
    }
}
