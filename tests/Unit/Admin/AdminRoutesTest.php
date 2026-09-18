<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Admin;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Admin\AdminRoutes;
use Zfeeder\Http\Router;

#[CoversClass(AdminRoutes::class)]
final class AdminRoutesTest extends TestCase
{
    private function router(): Router
    {
        return AdminRoutes::register(new Router());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function routes(): iterable
    {
        yield 'main' => ['GET', '/admin', AdminRoutes::MAIN];
        yield 'login form' => ['GET', '/admin/login', AdminRoutes::LOGIN];
        yield 'login submit' => ['POST', '/admin/login', AdminRoutes::LOGIN_SUBMIT];
        yield 'logout' => ['POST', '/admin/logout', AdminRoutes::LOGOUT];
        yield 'add new' => ['GET', '/admin/add-new', AdminRoutes::ADD_FEED];
        yield 'add new submit' => ['POST', '/admin/add-new', AdminRoutes::ADD_FEED_SUBMIT];
        yield 'subscriptions' => ['GET', '/admin/subscriptions', AdminRoutes::SUBSCRIPTIONS];
        yield 'subscriptions submit' => ['POST', '/admin/subscriptions', AdminRoutes::SUBSCRIPTIONS_SUBMIT];
        yield 'config' => ['GET', '/admin/config', AdminRoutes::CONFIG];
        yield 'config submit' => ['POST', '/admin/config', AdminRoutes::CONFIG_SUBMIT];
        yield 'import' => ['GET', '/admin/import', AdminRoutes::IMPORT];
        yield 'import submit' => ['POST', '/admin/import', AdminRoutes::IMPORT_SUBMIT];
        yield 'export' => ['GET', '/admin/export/zfeeder', AdminRoutes::EXPORT];
        yield 'updates' => ['GET', '/admin/updates', AdminRoutes::UPDATES];
        yield 'discover fragment' => ['POST', '/admin/_/discover', AdminRoutes::PARTIAL_DISCOVER];
        yield 'table fragment' => ['GET', '/admin/_/subscriptions', AdminRoutes::PARTIAL_SUBSCRIPTIONS];
        yield 'table fragment submit' => ['POST', '/admin/_/subscriptions', AdminRoutes::PARTIAL_SUBSCRIPTIONS_SUBMIT];
    }

    #[DataProvider('routes')]
    public function testEachPathReachesItsHandler(string $method, string $path, string $handler): void
    {
        $match = $this->router()->dispatch(new ServerRequest($method, 'http://zfeeder.test' . $path));

        self::assertIsArray($match);
        self::assertSame($handler, $match['handler']);
    }

    public function testTheCategoryIsCapturedFromTheExportPath(): void
    {
        $match = $this->router()->dispatch(new ServerRequest('GET', 'http://zfeeder.test/admin/export/my-feeds'));

        self::assertIsArray($match);
        self::assertSame(['category' => 'my-feeds'], $match['params']);
    }

    public function testEveryRegisteredHandlerIsListed(): void
    {
        foreach ($this->router()->routes() as $route) {
            self::assertContains($route->handler, AdminRoutes::handlers(), $route->pattern);
        }
    }

    public function testNoReadRouteIsAlsoAWriteRoute(): void
    {
        // A GET must never be able to change anything, which in 1.6 it could.
        foreach ($this->router()->routes() as $route) {
            if (in_array('GET', $route->methods, true)) {
                self::assertNotContains('POST', $route->methods, $route->pattern . ' answers both GET and POST');
            }
        }
    }

    public function testOnlyTheLoginScreensAreReachableAnonymously(): void
    {
        self::assertSame(
            [AdminRoutes::LOGIN, AdminRoutes::LOGIN_SUBMIT],
            AdminRoutes::PUBLIC_HANDLERS,
        );
    }
}
