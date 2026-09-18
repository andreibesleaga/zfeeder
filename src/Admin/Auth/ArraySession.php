<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Auth;

/**
 * An in-memory session for tests: the same contract, none of the globals.
 *
 * The identifier is generated the same way a real one is opaque, and changes
 * on `regenerate()`, which is what the session fixation test asserts.
 */
final class ArraySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    private string $id = '';

    private bool $started = false;

    /** @param array<string, mixed> $initial */
    public function __construct(array $initial = [])
    {
        $this->data = $initial;
    }

    /** @param array{lifetime?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: string} $cookieParams */
    public function start(string $name, array $cookieParams): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        if ($this->id === '') {
            $this->id = bin2hex(random_bytes(16));
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->id = '';
        $this->started = false;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
