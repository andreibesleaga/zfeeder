<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Admin\AdminRoutes;
use Zfeeder\Admin\Auth\ArraySession;
use Zfeeder\Admin\Auth\Csrf;
use Zfeeder\Http\Router;
use Zfeeder\Subscription\Feed;

/**
 * S4 — every mutating route refuses a request whose token is missing, empty or
 * from another session, and changes nothing while refusing it. A valid token is
 * accepted, the comparison is `hash_equals()`, and no GET route mutates.
 *
 * Closes 1.6 defect L5: there was no token of any kind, and
 * `newsfeeds/admin.php:112-115` dispatched on `$_GET['zfaction']`, so
 * `<img src="…/admin.php?zfaction=delete&…">` in any page a signed-in
 * administrator opened was a working attack.
 *
 * The control lives in `src/Admin/Auth/Csrf.php` (one token per session,
 * `hash_equals`, a missing token treated as a wrong one),
 * `src/Admin/Controller/AbstractController::csrfValid()`, which every submit
 * handler calls first, and the read/write route split in
 * `src/Admin/AdminRoutes.php`.
 *
 * The route list is taken from the router, not written down here, so a route
 * added later is covered without anyone remembering to add it.
 */
final class S04CsrfTest extends SecurityTestCase
{
    /** @return iterable<string, array{string}> every path the panel answers a POST on */
    public static function mutatingPaths(): iterable
    {
        foreach (AdminRoutes::register(new Router())->routes() as $route) {
            if (in_array('POST', $route->methods, true)) {
                yield $route->pattern => [$route->pattern];
            }
        }
    }

