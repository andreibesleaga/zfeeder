<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use Zfeeder\Storage\AbstractSubscriptionStore;
use Zfeeder\Storage\Sqlite\Database;
use Zfeeder\Storage\Sqlite\SqliteSubscriptionStore;
use Zfeeder\Storage\SubscriptionStoreInterface;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Tests\Support\SubscriptionStoreContractTestCase;

#[CoversClass(SqliteSubscriptionStore::class)]
#[CoversClass(AbstractSubscriptionStore::class)]
#[CoversClass(Database::class)]
final class SqliteSubscriptionStoreTest extends SubscriptionStoreContractTestCase
{
    private ?Database $database = null;

    protected function createStore(): SubscriptionStoreInterface
    {
        $this->database = new Database($this->tempPath('zfeeder.sqlite'));

        return new SqliteSubscriptionStore($this->database);
    }

    protected function releaseStore(): void
    {
        $this->database?->close();
        $this->database = null;
    }

    public function testDeletingACategoryCascadesToItsFeeds(): void
    {
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [new Feed('http://a.test/f', 'A')]));
        $this->store->deleteCategory('news');

        self::assertSame(0, $this->countFeedRows());
    }

    public function testSavingReplacesFeedRowsRatherThanAccumulating(): void
    {
        $this->store->createCategory('news');
        for ($i = 0; $i < 3; ++$i) {
            $this->store->saveCategory(new Category('news', [
                new Feed('http://a.test/f', 'A', position: 1),
                new Feed('http://b.test/f', 'B', position: 2),
            ]));
        }

        self::assertSame(2, $this->countFeedRows());
    }

    /** Values are bound, never interpolated: SQL metacharacters are just text. */
    public function testSqlMetacharactersInFeedFieldsAreStoredVerbatim(): void
    {
        $nasty = "'); DROP TABLE feeds; --";
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [
            new Feed('http://a.test/f?a=1&b=2', $nasty, $nasty, '', 1, 60, 3, true, $nasty),
        ]));

        $feed = $this->store->category('news')->feeds[0];

        self::assertSame($nasty, $feed->title);
        self::assertSame($nasty, $feed->description);
        self::assertSame(1, $this->countFeedRows());
    }

    public function testTheDatabaseFileIsCreatedAndMigrated(): void
    {
        $this->store->createCategory('news');

        self::assertFileExists($this->tempPath('zfeeder.sqlite'));
        self::assertNotNull($this->database);
        self::assertSame(1, $this->database->version());
    }

    private function countFeedRows(): int
    {
        self::assertNotNull($this->database);
        $statement = $this->database->pdo()->query('SELECT COUNT(*) AS n FROM feeds');
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return (int) $row['n'];
    }
}
