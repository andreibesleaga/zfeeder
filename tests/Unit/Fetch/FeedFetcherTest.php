<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Fetch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zfeeder\Config\Config;
use Zfeeder\Fetch\FeedFetcher;
use Zfeeder\Fetch\FetchResult;
use Zfeeder\Fetch\UrlGuard;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Feed;

/**
 * The fetcher against a mock transport and a frozen clock: every TTL decision,
 * every conditional request and every failure mode is asserted without a
 * socket, a sleep or a real second passing.
 */
#[CoversClass(FeedFetcher::class)]
final class FeedFetcherTest extends TestCase
{
    private const string NOW = '2026-09-18T12:00:00+00:00';
    private const string URL = 'https://feeds.example.com/news.xml';
    private const string BODY = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>';

    private InMemoryCacheStore $cache;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->cache = new InMemoryCacheStore();
        $this->requests = [];
    }

    public function testFreshFetchStoresBodyEtagAndLastModified(): void
    {
        $fetcher = $this->fetcher($this->client([
            new MockResponse(self::BODY, ['response_headers' => [
                'etag' => '"v1"',
                'last-modified' => 'Wed, 17 Sep 2026 10:00:00 GMT',
                'content-type' => 'application/rss+xml',
            ]]),
        ]));

        $result = $fetcher->fetch(self::URL, 60);

        self::assertSame(FetchResult::CACHED, $result->outcome);
        self::assertTrue($result->isSuccess());
        self::assertSame(self::URL . ' - cached', $result->legacyLine());

        $stored = $this->cache->get(self::URL);
        self::assertInstanceOf(CacheEntry::class, $stored);
        self::assertSame(self::BODY, $stored->body);
        self::assertSame('"v1"', $stored->etag);
        self::assertSame('Wed, 17 Sep 2026 10:00:00 GMT', $stored->lastModified);
        self::assertSame(self::NOW, $stored->fetchedAt->format('c'));
        self::assertSame('', $stored->error);
    }

    public function testFreshFetchSendsTheConfiguredUserAgent(): void
    {
        $fetcher = $this->fetcher($this->client([new MockResponse(self::BODY)]), [
            'fetch_user_agent' => 'zFeeder-test/1.0',
        ]);

        $fetcher->fetch(self::URL, 60);

        self::assertCount(1, $this->requests);
        self::assertContains('User-Agent: zFeeder-test/1.0', $this->headersOf(0));
    }

    public function testNotExpiredEntryShortCircuitsWithoutAnyRequest(): void
    {
        $this->cache->put($this->entry(fetchedMinutesAgo: 5));

        $fetcher = $this->fetcher($this->client([]));
        $result = $fetcher->fetch(self::URL, 60);

        self::assertSame(FetchResult::NOT_EXPIRED, $result->outcome);
        self::assertSame(self::URL . ' - not expired yet', $result->legacyLine());
        self::assertSame([], $this->requests, 'A live entry must not cause a request.');
    }

    public function testForceBypassesTheTimeToLive(): void
    {
        $this->cache->put($this->entry(fetchedMinutesAgo: 5));

        $fetcher = $this->fetcher($this->client([new MockResponse(self::BODY)]));
        $result = $fetcher->fetch(self::URL, 60, force: true);

        self::assertSame(FetchResult::CACHED, $result->outcome);
        self::assertCount(1, $this->requests);
    }

    public function testExpiredEntrySendsConditionalHeadersAndHandles304(): void
    {
        $this->cache->put($this->entry(fetchedMinutesAgo: 180, etag: '"v1"', lastModified: 'Wed, 17 Sep 2026 10:00:00 GMT'));

        $fetcher = $this->fetcher($this->client([new MockResponse('', ['http_code' => 304])]));
        $result = $fetcher->fetch(self::URL, 60);

        $headers = $this->headersOf(0);
        self::assertContains('If-None-Match: "v1"', $headers);
        self::assertContains('If-Modified-Since: Wed, 17 Sep 2026 10:00:00 GMT', $headers);

        self::assertSame(FetchResult::NOT_MODIFIED, $result->outcome);
        self::assertSame(self::URL . ' - cached', $result->legacyLine());

        $stored = $this->cache->get(self::URL);
        self::assertInstanceOf(CacheEntry::class, $stored);
        self::assertSame('cached body', $stored->body, 'A 304 must keep the body it already had.');
        self::assertSame(self::NOW, $stored->fetchedAt->format('c'), 'A 304 restarts the TTL.');
        self::assertFalse($stored->isExpired(60, new \DateTimeImmutable(self::NOW)));
    }

    public function testNoConditionalHeadersWhenNothingIsCached(): void
    {
        $fetcher = $this->fetcher($this->client([new MockResponse(self::BODY)]));
        $fetcher->fetch(self::URL, 60);

        $headers = $this->headersOf(0);
        foreach ($headers as $header) {
            self::assertStringStartsNotWith('If-None-Match', $header);
            self::assertStringStartsNotWith('If-Modified-Since', $header);
        }
    }

    public function testA304WithNothingCachedIsAFailureRatherThanAnEmptyFeed(): void
    {
        $fetcher = $this->fetcher($this->client([new MockResponse('', ['http_code' => 304])]));
        $result = $fetcher->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertNull($result->entry);
    }

    /** @return iterable<string, array{0: int}> */
    public static function errorStatuses(): iterable
    {
        yield '404' => [404];
        yield '410' => [410];
        yield '429' => [429];
        yield '500' => [500];
        yield '503' => [503];
    }

    #[DataProvider('errorStatuses')]
    public function testErrorStatusFailsAndKeepsNothingWhenTheCacheIsEmpty(int $status): void
    {
        $fetcher = $this->fetcher($this->client([new MockResponse('nope', ['http_code' => $status])]));
        $result = $fetcher->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('HTTP ' . $status, $result->message);
        self::assertSame(self::URL . ' - NOT cached; check connection', $result->legacyLine());
        self::assertNull($this->cache->get(self::URL));
    }

    public function testServerErrorKeepsServingTheStaleEntry(): void
    {
        $this->cache->put($this->entry(fetchedMinutesAgo: 180));

        $fetcher = $this->fetcher($this->client([new MockResponse('boom', ['http_code' => 500])]));
        $result = $fetcher->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertInstanceOf(CacheEntry::class, $result->entry);
        self::assertSame('cached body', $result->entry->body, '1.6 kept showing the old headlines.');
        self::assertStringContainsString('HTTP 500', $result->entry->error);

        $stored = $this->cache->get(self::URL);
        self::assertInstanceOf(CacheEntry::class, $stored);
        self::assertSame('cached body', $stored->body);
        self::assertTrue(
            $stored->isExpired(60, new \DateTimeImmutable(self::NOW)),
            'A failure must not restart the TTL, or the retry would be delayed.',
        );
    }

    public function testTransportFailureIsReportedAndKeepsTheStaleEntry(): void
    {
        $this->cache->put($this->entry(fetchedMinutesAgo: 180));

        $client = $this->client(static function (): MockResponse {
            throw new TransportException('Idle timeout reached for "https://feeds.example.com/news.xml".');
        });

        $result = $this->fetcher($client)->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('Idle timeout', $result->message);
        self::assertInstanceOf(CacheEntry::class, $result->entry);
        self::assertSame('cached body', $result->entry->body);
    }

    public function testTheConfiguredTimeoutIsPassedToTheTransport(): void
    {
        $fetcher = $this->fetcher($this->client([new MockResponse(self::BODY)]), ['fetch_timeout' => 7]);
        $fetcher->fetch(self::URL, 60);

        self::assertSame(7.0, (float) $this->requests[0]['options']['timeout']);
    }

    public function testBodyLargerThanTheLimitIsAbandoned(): void
    {
        // Chunked so that the ceiling is crossed mid-stream, which is the case
        // that proves the reader is counting rather than buffering first.
        $chunks = array_fill(0, 8, str_repeat('a', 512));
        $client = $this->client([new MockResponse($chunks)]);

        $result = $this->fetcher($client, ['fetch_max_bytes' => 2048])->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('larger than the 2048 byte limit', $result->message);
        self::assertNull($this->cache->get(self::URL));
    }

    public function testAnAnnouncedOversizeIsRefusedBeforeReading(): void
    {
        // The body really is this long: MockResponse rejects a Content-Length
        // that does not match, and the point here is the header check, not a
        // lying server (readBounded() is what catches those).
        $client = $this->client([
            new MockResponse(str_repeat('c', 4096), ['response_headers' => ['content-length' => '4096']]),
        ]);

        $result = $this->fetcher($client, ['fetch_max_bytes' => 2048])->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('announces 4096 bytes', $result->message);
        self::assertNull($this->cache->get(self::URL));
    }

    public function testBodyExactlyAtTheLimitIsAccepted(): void
    {
        $body = str_repeat('b', 2048);
        $client = $this->client([new MockResponse($body)]);

        $result = $this->fetcher($client, ['fetch_max_bytes' => 2048])->fetch(self::URL, 60);

        self::assertSame(FetchResult::CACHED, $result->outcome);
        self::assertInstanceOf(CacheEntry::class, $result->entry);
        self::assertSame($body, $result->entry->body);
    }

    public function testEmptyBodyIsTreatedAsAFailure(): void
    {
        $result = $this->fetcher($this->client([new MockResponse('   ')]))->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('empty body', $result->message);
    }

    public function testRedirectIsFollowedManuallyAndTheFinalBodyIsStored(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/new/news.xml']]),
            new MockResponse(self::BODY),
        ]);

        $result = $this->fetcher($client)->fetch(self::URL, 60);

        self::assertSame(FetchResult::CACHED, $result->outcome);
        self::assertCount(2, $this->requests);
        self::assertSame('https://feeds.example.com/new/news.xml', $this->requests[1]['url']);
        self::assertSame(0, $this->requests[0]['options']['max_redirects'], 'Symfony must not follow redirects itself.');

        $stored = $this->cache->get(self::URL);
        self::assertInstanceOf(CacheEntry::class, $stored);
        self::assertSame(self::URL, $stored->url, 'The cache is keyed by the subscribed URL, not the final one.');
    }

    public function testValidatorsAreNotReplayedAfterARedirect(): void
    {
        $this->cache->put($this->entry(fetchedMinutesAgo: 180, etag: '"v1"'));

        $client = $this->client([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://cdn.example.com/news.xml']]),
            new MockResponse(self::BODY),
        ]);

        $this->fetcher($client)->fetch(self::URL, 60);

        self::assertContains('If-None-Match: "v1"', $this->headersOf(0));
        self::assertNotContains('If-None-Match: "v1"', $this->headersOf(1));
    }

    public function testRedirectToAPrivateAddressIsRefused(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://169.254.169.254/latest/meta-data/']]),
            new MockResponse('SECRET'),
        ]);

        $result = $this->fetcher($client)->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('metadata', $result->message);
        self::assertCount(1, $this->requests, 'The second hop must never be requested.');
        self::assertNull($this->cache->get(self::URL));
    }

    public function testRedirectToLoopbackIsRefusedEvenAfterSeveralPublicHops(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://a.example.com/1']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://b.example.com/2']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://127.0.0.1:8080/admin']]),
            new MockResponse('SECRET'),
        ]);

        $result = $this->fetcher($client)->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('loopback', $result->message);
        self::assertCount(3, $this->requests);
    }

    public function testTooManyRedirectsFails(): void
    {
        $client = $this->client(function (string $method, string $url): MockResponse {
            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'https://feeds.example.com/hop' . count($this->requests)],
            ]);
        });

        $result = $this->fetcher($client, ['fetch_max_redirects' => 3])->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('More than 3 redirects', $result->message);
        self::assertCount(4, $this->requests, 'One original request plus three permitted hops.');
    }

    public function testRedirectWithoutALocationHeaderFails(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 302])]);
        $result = $this->fetcher($client)->fetch(self::URL, 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('without a Location header', $result->message);
    }

    public function testASubscriptionToAForbiddenSchemeNeverReachesTheTransport(): void
    {
        $client = $this->client([new MockResponse('root:x:0:0:')]);
        $result = $this->fetcher($client)->fetch('file:///etc/passwd', 60);

        self::assertSame(FetchResult::FAILED, $result->outcome);
        self::assertStringContainsString('"file" scheme', $result->message);
        self::assertSame([], $this->requests);
    }

    public function testFetchAllReturnsOneResultPerDistinctFeed(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse(self::BODY));
        $fetcher = $this->fetcher($client);

        $results = $fetcher->fetchAll([
            new Feed('https://a.example.com/feed.xml', refreshMinutes: 60),
            new Feed('https://b.example.com/feed.xml', refreshMinutes: 60),
            new Feed('https://a.example.com/feed.xml', refreshMinutes: 30),
        ], false);

        self::assertCount(2, $results);
        self::assertSame('https://a.example.com/feed.xml', $results[0]->url);
        self::assertSame('https://b.example.com/feed.xml', $results[1]->url);
        self::assertCount(2, $this->requests);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function redirectTargets(): iterable
    {
        yield 'absolute' => ['https://a.test/dir/feed.xml', 'https://other.test/x.xml', 'https://other.test/x.xml'];
        yield 'scheme relative' => ['https://a.test/dir/feed.xml', '//cdn.test/x.xml', 'https://cdn.test/x.xml'];
        yield 'root relative' => ['https://a.test/dir/feed.xml', '/x.xml', 'https://a.test/x.xml'];
        yield 'path relative' => ['https://a.test/dir/feed.xml', 'x.xml', 'https://a.test/dir/x.xml'];
        yield 'parent relative' => ['https://a.test/dir/sub/feed.xml', '../x.xml', 'https://a.test/dir/x.xml'];
        yield 'keeps the port' => ['https://a.test:8443/dir/feed.xml', '/x.xml', 'https://a.test:8443/x.xml'];
    }

    #[DataProvider('redirectTargets')]
    public function testRedirectTargetsResolveTheWayTheGuardWillSeeThem(string $base, string $location, string $expected): void
    {
        self::assertSame($expected, FeedFetcher::resolveUrl($location, $base));
    }

    // ---- helpers ---------------------------------------------------------

    /** @param array<string, string|int|bool> $overrides */
    private function fetcher(HttpClientInterface $client, array $overrides = []): FeedFetcher
    {
        $config = Config::forTesting($overrides);

        return new FeedFetcher(
            $config,
            $this->cache,
            new UrlGuard($config, static fn (string $host): array => ['93.184.216.34']),
            $client,
            new NullLogger(),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW),
        );
    }

    /** @param list<MockResponse>|callable(string, string, array<string, mixed>): MockResponse $responses */
    private function client(array|callable $responses): MockHttpClient
    {
        $queue = is_array($responses) ? new \ArrayIterator($responses) : null;

        return new MockHttpClient(function (string $method, string $url, array $options) use ($queue, $responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if ($queue === null) {
                /** @var callable(string, string, array<string, mixed>): MockResponse $responses */
                return $responses($method, $url, $options);
            }

            if (!$queue->valid()) {
                throw new \LogicException('The mock client ran out of responses for ' . $url);
            }
            $response = $queue->current();
            $queue->next();
            self::assertInstanceOf(MockResponse::class, $response);

            return $response;
        }, 'https://feeds.example.com');
    }

    /** @return list<string> the "Name: value" header lines of request $index */
    private function headersOf(int $index): array
    {
        $headers = $this->requests[$index]['options']['headers'] ?? [];
        self::assertIsArray($headers);

        $out = [];
        foreach ($headers as $key => $value) {
            $out[] = is_int($key) ? (string) $value : $key . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return $out;
    }

    private function entry(
        int $fetchedMinutesAgo,
        ?string $etag = null,
        ?string $lastModified = null,
    ): CacheEntry {
        $fetchedAt = (new \DateTimeImmutable(self::NOW))->modify('-' . $fetchedMinutesAgo . ' minutes');
        self::assertInstanceOf(\DateTimeImmutable::class, $fetchedAt);

        return new CacheEntry(self::URL, 'cached body', $fetchedAt, $etag, $lastModified);
    }

    /**
     * A regression from the first live deployment: every feed came back
     * unparseable with "Start tag expected". Setting Accept-Encoding by hand
     * tells the transport that the caller will deal with the encoding, so curl
     * stops decompressing and the gzipped bytes reach the XML parser. Leaving
     * the header unset lets the client negotiate and decode it.
     */
    public function testTheRequestDoesNotSetAcceptEncodingItself(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['headers'] ?? [];

            return new MockResponse('<rss version="2.0"><channel><title>t</title></channel></rss>', [
                'response_headers' => ['content-type' => 'application/rss+xml'],
            ]);
        });

        $this->fetcher($client)->fetch('https://feeds.example.com/rss.xml', 60);

        $names = array_map(
            static fn (string $header): string => strtolower(explode(':', $header, 2)[0]),
            array_values($seen),
        );

        self::assertNotContains(
            'accept-encoding',
            $names,
            'Accept-Encoding must be left to the HTTP client so it decompresses the body',
        );
        self::assertContains('accept', $names, 'but Accept is still sent');
    }
}
