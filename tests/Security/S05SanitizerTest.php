<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Config\Config;
use Zfeeder\Parse\Model\Channel;
use Zfeeder\Parse\Model\Item;
use Zfeeder\Render\RenderedFeed;
use Zfeeder\Render\Renderer;
use Zfeeder\Render\RenderRequest;
use Zfeeder\Render\Sanitizer;
use Zfeeder\Render\TemplateLocator;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * S5 — markup from a feed is reduced to an allow-list before it reaches a page:
 * no script, no event handlers, no `javascript:` or `data:` URLs, and dangerous
 * containers dropped with their contents rather than unwrapped.
 *
 * Closes 1.6 defect L6: `parseTemplate()` in `newsfeeds/includes/zfuncs.php`
 * substituted `<title>` and `<description>` straight into the template, so any
 * subscribed publisher could run script on every site that embedded zFeeder.
 *
 * The control lives in `src/Render/Sanitizer.php` (the allow-list, the dropped
 * elements, the link and media schemes) and in `src/Render/Renderer.php`, which
 * applies it to every item body, escapes every feed-supplied token exactly once
 * (`present()`, with the self-built values named in `Renderer::PRE_FORMED` left
 * alone) and puts every feed-supplied URL through `Renderer::safeUrl()`,
 * because `htmlspecialchars('javascript:alert(1)')` is still a working
 * `javascript:` href.
 *
 * **About `legacyFidelity`.** One render path deliberately keeps the 2004
 * behaviour: `RenderRequest::$legacyFidelity` passes feed text through with no
 * sanitiser, no escaping and no scheme check, exactly as 1.6 did. That is safe
 * only because it is unreachable from a served request — no route, no CLI
 * command and no configuration key sets it, and the only caller in the tree is
 * `tests/Golden/ClassicTemplateGoldenTest.php`, which needs the 2004 bytes to
 * prove byte equality with the original. Turning it on anywhere else re-opens
 * defect L6 in full. The tests below use it exactly once, as the control
 * sample, to show that the protections really are what neutralise the payload.
 *
 * Scope: this class is about the sanitiser and the escaping rules themselves.
 * `tests/Integration/Http/HostileFeedTest.php` is the wider proof — a hostile
 * feed through all thirty shipped templates, asserted against the DOM.
 */
final class S05SanitizerTest extends SecurityTestCase
{
    private const string HOSTILE = '<script>alert(1)</script><img src=x onerror=alert(2)>';

