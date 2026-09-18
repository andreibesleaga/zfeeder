<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zfeeder\Admin\Auth\PasswordHasher;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    public function testAHashIsArgon2idAndSalted(): void
    {
        $hasher = new PasswordHasher();

        $first = $hasher->hash('a-password-worth-having');
        $second = $hasher->hash('a-password-worth-having');

        self::assertStringStartsWith('$argon2id$', $first);
        self::assertNotSame($first, $second, 'two hashes of one password must differ');
    }

    public function testTheRightPasswordVerifies(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('a-password-worth-having');

        self::assertTrue($hasher->verify('a-password-worth-having', $hash));
        self::assertFalse($hasher->verify('a-password-worth-having ', $hash));
        self::assertFalse($hasher->verify('', $hash));
    }

    public function testAnEmptyHashNeverVerifies(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->verify('anything', ''));
        self::assertFalse($hasher->verify('', ''));
        self::assertFalse($hasher->verify('anything', '   '));
    }

    public function testRubbishInThePlaceOfAHashNeverVerifies(): void
    {
        $hasher = new PasswordHasher();

        // 1.6 stored an MD5 digest; an installation that copied one across
        // must fail closed rather than compare it with anything.
        self::assertFalse($hasher->verify('secret', md5('secret')));
    }

    public function testAFreshHashDoesNotNeedRehashing(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->needsRehash($hasher->hash('a-password-worth-having')));
        self::assertTrue($hasher->needsRehash(''));
        self::assertTrue($hasher->needsRehash(md5('secret')));
    }
}
