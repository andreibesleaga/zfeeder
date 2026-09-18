<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zfeeder\Admin\Auth\ArraySession;
use Zfeeder\Admin\Auth\Csrf;

#[CoversClass(Csrf::class)]
final class CsrfTest extends TestCase
{
    public function testTheTokenIsStableWithinASession(): void
    {
        $csrf = new Csrf(new ArraySession());

        self::assertSame($csrf->token(), $csrf->token());
    }

    public function testTheTokenIsLongAndUrlSafe(): void
    {
        $token = (new Csrf(new ArraySession()))->token();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    public function testTwoSessionsGetDifferentTokens(): void
    {
        self::assertNotSame(
            (new Csrf(new ArraySession()))->token(),
            (new Csrf(new ArraySession()))->token(),
        );
    }

    public function testTheMatchingTokenValidates(): void
    {
        $csrf = new Csrf(new ArraySession());

        self::assertTrue($csrf->validate($csrf->token()));
    }

    public function testAMissingOrWrongTokenIsARefusal(): void
    {
        $csrf = new Csrf(new ArraySession());
        $token = $csrf->token();

        self::assertFalse($csrf->validate(null));
        self::assertFalse($csrf->validate(''));
        self::assertFalse($csrf->validate('   '));
        self::assertFalse($csrf->validate(substr($token, 0, -1)));
        self::assertFalse($csrf->validate($token . 'x'));
    }

    public function testASessionWithoutATokenValidatesNothing(): void
    {
        $session = new ArraySession();
        $issued = (new Csrf($session))->token();

        $empty = new Csrf(new ArraySession([]));
        $empty->validate($issued);

        // A token from elsewhere is not accepted merely because it is a token.
        self::assertNotSame($issued, $empty->token());
        self::assertFalse((new Csrf(new ArraySession()))->validate($issued));
    }

    public function testRotatingIssuesANewToken(): void
    {
        $csrf = new Csrf(new ArraySession());
        $first = $csrf->token();

        $second = $csrf->rotate();

        self::assertNotSame($first, $second);
        self::assertFalse($csrf->validate($first));
        self::assertTrue($csrf->validate($second));
    }
}
