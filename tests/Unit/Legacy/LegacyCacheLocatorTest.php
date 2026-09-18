<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Legacy\LegacyCacheLocator;
use Zfeeder\Tests\Support\StorageTempDirectory;

#[CoversClass(LegacyCacheLocator::class)]
final class LegacyCacheLocatorTest extends TestCase
{
    use StorageTempDirectory;

    private LegacyCacheLocator $locator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locator = new LegacyCacheLocator();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> the exact names 1.6 would have produced */
    public static function urlProvider(): array
    {
        return [
            'project feed' => [
                'http://zvonnews.sf.net/news/rss.php',
                'http___zvonnews_sf_net_news_rss_php.xml',
            ],
            'query string' => [
                'http://sourceforge.net/export/rss2_projnews.php?group_id=84348',
                'http___sourceforge_net_export_rss2_projnews_php_group_id_84348.xml',
            ],
            'https and port' => [
                'https://example.org:8080/feed',
                'https___example_org_8080_feed.xml',
            ],
            'already alphanumeric' => ['feed', 'feed.xml'],
            // 1.6 worked on bytes, so a two-byte character becomes two underscores.
            'unicode' => ['http://exämple.org/f', 'http___ex__mple_org_f.xml'],
            'empty' => ['', '.xml'],
        ];
    }

    #[DataProvider('urlProvider')]
    public function testTheFileNameMatchesTheNineteenNinetiesScheme(string $url, string $expected): void
    {
        self::assertSame($expected, $this->locator->filename($url));
    }

    /** The same rule 1.6's ereg_replace applied, expressed with PCRE. */
    public function testTheNameContainsNothingButAlphanumericsAndUnderscores(): void
    {
        $name = $this->locator->filename('http://a.test/feed.xml?x=1&y=2#frag');

        self::assertSame(1, preg_match('/^[a-zA-Z0-9_]+\.xml$/', $name));
    }

    public function testFindReturnsNullWhenNothingWasCached(): void
    {
        self::assertNull($this->locator->find($this->tempDir(), 'http://a.test/feed.xml'));
        self::assertNull($this->locator->read($this->tempDir(), 'http://a.test/feed.xml'));
        self::assertNull($this->locator->fetchedAt($this->tempDir(), 'http://a.test/feed.xml'));
    }

    public function testFindReturnsTheFileAZfeederSixteenWouldHaveWritten(): void
    {
        $url = 'http://zvonnews.sf.net/news/rss.php';
        $path = $this->tempPath($this->locator->filename($url));
        file_put_contents($path, '<rss version="2.0"><channel><title>zFeeder</title></channel></rss>');
        touch($path, 1077630000);

        self::assertSame($path, $this->locator->find($this->tempDir(), $url));
        self::assertSame(
            '<rss version="2.0"><channel><title>zFeeder</title></channel></rss>',
            $this->locator->read($this->tempDir(), $url),
        );
        $fetchedAt = $this->locator->fetchedAt($this->tempDir(), $url);
        self::assertNotNull($fetchedAt);
        self::assertSame(1077630000, $fetchedAt->getTimestamp());
    }

    public function testATrailingSlashOnTheDirectoryIsHarmless(): void
    {
        $url = 'http://a.test/feed.xml';
        file_put_contents($this->tempPath($this->locator->filename($url)), 'body');

        self::assertNotNull($this->locator->find($this->tempDir() . '/', $url));
    }

    /** Documented, deliberate: the 1.6 scheme is not injective, which is why 2.0 hashes. */
    public function testTheLegacySchemeCollidesForDifferentUrls(): void
    {
        self::assertSame(
            $this->locator->filename('http://a.test/x'),
            $this->locator->filename('http://a.test_x'),
        );
    }
}
