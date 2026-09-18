<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zfeeder\Admin\Auth\RateLimiter;
use Zfeeder\Tests\Support\StorageTempDirectory;

/**
 * The limiter against a clock the test moves by hand: no sleeping, and the
 * window boundary is asserted exactly rather than approximately.
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    use StorageTempDirectory;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-18T12:00:00+00:00');
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function limiter(int $limit = 3, int $window = 900): RateLimiter
    {
        return new RateLimiter($this->tempPath('ratelimit'), $limit, $window, fn (): \DateTimeImmutable => $this->now);
    }

    public function testAFreshKeyIsAllowed(): void
    {
        self::assertTrue($this->limiter()->check('1.2.3.4|admin'));
        self::assertSame(0, $this->limiter()->retryAfter('1.2.3.4|admin'));
    }

    public function testTheLimitIsReachedAfterTheGivenNumberOfAttempts(): void
    {
        $limiter = $this->limiter(3);

        $limiter->record('key');
        self::assertTrue($limiter->check('key'));
        $limiter->record('key');
        self::assertTrue($limiter->check('key'));
        $limiter->record('key');

        self::assertFalse($limiter->check('key'));
        self::assertSame(900, $limiter->retryAfter('key'));
    }

    public function testAttemptsLeaveTheWindow(): void
    {
        $limiter = $this->limiter(2, 60);
        $limiter->record('key');
        $limiter->record('key');
        self::assertFalse($limiter->check('key'));

        $this->now = $this->now->modify('+30 seconds');
        self::assertFalse($limiter->check('key'), 'still inside the window');
        self::assertSame(30, $limiter->retryAfter('key'));

        $this->now = $this->now->modify('+31 seconds');
        self::assertTrue($limiter->check('key'), 'the window has passed');
    }

    public function testTwoKeysAreCountedSeparately(): void
    {
        $limiter = $this->limiter(1);
        $limiter->record('1.2.3.4|admin');

        self::assertFalse($limiter->check('1.2.3.4|admin'));
        self::assertTrue($limiter->check('5.6.7.8|admin'));
        self::assertTrue($limiter->check('1.2.3.4|someone-else'));
    }

    public function testClearingForgetsTheKey(): void
    {
        $limiter = $this->limiter(1);
        $limiter->record('key');
        self::assertFalse($limiter->check('key'));

        $limiter->clear('key');

        self::assertTrue($limiter->check('key'));
        self::assertSame(0, $limiter->retryAfter('key'));
    }

    public function testTheKeyNeverBecomesAFileName(): void
    {
        $limiter = $this->limiter();
        $limiter->record('../../etc/passwd|admin');

        $files = glob($this->tempPath('ratelimit') . '/*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('#/[0-9a-f]{64}\.json$#', $files[0]);
    }

    public function testACorruptedCounterDoesNotLockAnybodyOut(): void
    {
        $limiter = $this->limiter(1);
        $limiter->record('key');
        $files = glob($this->tempPath('ratelimit') . '/*.json');
        self::assertIsArray($files);
        file_put_contents($files[0], 'not json at all');

        self::assertTrue($limiter->check('key'));
    }

    public function testTheStateSurvivesANewInstance(): void
    {
        $this->limiter(1)->record('key');

        self::assertFalse($this->limiter(1)->check('key'));
    }
}
