<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Exception\StorageException;
use Zfeeder\Exception\ZfeederException;
use Zfeeder\Storage\SubscriptionStoreInterface;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * The subscription contract, run against every backend.
 *
 * Both stores must be indistinguishable from the outside: the admin panel, the
 * renderer and the CLI are written against the interface, and the owner may
 * switch `storage` at any time. Anything that is true of one store and not the
 * other is a bug, so this case lives here and each backend only supplies a
 * configured instance.
 */
abstract class SubscriptionStoreContractTestCase extends TestCase
{
    use StorageTempDirectory;

    protected SubscriptionStoreInterface $store;

    abstract protected function createStore(): SubscriptionStoreInterface;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore();
    }

    protected function tearDown(): void
    {
        $this->releaseStore();
        $this->removeTempDir();
        parent::tearDown();
    }

    /** Hook for backends that hold an open handle (SQLite). */
    protected function releaseStore(): void
    {
    }

    public function testAnEmptyStoreHasNoCategories(): void
    {
        self::assertSame([], $this->store->categories());
        self::assertFalse($this->store->has('news'));
    }

    public function testCreateCategoryMakesAnEmptyCategory(): void
    {
        $this->store->createCategory('news');

        self::assertTrue($this->store->has('news'));
        self::assertSame(['news'], $this->store->categories());
        self::assertSame([], $this->store->category('news')->feeds);
    }

    public function testCreateCategoryTwiceIsRefused(): void
    {
        $this->store->createCategory('news');

        $this->expectException(StorageException::class);
        $this->store->createCategory('news');
    }

    public function testCategoriesAreSortedByName(): void
    {
        foreach (['technology', 'general', 'zfeeder', 'news'] as $name) {
            $this->store->createCategory($name);
        }

        self::assertSame(['general', 'news', 'technology', 'zfeeder'], $this->store->categories());
    }

    public function testSaveAndReadBackKeepsEveryFeedField(): void
    {
        $feed = new Feed(
            xmlUrl: 'http://fixtures.test/rss20-zfeeder.xml',
            title: 'zFeeder & friends <2004>',
            description: 'Quotes " and apostrophes \' survive',
            htmlUrl: 'http://zvonnews.sourceforge.net/',
            position: 1,
            refreshMinutes: 6000,
            showedItems: 3,
            subscribed: true,
            language: 'en',
        );
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [$feed]));

        $read = $this->store->category('news')->feeds[0];

        self::assertSame($feed->xmlUrl, $read->xmlUrl);
        self::assertSame($feed->title, $read->title);
        self::assertSame($feed->description, $read->description);
        self::assertSame($feed->htmlUrl, $read->htmlUrl);
        self::assertSame($feed->position, $read->position);
        self::assertSame($feed->refreshMinutes, $read->refreshMinutes);
        self::assertSame($feed->showedItems, $read->showedItems);
        self::assertSame($feed->subscribed, $read->subscribed);
        self::assertSame($feed->language, $read->language);
    }

    public function testUnsubscribedAndZeroItemFeedsAreStoredButNotRendered(): void
    {
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [
            new Feed('http://a.test/feed.xml', 'A', position: 1, subscribed: false),
            new Feed('http://b.test/feed.xml', 'B', position: 2, showedItems: 0),
            new Feed('http://c.test/feed.xml', 'C', position: 3),
        ]));

        $category = $this->store->category('news');

        self::assertCount(3, $category->feeds);
        self::assertSame(['http://c.test/feed.xml'], array_map(
            static fn (Feed $f): string => $f->xmlUrl,
            $category->renderableFeeds(),
        ));
    }

    public function testFeedsComeBackInPositionOrder(): void
    {
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [
            new Feed('http://c.test/feed.xml', 'C', position: 3),
            new Feed('http://a.test/feed.xml', 'A', position: 1),
            new Feed('http://b.test/feed.xml', 'B', position: 2),
        ]));

        self::assertSame(['A', 'B', 'C'], array_map(
            static fn (Feed $f): string => $f->title,
            $this->store->category('news')->feeds,
        ));
    }

    public function testSavingReplacesThePreviousSubscriptionList(): void
    {
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [
            new Feed('http://a.test/feed.xml', 'A', position: 1),
            new Feed('http://b.test/feed.xml', 'B', position: 2),
        ]));
        $this->store->saveCategory(new Category('news', [
            new Feed('http://c.test/feed.xml', 'C', position: 1),
        ]));

        $feeds = $this->store->category('news')->feeds;

        self::assertCount(1, $feeds);
        self::assertSame('http://c.test/feed.xml', $feeds[0]->xmlUrl);
    }

    public function testHeadMetadataSurvivesASave(): void
    {
        $date = new \DateTimeImmutable('2004-02-24 13:32:52', new \DateTimeZone('UTC'));
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [], $date, 'Andrei', 'owner@example.org'));

        $category = $this->store->category('news');

        self::assertNotNull($category->dateModified);
        self::assertSame($date->getTimestamp(), $category->dateModified->getTimestamp());
        self::assertSame('Andrei', $category->ownerName);
        self::assertSame('owner@example.org', $category->ownerEmail);
    }

    public function testDeleteRemovesTheCategory(): void
    {
        $this->store->createCategory('news');
        $this->store->deleteCategory('news');

        self::assertFalse($this->store->has('news'));
        self::assertSame([], $this->store->categories());
    }

    public function testDeletingAnUnknownCategoryThrows(): void
    {
        $this->expectException(StorageException::class);
        $this->store->deleteCategory('news');
    }

    public function testReadingAnUnknownCategoryThrows(): void
    {
        $this->expectException(StorageException::class);
        $this->store->category('news');
    }

    public function testExportingAnUnknownCategoryThrows(): void
    {
        $this->expectException(StorageException::class);
        $this->store->exportOpml('news');
    }

    /** @return list<array{string}> */
    public static function invalidNameProvider(): array
    {
        return [
            ['News'],
            ['news feed'],
            ['news.opml'],
            ['a/b'],
            [''],
            [str_repeat('a', 41)],
            ["news\0"],
        ];
    }

    #[DataProvider('invalidNameProvider')]
    public function testInvalidNamesAreRefused(string $name): void
    {
        self::assertFalse($this->store->has($name));

        $this->expectException(ZfeederException::class);
        $this->store->createCategory($name);
    }

    public function testTraversalAttemptsAreRefused(): void
    {
        $outside = $this->tempPath('escaped.opml');

        foreach (['../escaped', '../../etc/passwd', '..%2Fescaped', './news'] as $name) {
            $threw = false;

            try {
                $this->store->createCategory($name);
            } catch (ZfeederException) {
                $threw = true;
            }
            self::assertTrue($threw, sprintf('Expected "%s" to be refused.', $name));
        }

        self::assertFileDoesNotExist($outside);
        self::assertSame([], $this->store->categories());
    }

    public function testOpmlExportAndImportRoundTripsACategory(): void
    {
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [
            new Feed('http://a.test/feed.xml', 'A & B', 'Desc <b>', 'http://a.test/', 1, 120, 5, true, 'en'),
            new Feed('http://b.test/feed.xml', 'B', '', '', 2, 60, 0, false, ''),
        ], new \DateTimeImmutable('2004-02-24 13:32:52', new \DateTimeZone('UTC'))));

        $opml = $this->store->exportOpml('news');
        $imported = $this->store->importOpml('copy', $opml, true);

        self::assertSame(2, $imported);
        self::assertEquals(
            $this->store->category('news')->feeds,
            $this->store->category('copy')->feeds,
        );
    }

    public function testImportWithReplaceDropsTheOldSubscriptions(): void
    {
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [new Feed('http://old.test/feed.xml', 'Old', position: 1)]));

        $count = $this->store->importOpml('news', $this->opmlWithOneFeed('http://new.test/feed.xml'), true);

        self::assertSame(1, $count);
        self::assertSame(['http://new.test/feed.xml'], array_map(
            static fn (Feed $f): string => $f->xmlUrl,
            $this->store->category('news')->feeds,
        ));
    }

    public function testImportWithoutReplaceAppendsAndRenumbers(): void
    {
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [
            new Feed('http://old.test/feed.xml', 'Old', position: 7),
        ]));

        $count = $this->store->importOpml('news', $this->opmlWithOneFeed('http://new.test/feed.xml'), false);
        $feeds = $this->store->category('news')->feeds;

        self::assertSame(1, $count);
        self::assertCount(2, $feeds);
        self::assertSame('http://old.test/feed.xml', $feeds[0]->xmlUrl);
        self::assertSame(7, $feeds[0]->position);
        self::assertSame('http://new.test/feed.xml', $feeds[1]->xmlUrl);
        self::assertSame(8, $feeds[1]->position);
    }

    public function testImportCreatesAMissingCategory(): void
    {
        $count = $this->store->importOpml('fresh', $this->opmlWithOneFeed('http://new.test/feed.xml'), true);

        self::assertSame(1, $count);
        self::assertTrue($this->store->has('fresh'));
        self::assertCount(1, $this->store->category('fresh')->feeds);
    }

    public function testImportRefusesAnInvalidCategoryName(): void
    {
        $this->expectException(ZfeederException::class);
        $this->store->importOpml('../escape', $this->opmlWithOneFeed('http://new.test/feed.xml'), true);
    }

    public function testEveryLegacySubscriptionFileImports(): void
    {
        $files = glob(\dirname(__DIR__, 2) . '/tests/fixtures/legacy-1.6/newsfeeds/categories/*.opml');
        self::assertIsArray($files);
        self::assertNotCount(0, $files);

        foreach ($files as $file) {
            $name = basename($file, '.opml');
            $opml = file_get_contents($file);
            self::assertIsString($opml);

            $count = $this->store->importOpml($name, $opml, true);

            self::assertCount($count, $this->store->category($name)->feeds, 'Category ' . $name);
        }
    }

    private function opmlWithOneFeed(string $url): string
    {
        return '<?xml version="1.0"?><opml version="2.0"><head><title>x</title></head><body>'
            . '<outline type="rss" position="1" text="New" title="New" description="" xmlUrl="' . $url . '"'
            . ' htmlUrl="" refreshTime="60" showedItems="3" isSubscribed="yes" language="" />'
            . '</body></opml>';
    }
}
