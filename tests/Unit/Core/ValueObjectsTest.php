<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zfeeder\Cli\Application;
use Zfeeder\Config\Config;
use Zfeeder\Exception\ConfigException;
use Zfeeder\Fetch\FetchResult;
use Zfeeder\Kernel;
use Zfeeder\Parse\Model\Channel;
use Zfeeder\Parse\Model\Enclosure;
use Zfeeder\Parse\Model\Item;
use Zfeeder\Render\RenderedFeed;
use Zfeeder\Render\RenderRequest;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Tests\Support\StorageTempDirectory;
use Zfeeder\Version;

/**
 * The small types the rest of the program is built from, and the console
 * application's wiring. Individually trivial, collectively the vocabulary
 * everything else speaks, so their edges are worth pinning down.
 */
final class ValueObjectsTest extends TestCase
{
    use StorageTempDirectory;

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testTheVersionIsReportedConsistently(): void
    {
        self::assertMatchesRegularExpression('/^2\.\d+\.\d+$/', Version::NUMBER);
        self::assertSame('zFeeder ' . Version::NUMBER, Version::full());
        self::assertStringContainsString(Version::NUMBER, Version::userAgent());
        self::assertStringContainsString(Version::HOMEPAGE, Version::userAgent());
    }

    public function testAChannelCanBeGivenItemsWithoutLosingItsMetadata(): void
    {
        $channel = new Channel(
            title: 'Example',
            link: 'https://example.com/',
            description: 'A feed',
            language: 'en',
            copyright: '(c) Example',
            logoUrl: 'https://example.com/logo.png',
            logoTitle: 'Example',
            logoLink: 'https://example.com/',
            lastBuild: new \DateTimeImmutable('2026-09-18T00:00:00+00:00'),
            format: 'rss-2.0',
        );

        $withItems = $channel->withItems([new Item('1', 'First', 'https://example.com/1')]);

        self::assertCount(1, $withItems->items);
        self::assertSame('Example', $withItems->title);
        self::assertSame('rss-2.0', $withItems->format);
        self::assertSame('(c) Example', $withItems->copyright);
        self::assertSame('https://example.com/logo.png', $withItems->logoUrl);
        self::assertCount(0, $channel->items, 'the original is untouched');
    }

    public function testAnItemPrefersItsFullContentOverItsSummary(): void
    {
        $summaryOnly = new Item('1', 'T', 'https://example.com/1', summaryHtml: '<p>short</p>');
        $both = new Item('2', 'T', 'https://example.com/2', summaryHtml: '<p>short</p>', contentHtml: '<p>long</p>');

        self::assertSame('<p>short</p>', $summaryOnly->bodyHtml());
        self::assertSame('<p>long</p>', $both->bodyHtml());
    }

    public function testAnItemCarriesItsEnclosures(): void
    {
        $item = new Item('1', 'T', 'https://example.com/1', enclosures: [
            new Enclosure('https://example.com/a.mp3', 'audio/mpeg', 1024),
        ]);

        self::assertSame('https://example.com/a.mp3', $item->enclosures[0]->url);
        self::assertSame('audio/mpeg', $item->enclosures[0]->type);
        self::assertSame(1024, $item->enclosures[0]->length);
    }

    public function testACategoryRejectsANameThatCouldEscapeItsDirectory(): void
    {
        foreach (['../etc', 'a/b', 'UPPER', 'with space', '', str_repeat('x', 41), 'dot.name'] as $bad) {
            self::assertFalse(Category::isValidName($bad), $bad . ' must be refused');
        }
        foreach (['news', 'open-source', 'a_b', 'x', str_repeat('x', 40)] as $good) {
            self::assertTrue(Category::isValidName($good), $good . ' must be accepted');
        }

        $this->expectException(ConfigException::class);
        Category::assertValidName('../escape');
    }

    public function testACategoryOrdersItsFeedsByPositionAndDropsTheOnesThatCannotRender(): void
    {
        $category = new Category('news', [
            new Feed(xmlUrl: 'https://c.example/f', position: 3, showedItems: 1),
            new Feed(xmlUrl: 'https://a.example/f', position: 1, showedItems: 1),
            new Feed(xmlUrl: 'https://off.example/f', position: 2, showedItems: 1, subscribed: false),
            new Feed(xmlUrl: 'https://zero.example/f', position: 0, showedItems: 0),
        ]);

        $renderable = $category->renderableFeeds();

        self::assertCount(2, $renderable);
        self::assertSame('https://a.example/f', $renderable[0]->xmlUrl);
        self::assertSame('https://c.example/f', $renderable[1]->xmlUrl);
        self::assertSame(4, $category->count());
    }