    /**
     * A body that would really change something on the path it is sent to.
     *
     * @return array<string, mixed>
     */
    private static function destructiveBody(string $path): array
    {
        return match ($path) {
            '/admin/login' => ['admin_user' => self::USER, 'admin_pass' => self::PASSWORD],
            '/admin/add-new' => [
                'action' => 'subscribe',
                'category' => self::CATEGORY,
                'feed_url' => 'https://attacker.example/feed.xml',
                'title' => 'Injected',
            ],
            '/admin/subscriptions', '/admin/_/subscriptions' => [
                'action' => 'delete',
                'category' => self::CATEGORY,
                'select' => ['0' => '1'],
                'xml_url' => ['0' => 'https://example.com/feed.xml'],
            ],
            '/admin/config' => ['action' => 'save', 'max_description_chars' => '1', 'powered_by' => '0'],
            '/admin/import' => ['action' => 'import', 'category' => self::CATEGORY, 'mode' => 'replace'],
            '/admin/_/discover' => ['site_url' => 'https://attacker.example/'],
            default => ['action' => 'save'],
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        $this->withFeeds([new Feed('https://example.com/feed.xml', 'Example', '', '', 1, 60, 3, true)]);
    }

    #[DataProvider('mutatingPaths')]
    public function testAMissingTokenIsRefusedAndNothingChanges(string $path): void
    {
        $before = $this->dataDirectoryFingerprint();

        $response = $this->send('POST', $path, self::destructiveBody($path));

        self::assertSame(403, $response->getStatusCode(), $path . ' accepted a request with no token');
        self::assertSame($before, $this->dataDirectoryFingerprint(), $path . ' changed the data directory anyway');
    }

    #[DataProvider('mutatingPaths')]
    public function testAnEmptyTokenIsRefusedAndNothingChanges(string $path): void
    {
        // An empty field must fail exactly like a wrong one; treating "" as
        // "nothing to check" is how this protection is usually lost.
        $this->token();
        $before = $this->dataDirectoryFingerprint();

        $response = $this->send('POST', $path, [Csrf::FIELD => ''] + self::destructiveBody($path));

        self::assertSame(403, $response->getStatusCode(), $path . ' accepted an empty token');
        self::assertSame($before, $this->dataDirectoryFingerprint(), $path . ' changed the data directory anyway');
    }

    #[DataProvider('mutatingPaths')]
    public function testATokenFromAnotherSessionIsRefusedAndNothingChanges(string $path): void
    {
        // A well-formed token that simply belongs to somebody else: this is the
        // attack the token exists for.
        $foreign = (new Csrf(new ArraySession()))->token();
        $this->token();
        $before = $this->dataDirectoryFingerprint();

        $response = $this->send('POST', $path, [Csrf::FIELD => $foreign] + self::destructiveBody($path));

        self::assertSame(403, $response->getStatusCode(), $path . ' accepted another session\'s token');
        self::assertSame($before, $this->dataDirectoryFingerprint(), $path . ' changed the data directory anyway');
    }

    #[DataProvider('mutatingPaths')]
    public function testAValidTokenIsAccepted(string $path): void
    {
        // The other half: the refusals above are the token check, not a route
        // that refuses everything.
        $response = $this->send('POST', $path, $this->withToken(self::destructiveBody($path)));

        self::assertNotSame(403, $response->getStatusCode(), $path . ' refused a valid token');
    }

    #[DataProvider('mutatingPaths')]
    public function testATokenThatDiffersInOneCharacterIsRefused(string $path): void
    {
        $token = $this->token();
        $nearMiss = substr($token, 0, -1) . ($token[strlen($token) - 1] === 'a' ? 'b' : 'a');
        self::assertSame(strlen($token), strlen($nearMiss));

        $response = $this->send('POST', $path, [Csrf::FIELD => $nearMiss] + self::destructiveBody($path));

        self::assertSame(403, $response->getStatusCode(), $path . ' accepted a near miss');
    }

    public function testAPrefixOfTheRealTokenIsRefused(): void
    {
        // `hash_equals()` compares the length first, so a truncated token can
        // never be accepted and cannot be used to measure the comparison.
        $token = $this->token();

        foreach ([substr($token, 0, 1), substr($token, 0, 20), $token . 'x'] as $attempt) {
            $response = $this->send('POST', '/admin/config', [Csrf::FIELD => $attempt, 'action' => 'save']);
            self::assertSame(403, $response->getStatusCode(), 'accepted "' . $attempt . '"');
        }
    }

    public function testTheComparisonIsHashEqualsAndNotAStringComparison(): void
    {
        // Behaviour cannot tell `===` from `hash_equals()` apart from timing,
        // which is not something a test suite should try to measure. The
        // constant-time call is therefore asserted where it lives.
        $source = self::readFile(self::projectRoot() . '/src/Admin/Auth/Csrf.php');

        self::assertStringContainsString('hash_equals(', $source);
        self::assertDoesNotMatchRegularExpression(
            '/return\s+\$expected\s*===\s*\$submitted/',
            $source,
            'the token comparison stopped being constant time',
        );

        $csrf = new Csrf($this->session);
        $token = $csrf->token();
        self::assertTrue($csrf->validate($token));
        self::assertFalse($csrf->validate(null));
        self::assertFalse($csrf->validate(''));
    }

    public function testASessionWithNoTokenAcceptsNothingAtAll(): void
    {
        // Otherwise "no expected token" would match "no submitted token".
        $csrf = new Csrf(new ArraySession());

        self::assertFalse($csrf->validate(null));
        self::assertFalse($csrf->validate(''));
        self::assertFalse($csrf->validate('anything'));
    }

    public function testTheTokenIsRotatedOnSignInSoAPreSessionTokenIsWorthless(): void
    {
        $this->bootKernel();
        $this->seedCategory();

        $before = $this->token();
        $this->send('POST', '/admin/login', $this->withToken([
            'admin_user' => self::USER,
            'admin_pass' => self::PASSWORD,
        ]));
        $after = $this->token();

        self::assertNotSame($before, $after, 'the token survived the sign-in');

        $response = $this->send('POST', '/admin/config', [Csrf::FIELD => $before, 'action' => 'save']);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testNoGetRouteMutatesState(): void
    {
        $before = $this->dataDirectoryFingerprint();

        // The 1.6 attack, replayed against 2.0: the same parameters that delete
        // a subscription, sent as a link rather than a form.
        $query = 'action=delete&category=' . self::CATEGORY
            . '&select[0]=1&xml_url[0]=' . rawurlencode('https://example.com/feed.xml');

        foreach (['/admin/subscriptions', '/admin/config', '/admin/import', '/admin/add-new', '/admin'] as $path) {
            $response = $this->send('GET', $path . '?' . $query);
            self::assertLessThan(400, $response->getStatusCode(), $path . ' failed to render');
        }

        self::assertSame($before, $this->dataDirectoryFingerprint(), 'a GET changed the data directory');
        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testEveryWriteHandlerIsReachableOnlyByPost(): void
    {
        $writeHandlers = [
            AdminRoutes::LOGIN_SUBMIT,
            AdminRoutes::LOGOUT,
            AdminRoutes::ADD_FEED_SUBMIT,
            AdminRoutes::SUBSCRIPTIONS_SUBMIT,
            AdminRoutes::CONFIG_SUBMIT,
            AdminRoutes::IMPORT_SUBMIT,
            AdminRoutes::PARTIAL_DISCOVER,
            AdminRoutes::PARTIAL_SUBSCRIPTIONS_SUBMIT,
        ];

        foreach ($this->router->routes() as $route) {
            if (in_array($route->handler, $writeHandlers, true)) {
                self::assertSame(['POST'], $route->methods, $route->handler . ' is reachable by a safe method');
            }
        }
    }
}
