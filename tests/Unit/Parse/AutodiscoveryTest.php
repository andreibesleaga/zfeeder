<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Parse;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Parse\Autodiscovery;

#[CoversClass(Autodiscovery::class)]
final class AutodiscoveryTest extends TestCase
{
    private Autodiscovery $discovery;

    protected function setUp(): void
    {
        $this->discovery = new Autodiscovery();
    }

    public function testAPageDeclaringSeveralFeedsReturnsThemInDocumentOrder(): void
    {
        $found = $this->discover('two-feeds.html', 'https://blog.example.com/posts/hello.html');

        self::assertSame(
            [
                'https://blog.example.com/feed.xml',
                'https://blog.example.com/posts/atom.xml',
                'https://cdn.example.org/feeds/blog.json',
            ],
            array_column($found, 'url'),
        );

        self::assertSame('application/rss+xml', $found[0]['type']);
        self::assertSame('Example Weblog RSS', $found[0]['title']);
        self::assertSame('application/atom+xml', $found[1]['type']);
        self::assertSame('application/feed+json', $found[2]['type']);
    }

    public function testNonFeedAlternatesAreIgnored(): void
    {
        $urls = array_column($this->discover('two-feeds.html', 'https://blog.example.com/posts/hello.html'), 'url');

        self::assertNotContains('https://blog.example.com/print', $urls, 'text/html is not a feed.');
        self::assertNotContains('https://blog.example.com/style.css', $urls, 'A stylesheet is not an alternate.');
    }

    public function testTwoSpellingsOfOneUrlAreReturnedOnce(): void
    {
        // The fixture declares /feed.xml and /blog/../feed.xml.
        $urls = array_column($this->discover('two-feeds.html', 'https://blog.example.com/posts/hello.html'), 'url');

        self::assertSame(array_values(array_unique($urls)), $urls);
        self::assertCount(3, $urls);
    }

    public function testRelTokenListsCount(): void
    {
        // The Atom link in the fixture is rel="alternate home".
        $urls = array_column($this->discover('two-feeds.html', 'https://blog.example.com/posts/hello.html'), 'url');

        self::assertContains('https://blog.example.com/posts/atom.xml', $urls);
    }

    public function testBaseHrefWinsOverThePageUrl(): void
    {
        $found = $this->discover('base-href.html', 'https://origin.example.com/deep/page.html');

        self::assertSame(
            [
                'https://static.example.net/site/feeds/news.xml',
                'https://static.example.net/absolute/atom.xml',
            ],
            array_column($found, 'url'),
        );
    }

    public function testUppercaseRelAndTypeAreRecognised(): void
    {
        $found = $this->discover('base-href.html', 'https://origin.example.com/deep/page.html');

        self::assertSame('application/rss+xml', $found[0]['type']);
    }

    public function testSchemeRelativeHrefsInheritThePageScheme(): void
    {
        $found = $this->discover('scheme-relative.html', 'https://www.example.com/index.html');

        self::assertSame(
            ['https://cdn.example.com/rss/latest.xml', 'https://cdn.example.com/rss/latest.json'],
            array_column($found, 'url'),
        );
        self::assertSame('application/rss+xml', $found[0]['type'], 'A charset parameter is not part of the type.');
        self::assertSame('application/json', $found[1]['type']);
    }

    public function testSchemeRelativeHrefsOverPlainHttp(): void
    {
        $found = $this->discover('scheme-relative.html', 'http://www.example.com/index.html');

        self::assertSame('http://cdn.example.com/rss/latest.xml', $found[0]['url']);
    }