    public function testAFeedNormalisesImpossibleNumbersAndNeedsAUrl(): void
    {
        $feed = new Feed(xmlUrl: 'https://example.com/f', position: -5, refreshMinutes: 0, showedItems: -1);

        self::assertSame(0, $feed->position);
        self::assertSame(1, $feed->refreshMinutes, 'a zero refresh interval would mean refetching on every request');
        self::assertSame(0, $feed->showedItems);
        self::assertFalse($feed->isRenderable());
        self::assertSame('https://example.com/f', $feed->label(), 'an untitled feed falls back to its URL');

        $this->expectException(ConfigException::class);
        new Feed(xmlUrl: '   ');
    }

    public function testACacheEntryExpiresOnTheIntervalItWasGiven(): void
    {
        $fetchedAt = new \DateTimeImmutable('2026-09-18T12:00:00+00:00');
        $entry = new CacheEntry('https://example.com/f', 'body', $fetchedAt);

        self::assertFalse($entry->isExpired(10, new \DateTimeImmutable('2026-09-18T12:09:59+00:00')));
        self::assertTrue($entry->isExpired(10, new \DateTimeImmutable('2026-09-18T12:10:01+00:00')));
        // A zero or negative interval is treated as one minute, as 1.6 did.
        self::assertTrue($entry->isExpired(0, new \DateTimeImmutable('2026-09-18T12:01:01+00:00')));

        $later = $entry->withFetchedAt(new \DateTimeImmutable('2026-09-18T13:00:00+00:00'));
        self::assertFalse($later->isExpired(10, new \DateTimeImmutable('2026-09-18T13:05:00+00:00')));
        self::assertSame('body', $later->body);

        self::assertSame('gone away', $entry->withError('gone away')->error);
    }

    public function testAFetchResultReportsOutcomesInThe2004Wording(): void
    {
        $cached = new FetchResult('https://e.example/f', FetchResult::CACHED);
        $notModified = new FetchResult('https://e.example/f', FetchResult::NOT_MODIFIED);
        $notExpired = new FetchResult('https://e.example/f', FetchResult::NOT_EXPIRED);
        $failed = new FetchResult('https://e.example/f', FetchResult::FAILED);

        self::assertSame('https://e.example/f - cached', $cached->legacyLine());
        self::assertSame('https://e.example/f - cached', $notModified->legacyLine());
        self::assertSame('https://e.example/f - not expired yet', $notExpired->legacyLine());
        self::assertSame('https://e.example/f - NOT cached; check connection', $failed->legacyLine());

        self::assertTrue($cached->isSuccess());
        self::assertTrue($notExpired->isSuccess());
        self::assertFalse($failed->isSuccess());
    }

    public function testARenderRequestParsesThePositionFilterAndTheMoreFlag(): void
    {
        $request = new RenderRequest(positions: 'p1,p3', moreFeed: 2);

        self::assertSame([1, 3], $request->positionFilter());
        self::assertTrue($request->wantsMore(2));
        self::assertFalse($request->wantsMore(1));

        self::assertNull((new RenderRequest())->positionFilter());
        self::assertNull((new RenderRequest(positions: '   '))->positionFilter());
        self::assertFalse((new RenderRequest())->wantsMore(0));

        self::assertTrue((new RenderRequest())->withLegacyFidelity()->legacyFidelity);
        self::assertFalse((new RenderRequest(legacyFidelity: true))->withLegacyFidelity(false)->legacyFidelity);
    }

    public function testARenderedFeedExposesTheItemsOfItsChannel(): void
    {
        $feed = new Feed(xmlUrl: 'https://example.com/f', showedItems: 3);
        $channel = (new Channel(title: 'C'))->withItems([new Item('1', 'A', 'https://example.com/1')]);

        self::assertCount(1, (new RenderedFeed($feed, $channel))->items());
        self::assertCount(0, (new RenderedFeed($feed, null))->items(), 'no channel means nothing to show');
        self::assertSame($feed, RenderedFeed::fromTriple([$feed, $channel, null])->feed);
    }

    public function testTheConsoleApplicationRegistersEveryCommand(): void
    {
        $config = Config::forTesting([], $this->tempDir());
        $application = new Application(new Kernel($config));

        $expected = [
            'refresh', 'list-feeds', 'add', 'import', 'export', 'migrate',
            'check-config', 'hash-password', 'legacy-import', 'purge', 'docs:config',
        ];
        foreach ($expected as $name) {
            self::assertTrue($application->has($name), 'bin/zfeeder must offer ' . $name);
        }

        self::assertSame(Version::NAME, $application->getName());
        self::assertSame(Version::NUMBER, $application->getVersion());
    }
}
