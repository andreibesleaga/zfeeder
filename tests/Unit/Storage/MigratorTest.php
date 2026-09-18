<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zfeeder\Exception\StorageException;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\CacheStoreInterface;
use Zfeeder\Storage\Flat\FileCacheStore;
use Zfeeder\Storage\Flat\OpmlSubscriptionStore;
use Zfeeder\Storage\Migrator;
use Zfeeder\Storage\Sqlite\Database;
use Zfeeder\Storage\Sqlite\SqliteCacheStore;
use Zfeeder\Storage\Sqlite\SqliteSubscriptionStore;
use Zfeeder\Storage\SubscriptionStoreInterface;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Tests\Support\StorageTempDirectory;

#[CoversClass(Migrator::class)]
final class MigratorTest extends TestCase
{
    use StorageTempDirectory;

    private ?Database $database = null;

    protected function tearDown(): void
    {
        $this->database?->close();
        $this->database = null;
        $this->removeTempDir();
        parent::tearDown();
    }

    public function testFlatToSqliteAndBackPreservesEverything(): void
    {
        $flat = new OpmlSubscriptionStore($this->tempPath('categories'));
        $flatCache = new FileCacheStore($this->tempPath('cache'));
        $this->seed($flat, $flatCache);

        $this->database = new Database($this->tempPath('zfeeder.sqlite'));
        $sqlite = new SqliteSubscriptionStore($this->database);
        $sqliteCache = new SqliteCacheStore($this->database);

        $forward = (new Migrator())->migrate($flat, $sqlite, $flatCache, $sqliteCache);

        self::assertSame(['categories' => 2, 'feeds' => 3, 'cache' => 2], $forward);

        $backFlat = new OpmlSubscriptionStore($this->tempPath('categories-back'));
        $backCache = new FileCacheStore($this->tempPath('cache-back'));
        $backward = (new Migrator())->migrate($sqlite, $backFlat, $sqliteCache, $backCache);

        self::assertSame(['categories' => 2, 'feeds' => 3, 'cache' => 2], $backward);
        self::assertSame($flat->categories(), $backFlat->categories());

        foreach ($flat->categories() as $name) {
            self::assertEquals($flat->category($name)->feeds, $backFlat->category($name)->feeds, 'Category ' . $name);
            self::assertSame(
                $flat->category($name)->dateModified?->getTimestamp(),
                $backFlat->category($name)->dateModified?->getTimestamp(),
            );
            self::assertSame($flat->category($name)->ownerName, $backFlat->category($name)->ownerName);
        }

        self::assertSame($flatCache->urls(), $backCache->urls());
        foreach ($flatCache->urls() as $url) {
            $source = $flatCache->get($url);
            $copy = $backCache->get($url);
            self::assertNotNull($source);
            self::assertNotNull($copy);
            self::assertSame($source->body, $copy->body);
            self::assertSame($source->etag, $copy->etag);
            self::assertSame($source->fetchedAt->getTimestamp(), $copy->fetchedAt->getTimestamp());
        }
    }

    public function testSubscriptionsCanBeMigratedWithoutACache(): void
    {
        $flat = new OpmlSubscriptionStore($this->tempPath('categories'));
        $flat->createCategory('news');
        $flat->saveCategory(new Category('news', [new Feed('http://a.test/f', 'A')]));

        $this->database = new Database($this->tempPath('zfeeder.sqlite'));
        $counts = (new Migrator())->migrate($flat, new SqliteSubscriptionStore($this->database));

        self::assertSame(['categories' => 1, 'feeds' => 1, 'cache' => 0], $counts);
    }

    public function testMigratingIntoAnExistingCategoryOverwritesIt(): void
    {
        $flat = new OpmlSubscriptionStore($this->tempPath('categories'));
        $flat->createCategory('news');
        $flat->saveCategory(new Category('news', [new Feed('http://new.test/f', 'New')]));

        $target = new OpmlSubscriptionStore($this->tempPath('target'));
        $target->createCategory('news');
        $target->saveCategory(new Category('news', [
            new Feed('http://stale.test/f', 'Stale'),
            new Feed('http://stale2.test/f', 'Stale too', position: 2),
        ]));

        (new Migrator())->migrate($flat, $target);

        self::assertSame(['http://new.test/f'], array_map(
            static fn (Feed $f): string => $f->xmlUrl,
            $target->category('news')->feeds,
        ));
    }

