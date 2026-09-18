<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Admin\Auth\SessionAuth;
use Zfeeder\Config\Config;
use Zfeeder\Tests\Unit\Admin\RecordingSession;

/**
 * S3 — the session identifier changes on sign-in, the cookie is HttpOnly and
 * SameSite=Lax, Secure follows the scheme (or the `session_secure` override),
 * an idle session is signed out, and signing out leaves nothing behind the old
 * identifier.
 *
 * Closes 1.6 defects L3 and L4: `newsfeeds/admin.php:44` stored the *plaintext
 * password* in `$_SESSION` and `adminfuncs.php:26` re-compared it on every
 * request, while `admin.php:36` called `session_start()` with PHP's defaults —
 * no regeneration, no cookie flags — so an identifier planted in a victim's
 * browser stayed valid straight through their sign-in.
 *
 * The control lives in `src/Admin/Auth/SessionAuth.php` (regeneration, the
 * cookie policy, the idle check against an injected clock) and
 * `src/Admin/Auth/PhpSession.php` (`use_strict_mode`, `use_only_cookies`,
 * `sid_length`, and expiring the cookie on destroy).
 */
final class S03SessionTest extends SecurityTestCase
{
    /** @param array<string, string|int|bool> $overrides */
    private function authFor(
        ServerRequestInterface $request,
        RecordingSession $session,
        array $overrides = [],
    ): SessionAuth {
        $config = Config::forTesting($overrides + [
            'admin_user' => self::USER,
            'admin_password_hash' => self::passwordHash(),
        ], $this->tempDir());

        $auth = new SessionAuth($config, $session, clock: fn (): \DateTimeImmutable => $this->clock->now());
        $auth->start($request);

        return $auth;
    }

