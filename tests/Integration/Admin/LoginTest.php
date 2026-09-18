<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use Zfeeder\Admin\Auth\Csrf;
use Zfeeder\Admin\Auth\SessionAuth;

/**
 * Signing in: the happy path, the three ways it must fail, and the two
 * properties that are easy to lose in a refactor — the session identifier
 * changes, and the error message says nothing useful to a stranger.
 */
final class LoginTest extends AdminTestCase
{
    public function testTheFormIsShownToAnAnonymousVisitor(): void
    {
        $response = $this->send('GET', '/admin/login');

        self::assertSame(200, $response->getStatusCode());
        $body = self::bodyOf($response);
        self::assertStringContainsString('<label class="zf-label" for="admin_user">', $body);
        self::assertStringContainsString('name="' . Csrf::FIELD . '"', $body);
    }

    public function testCorrectCredentialsSignTheAdministratorIn(): void
    {
        $response = $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
        self::assertSame(self::USER, $this->session->get(SessionAuth::USER_KEY));
    }

    public function testTheSessionIdentifierChangesOnSignIn(): void
    {
        // Whatever identifier the visitor arrived with must not survive.
        $this->session->start('zfsid', []);
        $before = $this->session->id();
        self::assertNotSame('', $before);

        $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));

        self::assertNotSame($before, $this->session->id());
    }

    public function testAWrongPasswordIsRefusedWithoutSayingWhy(): void
    {
        $response = $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => 'not-the-password',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($this->session->get(SessionAuth::USER_KEY));

        $body = self::bodyOf($response);
        self::assertStringContainsString('do not match', $body);
        self::assertStringNotContainsString('unknown user', strtolower($body));
        self::assertStringNotContainsString('wrong password', strtolower($body));
    }

    public function testAnUnknownUserGetsExactlyTheSameAnswer(): void
    {
        $wrongUser = self::bodyOf($this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => 'nobody',
            'admin_pass' => self::PASSWORD,
        ])));

        self::assertStringContainsString('do not match', $wrongUser);
    }

    public function testAFormWithoutATokenIsRefused(): void
    {
        $response = $this->send('POST', '/admin/login', [
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->session->get(SessionAuth::USER_KEY));
    }

    public function testTooManyAttemptsAreThrottled(): void
    {
        $limit = $this->config->int('login_max_attempts');

        for ($attempt = 0; $attempt < $limit; ++$attempt) {
            $response = $this->send('POST', '/admin/login', $this->withToken([
                'admin_user' => self::USER,
                'admin_pass' => 'wrong',
            ]));
            self::assertSame(401, $response->getStatusCode(), 'attempt ' . $attempt);
        }

        $throttled = $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => 'wrong',
        ]));

        self::assertSame(429, $throttled->getStatusCode());
        self::assertGreaterThan(0, (int) $throttled->getHeaderLine('Retry-After'));
        self::assertStringContainsString('Too many sign-in attempts', self::bodyOf($throttled));
    }

    public function testThrottlingRefusesEvenTheCorrectPassword(): void
    {
        $limit = $this->config->int('login_max_attempts');
        for ($attempt = 0; $attempt < $limit; ++$attempt) {
            $this->send('POST', '/admin/login', $this->withToken([
                'admin_user' => self::USER,
                'admin_pass' => 'wrong',
            ]));
        }

        $response = $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));

        self::assertSame(429, $response->getStatusCode());
        self::assertNull($this->session->get(SessionAuth::USER_KEY));
    }

    public function testASignedInVisitorIsSentOnToThePanel(): void
    {
        $this->signIn();

        $response = $this->send('GET', '/admin/login');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
    }

    public function testSigningOutEndsTheSession(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/logout', $this->withToken([]));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
        self::assertSame([], $this->session->all());
    }

    public function testSigningOutNeedsAToken(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/logout', []);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(self::USER, $this->session->get(SessionAuth::USER_KEY));
    }

    public function testAnIdleSessionIsSignedOut(): void
    {
        $this->session->set(SessionAuth::USER_KEY, self::USER);
        $this->session->set(
            SessionAuth::SEEN_KEY,
            (new \DateTimeImmutable(self::NOW))->getTimestamp() - $this->config->int('session_idle_seconds') - 1,
        );

        $response = $this->send('GET', '/admin');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }
}
