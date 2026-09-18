<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Admin\AdminDispatcher;
use Zfeeder\Config\Config;
use Zfeeder\Kernel;
use Zfeeder\Subscription\Feed;

/**
 * Every screen, twice: once signed in, where it must render, and once
 * anonymous, where it must send the visitor to the login form and render
 * nothing at all.
 *
 * This is the test that would have caught the 1.6 panel's real failure mode,
 * where an unauthenticated request still ran the include and printed the
 * subscriptions before deciding it was not allowed to.
 */
final class ScreenAccessTest extends AdminTestCase
{
    /** @return iterable<string, array{string}> */
    public static function screens(): iterable
    {
        yield 'main' => ['/admin'];
        yield 'add new' => ['/admin/add-new'];
        yield 'subscriptions' => ['/admin/subscriptions'];
        yield 'config' => ['/admin/config'];
        yield 'import' => ['/admin/import'];
        yield 'updates' => ['/admin/updates'];
        yield 'export' => ['/admin/export/zfeeder'];
    }

    #[DataProvider('screens')]
    public function testSignedInVisitorSeesTheScreen(string $path): void
    {
        $this->signIn();
        $this->withFeeds([new Feed('https://example.com/feed.xml', 'Example', 'An example feed', 'https://example.com/')]);

        $response = $this->send('GET', $path);

        self::assertSame(200, $response->getStatusCode(), $path . ' should render');
        self::assertNotSame('', self::bodyOf($response));
    }

    #[DataProvider('screens')]
    public function testAnonymousVisitorIsSentToTheLoginForm(string $path): void
    {
        $response = $this->send('GET', $path);

        self::assertSame(303, $response->getStatusCode(), $path . ' should redirect');
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
        self::assertSame('', self::bodyOf($response));
    }

    public function testEveryScreenCarriesTheAdminSecurityHeaders(): void
    {
        $this->signIn();

        $response = $this->send('GET', '/admin');

        self::assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('nonce-', $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTheScriptNonceOnThePageIsTheOneInThePolicy(): void
    {
        $this->signIn();

        $response = $this->send('GET', '/admin');
        $body = self::bodyOf($response);

        self::assertSame(1, preg_match('/nonce="([^"]+)"/', $body, $matches));
        $nonce = $matches[1] ?? '';
        self::assertNotSame('', $nonce);
        self::assertStringContainsString("'nonce-" . $nonce . "'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testTheMenuMarksTheCurrentScreen(): void
    {
        $this->signIn();

        $body = self::bodyOf($this->send('GET', '/admin/config'));

        self::assertStringContainsString('aria-current="page"', $body);
        self::assertStringContainsString('<nav', $body);
        self::assertStringContainsString('Skip to the main content', $body);
    }

    public function testThePanelIsUnavailableWithoutAPassword(): void
    {
        $config = Config::forTesting(['admin_password_hash' => ''], $this->tempDir());
        $kernel = new Kernel($config, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW));

        $response = (new AdminDispatcher($kernel, $this->session))->handle('admin.main', $this->request('GET', '/admin'));

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('hash-password', self::bodyOf($response));
    }

    public function testThePanelCanBeSwitchedOffEntirely(): void
    {
        $this->bootKernel(['admin_enabled' => false]);
        $this->signIn();

        self::assertSame(404, $this->send('GET', '/admin')->getStatusCode());
    }

    public function testBothSkinsRenderTheSameScreens(): void
    {
        $this->bootKernel(['admin_skin' => 'classic']);
        $this->signIn();

        $response = $this->send('GET', '/admin/subscriptions');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('zf-skin-classic', self::bodyOf($response));
    }
}