    public function testVerificationFailsWhenTheDestinationDropsAFeed(): void
    {
        $flat = new OpmlSubscriptionStore($this->tempPath('categories'));
        $flat->createCategory('news');
        $flat->saveCategory(new Category('news', [
            new Feed('http://a.test/f', 'A', position: 1),
            new Feed('http://b.test/f', 'B', position: 2),
        ]));

        $lossy = $this->lossyStore(new OpmlSubscriptionStore($this->tempPath('target')));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('copied 1 of 2');
        (new Migrator())->migrate($flat, $lossy);
    }

    public function testVerificationFailsWhenTheCacheIsNotCopied(): void
    {
        $flat = new OpmlSubscriptionStore($this->tempPath('categories'));
        $source = new FileCacheStore($this->tempPath('cache'));
        $source->put(new CacheEntry('http://a.test/f', 'body', new \DateTimeImmutable('@1000000000')));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('lost the cached feed');
        (new Migrator())->migrate($flat, new OpmlSubscriptionStore($this->tempPath('target')), $source, $this->blackHoleCache());
    }

    private function seed(SubscriptionStoreInterface $store, CacheStoreInterface $cache): void
    {
        $store->createCategory('news');
        $store->saveCategory(new Category(
            'news',
            [
                new Feed('http://a.test/f', 'A & co', 'Desc', 'http://a.test/', 1, 120, 5, true, 'en'),
                new Feed('http://b.test/f', 'B', '', '', 2, 60, 0, false, ''),
            ],
            new \DateTimeImmutable('2004-02-24 13:32:52', new \DateTimeZone('UTC')),
            'Andrei',
            'owner@example.org',
        ));
        $store->createCategory('technology');
        $store->saveCategory(new Category('technology', [new Feed('http://c.test/f', 'C', position: 1)]));

        $cache->put(new CacheEntry('http://a.test/f', '<rss>a</rss>', new \DateTimeImmutable('@1700000000'), 'etag-a'));
        $cache->put(new CacheEntry('http://c.test/f', '<rss>c</rss>', new \DateTimeImmutable('@1700000100')));
    }

    /** A destination that silently keeps only the first feed of a category. */
    private function lossyStore(SubscriptionStoreInterface $inner): SubscriptionStoreInterface
    {
        return new class ($inner) implements SubscriptionStoreInterface {
            public function __construct(private readonly SubscriptionStoreInterface $inner)
            {
            }

            public function categories(): array
            {
                return $this->inner->categories();
            }

            public function has(string $name): bool
            {
                return $this->inner->has($name);
            }

            public function category(string $name): Category
            {
                return $this->inner->category($name);
            }

            public function saveCategory(Category $category): void
            {
                $feeds = \array_slice($category->feeds, 0, 1);
                $this->inner->saveCategory(new Category($category->name, $feeds));
            }

            public function createCategory(string $name): void
            {
                $this->inner->createCategory($name);
            }

            public function deleteCategory(string $name): void
            {
                $this->inner->deleteCategory($name);
            }

            public function exportOpml(string $name): string
            {
                return $this->inner->exportOpml($name);
            }

            public function importOpml(string $name, string $opml, bool $replace): int
            {
                return $this->inner->importOpml($name, $opml, $replace);
            }
        };
    }

    /** A destination cache that accepts everything and keeps nothing. */
    private function blackHoleCache(): CacheStoreInterface
    {
        return new class () implements CacheStoreInterface {
            public function get(string $url): ?CacheEntry
            {
                return null;
            }

            public function put(CacheEntry $entry): void
            {
            }

            public function delete(string $url): void
            {
            }

            public function purge(int $olderThanSeconds): int
            {
                return 0;
            }

            /** @return list<string> */
            public function urls(): array
            {
                return [];
            }
        };
    }
}
