<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Auth;

/**
 * The administrator password, hashed the way 2026 expects.
 *
 * 1.6 stored `md5($password)` in a PHP file. That is two defects in one line:
 * an unsalted, GPU-trivial digest, and a credential living inside executable
 * code. 2.0 stores an Argon2id hash in the JSON configuration, which is data,
 * and compares with `password_verify()`, which is constant time for a given
 * hash and does the salt handling itself.
 *
 * An empty configured hash is not "no password": it means the panel has never
 * been set up, and the caller must refuse to serve it at all.
 */
final class PasswordHasher
{
    /** Short passwords are the one rule the panel enforces on the operator. */
    public const int MIN_LENGTH = 12;

    /**
     * Cost parameters. Defaults are PHP's, restated here so that a change is a
     * deliberate edit rather than a side effect of an upgrade: 64 MiB of memory
     * and four passes is a fraction of a second on a small VPS and a genuine
     * obstacle to offline cracking.
     *
     * @var array{memory_cost: int, time_cost: int, threads: int}
     */
    private const array OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1,
    ];

    /**
     * A hash of a value nobody knows, used to spend the same time verifying a
     * password for a user that does not exist as for one that does.
     */
    private ?string $decoyHash = null;

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(string $plain, string $hash): bool
    {
        if (trim($hash) === '') {
            // Nothing to compare against; still burn the time so that an
            // unconfigured panel cannot be told apart from a configured one.
            $this->burn($plain);

            return false;
        }

        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        if (trim($hash) === '') {
            return true;
        }

        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    /** Verifies against a throwaway hash purely for its timing. */
    public function burn(string $plain): void
    {
        $this->decoyHash ??= $this->hash(bin2hex(random_bytes(16)));
        password_verify($plain, $this->decoyHash);
    }
}
