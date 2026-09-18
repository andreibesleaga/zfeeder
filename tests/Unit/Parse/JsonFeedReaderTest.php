<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Parse;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Exception\ParseException;
use Zfeeder\Parse\JsonFeedReader;

#[CoversClass(JsonFeedReader::class)]
final class JsonFeedReaderTest extends TestCase
{
    private JsonFeedReader $reader;

    protected function setUp(): void
    {
        $this->reader = new JsonFeedReader();
    }

    public function testTheFixtureMapsToTheSameModelTheXmlFormatsProduce(): void
    {
        $channel = $this->reader->parse(zf_fixture_contents('feeds-2026/jsonfeed11-notes.json'), 'https://notes.example.io/feed.json');

        self::assertSame('json-1.1', $channel->format);
        self::assertSame('Notes in JSON', $channel->title);
        self::assertSame('https://notes.example.io/', $channel->link);
        self::assertSame('Short notes published as JSON Feed', $channel->description);
        self::assertSame('en', $channel->language);
        self::assertSame('https://notes.example.io/icon.png', $channel->logoUrl);
        self::assertSame('Notes in JSON', $channel->logoTitle);
        self::assertCount(2, $channel->items);
    }

    public function testContentHtmlIsKeptAsMarkupAndSummaryStaysSeparate(): void
    {
        $item = $this->parseFixture()->items[0];

        self::assertSame('https://notes.example.io/1', $item->id);
        self::assertSame('https://notes.example.io/1', $item->link);
        self::assertSame('JSON Feed is still fine', $item->title);
        self::assertSame('<p>A small format that does <em>one job</em> and stops there.</p>', $item->contentHtml);
        self::assertSame('A small format that does one job.', $item->summaryHtml);
        self::assertSame('N. Publisher', $item->author);
        self::assertSame('2026-09-14T10:00:00Z', $item->rawDate);
        self::assertSame('2026-09-14T10:00:00+00:00', $item->published?->format('c'));
    }

    public function testContentTextBecomesTheBodyWhenThereIsNoHtml(): void
    {
        $item = $this->parseFixture()->items[1];

        self::assertSame('Enclosures predate podcasts by a few years.', $item->contentHtml);
        self::assertSame('Enclosures predate podcasts by a few years.', $item->summaryHtml);
    }

    public function testAttachmentsBecomeEnclosures(): void
    {
        $item = $this->parseFixture()->items[1];

        self::assertCount(1, $item->enclosures);
        self::assertSame('https://notes.example.io/audio/2.mp3', $item->enclosures[0]->url);
        self::assertSame('audio/mpeg', $item->enclosures[0]->type);
        self::assertSame(5120000, $item->enclosures[0]->length);
    }

    public function testFeedLevelAuthorIsInheritedByItemsThatNameNone(): void
    {
        self::assertSame('N. Publisher', $this->parseFixture()->items[1]->author);
    }

    public function testVersion10AuthorObjectIsRead(): void
    {
        $channel = $this->reader->parse(json_encode([
            'version' => 'https://jsonfeed.org/version/1',
            'title' => 'Old style',
            'home_page_url' => 'https://old.example/',
            'favicon' => 'https://old.example/fav.png',
            'author' => ['name' => 'Single Author'],
            'items' => [
                ['id' => '1', 'url' => 'https://old.example/1', 'title' => 'One', 'content_text' => 'body'],
            ],
        ], JSON_THROW_ON_ERROR), 'https://old.example/feed.json');

        self::assertSame('json-1.1', $channel->format, 'The model carries one JSON token whatever the minor version.');
        self::assertSame('https://old.example/fav.png', $channel->logoUrl, 'favicon stands in for a missing icon.');
        self::assertSame('Single Author', $channel->items[0]->author);
    }

