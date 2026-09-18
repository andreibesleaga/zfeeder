<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Auth;

/**
 * One token per session, compared in constant time.
 *
 * Per session rather than per form: a per-form token buys very little once the
 * cookie is `SameSite=Lax` and costs a broken back button and a broken second
 * tab, both of which an administrator will meet on the subscriptions screen.
 *
 * A missing token is treated exactly like a wrong one. Anything else turns
 * "forget the hidden field" into "skip the check".
 */
final class Csrf
{
    public const string SESSION_KEY = '_zf_csrf';
    public const string FIELD = 'csrf_token';

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function token(): string
    {
        $existing = $this->session->get(self::SESSION_KEY);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function validate(?string $submitted): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        if ($submitted === null || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /** A fresh token, used after a sign-in regenerates the session. */
    public function rotate(): string
    {
        $this->session->remove(self::SESSION_KEY);

        return $this->token();
    }
}