    private static function httpsRequest(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'https://zfeeder.test/admin', [], null, '1.1', ['REMOTE_ADDR' => self::ADDRESS]);
    }

    private static function plainRequest(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://zfeeder.test/admin', [], null, '1.1', ['REMOTE_ADDR' => self::ADDRESS]);
    }

    public function testTheSessionIdentifierChangesOnASuccessfulSignIn(): void
    {
        // Opening the form is what an attacker uses to plant an identifier.
        $this->send('GET', '/admin/login');
        $planted = $this->session->id();
        self::assertNotSame('', $planted);

        $response = $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));

        self::assertSame(303, $response->getStatusCode());
        self::assertNotSame($planted, $this->session->id(), 'the planted identifier survived the sign-in');
        self::assertSame(self::USER, $this->session->get(SessionAuth::USER_KEY));
    }

    public function testAFailedSignInLeavesNoAuthenticatedSessionBehind(): void
    {
        $this->send('GET', '/admin/login');

        $response = $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => 'wrong',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($this->session->get(SessionAuth::USER_KEY));
    }

    public function testThePlaintextPasswordIsNeverStoredInTheSession(): void
    {
        $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));

        $stored = json_encode($this->session->all(), JSON_UNESCAPED_SLASHES);
        self::assertIsString($stored);
        self::assertStringNotContainsString(self::PASSWORD, $stored, '1.6 defect L3 is back: the password is in the session');
        self::assertStringNotContainsString('$argon2', $stored, 'the hash itself is in the session');
    }

    public function testTheCookieIsHttpOnlyAndSameSiteLax(): void
    {
        $session = new RecordingSession();
        $this->authFor(self::plainRequest(), $session);

        self::assertTrue($session->startedWith['httponly'] ?? false, 'script can read the session cookie');
        self::assertSame('Lax', $session->startedWith['samesite'] ?? null);
        self::assertSame('zfsid', $session->startedWith['name'] ?? null);
        self::assertSame(0, $session->startedWith['lifetime'] ?? null, 'a persistent cookie outlives the browser');
    }

    public function testSecureIsSetOverHttpsAndNotOverPlainHttp(): void
    {
        $secure = new RecordingSession();
        $this->authFor(self::httpsRequest(), $secure);
        self::assertTrue($secure->startedWith['secure'] ?? false, 'no Secure flag on an HTTPS request');

        // The other half: on plain HTTP the flag must be absent, or a browser
        // would drop the cookie and sign-in would silently do nothing.
        $plain = new RecordingSession();
        $this->authFor(self::plainRequest(), $plain);
        self::assertFalse($plain->startedWith['secure'] ?? true, 'Secure was set on a plaintext connection');
    }

    public function testSessionSecureAlwaysAndNeverOverrideTheScheme(): void
    {
        $always = new RecordingSession();
        $this->authFor(self::plainRequest(), $always, ['session_secure' => 'always']);
        self::assertTrue($always->startedWith['secure'] ?? false);

        $never = new RecordingSession();
        $this->authFor(self::httpsRequest(), $never, ['session_secure' => 'never']);
        self::assertFalse($never->startedWith['secure'] ?? true);
    }

    public function testAForwardedProtocolAloneDoesNotEarnASecureCookie(): void
    {
        $request = self::plainRequest()->withHeader('X-Forwarded-Proto', 'https');

        $untrusted = new RecordingSession();
        $this->authFor($request, $untrusted);
        self::assertFalse($untrusted->startedWith['secure'] ?? true, 'a client claimed HTTPS and was believed');

        $trusted = new RecordingSession();
        $this->authFor($request, $trusted, ['trusted_proxies' => self::ADDRESS]);
        self::assertTrue($trusted->startedWith['secure'] ?? false, 'a declared proxy was not believed');
    }

    public function testTheCookiePathIsTheInstallationsOwnPath(): void
    {
        $session = new RecordingSession();
        $this->authFor(self::plainRequest(), $session, ['base_url' => 'https://host.example/news/']);

        self::assertSame('/news/', $session->startedWith['path'] ?? null);
    }

    public function testAnIdleSessionIsSignedOut(): void
    {
        $this->bootKernel(['session_idle_seconds' => 60]);
        $this->signIn();

        // Inside the window the panel is still open, and every request moves
        // the marker forward.
        $this->clock->advance(59);
        self::assertSame(200, $this->send('GET', '/admin')->getStatusCode());

        $this->clock->advance(61);
        $response = $this->send('GET', '/admin');

        self::assertSame(303, $response->getStatusCode(), 'an idle session still reached the panel');
        self::assertStringContainsString('/admin/login', $response->getHeaderLine('Location'));
        self::assertNull($this->session->get(SessionAuth::USER_KEY));
    }

    public function testWithALongIdleLimitTheSameWaitChangesNothing(): void
    {
        // The weakened build, to show the test above is the timeout and not the
        // clock: the identical wait against a configuration that permits it.
        $this->bootKernel(['session_idle_seconds' => 86400]);
        $this->signIn();

        $this->clock->advance(3600);

        self::assertSame(200, $this->send('GET', '/admin')->getStatusCode());
    }

    public function testActivityKeepsTheSessionAliveWithoutExtendingItForEver(): void
    {
        $this->bootKernel(['session_idle_seconds' => 60]);
        $this->signIn();

        for ($i = 0; $i < 5; ++$i) {
            $this->clock->advance(50);
            self::assertSame(200, $this->send('GET', '/admin')->getStatusCode(), 'request ' . $i);
        }

        $this->clock->advance(61);
        self::assertSame(303, $this->send('GET', '/admin')->getStatusCode());
    }

    public function testSigningOutDestroysTheSessionSoTheOldIdentifierIsWorthless(): void
    {
        $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));
        $signedInId = $this->session->id();
        self::assertNotSame('', $signedInId);

        $response = $this->send('POST', '/admin/logout', $this->withToken([]));
        self::assertSame(303, $response->getStatusCode());

        // Nothing is left behind the old identifier: no user, no CSRF token,
        // no last-seen marker, and the identifier itself is gone.
        self::assertSame([], $this->session->all());
        self::assertNotSame($signedInId, $this->session->id());
        self::assertNull($this->session->get(SessionAuth::USER_KEY));

        self::assertSame(303, $this->send('GET', '/admin')->getStatusCode(), 'the panel was reachable after signing out');
    }
}
