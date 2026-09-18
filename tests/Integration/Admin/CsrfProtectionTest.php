<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Admin\Auth\Csrf;
use Zfeeder\Subscription\Feed;

/**
 * No POST route accepts a request without a matching token, and a route added
 * later without one will fail here rather than quietly become a hole: the list
 * below is asserted to cover every POST route the router knows.
 */
final class CsrfProtectionTest extends AdminTestCase
{
    /** @return iterable<string, array{string}> */
    public static function postPaths(): iterable
    {
        yield 'login' => ['/admin/login'];
        yield 'logout' => ['/admin/logout'];
        yield 'add new' => ['/admin/add-new'];
        yield 'subscriptions' => ['/admin/subscriptions'];
        yield 'config' => ['/admin/config'];
        yield 'import' => ['/admin/import'];
        yield 'discover partial' => ['/admin/_/discover'];
        yield 'subscriptions partial' => ['/admin/_/subscriptions'];
    }

    #[DataProvider('postPaths')]
    public function testAMissingTokenIsRefused(string $path): void
    {
        $this->signIn();
        $this->withFeeds([new Feed('https://example.com/feed.xml', 'Example')]);

        $response = $this->send('POST', $path, ['action' => 'save']);

        self::assertSame(403, $response->getStatusCode(), $path . ' accepted a request with no token');
    }

    #[DataProvider('postPaths')]
    public function testAWrongTokenIsRefused(string $path): void
    {
        $this->signIn();
        $this->token();

        $response = $this->send('POST', $path, [Csrf::FIELD => 'not-the-token', 'action' => 'save']);

        self::assertSame(403, $response->getStatusCode(), $path . ' accepted a forged token');
    }

    public function testTheListAboveCoversEveryPostRoute(): void
    {
        $covered = [];
        foreach (self::postPaths() as $case) {
            $covered[] = $case[0];
        }

        $registered = [];
        foreach ($this->router->routes() as $route) {
            if (in_array('POST', $route->methods, true)) {
                $registered[] = $route->pattern;
            }
        }

        sort($covered);
        sort($registered);
        self::assertSame($registered, $covered);
    }

    public function testAnEmptySessionHasNoTokenToMatch(): void
    {
        $this->signIn();

        // A token from another session is not accepted here.
        $foreign = (new Csrf(new \Zfeeder\Admin\Auth\ArraySession()))->token();

        $response = $this->send('POST', '/admin/config', [Csrf::FIELD => $foreign]);

        self::assertSame(403, $response->getStatusCode());
    }
}
