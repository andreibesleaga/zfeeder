<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Subscription\Feed;

/**
 * Adding a feed: autodiscovery, preview, subscribe — and the refusals.
 *
 * Every outbound request in these tests goes through a mock transport, so the
 * suite never opens a socket; the address rules are exercised separately with
 * the guard switched back on, because that is the test that matters.
 */
final class AddFeedScreenTest extends AdminTestCase
{
    private const string FEED_XML = <<<'XML'
        <?xml version="1.0"?>
        <rss version="2.0"><channel>
        <title>Example news</title>
        <link>https://example.com/</link>
        <description>Everything that happens at Example</description>
        <language>en-gb</language>
        <copyright>(c) Example</copyright>
        <lastBuildDate>Wed, 17 Sep 2026 10:00:00 GMT</lastBuildDate>
        <item><title>First</title><link>https://example.com/1</link></item>
        </channel></rss>
        XML;

    private const string PAGE_HTML = <<<'HTML'
        <html><head>
        <link rel="alternate" type="application/rss+xml" title="Example news" href="/feed.xml">
        </head><body>Hello</body></html>
        HTML;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function testTheFormOffersBothBoxesAndTheBookmarklet(): void
    {
        $body = self::bodyOf($this->send('GET', '/admin/add-new'));

        self::assertStringContainsString('for="site_url"', $body);
        self::assertStringContainsString('for="feed_url"', $body);
        self::assertStringContainsString('ShowOnMySite', $body);
    }

    public function testAutodiscoveryListsWhatThePageDeclares(): void
    {
        $this->mockHttp([new MockResponse(self::PAGE_HTML, ['response_headers' => ['content-type' => 'text/html']])]);

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'discover',
            'site_url' => 'https://example.com/',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = self::bodyOf($response);
        self::assertStringContainsString('https://example.com/feed.xml', $body);
        self::assertStringContainsString('Example news', $body);
    }

    public function testAutodiscoveryAsAnHtmxFragmentIsTheSameMarkupWithoutThePage(): void
    {
        $this->mockHttp([new MockResponse(self::PAGE_HTML)]);

        $response = $this->send('POST', '/admin/_/discover', $this->withToken([
            'site_url' => 'https://example.com/',
        ]), ['HX-Request' => 'true']);

        $body = self::bodyOf($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsNotWith('<!DOCTYPE', $body);
        self::assertStringContainsString('https://example.com/feed.xml', $body);
    }

    public function testAPageWithoutFeedsSuggestsTheUsualAddresses(): void
    {
        $this->mockHttp([new MockResponse('<html><head></head><body>nothing here</body></html>')]);

        $body = self::bodyOf($this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'discover',
            'site_url' => 'https://example.com/',
        ])));

        self::assertStringContainsString('declares no feeds', $body);
        self::assertStringContainsString('https://example.com/feed', $body);
    }

    public function testAFeedAddressIsFetchedAndPreviewed(): void
    {
        $this->mockHttp([new MockResponse(self::FEED_XML, ['response_headers' => ['content-type' => 'application/rss+xml']])]);

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'preview',
            'feed_url' => 'https://example.com/feed.xml',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = self::bodyOf($response);
        self::assertStringContainsString('Example news', $body);
        self::assertStringContainsString('Everything that happens at Example', $body);
        self::assertStringContainsString('en-gb', $body);
        self::assertStringContainsString('validator.w3.org/feed/check.cgi', $body);
        self::assertStringContainsString('value="subscribe"', $body);
    }

    public function testTheBookmarkletAddressPreviewsStraightAway(): void
    {
        $this->mockHttp([new MockResponse(self::FEED_XML)]);

        $request = $this->request('GET', '/admin/add-new')
            ->withQueryParams(['feed_url' => 'https://example.com/feed.xml']);

        self::assertStringContainsString('Example news', self::bodyOf($this->dispatch($request)));
    }

    public function testSubscribingAppendsToTheChosenCategory(): void
    {
        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'subscribe',
            'feed_url' => 'https://example.com/feed.xml',
            'site_url' => 'https://example.com/',
            'language' => 'en-gb',
            'title' => 'Example news',
            'description' => 'Everything that happens at Example',
            'refresh_minutes' => '30',
            'showed_items' => '5',
            'subscribed' => '1',
            'category' => self::CATEGORY,
        ]));

        self::assertSame(303, $response->getStatusCode());

        $feeds = $this->kernel->subscriptions()->category(self::CATEGORY)->feeds;
        self::assertCount(1, $feeds);
        self::assertSame('https://example.com/feed.xml', $feeds[0]->xmlUrl);
        self::assertSame('Example news', $feeds[0]->title);
        self::assertSame(30, $feeds[0]->refreshMinutes);
        self::assertSame(5, $feeds[0]->showedItems);
        self::assertTrue($feeds[0]->subscribed);
        self::assertSame(1, $feeds[0]->position);
    }

    public function testSubscribingTwiceToTheSameFeedIsRefused(): void
    {
        $this->withFeeds([new Feed('https://example.com/feed.xml', 'Example news')]);

        $body = self::bodyOf($this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'subscribe',
            'feed_url' => 'https://example.com/feed.xml',
            'category' => self::CATEGORY,
        ])));

        self::assertStringContainsString('already in the', $body);
        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAPrivateAddressIsRefused(): void
    {
        $this->bootKernel(['allow_private_hosts' => false]);
        $this->signIn();

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'preview',
            'feed_url' => 'http://127.0.0.1/feed.xml',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('loopback', self::bodyOf($response));
    }

    public function testAPrivateAddressCannotBeSubscribedToEither(): void
    {
        $this->bootKernel(['allow_private_hosts' => false]);
        $this->signIn();

        $body = self::bodyOf($this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'subscribe',
            'feed_url' => 'http://169.254.169.254/latest/meta-data/',
            'category' => self::CATEGORY,
        ])));

        self::assertStringContainsString('cannot be used', $body);
        self::assertSame([], $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAFileSchemeIsRefused(): void
    {
        $this->bootKernel(['allow_private_hosts' => false]);
        $this->signIn();

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'discover',
            'site_url' => 'file:///etc/passwd',
        ]));

        self::assertStringContainsString('not allowed', self::bodyOf($response));
    }

    public function testSomethingThatIsNotAFeedIsRefused(): void
    {
        $this->mockHttp([new MockResponse('this is not a feed at all')]);

        $response = $this->send('POST', '/admin/add-new', $this->withToken([
            'action' => 'preview',
            'feed_url' => 'https://example.com/not-a-feed',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('not a feed', self::bodyOf($response));
    }
}
