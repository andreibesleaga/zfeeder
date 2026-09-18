<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Auth;

/**
 * The production session: PHP's own, opened with the cookie flags the panel
 * requires and never with an identifier the client invented.
 *
 * `use_strict_mode` is the setting that matters most here. Without it PHP
 * happily adopts a session id supplied in a cookie, which is half of a
 * fixation attack handed over for free; with it an unknown id is replaced.
 */
final class PhpSession implements SessionInterface
{
    /** @param array{lifetime?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: string} $cookieParams */
    public function start(string $name, array $cookieParams): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            return;
        }

        $samesite = $cookieParams['samesite'] ?? 'Lax';

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $cookieParams['lifetime'] ?? 0,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => $cookieParams['secure'] ?? false,
            'httponly' => $cookieParams['httponly'] ?? true,
            // Only the three legal values reach PHP; anything else would be
            // dropped by the browser and silently disable the protection.
            'samesite' => in_array($samesite, ['Lax', 'Strict', 'None'], true) ? $samesite : 'Lax',
        ]);
        session_start([
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'cookie_httponly' => 1,
            'sid_length' => 48,
            'sid_bits_per_character' => 5,
        ]);
    }

    public function id(): string
    {
        $id = session_id();

        return $id === false ? '' : $id;
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $name = session_name();
        $params = session_get_cookie_params();
        $_SESSION = [];
        session_unset();
        session_destroy();

        if ($name !== false && !headers_sent()) {
            // Destroying the session server side leaves the cookie in the
            // browser; expiring it is what actually signs the visitor out.
            setcookie($name, '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        /** @var array<string, mixed> $session */
        $session = $_SESSION ?? [];

        return $session;
    }
}
