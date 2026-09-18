<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use Zfeeder\Storage\AtomicFile;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\CacheStoreInterface;
use Zfeeder\Storage\Flat\FileCacheStore;
use Zfeeder\Tests\Support\CacheStoreContractTestCase;

#[CoversClass(FileCacheStore::class)]
#[CoversClass(AtomicFile::class)]
#[CoversClass(CacheEntry::class)]
final class FlatCacheStoreTest extends CacheStoreContractTestCase
{
    protected function createCache(): CacheStoreInterface
    {
        return new FileCacheStore($this->tempPath('cache'));
    }

    public function testTheBodyIsStoredUnderTheSha256OfTheUrl(): void
    {
        $url = 'http://a.test/feed.xml';
        $this->cache->put(new CacheEntry($url, 'body', $this->at('2026-09-18 10:00:00')));

        $expected = $this->tempPath('cache/' . hash('sha256', $url) . '.xml');

        self::assertFileExists($expected);
        self::assertSame('body', file_get_contents($expected));
    }

    public function testTheSidecarHoldsTheConditionalRequestMetadata(): void
    {
        $url = 'http://a.test/feed.xml';
        $this->cache->put(new CacheEntry($url, 'body', $this->at('2026-09-18 10:00:00'), 'W/"e"', 'Wed, 25 Feb 2004 14:00:00 GMT', 304, 'none'));

        $raw = file_get_contents($this->tempPath('cache/' . hash('sha256', $url) . '.json'));
        self::assertIsString($raw);
        /** @var array<string, mixed> $meta */
        $meta = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);

        self::assertSame($url, $meta['url']);
        self::assertSame($this->at('2026-09-18 10:00:00')->getTimestamp(), $meta['fetchedAt']);
        self::assertSame('W/"e"', $meta['etag']);
        self::assertSame('Wed, 25 Feb 2004 14:00:00 GMT', $meta['lastModified']);
        self::assertSame(304, $meta['status']);
        self::assertSame('none', $meta['error']);
    }

    public function testCacheFilesAreNotWorldReadable(): void
    {
        $url = 'http://a.test/feed.xml';
        $this->cache->put(new CacheEntry($url, 'body', $this->at('2026-09-18 10:00:00')));

        foreach (['.xml', '.json'] as $suffix) {
            $mode = fileperms($this->tempPath('cache/' . hash('sha256', $url) . $suffix));
            self::assertNotFalse($mode);
            self::assertSame(0, $mode & 0o007, 'Cached feeds must not be world readable.');
        }
    }

    public function testPurgeRemovesBothTheBodyAndTheSidecar(): void
    {
        $url = 'http://a.test/feed.xml';
        $this->cache->put(new CacheEntry($url, 'body', $this->fromTimestamp(time() - 7200)));

        self::assertSame(1, $this->cache->purge(60));
        self::assertFileDoesNotExist($this->tempPath('cache/' . hash('sha256', $url) . '.xml'));
        self::assertFileDoesNotExist($this->tempPath('cache/' . hash('sha256', $url) . '.json'));
    }

    public function testACorruptSidecarIsAMissNotAFatalError(): void
    {
        $url = 'http://a.test/feed.xml';
        $this->cache->put(new CacheEntry($url, 'body', $this->at('2026-09-18 10:00:00')));
        file_put_contents($this->tempPath('cache/' . hash('sha256', $url) . '.json'), '{not json');

        self::assertNull($this->cache->get($url));
        self::assertSame([], $this->cache->urls());
    }

    public function testMetadataWithoutABodyIsAMiss(): void
    {
        $url = 'http://a.test/feed.xml';
        $this->cache->put(new CacheEntry($url, 'body', $this->at('2026-09-18 10:00:00')));
        unlink($this->tempPath('cache/' . hash('sha256', $url) . '.xml'));

        self::assertNull($this->cache->get($url));
    }

    public function testReadingAnAbsentCacheDirectoryIsEmptyNotAnError(): void
    {
        $cache = new FileCacheStore($this->tempPath('never-created'));

        self::assertSame([], $cache->urls());
        self::assertSame(0, $cache->purge(0));
        self::assertNull($cache->get('http://a.test/f'));
    }
}
