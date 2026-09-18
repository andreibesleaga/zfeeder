<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Admin\Controller\UpdatesController;

/**
 * The release document is remote input that ends up on an admin page, so the
 * only thing ever taken out of it is a string shaped like a version.
 */
#[CoversClass(UpdatesController::class)]
final class UpdateCheckTest extends TestCase
{
    public function testAReleaseTagIsRead(): void
    {
        self::assertSame('2.1.0', UpdatesController::latestTag('{"tag_name":"v2.1.0"}'));
        self::assertSame('2.1.0', UpdatesController::latestTag('{"tag_name":"2.1.0"}'));
        self::assertSame('2.1.0-rc.1', UpdatesController::latestTag('{"tag_name":"v2.1.0-rc.1"}'));
    }

    /** @return iterable<string, array{string}> */
    public static function rubbish(): iterable
    {
        yield 'not json' => ['<html>nope</html>'];
        yield 'no tag' => ['{"name":"latest"}'];
        yield 'not a string' => ['{"tag_name":42}'];
        yield 'markup in the tag' => ['{"tag_name":"<script>alert(1)</script>"}'];
        yield 'a whole sentence' => ['{"tag_name":"version two point one"}'];
        yield 'empty' => [''];
    }

    #[DataProvider('rubbish')]
    public function testAnythingElseIsIgnored(string $json): void
    {
        self::assertNull(UpdatesController::latestTag($json));
    }

    public function testVersionsAreComparedNumerically(): void
    {
        self::assertTrue(UpdatesController::isNewer('2.1.0', '2.0.0'));
        self::assertTrue(UpdatesController::isNewer('2.0.1', '2.0.0'));
        self::assertTrue(UpdatesController::isNewer('10.0.0', '9.0.0'));
        self::assertFalse(UpdatesController::isNewer('2.0.0', '2.0.0'));
        self::assertFalse(UpdatesController::isNewer('1.6', '2.0.0'));
        self::assertFalse(UpdatesController::isNewer('2.0.0-rc.1', '2.0.0'));
    }
}
