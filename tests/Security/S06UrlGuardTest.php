<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Fetch\FetchResult;
use Zfeeder\Subscription\Feed;

/**
 * S6 — the address guard, exercised where it actually protects something: in
 * the fetcher and in the panel. The scheme allow-list, the address tables and
 * the per-hop re-check of a redirect chain are covered as a table by
 * `tests/Unit/Fetch/UrlGuardTest.php`; this class asserts that a subscription,
 * a preview and an OPML import cannot reach a loopback, private, link-local or
 * metadata address, and that nothing is written to the cache when they try.
 *
 * Closes 1.6 defects L7 and L8: `newsfeeds/includes/zfuncs.php:281` fetched
 * feeds with `fopen($url, "r")`, so every stream wrapper PHP had — `file://`,
 * `php://`, `ftp://` — was an accepted "feed address", redirects were followed
 * with no checks at all, and `:283-290` read until the stream ended, with no
 * size limit and no timeout.
 *
 * The control lives in `src/Fetch/UrlGuard.php` (schemes, address tables,
 * injected resolver), `src/Fetch/FeedFetcher::followRedirects()` (which sets
 * `max_redirects: 0` and re-runs the guard on every hop) and
 * `src/Admin/Http/GuardedFetch.php` (the same rules for the panel's own
 * outbound requests).
 *
 * Every address used here is a literal, so the resolver is never called and
 * the suite never asks DNS anything.
 */
final class S06UrlGuardTest extends SecurityTestCase
{
    private const string PUBLIC_URL = 'http://203.0.113.9/feed.xml';

    private const string FEED_XML = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
        . '<link>http://203.0.113.9/</link><description>d</description>'
        . '<item><title>i</title><link>http://203.0.113.9/1</link></item></channel></rss>';

