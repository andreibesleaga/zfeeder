<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Zfeeder\Config\Config;
use Zfeeder\Parse\Model\Channel;
use Zfeeder\Parse\Model\Enclosure;
use Zfeeder\Parse\Model\Item;
use Zfeeder\Render\RenderedFeed;
use Zfeeder\Render\Renderer;
use Zfeeder\Render\RenderRequest;
use Zfeeder\Render\TemplateEngine;
use Zfeeder\Render\TemplateLocator;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * The parts of the renderer the golden corpus cannot reach: the modern,
 * non-legacy path where feed text is sanitised, truncated and escaped, and the
 * nine 2.0 tokens.
 */
final class RendererTest extends TestCase
{
    /** @param array<string, string|int|bool> $overrides */
    private function renderer(array $overrides = []): Renderer
    {
        $config = Config::forTesting($overrides + [
            'base_url' => 'https://host.example/',
            'template_set' => 'classic',
            'channel_location' => 'top',
            'channel_one_bar' => true,
            'powered_by' => false,
        ]);

        return new Renderer(
            $config,
            new TemplateLocator($config),
            now: new \DateTimeImmutable('2026-09-18 12:00:00', new \DateTimeZone('UTC')),
        );
    }

    /**
     * @param  list<Item>         $items
     * @return list<RenderedFeed>
     */
    private function feeds(array $items, int $showedItems = 10): array
    {
        $feed = new Feed('https://feed.example/rss.xml', 'Example', position: 1, showedItems: $showedItems);
        $channel = new Channel(
            title: 'Example',
            link: 'https://feed.example/',
            description: 'A feed',
            logoUrl: 'https://feed.example/logo.png',
            logoTitle: 'Example',
            logoLink: 'https://feed.example/',
            items: $items,
        );

        return [new RenderedFeed(
            $feed,
            $channel,
            new CacheEntry($feed->xmlUrl, '', new \DateTimeImmutable('2026-09-17 06:00:00', new \DateTimeZone('UTC'))),
        )];
    }

    private function category(): Category
    {
        return new Category('demo');
    }

    private function item(string $summary = '', string $content = '', string $title = 'Title'): Item
    {
        return new Item(
            id: 'id-1',
            title: $title,
            link: 'https://feed.example/1',
            summaryHtml: $summary,
            contentHtml: $content,
            published: new \DateTimeImmutable('2026-09-16 12:00:00', new \DateTimeZone('UTC')),
            author: 'A. Author',
            enclosures: [new Enclosure('https://feed.example/a.mp3', 'audio/mpeg', 10)],
            rawDate: 'Wed, 16 Sep 2026 12:00:00 GMT',
        );
    }

    /** @param list<RenderedFeed> $feeds */
    private function render(Renderer $renderer, array $feeds, bool $legacy = false): string
    {
        return $renderer->render(
            $this->category(),
            $feeds,
            new RenderRequest(template: 'simplegray', selfUrl: '/i.php', legacyFidelity: $legacy),
        );
    }

    public function testFeedMarkupIsSanitisedOutsideLegacyMode(): void
    {
        $html = $this->render(
            $this->renderer(),
            $this->feeds([$this->item(summary: '<p>ok</p><script>alert(1)</script>')]),
        );

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('alert(1)', $html);
        self::assertStringContainsString('<p>ok</p>', $html);
    }

    public function testLegacyModePassesFeedMarkupThroughVerbatim(): void
    {
        $payload = '<p>ok</p><script>alert(1)</script>';
        $html = $this->render($this->renderer(), $this->feeds([$this->item(summary: $payload)]), legacy: true);

        self::assertStringContainsString($payload, $html);
    }

    public function testLegacyModeConcatenatesSummaryAndContentLike16sSaxBuffer(): void
    {
        $html = $this->render(
            $this->renderer(),
            $this->feeds([$this->item(summary: 'SUMMARY', content: 'CONTENT')]),
            legacy: true,
        );

        self::assertStringContainsString('SUMMARYCONTENT', $html);
    }

    public function testMaxDescriptionCharsIsApplied(): void
    {
        $html = $this->render(
            $this->renderer(['max_description_chars' => 5]),
            $this->feeds([$this->item(summary: '<p>abcdefghijklmn</p>')]),
        );

        self::assertStringContainsString("<p>abcde\u{2026}</p>", $html);
    }

    public function testAllowHtmlInItemsOffFlattensAndEscapes(): void
    {
        $html = $this->render(
            $this->renderer(['allow_html_in_items' => false]),
            $this->feeds([$this->item(summary: '<p>a &amp; b</p>')]),
        );

        self::assertStringContainsString('a &amp; b', $html);
        self::assertStringNotContainsString('<p>a', $html);
    }

