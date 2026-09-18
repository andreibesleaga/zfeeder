<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Auth;

/**
 * The little of a PHP session that the panel actually uses.
 *
 * It exists for one reason: `session_start()` writes headers and touches
 * global state, so a test that wanted to assert "the session id changed on
 * login" would have to run in a subprocess. With this seam the same assertion
 * is three lines against an array.
 */
interface SessionInterface
{
    /**
     * Opens the session. Implementations that do not use PHP sessions ignore
     * the cookie parameters.
     *
     * @param array{lifetime?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: string} $cookieParams
     */
    public function start(string $name, array $cookieParams): void;

    /** The current session identifier; '' when no session is open. */
    public function id(): string;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** New identifier, same contents: the session fixation defence. */
    public function regenerate(): void;

    /** Empties and closes the session, expiring its cookie where there is one. */
    public function destroy(): void;

    /** @return array<string, mixed> */
    public function all(): array;
}
