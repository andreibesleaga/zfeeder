<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Http;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * The use case that ties fetching, parsing and rendering together, and the
 * kernel that builds it.
 */
final class FeedServiceTest extends PublicSurfaceTestCase
{
    public function testAnUnknownCategoryFallsBackToTheDefaultRatherThanFailing(): void
    {
        // Embedded output must never turn into an error page; 1.6 fell back
        // silently and so does this.
        self::assertSame(self::CATEGORY, $this->kernel->feeds()->resolveCategory('no-such-thing')->name);
        self::assertSame(self::CATEGORY, $this->kernel->feeds()->resolveCategory(null)->name);
        self::assertSame(self::CATEGORY, $this->kernel->feeds()->resolveCategory('../etc')->name);
    }

    public function testAKnownCategoryIsUsed(): void
    {
        self::assertSame('other', $this->kernel->feeds()->resolveCategory('other')->name);
    }

    public function testWithNoDefaultItFallsBackToWhateverExists(): void
    {
        $this->bootKernel(['default_category' => 'missing']);
        $this->seed();

        self::assertContains($this->kernel->feeds()->resolveCategory(null)->name, ['demo', 'other']);
    }

    public function testWithNoCategoriesAtAllItSaysSoClearly(): void
    {
        $this->bootKernel(['default_category' => 'missing']);
        foreach ($this->kernel->subscriptions()->categories() as $name) {
            $this->kernel->subscriptions()->deleteCategory($name);
        }

        $this->expectException(\Zfeeder\Exception\StorageException::class);
        $this->expectExceptionMessageMatches('/no subscription categories/i');
        $this->kernel->feeds()->resolveCategory(null);
    }

    public function testOfflineModeNeverOpensASocket(): void
    {
        // A client that would explode if called at all proves the point.
        $this->kernel->withHttpClient(new MockHttpClient(static function (): MockResponse {
            throw new \LogicException('offline mode must not fetch');
        }));

        $loaded = $this->kernel->feeds()->load($this->kernel->feeds()->resolveCategory(self::CATEGORY));

        self::assertCount(2, $loaded);
        self::assertNotNull($loaded[0]->channel);
    }

    public function testAFeedThatWillNotParseDoesNotTakeThePageDownWithIt(): void
    {
        $this->kernel->cache()->put(new CacheEntry(
            'https://techwire.example.com/feed.xml',
            'this is not xml at all',
            new \DateTimeImmutable(self::NOW),
        ));

        $loaded = $this->kernel->feeds()->load($this->kernel->feeds()->resolveCategory(self::CATEGORY));

        self::assertNull($loaded[0]->channel, 'the broken feed yields no channel');
        self::assertNotNull($loaded[1]->channel, 'the healthy feed still renders');
        self::assertStringContainsString('Science Desk', self::bodyOf($this->get('/embed')));
    }

    public function testAFeedWithNoCacheEntryIsSkippedQuietly(): void
    {
        $this->kernel->subscriptions()->saveCategory(new Category(self::CATEGORY, [
            new Feed(xmlUrl: 'https://never-fetched.example/feed.xml', title: 'Absent', position: 1, showedItems: 3),
        ]));

        $loaded = $this->kernel->feeds()->load($this->kernel->feeds()->resolveCategory(self::CATEGORY));

        self::assertCount(1, $loaded);
        self::assertNull($loaded[0]->channel);
        self::assertSame(200, $this->get('/embed')->getStatusCode());
    }

    public function testUnsubscribedAndZeroItemFeedsAreNotLoaded(): void
    {
        $this->kernel->subscriptions()->saveCategory(new Category(self::CATEGORY, [
            new Feed(xmlUrl: 'https://techwire.example.com/feed.xml', title: 'On', position: 1, showedItems: 3),
            new Feed(xmlUrl: 'https://science.example.com/feed.xml', title: 'Off', position: 2, showedItems: 3, subscribed: false),
            new Feed(xmlUrl: 'https://science.example.com/feed.xml', title: 'Zero', position: 3, showedItems: 0),
        ]));

        self::assertCount(1, $this->kernel->feeds()->load($this->kernel->feeds()->resolveCategory(self::CATEGORY)));
    }

    public function testRefreshDeduplicatesAFeedSubscribedTwice(): void
    {
        $calls = 0;
        $this->kernel->withHttpClient(new MockHttpClient(function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse(zf_fixture_contents('feeds-2026/rss20-science.xml'), [
                'response_headers' => ['content-type' => 'application/rss+xml'],
            ]);
        }));
        $this->kernel->subscriptions()->saveCategory(new Category(self::CATEGORY, [
            new Feed(xmlUrl: 'https://one.example/feed.xml', title: 'A', position: 1, showedItems: 3),
            new Feed(xmlUrl: 'https://one.example/feed.xml', title: 'A again', position: 2, showedItems: 3),
        ]));

