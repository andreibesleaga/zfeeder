<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Admin;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zfeeder\Admin\Auth\ArraySession;
use Zfeeder\Admin\Auth\PasswordHasher;
use Zfeeder\Admin\Auth\SessionAuth;
use Zfeeder\Config\Config;

#[CoversClass(SessionAuth::class)]
final class SessionAuthTest extends TestCase
{
    private const string PASSWORD = 'a-password-worth-having';

    private static ?string $hash = null;

    private \DateTimeImmutable $now;

    private ArraySession $session;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-18T12:00:00+00:00');
        $this->session = new ArraySession();
        self::$hash ??= (new PasswordHasher())->hash(self::PASSWORD);
    }

    /** @param array<string, string|int|bool> $overrides */
    private function auth(array $overrides = []): SessionAuth
    {
        $config = Config::forTesting($overrides + [
            'admin_user' => 'admin',
            'admin_password_hash' => (string) self::$hash,
        ]);

        return new SessionAuth($config, $this->session, new PasswordHasher(), fn (): \DateTimeImmutable => $this->now);
    }

    public function testTheRightCredentialsSignIn(): void
    {
        $auth = $this->auth();

        self::assertTrue($auth->attempt('admin', self::PASSWORD));
        self::assertTrue($auth->isAuthenticated());
        self::assertSame('admin', $auth->user());
    }

    public function testTheSessionIdentifierChanges(): void
    {
        $this->session->start('zfsid', []);
        $before = $this->session->id();

        $this->auth()->attempt('admin', self::PASSWORD);

        self::assertNotSame($before, $this->session->id());
    }

    public function testAWrongPasswordOrUserIsRefused(): void
    {
        self::assertFalse($this->auth()->attempt('admin', 'wrong'));
        self::assertFalse($this->auth()->attempt('someone', self::PASSWORD));
        self::assertFalse($this->auth()->isAuthenticated());
        self::assertNull($this->auth()->user());
    }

    public function testAnInstallationWithoutAPasswordCannotBeSignedInTo(): void
    {
        self::assertFalse($this->auth(['admin_password_hash' => ''])->attempt('admin', ''));
        self::assertFalse($this->auth(['admin_password_hash' => ''])->attempt('admin', self::PASSWORD));
    }

    public function testAnIdleSessionIsSignedOut(): void
    {
        $auth = $this->auth(['session_idle_seconds' => 600]);
        $auth->attempt('admin', self::PASSWORD);

        $this->now = $this->now->modify('+599 seconds');
        self::assertTrue($auth->isAuthenticated(), 'still inside the idle window');

        // Each check counts as activity, so the clock has to jump past the
        // whole window from the last one.
        $this->now = $this->now->modify('+601 seconds');
        self::assertFalse($auth->isAuthenticated());
        self::assertSame([], $this->session->all());
    }

    public function testActivityKeepsTheSessionAlive(): void
    {
        $auth = $this->auth(['session_idle_seconds' => 600]);
        $auth->attempt('admin', self::PASSWORD);

        for ($step = 0; $step < 5; ++$step) {
            $this->now = $this->now->modify('+500 seconds');
            self::assertTrue($auth->isAuthenticated());
        }
    }

    public function testASessionWithoutALastSeenStampIsNotTrusted(): void
    {
        $this->session->set(SessionAuth::USER_KEY, 'admin');

        self::assertFalse($this->auth()->isAuthenticated());
    }

    public function testSigningOutEmptiesTheSession(): void
    {
        $auth = $this->auth();
        $auth->attempt('admin', self::PASSWORD);

        $auth->logout();

        self::assertFalse($auth->isAuthenticated());
        self::assertSame([], $this->session->all());
    }

    public function testTheCookieIsSecureOverHttpsAndNotOverPlainHttp(): void
    {
        $config = Config::forTesting(['admin_password_hash' => (string) self::$hash, 'session_name' => 'zfsid']);

        $secure = new RecordingSession();
        (new SessionAuth($config, $secure, new PasswordHasher(), fn (): \DateTimeImmutable => $this->now))
            ->start(new ServerRequest('GET', 'https://zfeeder.test/admin'));

        self::assertSame('zfsid', $secure->startedWith['name']);
        self::assertTrue($secure->startedWith['secure']);
        self::assertTrue($secure->startedWith['httponly']);
        self::assertSame('Lax', $secure->startedWith['samesite']);
        self::assertSame('/', $secure->startedWith['path']);

        $plain = new RecordingSession();
        (new SessionAuth($config, $plain, new PasswordHasher(), fn (): \DateTimeImmutable => $this->now))
            ->start(new ServerRequest('GET', 'http://zfeeder.test/admin'));

        self::assertFalse($plain->startedWith['secure']);
    }

    public function testTheCookieFlagCanBeForcedEitherWay(): void
    {
        $always = new RecordingSession();
        $config = Config::forTesting([
            'admin_password_hash' => (string) self::$hash,
            'session_secure' => 'always',
        ]);
        (new SessionAuth($config, $always, new PasswordHasher(), fn (): \DateTimeImmutable => $this->now))
            ->start(new ServerRequest('GET', 'http://zfeeder.test/admin'));
        self::assertTrue($always->startedWith['secure']);

        $never = new RecordingSession();
        $config = Config::forTesting([
            'admin_password_hash' => (string) self::$hash,
            'session_secure' => 'never',
        ]);
        (new SessionAuth($config, $never, new PasswordHasher(), fn (): \DateTimeImmutable => $this->now))
            ->start(new ServerRequest('GET', 'https://zfeeder.test/admin'));
        self::assertFalse($never->startedWith['secure']);
    }

    public function testTheCookiePathFollowsTheInstallation(): void
    {
        $session = new RecordingSession();
        $config = Config::forTesting([
            'admin_password_hash' => (string) self::$hash,
            'base_url' => 'https://example.com/news/zfeeder/',
        ]);

        (new SessionAuth($config, $session, new PasswordHasher(), fn (): \DateTimeImmutable => $this->now))
            ->start(new ServerRequest('GET', 'https://example.com/news/zfeeder/admin'));

        self::assertSame('/news/zfeeder/', $session->startedWith['path']);
    }
}