    public function testItemAuthorWinsOverTheFeedAuthor(): void
    {
        $channel = $this->reader->parse(json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 't',
            'authors' => [['name' => 'Feed Author']],
            'items' => [
                ['id' => '1', 'title' => 'One', 'authors' => [['name' => 'Item Author']]],
                ['id' => '2', 'title' => 'Two'],
            ],
        ], JSON_THROW_ON_ERROR), 'https://x.example/feed.json');

        self::assertSame('Item Author', $channel->items[0]->author);
        self::assertSame('Feed Author', $channel->items[1]->author);
    }

    public function testPlainTextIsEscapedSoItCannotBecomeMarkup(): void
    {
        $channel = $this->reader->parse(json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 't',
            'items' => [[
                'id' => '1',
                'title' => 'Escaping',
                'content_text' => '<script>alert(1)</script> & more',
                'summary' => 'Tom & Jerry <b>',
            ]],
        ], JSON_THROW_ON_ERROR), 'https://x.example/feed.json');

        $item = $channel->items[0];
        self::assertStringNotContainsString('<script>', $item->contentHtml);
        self::assertStringContainsString('&lt;script&gt;', $item->contentHtml);
        self::assertSame('Tom &amp; Jerry &lt;b&gt;', $item->summaryHtml);
    }

    public function testMissingIdFallsBackToTheLinkAndTitle(): void
    {
        $channel = $this->reader->parse(json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 't',
            'items' => [['url' => 'https://x.example/1', 'title' => 'One']],
        ], JSON_THROW_ON_ERROR), 'https://x.example/feed.json');

        self::assertSame(sha1('https://x.example/1|One'), $channel->items[0]->id);
    }

    public function testExternalUrlIsUsedWhenThereIsNoUrl(): void
    {
        $channel = $this->reader->parse(json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 't',
            'items' => [['id' => '1', 'external_url' => 'https://elsewhere.example/a', 'title' => 'Link post']],
        ], JSON_THROW_ON_ERROR), 'https://x.example/feed.json');

        self::assertSame('https://elsewhere.example/a', $channel->items[0]->link);
    }

    public function testDateModifiedStandsInForAMissingPublicationDate(): void
    {
        $channel = $this->reader->parse(json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 't',
            'items' => [['id' => '1', 'title' => 'One', 'date_modified' => '2026-09-14T10:00:00Z']],
        ], JSON_THROW_ON_ERROR), 'https://x.example/feed.json');

        self::assertSame('2026-09-14T10:00:00Z', $channel->items[0]->rawDate);
    }

    /** @return iterable<string, array{0: string}> */
    public static function tolerated(): iterable
    {
        yield 'no items key' => ['{"version":"https://jsonfeed.org/version/1.1","title":"t"}'];
        yield 'items not an array' => ['{"version":"https://jsonfeed.org/version/1.1","title":"t","items":"oops"}'];
        yield 'item is a scalar' => ['{"version":"https://jsonfeed.org/version/1.1","title":"t","items":[1,2]}'];
        yield 'no version at all' => ['{"title":"t","items":[]}'];
        yield 'attachment without a url' => ['{"version":"https://jsonfeed.org/version/1.1","title":"t","items":[{"id":"1","attachments":[{"mime_type":"audio/mpeg"}]}]}'];
        yield 'wrong field types' => ['{"version":"https://jsonfeed.org/version/1.1","title":5,"language":true,"items":[]}'];
    }

    #[DataProvider('tolerated')]
    public function testOddDocumentsAreToleratedRatherThanRefused(string $body): void
    {
        $channel = $this->reader->parse($body, 'https://x.example/feed.json');

        self::assertSame('json-1.1', $channel->format);
    }

    /** @return iterable<string, array{0: string}> */
    public static function fatal(): iterable
    {
        yield 'broken json' => ['{"title": '];
        yield 'not an object' => ['[1, 2, 3]'];
        yield 'json feed 2' => ['{"version":"https://jsonfeed.org/version/2","title":"t"}'];
        yield 'foreign version url' => ['{"version":"https://evil.example/version/1.1","title":"t"}'];
    }

    #[DataProvider('fatal')]
    public function testUnreadableDocumentsThrow(string $body): void
    {
        $this->expectException(ParseException::class);
        $this->reader->parse($body, 'https://x.example/feed.json');
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function sniffs(): iterable
    {
        yield 'object' => ['{"version":"x"}', true];
        yield 'leading whitespace' => ["\n\t  {\"a\":1}", true];
        yield 'byte order mark' => ["\u{FEFF}{\"a\":1}", true];
        yield 'xml declaration' => ['<?xml version="1.0"?><rss/>', false];
        yield 'bare element' => ['<rss version="2.0"/>', false];
        yield 'json array' => ['[1,2]', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('sniffs')]
    public function testContentSniffing(string $body, bool $expected): void
    {
        self::assertSame($expected, JsonFeedReader::looksLikeJson($body));
    }

    private function parseFixture(): \Zfeeder\Parse\Model\Channel
    {
        return $this->reader->parse(zf_fixture_contents('feeds-2026/jsonfeed11-notes.json'), 'https://notes.example.io/feed.json');
    }
}
