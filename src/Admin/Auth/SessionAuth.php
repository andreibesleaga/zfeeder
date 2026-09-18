<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Config\Config;
use Zfeeder\Http\SecurityHeaders;

/**
 * Who is signed in to the panel, and for how much longer.
 *
 * Three defences live here, and each one answers a concrete way the 1.6 panel
 * could be taken over:
 *
 *  - the identifier is regenerated on a successful sign-in, so an id planted
 *    beforehand is worthless (1.6 kept the same id, and kept the *password*
 *    in `$_SESSION` besides);
 *  - the cookie is HttpOnly, SameSite=Lax and Secure on HTTPS, so script and
 *    cross-site navigation cannot reach or replay it;
 *  - an idle session is signed out, because a panel left open on a laptop in
 *    a café is the failure mode nobody plans for.
 *
 * The clock is injected: every timeout decision is a subtraction against
 * "now", and a test must be able to say what now is without sleeping.
 */
final class SessionAuth
{
    public const string USER_KEY = '_zf_admin_user';
    public const string SEEN_KEY = '_zf_last_seen';

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    private bool $started = false;

    /** @param (callable(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly Config $config,
        private readonly SessionInterface $session,
        private readonly PasswordHasher $hasher = new PasswordHasher(),
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** Opens the session with the panel's cookie policy. */
    public function start(ServerRequestInterface $request): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        $this->session->start($this->sessionName(), [
            'lifetime' => 0,
            'path' => $this->cookiePath(),
            'domain' => '',
            'secure' => $this->secureCookie($request),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Checks one set of credentials.
     *
     * Both the user name and the password are compared even when the name is
     * already wrong, so that the answer takes the same time either way and the
     * form cannot be used to enumerate the account.
     */
    public function attempt(string $user, string $password): bool
    {
        $expectedUser = trim($this->config->string('admin_user'));
        $hash = trim($this->config->string('admin_password_hash'));

        if ($hash === '') {
            $this->hasher->burn($password);

            return false;
        }

        $userMatches = hash_equals($expectedUser, trim($user));
        $passwordMatches = $this->hasher->verify($password, $hash);

        if (!$userMatches || !$passwordMatches) {
            return false;
        }

        // Session fixation: whatever identifier the client arrived with is
        // discarded before anything worth stealing is stored under it.
        $this->session->regenerate();
        $this->session->set(self::USER_KEY, $expectedUser);
        $this->session->set(self::SEEN_KEY, ($this->clock)()->getTimestamp());

        return true;
    }

    public function isAuthenticated(): bool
    {
        $user = $this->session->get(self::USER_KEY);
        if (!is_string($user) || $user === '') {
            return false;
        }

        if ($this->isIdle()) {
            $this->logout();

            return false;
        }

        $this->session->set(self::SEEN_KEY, ($this->clock)()->getTimestamp());

        return true;
    }

    public function user(): ?string
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $user = $this->session->get(self::USER_KEY);

        return is_string($user) ? $user : null;
    }

    public function logout(): void
    {
        $this->session->remove(self::USER_KEY);
        $this->session->remove(self::SEEN_KEY);
        $this->session->destroy();
    }

    private function isIdle(): bool
    {
        $seen = $this->session->get(self::SEEN_KEY);
        if (!is_int($seen)) {
            return true;
        }

        $idle = max(60, $this->config->int('session_idle_seconds'));

        return ($this->clock)()->getTimestamp() - $seen > $idle;
    }

    private function sessionName(): string
    {
        $name = trim($this->config->string('session_name'));

        // The cookie name has to be a token; anything else and the browser
        // drops the header, which would look like "login does nothing".
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1 ? $name : 'zfsid';
    }

    private function secureCookie(ServerRequestInterface $request): bool
    {
        return match ($this->config->string('session_secure')) {
            'always' => true,
            'never' => false,
            default => (new SecurityHeaders($this->config))->isHttps($request),
        };
    }

    /**
     * The installation's own path, so a second application on the same host
     * never sees this cookie.
     */
    private function cookiePath(): string
    {
        $base = trim($this->config->string('base_url'));
        if ($base === '') {
            return '/';
        }

        $path = parse_url($base, PHP_URL_PATH);
        if (!is_string($path) || trim($path) === '') {
            return '/';
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : $path . '/';
    }
}