    public function testItemTitleIsEscapedOutsideLegacyMode(): void
    {
        $html = $this->render(
            $this->renderer(),
            $this->feeds([$this->item(title: '</a><img src=x onerror=alert(1)>')]),
        );

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;/a&gt;', $html);
    }

    public function testTheNineNewTokensAreSuppliedAndEscapedByDefault(): void
    {
        $source = "<!-- header -->\n<!-- ENDheader -->\n"
            . "<!-- channel -->F={feedid} P={position} S={set}\n<!-- ENDchannel -->\n"
            . '<!-- news -->A={author} E={enclosure} I={itemdate_iso} R={itemdate_rel} '
            . "SUM={summary} CON={content|raw}\n<!-- ENDnews -->\n"
            . "<!-- footer -->\n<!-- ENDfooter -->\n<!-- between -->\n<!-- ENDbetween -->\n";
        $template = (new TemplateEngine())->parse($source, 'modern/probe', 'modern');

        $html = $this->renderer()->renderTemplate(
            $template,
            $this->category(),
            $this->feeds([$this->item(summary: '<b>s</b>', content: '<b>c</b>')]),
            new RenderRequest(template: 'probe', selfUrl: '/i.php'),
        );

        self::assertStringContainsString('F=0 P=1 S=modern', $html);
        self::assertStringContainsString('A=A. Author', $html);
        self::assertStringContainsString('E=https://feed.example/a.mp3', $html);
        self::assertStringContainsString('I=2026-09-16T12:00:00+00:00', $html);
        self::assertStringContainsString('R=2 days ago', $html);
        // {summary} escapes by default, {content|raw} does not.
        self::assertStringContainsString('SUM=&lt;b&gt;s&lt;/b&gt;', $html);
        self::assertStringContainsString('CON=<b>c</b>', $html);
    }

    public function testTokensInsideFeedTextAreNeverExpandedAgain(): void
    {
        $source = "<!-- header -->\n<!-- ENDheader -->\n<!-- channel -->\n<!-- ENDchannel -->\n"
            . "<!-- news -->{description}|{author}\n<!-- ENDnews -->\n"
            . "<!-- footer -->\n<!-- ENDfooter -->\n<!-- between -->\n<!-- ENDbetween -->\n";
        $template = (new TemplateEngine())->parse($source, 'modern/probe', 'modern');

        $html = $this->renderer()->renderTemplate(
            $template,
            $this->category(),
            $this->feeds([$this->item(summary: 'evil {author} {scripturl}')]),
            new RenderRequest(template: 'probe', selfUrl: '/i.php'),
        );

        self::assertStringContainsString('evil {author} {scripturl}', $html);
    }

    public function testShowedItemsLimitMatchesThe16BreakCondition(): void
    {
        $items = array_map(fn (int $n): Item => $this->item(title: 'T' . $n), range(1, 6));
        $html = $this->render($this->renderer(), $this->feeds($items, showedItems: 3));

        self::assertSame(3, substr_count($html, '<!-- news -->'));
    }

    public function testMoreLiftsTheLimitForThatFeedOnly(): void
    {
        $items = array_map(fn (int $n): Item => $this->item(title: 'T' . $n), range(1, 6));
        $renderer = $this->renderer();
        $html = $renderer->render(
            $this->category(),
            $this->feeds($items, showedItems: 3),
            new RenderRequest(template: 'simplegray', moreFeed: 1, selfUrl: '/i.php'),
        );

        self::assertSame(6, substr_count($html, '<!-- news -->'));
    }

    public function testAnEmptyCategoryRendersThe16Message(): void
    {
        self::assertSame(
            Renderer::NO_FEEDS,
            $this->renderer()->render($this->category(), [], new RenderRequest(template: 'simplegray')),
        );
    }

    public function testPoweredByIsAppendedWhenBothConfigAndRequestAllowIt(): void
    {
        $html = $this->render($this->renderer(['powered_by' => true]), $this->feeds([$this->item()]));
        self::assertStringEndsWith(Renderer::POWERED_BY, $html);

        $off = $this->renderer(['powered_by' => true])->render(
            $this->category(),
            $this->feeds([$this->item()]),
            new RenderRequest(template: 'simplegray', showPoweredBy: false),
        );
        self::assertStringNotContainsString(Renderer::POWERED_BY, $off);
    }

    public function testChannelLocationNoneDrawsNoBar(): void
    {
        $html = $this->render($this->renderer(['channel_location' => 'none']), $this->feeds([$this->item()]));

        self::assertStringNotContainsString('<!-- channel -->', $html);
    }

    public function testPositionFilterSelectsAndOrders(): void
    {
        $request = new RenderRequest(positions: 'p3,p1');

        self::assertSame([3, 1], $request->positionFilter());
        self::assertNull((new RenderRequest())->positionFilter());
        self::assertTrue($request->wantsMore(0) === false);
    }
}