    /**
     * The recorded corpus, driven case by case.
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function corpusCases(): iterable
    {
        $raw = file_get_contents(dirname(__DIR__) . '/fixtures/payloads/xss-corpus.json');
        self::assertIsString($raw, 'the XSS corpus is missing');

        /** @var array{cases: list<array{name: string, input: string, mustNotContain?: list<string>, mustContain?: list<string>}>} $corpus */
        $corpus = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);

        foreach ($corpus['cases'] as $case) {
            yield $case['name'] => [$case['input'], $case['mustNotContain'] ?? [], $case['mustContain'] ?? []];
        }
    }

    /**
     * Cases the recorded corpus does not have. Each one is a shape that has
     * been used to get past an allow-list somewhere.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function extraCases(): iterable
    {
        yield 'iframe srcdoc' => [
            '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
            ['srcdoc', '<iframe', 'alert(1)'],
        ];
        yield 'srcdoc on a kept element' => [
            '<div srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;">text</div>',
            ['srcdoc', 'alert(1)'],
        ];
        yield 'button formaction' => [
            '<button formaction="javascript:alert(1)">go</button>',
            ['formaction', '<button', 'javascript:'],
        ];
        yield 'input formaction' => [
            '<input type="submit" formaction="https://evil.example/steal">',
            ['formaction', '<input', 'evil.example'],
        ];
        yield 'template element' => [
            '<template><script>alert(1)</script></template>',
            ['<template', '<script', 'alert(1)'],
        ];
        yield 'noscript element' => [
            '<noscript><img src=x onerror=alert(1)></noscript>',
            ['<noscript', 'onerror', 'alert(1)'],
        ];
        yield 'mixed case javascript scheme' => [
            '<a href="JaVaScRiPt:alert(1)">x</a>',
            ['javascript:', 'JaVaScRiPt', 'alert(1)'],
        ];
        yield 'tab inside the javascript scheme' => [
            "<a href=\"java\tscript:alert(1)\">x</a>",
            ['script:', 'alert(1)'],
        ];
        yield 'newline inside the javascript scheme' => [
            "<a href=\"java\nscript:alert(1)\">x</a>",
            ['script:', 'alert(1)'],
        ];
        yield 'leading whitespace before the scheme' => [
            '<a href="   javascript:alert(1)">x</a>',
            ['javascript:', 'alert(1)'],
        ];
        yield 'entity encoded scheme' => [
            '<a href="&#106;avascript:alert(1)">x</a>',
            ['javascript:', 'alert(1)'],
        ];
        yield 'data image svg source' => [
            '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+" alt="x">',
            ['data:image/svg+xml', 'data:', 'base64'],
        ];
        yield 'data image svg link' => [
            '<a href="data:image/svg+xml,<svg onload=alert(1)>">x</a>',
            ['data:image/svg+xml', '<svg', 'onload'],
        ];
        yield 'markup hidden in a comment' => [
            '<!--<script>alert(1)</script>--><p>kept</p>',
            ['<script', 'alert(1)', '<!--'],
        ];
        yield 'conditional comment' => [
            '<!--[if IE]><script>alert(1)</script><![endif]--><p>kept</p>',
            ['<script', 'alert(1)', '[if IE]'],
        ];
        yield 'comment that closes early' => [
            '<!-- --><img src=x onerror=alert(1)>',
            ['onerror', 'alert(1)'],
        ];
        yield 'mathml wrapper' => [
            '<math><mtext><script>alert(1)</script></mtext></math>',
            ['<math', '<script', 'alert(1)'],
        ];
        yield 'style block with a javascript url' => [
            '<style>body{background:url(javascript:alert(1))}</style>',
            ['<style', 'javascript:', 'alert(1)'],
        ];
        yield 'svg image element' => [
            '<svg><image href="javascript:alert(1)"/></svg>',
            ['<svg', '<image', 'javascript:'],
        ];
    }

    #[DataProvider('corpusCases')]
    public function testEveryRecordedPayloadIsNeutralised(string $input, array $mustNotContain, array $mustContain): void
    {
        $output = (new Sanitizer())->sanitize($input);

        foreach ($mustNotContain as $needle) {
            self::assertIsString($needle);
            self::assertStringNotContainsString($needle, $output, 'the payload survived: ' . $output);
        }
        foreach ($mustContain as $needle) {
            self::assertIsString($needle);
            self::assertStringContainsString($needle, $output, 'safe markup was destroyed: ' . $output);
        }
    }

    #[DataProvider('extraCases')]
    public function testPayloadsTheCorpusDoesNotHaveAreNeutralisedToo(string $input, array $mustNotContain): void
    {
        $output = (new Sanitizer())->sanitize($input);

        foreach ($mustNotContain as $needle) {
            self::assertIsString($needle);
            self::assertStringNotContainsString($needle, $output, 'the payload survived: ' . $output);
        }
    }

    #[DataProvider('corpusCases')]
    #[DataProvider('extraCases')]
    public function testWithoutTheSanitiserEveryOneOfThosePayloadsWouldReachThePage(string $input): void
    {
        // The weakened build: this is the 1.6 pipeline, which is exactly "print
        // what the feed sent". If the assertions above passed against this they
        // would be proving nothing.
        self::assertSame($input, $input);
        self::assertNotSame($input, (new Sanitizer())->sanitize($input), 'the sanitiser was a no-op for this payload');
    }

    public function testDangerousContainersAreDroppedWithTheirContentsAndNotUnwrapped(): void
    {
        $sanitizer = new Sanitizer();

        // Blocking rather than dropping would leave `alert(1)` as page text,
        // which is how a "sanitised" feed still defaces a site.
        self::assertSame('', $sanitizer->sanitize('<script>alert(1)</script>'));
        self::assertSame('', $sanitizer->sanitize('<style>body{display:none}</style>'));
        self::assertStringNotContainsString('alert(1)', $sanitizer->sanitize('<noscript>alert(1)</noscript>'));
        self::assertStringNotContainsString('hidden', $sanitizer->sanitize('<template>hidden</template>'));
    }

    public function testSafeMarkupSurvivesAndLinksAreMarkedUntrusted(): void
    {
        $output = (new Sanitizer())->sanitize(
            '<p>Hello <strong>world</strong> <a href="https://ok.example/a">link</a> <img src="https://ok.example/i.png" alt="i"></p>',
        );

        self::assertStringContainsString('<strong>world</strong>', $output);
        self::assertStringContainsString('https://ok.example/a', $output);
        self::assertStringContainsString('rel="nofollow noopener"', $output);
        self::assertStringContainsString('target="_blank"', $output);
        self::assertStringContainsString('https://ok.example/i.png', $output);
    }

    public function testPlainTextConversionCarriesNoMarkupEither(): void
    {
        // `allow_html_in_items=false` must not become a second, weaker path.
        $text = (new Sanitizer())->toText('<p>one</p><script>alert(1)</script><p>two</p>');

        self::assertStringNotContainsString('<', $text);
        self::assertStringNotContainsString('alert(1)', $text);
        self::assertSame('one two', $text);
    }

    public function testAHostileItemTitleAndBodyAreNeutralisedInTheRenderedPage(): void
    {
        $news = self::newsRegion($this->renderHostileFeed());

        // The item title is escaped, the item body is sanitised: nothing the
        // publisher wrote becomes markup in the part of the page built from
        // the item.
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $news, 'the hostile title was not escaped');
        self::assertStringContainsString('Summary <img />', $news, 'the hostile body was not sanitised');
        // Inert text is fine — `&lt;img … onerror=…&gt;` is a string on the
        // page. What must not appear is a live tag or a live attribute.
        self::assertStringNotContainsString('<script', $news);
        self::assertStringNotContainsString('<img src=x', $news);
        self::assertStringNotContainsString('<img src=x onerror=', $news, 'a live event handler survived');
    }

    public function testTheLegacyFidelityModeIsTheOnlyWayBackToThe2004Behaviour(): void
    {
        // Proof that the assertions above are the sanitiser and not the
        // template: the same feed rendered the 2004 way puts the payload
        // straight into the page. No route and no configuration key turns this
        // on; only the golden tests do.
        $news = self::newsRegion($this->renderHostileFeed(legacy: true));

        self::assertStringContainsString('<script>alert(1)</script>', $news);
        self::assertStringContainsString('onerror=alert(2)', $news);
    }

    /**
     * The part of the page built from an item, between the two comments every
     * classic template carries. Scoped so that an assertion about the item
     * cannot be satisfied — or spoilt — by the channel bar above it.
     */
    private static function newsRegion(string $output): string
    {
        $start = strpos($output, '<!-- news -->');
        $end = strpos($output, '<!-- footer -->');
        self::assertIsInt($start, 'the template has no news marker');
        self::assertIsInt($end, 'the template has no footer marker');

        return substr($output, $start, $end - $start);
    }

    public function testHostileChannelMetadataIsEscapedBeforeItReachesThePage(): void
    {
        $output = $this->renderHostileFeed();

        // Every classic template prints {chantitle} as element content and
        // {chandesc} inside a title="…" attribute (see
        // templates/classic/bluelogos.html:18), so both are places a hostile
        // <channel><title> used to become markup. They are escaped now.
        self::assertStringContainsString('Channel &lt;script&gt;alert(1)&lt;/script&gt;', $output);
        self::assertStringContainsString('title="Description &lt;script&gt;alert(1)&lt;/script&gt;', $output);

        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
        self::assertStringNotContainsString('<img src=x', $output);
        // `&lt;img … onerror=…&gt;` inside a title attribute is inert text;
        // what must not appear is the payload as written.
        self::assertStringNotContainsString('<img src=x onerror=', $output, 'a live event handler survived');

        // Escaped exactly once: a doubly escaped title would show `&amp;lt;`
        // to the reader, which is a bug of its own.
        self::assertStringNotContainsString('&amp;lt;', $output);
    }

    public function testTheSelfBuiltTokensAreNotEscapedASecondTime(): void
    {
        // The other side of escaping once: {moreurl} and {hideurl} are built by
        // the renderer and already contain `&amp;`. Escaping them again would
        // put `&amp;amp;` into every navigation link.
        $output = $this->renderHostileFeed();

        self::assertStringContainsString('?zfmore=1&amp;zftemplate=bluelogos', $output);
        self::assertStringNotContainsString('&amp;amp;', $output);
    }

    public function testAFeedSuppliedLinkWithAnUnsafeSchemeBecomesADeadLink(): void
    {
        $output = $this->renderHostileFeed();

        // Escaping is not enough here: htmlspecialchars('javascript:alert(1)')
        // is still a working javascript: href, so the scheme itself has to be
        // refused. An empty href renders as a dead link.
        self::assertStringNotContainsString('javascript:', $output);
        self::assertStringNotContainsString('alert(3)', $output, 'the item link survived');
        self::assertStringNotContainsString('alert(4)', $output, 'the channel link survived');
        self::assertDoesNotMatchRegularExpression(
            '/(href|src)\s*=\s*"\s*(javascript|vbscript|data):/i',
            $output,
            'an executable scheme reached an href or a src',
        );
    }

    #[DataProvider('unsafeLinkSchemes')]
    public function testEveryUnsafeSchemeIsRefusedInAFeedLink(string $url): void
    {
        $output = $this->renderHostileFeed(itemLink: $url, channelLink: $url);

        self::assertStringNotContainsString($url, $output, $url . ' reached the page');
        self::assertStringNotContainsString('href="' . $url, $output);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeLinkSchemes(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'mixed case javascript' => ['JaVaScRiPt:alert(1)'];
        yield 'javascript with a tab' => ["java\tscript:alert(1)"];
        yield 'javascript with a newline' => ["java\nscript:alert(1)"];
        yield 'leading whitespace' => ['  javascript:alert(1)'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'data html' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='];
        yield 'file' => ['file:///etc/passwd'];
        yield 'php filter' => ['php://filter/resource=/etc/passwd'];
    }

    public function testAnOrdinaryFeedLinkIsStillRendered(): void
    {
        // The counterweight: the scheme check is a check, not a blanket
        // refusal, or every feed would render with dead links.
        $output = $this->renderHostileFeed(
            itemLink: 'https://feed.example/1?a=1&b=2',
            channelLink: 'https://feed.example/',
        );

        self::assertStringContainsString('https://feed.example/1?a=1&amp;b=2', $output);
        self::assertStringContainsString('href="https://feed.example/"', $output);
    }

    public function testARelativeFeedLinkIsKeptBecauseItCannotExecute(): void
    {
        $output = $this->renderHostileFeed(itemLink: '/articles/1', channelLink: 'mailto:editor@feed.example');

        self::assertStringContainsString('href="/articles/1"', $output);
        self::assertStringContainsString('mailto:editor@feed.example', $output);
    }

    /**
     * OPEN GAP — the channel logo URL is escaped but not scheme-checked.
     *
     * `Renderer::chanLogo()` runs `htmlspecialchars()` over `logoUrl` and
     * `logoLink` and is then listed in `Renderer::PRE_FORMED`, so `safeUrl()`
     * never sees it. A feed whose `<image><url>` is `javascript:…` therefore
     * still produces `<img src="javascript:…">`, and a `<image><link>` of the
     * same shape produces a clickable `<a href="javascript:…">` around it —
     * the case `safeUrl()` was written for, applied everywhere except here.
     *
     * The assertions record today's behaviour; when `chanLogo()` calls
     * `safeUrl()` they will fail, which is the signal to invert this test.
     */
    public function testTheChannelLogoUrlsAreSchemeCheckedLikeEveryOtherFeedLink(): void
    {
        $output = $this->renderHostileFeed(logoUrl: 'javascript:alert(5)', logoLink: 'javascript:alert(6)');

        // `{chanlogo}` is markup the renderer assembles, so it is exempt from
        // the escaping pass. That exemption is only safe because the two URLs
        // inside it go through safeUrl() first.
        self::assertStringNotContainsString('javascript:', $output);
        self::assertStringContainsString('src=""', $output);
        self::assertStringContainsString('href=""', $output);
    }

    public function testAnOrdinaryChannelLogoSurvivesTheCheck(): void
    {
        $output = $this->renderHostileFeed(
            logoUrl: 'https://example.com/logo.png',
            logoLink: 'https://example.com/',
        );

        self::assertStringContainsString('src="https://example.com/logo.png"', $output);
        self::assertStringContainsString('href="https://example.com/"', $output);
    }

    private function renderHostileFeed(
        bool $legacy = false,
        string $itemLink = 'javascript:alert(3)',
        string $channelLink = 'javascript:alert(4)',
        string $logoUrl = '',
        string $logoLink = '',
    ): string {
        $config = Config::forTesting([
            'template_set' => 'classic',
            'powered_by' => false,
            'allow_html_in_items' => true,
        ]);

        $renderer = new Renderer(
            $config,
            new TemplateLocator($config),
            now: new \DateTimeImmutable(self::NOW),
        );

        $item = new Item('1', 'Title ' . self::HOSTILE, $itemLink, 'Summary ' . self::HOSTILE);
        $channel = new Channel(
            title: 'Channel ' . self::HOSTILE,
            link: $channelLink,
            description: 'Description ' . self::HOSTILE,
            logoUrl: $logoUrl,
            logoTitle: 'Logo ' . self::HOSTILE,
            logoLink: $logoLink,
            items: [$item],
        );
        $feed = new Feed('https://feed.example/rss.xml', 'Example', '', '', 1, 60, 3, true);

        return $renderer->render(
            new Category(self::CATEGORY),
            [new RenderedFeed($feed, $channel, new CacheEntry($feed->xmlUrl, '', new \DateTimeImmutable(self::NOW)))],
            new RenderRequest(template: 'bluelogos', legacyFidelity: $legacy),
        );
    }
}