    public function testAPageDeclaringNothingReturnsNothing(): void
    {
        self::assertSame([], $this->discover('no-feeds.html', 'https://plain.example.com/'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function nonPages(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n "];
        yield 'plain text' => ['just some text, no markup at all'];
        yield 'binary-ish' => ["\x00\x01\x02\x03"];
        yield 'json' => ['{"not":"html"}'];
    }

    #[DataProvider('nonPages')]
    public function testUnparseableInputDeclaresNoFeedsRatherThanRaising(string $html): void
    {
        self::assertSame([], $this->discovery->discover($html, 'https://x.example/'));
    }

    public function testMalformedMarkupStillYieldsItsLinks(): void
    {
        $html = '<html><head><link rel=alternate type=application/rss+xml href=/feed.xml>'
            . '<title>No closing tags<body><p>Hi<div><b>bold</i>';

        $found = $this->discovery->discover($html, 'https://messy.example/page');

        self::assertSame('https://messy.example/feed.xml', $found[0]['url']);
    }

    public function testAFeedLinkWithoutATitleStillCounts(): void
    {
        $found = $this->discovery->discover(
            '<link rel="alternate" type="application/atom+xml" href="/atom">',
            'https://x.example/',
        );

        self::assertCount(1, $found);
        self::assertSame('', $found[0]['title']);
    }

    public function testAnEmptyHrefIsSkipped(): void
    {
        $found = $this->discovery->discover(
            '<link rel="alternate" type="application/rss+xml" href="">'
            . '<link rel="alternate" type="application/rss+xml" href="/real.xml">',
            'https://x.example/page',
        );

        self::assertSame(['https://x.example/real.xml'], array_column($found, 'url'));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function references(): iterable
    {
        yield 'absolute' => ['https://other.example/f.xml', 'https://a.example/dir/page.html', 'https://other.example/f.xml'];
        yield 'scheme relative' => ['//cdn.example/f.xml', 'https://a.example/dir/page.html', 'https://cdn.example/f.xml'];
        yield 'root relative' => ['/f.xml', 'https://a.example/dir/page.html', 'https://a.example/f.xml'];
        yield 'path relative' => ['f.xml', 'https://a.example/dir/page.html', 'https://a.example/dir/f.xml'];
        yield 'parent relative' => ['../f.xml', 'https://a.example/dir/sub/page.html', 'https://a.example/dir/f.xml'];
        yield 'dot relative' => ['./f.xml', 'https://a.example/dir/page.html', 'https://a.example/dir/f.xml'];
        yield 'directory base' => ['f.xml', 'https://a.example/dir/', 'https://a.example/dir/f.xml'];
        yield 'bare origin' => ['f.xml', 'https://a.example', 'https://a.example/f.xml'];
        yield 'keeps the port' => ['/f.xml', 'https://a.example:8443/dir/page.html', 'https://a.example:8443/f.xml'];
        yield 'keeps a query' => ['/f?format=rss', 'https://a.example/dir/page.html', 'https://a.example/f?format=rss'];
    }

    #[DataProvider('references')]
    public function testEveryRelativeFormResolvesAgainstThePage(string $href, string $pageUrl, string $expected): void
    {
        $found = $this->discovery->discover(
            '<link rel="alternate" type="application/rss+xml" href="' . htmlspecialchars($href, ENT_QUOTES) . '">',
            $pageUrl,
        );

        self::assertSame($expected, $found[0]['url']);
    }

    public function testFallbackPathsCoverTheUsualGuesses(): void
    {
        $paths = $this->discovery->fallbackPaths();

        foreach (['/feed', '/feed/', '/rss', '/rss.xml', '/index.xml', '/atom.xml', '/feed.json'] as $expected) {
            self::assertContains($expected, $paths);
        }
    }

    public function testFallbackPathsResolveAgainstThePageOrigin(): void
    {
        $urls = $this->discovery->fallbackPaths('https://plain.example.com/some/page.html');

        self::assertContains('https://plain.example.com/feed', $urls);
        self::assertContains('https://plain.example.com/feed/', $urls);
        self::assertContains('https://plain.example.com/feed.json', $urls);
        self::assertSame(count(Autodiscovery::FALLBACK_PATHS), count($urls));
    }

    /**
     * @return list<array{url: string, type: string, title: string}>
     */
    private function discover(string $fixture, string $pageUrl): array
    {
        return $this->discovery->discover(zf_fixture_contents('html/' . $fixture), $pageUrl);
    }
}