    private MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        // The guard is on: this is the only suite that runs with it enabled.
        $this->bootKernel(['allow_private_hosts' => false]);
        $this->seedCategory();
        $this->signIn();
    }

    /** @param list<MockResponse> $responses */
    private function transport(array $responses): void
    {
        $this->http = new MockHttpClient($responses);
        $this->kernel->withHttpClient($this->http);
    }

    /** @return iterable<string, array{string}> addresses that must never be contacted */
    public static function forbiddenAddresses(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'loopback by another octet' => ['http://127.1.2.3/admin'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'link local' => ['http://169.254.0.7/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 unique local' => ['http://[fd00::1]/'];
        yield 'ipv4 mapped into ipv6' => ['http://[::ffff:127.0.0.1]/'];
        yield 'private 10/8' => ['http://10.0.0.1/'];
        yield 'private 192.168/16' => ['http://192.168.1.1/'];
        yield 'private 172.16/12' => ['http://172.16.0.1/'];
        yield 'this network' => ['http://0.0.0.0/'];
        yield 'localhost by name' => ['http://localhost/feed.xml'];
        yield 'a local network name' => ['http://printer.local/feed.xml'];
    }

    /** @return iterable<string, array{string}> schemes that never reach a socket */
    public static function forbiddenSchemes(): iterable
    {
        yield 'file' => ['file:///etc/passwd'];
        yield 'php filter' => ['php://filter/convert.base64-encode/resource=/etc/passwd'];
        yield 'php input' => ['php://input'];
        yield 'gopher' => ['gopher://203.0.113.9:70/_hello'];
        yield 'data' => ['data:text/plain;base64,aGVsbG8='];
        yield 'ftp' => ['ftp://203.0.113.9/feed.xml'];
        yield 'no scheme at all' => ['/etc/passwd'];
    }

    #[DataProvider('forbiddenAddresses')]
    public function testASubscriptionToAForbiddenAddressIsRefusedAndNothingIsCached(string $url): void
    {
        $this->transport([new MockResponse(self::FEED_XML)]);

        $result = $this->kernel->fetcher()->fetch($url, 60, true);

        self::assertSame(FetchResult::FAILED, $result->outcome, $url . ' was fetched');
        self::assertStringContainsString('Refusing to fetch', $result->message);
        self::assertSame(0, $this->http->getRequestsCount(), $url . ' reached the transport');
        self::assertNull($this->kernel->cache()->get($url), $url . ' left something in the cache');
    }

    #[DataProvider('forbiddenSchemes')]
    public function testASubscriptionWithAForbiddenSchemeNeverReachesTheTransport(string $url): void
    {
        $this->transport([new MockResponse(self::FEED_XML)]);

        $result = $this->kernel->fetcher()->fetch($url, 60, true);

        self::assertSame(FetchResult::FAILED, $result->outcome, $url . ' was fetched');
        self::assertSame(0, $this->http->getRequestsCount(), $url . ' reached the transport');
        self::assertNull($this->kernel->cache()->get($url));
    }

    #[DataProvider('forbiddenAddresses')]
    public function testAPublicHostThatRedirectsToAForbiddenAddressIsRefused(string $target): void
    {
        // The first hop is a perfectly ordinary public address; the 302 is
        // where the attack is. Symfony is told not to follow anything, so this
        // hop is checked by the guard and not by the transport.
        $this->transport([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $target]]),
            new MockResponse(self::FEED_XML),
        ]);

        $result = $this->kernel->fetcher()->fetch(self::PUBLIC_URL, 60, true);

        self::assertSame(FetchResult::FAILED, $result->outcome, 'a redirect to ' . $target . ' was followed');
        self::assertStringContainsString('Refusing to fetch', $result->message);
        self::assertSame(1, $this->http->getRequestsCount(), 'the second hop was requested anyway');
        self::assertNull($this->kernel->cache()->get(self::PUBLIC_URL));
        self::assertNull($this->kernel->cache()->get($target));
    }

    public function testAChainOfPublicHopsEndingAtLoopbackIsStillRefused(): void
    {
        $this->transport([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'http://203.0.113.10/a']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://203.0.113.11/b']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://127.0.0.1/secret']]),
            new MockResponse(self::FEED_XML),
        ]);

        $result = $this->kernel->fetcher()->fetch(self::PUBLIC_URL, 60, true);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertSame(3, $this->http->getRequestsCount(), 'the loopback hop was requested');
        self::assertNull($this->kernel->cache()->get(self::PUBLIC_URL));
    }

    public function testARelativeRedirectCannotSmuggleAnAddressPastTheGuard(): void
    {
        $this->transport([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '//127.0.0.1/feed.xml']]),
            new MockResponse(self::FEED_XML),
        ]);

        $result = $this->kernel->fetcher()->fetch(self::PUBLIC_URL, 60, true);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertSame(1, $this->http->getRequestsCount());
    }

    public function testAnOrdinaryPublicFeedIsStillFetchedAndCached(): void
    {
        // The other half of every assertion above: with the guard on, a public
        // address works. Without this the suite could pass by refusing
        // everything.
        $this->transport([new MockResponse(self::FEED_XML)]);

        $result = $this->kernel->fetcher()->fetch(self::PUBLIC_URL, 60, true);

        self::assertSame(FetchResult::CACHED, $result->outcome, $result->message);
        self::assertSame(1, $this->http->getRequestsCount());
        self::assertNotNull($this->kernel->cache()->get(self::PUBLIC_URL));
    }

    public function testWithTheGuardSwitchedOffTheSameLoopbackFetchSucceeds(): void
    {
        // The weakened build: `allow_private_hosts` is the development switch,
        // and with it on the loopback fetch goes through. That is what the
        // tests above are preventing.
        $this->bootKernel(['allow_private_hosts' => true]);
        $this->transport([new MockResponse(self::FEED_XML)]);

        $result = $this->kernel->fetcher()->fetch('http://127.0.0.1/feed.xml', 60, true);

        self::assertSame(FetchResult::CACHED, $result->outcome);
        self::assertSame(1, $this->http->getRequestsCount());
    }

    public function testEvenWithPrivateHostsAllowedAFileUrlIsStillRefused(): void
    {
        // The scheme rule is not part of the development switch: `file://`
        // never reaches a socket, so it can never be "allowed for testing".
        $this->bootKernel(['allow_private_hosts' => true]);
        $this->transport([new MockResponse(self::FEED_XML)]);

        $result = $this->kernel->fetcher()->fetch('file:///etc/passwd', 60, true);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertSame(0, $this->http->getRequestsCount());
        self::assertStringNotContainsString('root:', $result->message);
    }

    // ---- the panel -------------------------------------------------------

    #[DataProvider('forbiddenAddresses')]
    public function testThePanelRefusesToPreviewAForbiddenAddress(string $url): void
    {
        $this->transport([new MockResponse(self::FEED_XML)]);

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'preview',
            'feed_url' => $url,
        ]));

        self::assertSame(400, $response->getStatusCode(), $url . ' was previewed');
        self::assertStringContainsString('Refusing to fetch', self::bodyOf($response));
        self::assertSame(0, $this->http->getRequestsCount());
    }

    #[DataProvider('forbiddenSchemes')]
    public function testThePanelRefusesToPreviewAForbiddenScheme(string $url): void
    {
        $this->transport([new MockResponse(self::FEED_XML)]);

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'preview',
            'feed_url' => $url,
        ]));

        self::assertSame(400, $response->getStatusCode(), $url . ' was previewed');
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testAForbiddenAddressCannotBeSubscribedToEvenByEditingTheForm(): void
    {
        // The preview form is a form: the address is checked again on the way
        // in, not only on the way to the preview.
        $this->transport([new MockResponse(self::FEED_XML)]);

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'subscribe',
            'category' => self::CATEGORY,
            'feed_url' => 'http://169.254.169.254/latest/meta-data/',
            'title' => 'Metadata',
            'showed_items' => '3',
        ]));

        self::assertStringContainsString('cannot be used', self::bodyOf($response));
        self::assertSame([], $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAutodiscoveryRefusesAForbiddenAddress(): void
    {
        $this->transport([new MockResponse('<html><head></head></html>')]);

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'discover',
            'site_url' => 'http://127.0.0.1:8080/',
        ]));

        self::assertStringContainsString('Refusing to fetch', self::bodyOf($response));
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testImportingAListFromAForbiddenAddressIsRefused(): void
    {
        $this->withFeeds([new Feed('https://kept.example/feed.xml', 'Kept', '', '', 1, 60, 3, true)]);
        $this->transport([new MockResponse('<opml version="1.0"><head/><body/></opml>')]);

        $response = $this->send('POST', '/admin/import', $this->withToken([
            'action' => 'import',
            'category' => self::CATEGORY,
            'mode' => 'replace',
            'opml_url' => 'http://169.254.169.254/latest/meta-data/iam/',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Refusing to fetch', self::bodyOf($response));
        self::assertSame(0, $this->http->getRequestsCount());
        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testImportingAListFromAnAddressThatRedirectsInwardsIsRefused(): void
    {
        $this->withFeeds([new Feed('https://kept.example/feed.xml', 'Kept', '', '', 1, 60, 3, true)]);
        $this->transport([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://10.1.2.3/list.opml']]),
            new MockResponse('<opml version="1.0"><head/><body/></opml>'),
        ]);

        $response = $this->send('POST', '/admin/import', $this->withToken([
            'action' => 'import',
            'category' => self::CATEGORY,
            'mode' => 'replace',
            'opml_url' => 'http://203.0.113.9/list.opml',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, $this->http->getRequestsCount(), 'the private hop was requested');
        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }
}
