<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zfeeder\Config\Config;
use Zfeeder\Storage\Flat\FileCacheStore;
use Zfeeder\Storage\Flat\OpmlSubscriptionStore;
use Zfeeder\Storage\Sqlite\SqliteCacheStore;
use Zfeeder\Storage\Sqlite\SqliteSubscriptionStore;
use Zfeeder\Storage\StoreFactory;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Tests\Support\StorageTempDirectory;

#[CoversClass(StoreFactory::class)]
final class StoreFactoryTest extends TestCase
{
    use StorageTempDirectory;

    private ?StoreFactory $factory = null;

    protected function tearDown(): void
    {
        $this->factory?->database()->close();
        $this->factory = null;
        $this->removeTempDir();
        parent::tearDown();
    }

    public function testFlatStorageYieldsTheFileBackedStores(): void
    {
        $factory = new StoreFactory(Config::forTesting(['storage' => 'flat'], $this->tempDir()));

        self::assertInstanceOf(OpmlSubscriptionStore::class, $factory->subscriptions());
        self::assertInstanceOf(FileCacheStore::class, $factory->cache());
    }

    public function testFlatStoresUseTheDerivedDataDirectories(): void
    {
        $config = Config::forTesting(['storage' => 'flat'], $this->tempDir());
        $factory = new StoreFactory($config);

        $subscriptions = $factory->subscriptions();
        self::assertInstanceOf(OpmlSubscriptionStore::class, $subscriptions);
        self::assertSame($this->tempDir() . '/categories', $subscriptions->directory());

        $cache = $factory->cache();
        self::assertInstanceOf(FileCacheStore::class, $cache);
        self::assertSame($this->tempDir() . '/cache', $cache->directory());
    }

    public function testSqliteStorageYieldsTheDatabaseBackedStores(): void
    {
        $this->factory = new StoreFactory(Config::forTesting(['storage' => 'sqlite'], $this->tempDir()));

        self::assertInstanceOf(SqliteSubscriptionStore::class, $this->factory->subscriptions());
        self::assertInstanceOf(SqliteCacheStore::class, $this->factory->cache());
        self::assertFileExists($this->tempDir() . '/zfeeder.sqlite');
        self::assertSame(1, $this->factory->database()->version());
    }

    public function testBothSqliteStoresShareOneConnection(): void
    {
        $this->factory = new StoreFactory(Config::forTesting(['storage' => 'sqlite'], $this->tempDir()));
        $subscriptions = $this->factory->subscriptions();
        $cache = $this->factory->cache();

        $subscriptions->createCategory('news');
        $subscriptions->saveCategory(new Category('news', [new Feed('http://a.test/f', 'A')]));

        self::assertSame(['news'], $this->factory->subscriptions()->categories());
        self::assertSame([], $cache->urls());
    }
}
