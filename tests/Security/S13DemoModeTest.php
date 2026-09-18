<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Admin\AdminRoutes;
use Zfeeder\Admin\Middleware\DemoMode;
use Zfeeder\Http\Router;
use Zfeeder\Subscription\Feed;

/**
 * S13 — with `demo_mode` on, every unsafe method is refused with 403 and the
 * data directory is provably untouched, while every screen is still readable.
 *
 * This is a 2.0 surface, added so the public demonstration can be opened to
 * anyone: 1.6 had no such mode, and no way to show the panel without giving it
 * away.
 *
 * `tests/Integration/Admin/DemoModeTest.php` covers the six write paths it
 * lists by hand and the flash wording. This class is the exhaustive half: the
 * route list comes from the router, the two exemptions are asserted to be
 * exactly sign-in and sign-out, and "nothing changed" is a fingerprint of the
 * whole data directory rather than a count of one category.
 *
 * The control lives in `src/Admin/Middleware/DemoMode.php`, which refuses by
 * method rather than by a list of screens, so a screen added later is refused
 * by default.
 */
final class S13DemoModeTest extends SecurityTestCase
{
    /** @return iterable<string, array{string}> every POST path the router knows */
    public static function postPaths(): iterable
    {
        foreach (AdminRoutes::register(new Router())->routes() as $route) {
            if (in_array('POST', $route->methods, true)) {
                yield $route->pattern => [$route->pattern];
            }
        }
    }

    /** @return iterable<string, array{string}> every GET path that is a screen */
    public static function readPaths(): iterable
    {
        yield 'main' => ['/admin'];
        yield 'login' => ['/admin/login'];
        yield 'add new' => ['/admin/add-new'];
        yield 'subscriptions' => ['/admin/subscriptions'];
        yield 'config' => ['/admin/config'];
        yield 'import' => ['/admin/import'];
        yield 'updates' => ['/admin/updates'];
        yield 'export' => ['/admin/export/zfeeder'];
        yield 'subscriptions partial' => ['/admin/_/subscriptions'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootKernel(['demo_mode' => true]);
        $this->seedCategory();
        $this->withFeeds([
            new Feed('https://one.example/feed.xml', 'One', '', '', 1, 60, 3, true),
            new Feed('https://two.example/feed.xml', 'Two', '', '', 2, 60, 3, true),
        ]);
    }

    #[DataProvider('postPaths')]
    public function testEveryWriteIsRefusedAndTheDataDirectoryIsUntouched(string $path): void
    {
        $this->signIn();
        $before = $this->dataDirectoryFingerprint();

        // A valid token, a signed-in administrator, and a body that really
        // would change something: the only thing stopping it is demo mode.
        $response = $this->send('POST', $path, $this->withToken([
            'action' => 'delete',
            'category' => self::CATEGORY,
            'select' => ['0' => '1'],
            'xml_url' => ['0' => 'https://one.example/feed.xml'],
            'mode' => 'replace',
            'site_url' => 'https://example.com/',
            'max_description_chars' => '1',
        ]));

        if (in_array(self::handlerFor($path), AdminRoutes::DEMO_EXEMPT_HANDLERS, true)) {
            // Signing in and out are exempt on purpose. They still change no
            // subscription; what they may touch is the login counter and the
            // log, which is why the fingerprint is not asserted for them.
            self::assertNotSame(403, $response->getStatusCode(), $path . ' is exempt but was refused');
        } else {
            self::assertSame(403, $response->getStatusCode(), $path . ' was allowed to write');
            self::assertStringContainsString('read-only', self::bodyOf($response));
            self::assertSame($before, $this->dataDirectoryFingerprint(), $path . ' changed the data directory');
        }

        self::assertCount(2, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    #[DataProvider('postPaths')]
    public function testTheSameWriteSucceedsWhenDemoModeIsOff(string $path): void
    {
        // The weakened build: without demo mode these requests are accepted, so
        // the refusals above are the middleware and not a broken route.
        $this->bootKernel(['demo_mode' => false]);
        $this->seedCategory();
        $this->signIn();

        $response = $this->send('POST', $path, $this->withToken(['action' => 'save', 'category' => self::CATEGORY]));

        self::assertNotSame(403, $response->getStatusCode(), $path . ' was refused with demo mode off');
    }

    #[DataProvider('readPaths')]
    public function testEveryScreenIsStillReadable(string $path): void
    {
        $this->signIn();

        $response = $this->send('GET', $path);

        self::assertLessThan(400, $response->getStatusCode(), $path . ' is not readable in demo mode');
    }

    public function testOtherUnsafeMethodsAreRefusedToo(): void
    {
        // The rule is "no unsafe method", not "no POST": PUT, PATCH and DELETE
        // must be refused as well, whether or not a route answers them today.
        $this->signIn();

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $middleware = new DemoMode(true, AdminRoutes::SUBSCRIPTIONS_SUBMIT, AdminRoutes::DEMO_EXEMPT_HANDLERS);
            $response = $middleware->process(
                $this->requestFrom($method, '/admin/subscriptions', self::ADDRESS),
                new \Zfeeder\Admin\Middleware\Pipeline([], static fn (): \Psr\Http\Message\ResponseInterface => \Zfeeder\Http\Responder::text('written')),
            );

            self::assertSame(403, $response->getStatusCode(), $method . ' was allowed through');
            self::assertStringNotContainsString('written', self::bodyOf($response));
        }
    }

    public function testSafeMethodsAreTheOnlyOnesLetThrough(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $middleware = new DemoMode(true, AdminRoutes::SUBSCRIPTIONS, AdminRoutes::DEMO_EXEMPT_HANDLERS);
            $response = $middleware->process(
                $this->requestFrom($method, '/admin/subscriptions', self::ADDRESS),
                new \Zfeeder\Admin\Middleware\Pipeline([], static fn (): \Psr\Http\Message\ResponseInterface => \Zfeeder\Http\Responder::text('read')),
            );

            self::assertSame(200, $response->getStatusCode(), $method . ' was refused');
        }
    }

    public function testTheExemptionsAreExactlySignInAndSignOut(): void
    {
        // Any other exemption would be a hole in the demonstration; this test
        // is what makes adding one a deliberate act.
        self::assertSame(
            [AdminRoutes::LOGIN_SUBMIT, AdminRoutes::LOGOUT],
            AdminRoutes::DEMO_EXEMPT_HANDLERS,
        );
    }

    public function testTheVisitorCanStillSignInAndOutWithoutChangingAnything(): void
    {
        $before = $this->dataDirectoryFingerprint();

        $signIn = $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));
        self::assertSame(303, $signIn->getStatusCode());

        $signOut = $this->send('POST', '/admin/logout', $this->withToken([]));
        self::assertSame(303, $signOut->getStatusCode());

        self::assertSame($before, $this->dataDirectoryFingerprint(), 'signing in and out changed the data directory');
    }

    public function testAnHtmxWriteIsRefusedWithAPlainSentenceRatherThanAPage(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/_/subscriptions', $this->withToken(['action' => 'delete']), ['HX-Request' => 'true']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('<!DOCTYPE', self::bodyOf($response));
    }

    private static function handlerFor(string $pattern): string
    {
        foreach (AdminRoutes::register(new Router())->routes() as $route) {
            if ($route->pattern === $pattern && in_array('POST', $route->methods, true)) {
                return $route->handler;
            }
        }

        return '';
    }
}
