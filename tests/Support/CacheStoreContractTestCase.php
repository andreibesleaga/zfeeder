<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Support;

use PHPUnit\Framework\TestCase;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\CacheStoreInterface;

/**
 * The cache contract, run against every backend.
 *
 * Time is always supplied by the test: entries are written with explicit
 * `fetchedAt` values in the past, so `purge()` can be exercised without a
 * `sleep()` and the result is the same on a fast machine and a loaded one.
 */
abstract class CacheStoreContractTestCase extends TestCase
{
    use StorageTempDirectory;

    protected CacheStoreInterface $cache;

    abstract protected function createCache(): CacheStoreInterface;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->createCache();
    }

    protected function tearDown(): void
    {
        $this->releaseCache();
        $this->removeTempDir();
        parent::tearDown();
    }

    /** Hook for backends that hold an open handle (SQLite). */
    protected function releaseCache(): void
    {
    }

    public function testAnEmptyCacheMissesAndListsNothing(): void
    {
        self::assertNull($this->cache->get('http://a.test/feed.xml'));
        self::assertSame([], $this->cache->urls());
    }

    public function testPutThenGetReturnsEveryField(): void
    {
        $entry = new CacheEntry(
            url: 'http://a.test/feed.xml',
            body: '<?xml version="1.0"?><rss><channel><title>Ünïcode &amp; <b></title></channel></rss>',
            fetchedAt: $this->at('2026-09-18 10:00:00'),
            etag: 'W/"abc123"',
            lastModified: 'Wed, 25 Feb 2004 14:00:00 GMT',
            status: 200,
            error: '',
        );

        $this->cache->put($entry);
        $read = $this->cache->get('http://a.test/feed.xml');

        self::assertNotNull($read);
        self::assertSame($entry->url, $read->url);
        self::assertSame($entry->body, $read->body);
        self::assertSame($entry->fetchedAt->getTimestamp(), $read->fetchedAt->getTimestamp());
        self::assertSame($entry->etag, $read->etag);
        self::assertSame($entry->lastModified, $read->lastModified);
        self::assertSame($entry->status, $read->status);
        self::assertSame($entry->error, $read->error);
    }

    public function testMissingValidatorsStayNull(): void
    {
        $this->cache->put(new CacheEntry('http://a.test/feed.xml', 'body', $this->at('2026-09-18 10:00:00')));

        $read = $this->cache->get('http://a.test/feed.xml');

        self::assertNotNull($read);
        self::assertNull($read->etag);
        self::assertNull($read->lastModified);
        self::assertSame(200, $read->status);
        self::assertSame('', $read->error);
    }

    public function testAFailedFetchCanBeRecordedAgainstTheStaleBody(): void
    {
        $this->cache->put(new CacheEntry(
            'http://a.test/feed.xml',
            'stale body',
            $this->at('2026-09-18 10:00:00'),
            status: 500,
            error: 'Connection timed out',
        ));

        $read = $this->cache->get('http://a.test/feed.xml');

        self::assertNotNull($read);
        self::assertSame('stale body', $read->body);
        self::assertSame(500, $read->status);
        self::assertSame('Connection timed out', $read->error);
    }

    public function testPutOverwritesTheSameUrl(): void
    {
        $url = 'http://a.test/feed.xml';
        $this->cache->put(new CacheEntry($url, 'first', $this->at('2026-09-18 10:00:00'), 'e1'));
        $this->cache->put(new CacheEntry($url, 'second', $this->at('2026-09-18 11:00:00'), 'e2'));

        $read = $this->cache->get($url);

        self::assertNotNull($read);
        self::assertSame('second', $read->body);
        self::assertSame('e2', $read->etag);
        self::assertSame([$url], $this->cache->urls());
    }

    public function testDifferentUrlsDoNotCollide(): void
    {
        // These two collapse onto one file under the 1.6 naming scheme, which is
        // exactly the bug the sha256 key exists to avoid.
        $this->cache->put(new CacheEntry('http://a.test/x', 'one', $this->at('2026-09-18 10:00:00')));
        $this->cache->put(new CacheEntry('http://a.test_x', 'two', $this->at('2026-09-18 10:00:00')));

        $first = $this->cache->get('http://a.test/x');
        $second = $this->cache->get('http://a.test_x');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('one', $first->body);
        self::assertSame('two', $second->body);
        self::assertCount(2, $this->cache->urls());
    }

    public function testUrlsListsEveryEntrySorted(): void
    {
        foreach (['http://c.test/f', 'http://a.test/f', 'http://b.test/f'] as $url) {
            $this->cache->put(new CacheEntry($url, 'body', $this->at('2026-09-18 10:00:00')));
        }

        self::assertSame(['http://a.test/f', 'http://b.test/f', 'http://c.test/f'], $this->cache->urls());
    }

    public function testDeleteRemovesTheEntry(): void
    {
        $this->cache->put(new CacheEntry('http://a.test/f', 'body', $this->at('2026-09-18 10:00:00')));
        $this->cache->delete('http://a.test/f');

        self::assertNull($this->cache->get('http://a.test/f'));
        self::assertSame([], $this->cache->urls());
    }

    public function testDeletingAnUnknownUrlIsHarmless(): void
    {
        $this->cache->delete('http://a.test/never-fetched');

        self::assertSame([], $this->cache->urls());
    }

    public function testPurgeRemovesOnlyEntriesOlderThanTheWindow(): void
    {
        $now = time();
        $this->cache->put(new CacheEntry('http://old.test/f', 'old', $this->fromTimestamp($now - 7200)));
        $this->cache->put(new CacheEntry('http://fresh.test/f', 'fresh', $this->fromTimestamp($now - 60)));

        $removed = $this->cache->purge(1800);

        self::assertSame(1, $removed);
        self::assertNull($this->cache->get('http://old.test/f'));
        self::assertNotNull($this->cache->get('http://fresh.test/f'));
        self::assertSame(['http://fresh.test/f'], $this->cache->urls());
    }

    public function testPurgeOfAnEmptyCacheRemovesNothing(): void
    {
        self::assertSame(0, $this->cache->purge(0));
    }

    public function testEntryExpiryFollowsTheRefreshWindow(): void
    {
        $entry = new CacheEntry('http://a.test/f', 'body', $this->at('2026-09-18 10:00:00'));

        self::assertFalse($entry->isExpired(60, $this->at('2026-09-18 10:59:00')));
        self::assertTrue($entry->isExpired(60, $this->at('2026-09-18 11:01:00')));
    }

    protected function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    protected function fromTimestamp(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }
}
