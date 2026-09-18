<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Psr\Http\Message\ResponseInterface;

/**
 * S2 — after `login_max_attempts` failures inside `login_window_seconds`, the
 * sign-in form answers 429 with `Retry-After` and never reaches the password
 * hash. The window is a sliding one, a success forgets the failures, and the
 * counter is per identity.
 *
 * Closes 1.6 defect L2: `newsfeeds/admin.php:36-48` printed "wrong password"
 * and recorded nothing at all, so the 2004 panel could be guessed at for ever,
 * at whatever rate the network allowed.
 *
 * The control lives in `src/Admin/Auth/RateLimiter.php` (the sliding window,
 * one JSON file per hashed key) and in
 * `src/Admin/Controller/LoginController::submit()`, which checks it *before*
 * `SessionAuth::attempt()` so that an unthrottled form cannot be turned into a
 * CPU exhaustion amplifier by way of Argon2id.
 */
final class S02LoginRateLimitTest extends SecurityTestCase
{
    private const int LIMIT = 3;
    private const int WINDOW = 60;
    private const string OTHER_ADDRESS = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootKernel([
            'login_max_attempts' => self::LIMIT,
            'login_window_seconds' => self::WINDOW,
        ]);
    }

    private function failedLogin(string $address = self::ADDRESS): ResponseInterface
    {
        return $this->sendFrom('POST', '/admin/login', $address, $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => 'not-the-password',
        ]));
    }

    private function correctLogin(string $address = self::ADDRESS): ResponseInterface
    {
        return $this->sendFrom('POST', '/admin/login', $address, $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));
    }

    public function testTheAttemptAfterTheLimitIsRefusedWith429AndARetryAfterHeader(): void
    {
        for ($i = 1; $i <= self::LIMIT; ++$i) {
            self::assertSame(401, $this->failedLogin()->getStatusCode(), 'attempt ' . $i . ' should still be answered');
        }

        $throttled = $this->failedLogin();

        self::assertSame(429, $throttled->getStatusCode());
        self::assertTrue($throttled->hasHeader('Retry-After'), 'a 429 without Retry-After tells the client nothing');

        $retryAfter = (int) $throttled->getHeaderLine('Retry-After');
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(self::WINDOW, $retryAfter);
        self::assertStringContainsString('Too many sign-in attempts', self::bodyOf($throttled));
    }

    public function testTheThrottleRefusesEvenTheCorrectPassword(): void
    {
        for ($i = 1; $i <= self::LIMIT; ++$i) {
            $this->failedLogin();
        }

        $response = $this->correctLogin();

        // 303 would mean the guess limit can be walked past by getting it right
        // on the next try, which is exactly what an attacker is doing.
        self::assertSame(429, $response->getStatusCode());
        self::assertNull($this->session->get('_zf_admin_user'));
    }

    public function testWithoutTheLimitTheSameAttackJustContinues(): void
    {
        // The weakened build: the identical sequence against a configuration
        // where the limit is out of reach. If this returned 429 the test above
        // would be proving the harness rather than the control.
        $this->bootKernel(['login_max_attempts' => 100, 'login_window_seconds' => self::WINDOW]);

        for ($i = 1; $i <= self::LIMIT + 1; ++$i) {
            self::assertSame(401, $this->failedLogin()->getStatusCode(), 'attempt ' . $i);
        }
    }

    public function testTheWindowExpiresAndAttemptsAreAllowedAgain(): void
    {
        for ($i = 1; $i <= self::LIMIT; ++$i) {
            $this->failedLogin();
        }
        self::assertSame(429, $this->failedLogin()->getStatusCode());

        // One second inside the window: still refused.
        $this->clock->advance(self::WINDOW - 1);
        self::assertSame(429, $this->failedLogin()->getStatusCode());

        // Past it: the remembered attempts have left the window.
        $this->clock->advance(2);
        self::assertSame(401, $this->failedLogin()->getStatusCode());
        self::assertSame(303, $this->correctLogin()->getStatusCode());
    }

    public function testASuccessfulSignInClearsTheCounter(): void
    {
        $this->failedLogin();
        $this->failedLogin();

        self::assertSame(303, $this->correctLogin()->getStatusCode());

        // Without the clear, the third failure below would be the fifth attempt
        // in the window and would answer 429.
        for ($i = 1; $i <= self::LIMIT; ++$i) {
            self::assertSame(401, $this->failedLogin()->getStatusCode(), 'attempt ' . $i . ' after a success');
        }
        self::assertSame(429, $this->failedLogin()->getStatusCode());
    }

    public function testTheLimitIsPerIdentitySoASecondAddressIsUnaffected(): void
    {
        for ($i = 1; $i <= self::LIMIT; ++$i) {
            $this->failedLogin(self::ADDRESS);
        }
        self::assertSame(429, $this->failedLogin(self::ADDRESS)->getStatusCode());

        // An attacker hammering the form from one address must not lock the
        // administrator out of their own panel from another.
        self::assertSame(401, $this->failedLogin(self::OTHER_ADDRESS)->getStatusCode());
        self::assertSame(303, $this->correctLogin(self::OTHER_ADDRESS)->getStatusCode());
    }

    public function testTheLimitIsAlsoPerUserNameSoOneNameCannotLockOutAnother(): void
    {
        for ($i = 1; $i <= self::LIMIT; ++$i) {
            $this->sendFrom('POST', '/admin/login', self::ADDRESS, $this->withToken([
                'admin_user' => 'someone-else',
                'admin_pass' => 'guess',
            ]));
        }

        $response = $this->sendFrom('POST', '/admin/login', self::ADDRESS, $this->withToken([
            'admin_user' => 'someone-else',
            'admin_pass' => 'guess',
        ]));
        self::assertSame(429, $response->getStatusCode());

        self::assertSame(303, $this->correctLogin()->getStatusCode());
    }

    public function testTheCounterIsStoredUnderAHashedKeySoTheAddressNeverBecomesAFileName(): void
    {
        $this->failedLogin();

        $directory = $this->config->dataDir() . '/ratelimit';
        self::assertDirectoryExists($directory);

        $entries = array_values(array_diff((array) scandir($directory), ['.', '..']));
        self::assertNotSame([], $entries);

        foreach ($entries as $entry) {
            self::assertIsString($entry);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}\.json$/', $entry, 'the key leaked into the file name');
            self::assertStringNotContainsString(self::ADDRESS, $entry);
            self::assertStringNotContainsString(self::USER, $entry);
        }
    }
}