        $results = $this->kernel->feeds()->refresh(self::CATEGORY, true);

        self::assertCount(1, $results, 'the same URL must only be fetched once');
        self::assertSame(1, $calls);
    }

    public function testRefreshReportsEachFeedInThe2004Wording(): void
    {
        $results = $this->kernel->feeds()->refresh(self::CATEGORY, false);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertTrue($result->isSuccess());
            self::assertStringContainsString(' - not expired yet', $result->legacyLine());
        }
    }

    public function testRefreshingAnUnknownCategoryDoesNothingRatherThanThrowing(): void
    {
        self::assertSame([], $this->kernel->feeds()->refresh('not-a-category'));
    }

    public function testChannelsReturnsOnlyFeedsThatParsed(): void
    {
        $channels = $this->kernel->feeds()->channels(self::CATEGORY);

        self::assertCount(2, $channels);
        self::assertSame('Tech Wire', $channels[0]->title);
    }

    public function testTheKernelBuildsEachServiceOnceAndAcceptsReplacements(): void
    {
        self::assertSame($this->kernel->subscriptions(), $this->kernel->subscriptions());
        self::assertSame($this->kernel->renderer(), $this->kernel->renderer());
        self::assertSame($this->kernel->feeds(), $this->kernel->feeds());

        $client = new MockHttpClient([]);
        self::assertSame($this->kernel, $this->kernel->withHttpClient($client));
        self::assertSame($client, $this->kernel->httpClient());
    }

    public function testTheKernelClockIsInjectable(): void
    {
        self::assertSame(self::NOW, $this->kernel->clock()->format(DATE_ATOM));
    }

    public function testTheLoggerWritesWhereConfigurationSaysAndRespectsTheLevel(): void
    {
        $path = $this->tempDir() . '/test.log';
        $this->bootKernel(['log_path' => $path, 'log_level' => 'warning']);

        $this->kernel->logger()->debug('this is beneath the threshold');
        $this->kernel->logger()->warning('feed {url} failed', ['url' => 'https://example.com/feed']);

        $contents = (string) file_get_contents($path);
        self::assertStringNotContainsString('beneath the threshold', $contents);
        self::assertStringContainsString('WARNING', $contents);
        self::assertStringContainsString('feed https://example.com/feed failed', $contents);
    }

    public function testAnUnparseableCacheEntryIsDiscardedAndRefetchedOnce(): void
    {
        // The first live deployment cached gzipped bodies because of a header
        // bug. Every page then stayed blank until the interval expired, because
        // the damaged entry was still "fresh". A bad entry now repairs itself.
        $calls = 0;
        $this->kernel->withHttpClient(new MockHttpClient(function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse(zf_fixture_contents('feeds-2026/rss20-science.xml'), [
                'response_headers' => ['content-type' => 'application/rss+xml'],
            ]);
        }));
        $this->bootKernelOnline();

        $this->kernel->cache()->put(new CacheEntry(
            'https://techwire.example.com/feed.xml',
            "\x1f\x8b\x08 this is not xml",
            new \DateTimeImmutable(self::NOW),
        ));

        $loaded = $this->kernel->feeds()->load($this->kernel->feeds()->resolveCategory(self::CATEGORY));

        self::assertNotNull($loaded[0]->channel, 'the feed should have been refetched and parsed');
        self::assertSame('Science Desk', $loaded[0]->channel->title);
        self::assertSame(1, $calls, 'exactly one extra request, not one per render');
    }

    public function testAFeedThatIsGenuinelyBrokenIsNotRefetchedRepeatedly(): void
    {
        $calls = 0;
        $this->kernel->withHttpClient(new MockHttpClient(function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse('still not xml', ['response_headers' => ['content-type' => 'application/rss+xml']]);
        }));
        $this->bootKernelOnline();

        $this->kernel->cache()->put(new CacheEntry(
            'https://techwire.example.com/feed.xml',
            'not xml either',
            new \DateTimeImmutable(self::NOW),
        ));

        $this->kernel->feeds()->load($this->kernel->feeds()->resolveCategory(self::CATEGORY));

        self::assertSame(1, $calls, 'the retry is bounded to a single attempt');
    }

    /** Re-boots with online refreshing, keeping the seeded data directory. */
    private function bootKernelOnline(): void
    {
        $client = $this->kernel->httpClient();
        $this->bootKernel(['refresh_mode' => 'online']);
        $this->kernel->withHttpClient($client);
    }
}
