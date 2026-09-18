<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\CacheStoreInterface;
use Zfeeder\Storage\Sqlite\Database;
use Zfeeder\Storage\Sqlite\SqliteCacheStore;
use Zfeeder\Tests\Support\CacheStoreContractTestCase;

#[CoversClass(SqliteCacheStore::class)]
#[CoversClass(Database::class)]
final class SqliteCacheStoreTest extends CacheStoreContractTestCase
{
    private ?Database $database = null;

    protected function createCache(): CacheStoreInterface
    {
        $this->database = new Database($this->tempPath('zfeeder.sqlite'));

        return new SqliteCacheStore($this->database);
    }

    protected function releaseCache(): void
    {
        $this->database?->close();
        $this->database = null;
    }

    public function testOneRowPerUrl(): void
    {
        $url = 'http://a.test/feed.xml';
        $this->cache->put(new CacheEntry($url, 'first', $this->at('2026-09-18 10:00:00')));
        $this->cache->put(new CacheEntry($url, 'second', $this->at('2026-09-18 11:00:00')));

        self::assertSame(1, $this->countRows());
    }

    public function testABodyWithNullBytesAndBinaryNoiseSurvives(): void
    {
        $body = "\x00\x01<rss>\xE2\x82\xAC</rss>\x00";
        $this->cache->put(new CacheEntry('http://a.test/f', $body, $this->at('2026-09-18 10:00:00')));

        $read = $this->cache->get('http://a.test/f');

        self::assertNotNull($read);
        self::assertSame($body, $read->body);
    }

    public function testPurgeDeletesRows(): void
    {
        $this->cache->put(new CacheEntry('http://old.test/f', 'old', $this->fromTimestamp(time() - 7200)));
        $this->cache->put(new CacheEntry('http://fresh.test/f', 'new', $this->fromTimestamp(time())));

        self::assertSame(1, $this->cache->purge(600));
        self::assertSame(1, $this->countRows());
    }

    private function countRows(): int
    {
        self::assertNotNull($this->database);
        $statement = $this->database->pdo()->query('SELECT COUNT(*) AS n FROM cache');
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return (int) $row['n'];
    }
}
