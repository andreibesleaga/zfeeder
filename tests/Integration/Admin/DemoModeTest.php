<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Subscription\Feed;

/**
 * The public demonstration: every screen readable, every write refused with a
 * sentence that explains itself, and the subscription list provably unchanged
 * afterwards.
 */
final class DemoModeTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootKernel(['demo_mode' => true]);
        $this->seedCategory();
    }

    /** @return iterable<string, array{string}> */
    public static function writePaths(): iterable
    {
        yield 'add new' => ['/admin/add-new'];
        yield 'subscriptions' => ['/admin/subscriptions'];
        yield 'config' => ['/admin/config'];
        yield 'import' => ['/admin/import'];
        yield 'discover partial' => ['/admin/_/discover'];
        yield 'subscriptions partial' => ['/admin/_/subscriptions'];
    }

    #[DataProvider('writePaths')]
    public function testEveryWriteIsRefused(string $path): void
    {
        $this->signIn();

        $response = $this->send('POST', $path, $this->withToken(['action' => 'save']));

        self::assertSame(403, $response->getStatusCode(), $path . ' was allowed to write');
        self::assertStringContainsString('read-only', self::bodyOf($response));
    }

    public function testTheSubscriptionListIsUntouched(): void
    {
        $this->signIn();
        $this->withFeeds([new Feed('https://example.com/feed.xml', 'Example', '', '', 1, 60, 3)]);

        $this->send('POST', '/admin/subscriptions', $this->withToken([
            'action' => 'delete',
            'category' => self::CATEGORY,
            'select' => ['0' => '1'],
            'xml_url' => ['0' => 'https://example.com/feed.xml'],
        ]));

        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testScreensAreStillReadable(): void
    {
        $this->signIn();

        foreach (['/admin', '/admin/subscriptions', '/admin/config', '/admin/import', '/admin/updates'] as $path) {
            self::assertSame(200, $this->send('GET', $path)->getStatusCode(), $path);
        }
    }

    public function testTheVisitorCanStillSignInAndOut(): void
    {
        $signIn = $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));
        self::assertSame(303, $signIn->getStatusCode());

        $signOut = $this->send('POST', '/admin/logout', $this->withToken([]));
        self::assertSame(303, $signOut->getStatusCode());
    }

    public function testTheMainScreenSaysSo(): void
    {
        $this->signIn();

        self::assertStringContainsString('demonstration mode', self::bodyOf($this->send('GET', '/admin')));
    }
}
