<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Admin;

use Zfeeder\Admin\Auth\ArraySession;
use Zfeeder\Admin\Auth\SessionInterface;

/**
 * An array session that also remembers how it was opened, so a test can assert
 * on the cookie policy without a real PHP session.
 */
final class RecordingSession implements SessionInterface
{
    /** @var array<string, mixed> */
    public array $startedWith = [];

    private ArraySession $inner;

    public function __construct()
    {
        $this->inner = new ArraySession();
    }

    /** @param array{lifetime?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: string} $cookieParams */
    public function start(string $name, array $cookieParams): void
    {
        $this->startedWith = ['name' => $name] + $cookieParams;
        $this->inner->start($name, $cookieParams);
    }

    public function id(): string
    {
        return $this->inner->id();
    }

    public function get(string $key): mixed
    {
        return $this->inner->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->inner->set($key, $value);
    }

    public function remove(string $key): void
    {
        $this->inner->remove($key);
    }

    public function regenerate(): void
    {
        $this->inner->regenerate();
    }

    public function destroy(): void
    {
        $this->inner->destroy();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->inner->all();
    }
}
