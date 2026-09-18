<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Exception\StorageException;
use Zfeeder\Subscription\Opml\Reader;

#[CoversClass(Reader::class)]
final class OpmlReaderTest extends TestCase
{
    private Reader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new Reader();
    }

    /** @return array<string, array{string}> every subscription file shipped with 1.6 */
    public static function legacyFileProvider(): array
    {
        $files = glob(\dirname(__DIR__, 3) . '/legacy/zfeeder-1.6/newsfeeds/categories/*.opml');
        $cases = [];
        foreach ($files === false ? [] : $files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    /** @return array<string, array{string}> the OPML fixtures the golden tests render from */
    public static function fixtureFileProvider(): array
    {
        $files = glob(\dirname(__DIR__, 3) . '/tests/fixtures/opml/*.opml');
        $cases = [];
        foreach ($files === false ? [] : $files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('legacyFileProvider')]
    public function testEveryLegacyFileParses(string $file): void
    {
        $xml = file_get_contents($file);
        self::assertIsString($xml);

        $feeds = $this->reader->readFeeds($xml);

        // empty.opml really is empty; every other 2004 file has subscriptions.
        self::assertSame(basename($file) === 'empty.opml', $feeds === []);
        foreach ($feeds as $feed) {
            self::assertNotSame('', $feed->xmlUrl);
            self::assertGreaterThan(0, $feed->position);
        }
    }

    #[DataProvider('fixtureFileProvider')]
    public function testEveryFixtureFileParses(string $file): void
    {
        $xml = file_get_contents($file);
        self::assertIsString($xml);

        self::assertCount(2, $this->reader->readFeeds($xml));
    }

    public function testACategoryCarriesItsNameAndHead(): void
    {
        $category = $this->reader->readCategory('general', $this->legacy('general.opml'));

        self::assertSame('general', $category->name);
        self::assertCount(4, $category->feeds);
        self::assertNotNull($category->dateModified);
        self::assertSame('Tue, 24 Feb 2004 13:32:52 GMT', $category->dateModified->format('D, d M Y H:i:s') . ' GMT');
    }

    public function testTheDocumentTitleNeverOverridesTheRequestedName(): void
    {
        $xml = '<opml version="2.0"><head><title>someone-else</title></head><body/></opml>';

        self::assertSame('news', $this->reader->readCategory('news', $xml)->name);
    }

    public function testHeadFieldsAreRead(): void
    {
        $xml = '<opml version="2.0"><head><title>news</title>'
            . '<dateModified>Wed, 25 Feb 2004 14:00:00 GMT</dateModified>'
            . '<ownerName>Andrei</ownerName><ownerEmail>owner@example.org</ownerEmail>'
            . '</head><body/></opml>';

        $head = $this->reader->readHead($xml);

        self::assertSame('news', $head['title']);
        self::assertNotNull($head['dateModified']);
        self::assertSame(1077717600, $head['dateModified']->getTimestamp());
        self::assertSame('Andrei', $head['ownerName']);
        self::assertSame('owner@example.org', $head['ownerEmail']);
    }

    public function testAMissingHeadIsNotAnError(): void
    {
        $head = $this->reader->readHead($this->legacy('empty.opml'));

        self::assertSame('', $head['title']);
        self::assertNull($head['dateModified']);
        self::assertSame('', $head['ownerName']);
        self::assertSame('', $head['ownerEmail']);
    }

    public function testAnUnreadableDateIsSimplyUnknown(): void
    {
        $xml = '<opml version="2.0"><head><dateModified>not a date</dateModified></head><body/></opml>';

        self::assertNull($this->reader->readHead($xml)['dateModified']);
    }

    /** The defaults 1.6's opmlStartElement() applied to missing attributes. */
    public function testMissingAttributesFallBackToTheLegacyDefaults(): void
    {
        $xml = '<opml version="2.0"><body>'
            . '<outline type="rss" position="1" xmlUrl="http://a.test/f" />'
            . '</body></opml>';

        $feed = $this->reader->readFeeds($xml)[0];

        self::assertSame(60, $feed->refreshMinutes);
        self::assertSame(0, $feed->showedItems);
        self::assertFalse($feed->subscribed);
        self::assertSame('', $feed->title);
        self::assertSame('', $feed->description);
        self::assertSame('', $feed->htmlUrl);
        self::assertSame('', $feed->language);
        self::assertFalse($feed->isRenderable());
    }

    public function testAttributeNamesAreMatchedWithoutRegardToCase(): void
    {
        $xml = '<opml version="2.0"><body>'
            . '<outline TYPE="rss" POSITION="2" TEXT="T" TITLE="Title" DESCRIPTION="D"'
            . ' XMLURL="http://a.test/f" HTMLURL="http://a.test/" REFRESHTIME="30" SHOWEDITEMS="4"'
            . ' ISSUBSCRIBED="YES" LANGUAGE="ro" />'
            . '</body></opml>';

        $feed = $this->reader->readFeeds($xml)[0];

        self::assertSame(2, $feed->position);
        self::assertSame('Title', $feed->title);
        self::assertSame('D', $feed->description);
        self::assertSame('http://a.test/f', $feed->xmlUrl);
        self::assertSame('http://a.test/', $feed->htmlUrl);
        self::assertSame(30, $feed->refreshMinutes);
        self::assertSame(4, $feed->showedItems);
        self::assertTrue($feed->subscribed);
        self::assertSame('ro', $feed->language);
    }

    public function testTextIsUsedWhenThereIsNoTitle(): void
    {
        $xml = '<opml version="2.0"><body>'
            . '<outline position="1" text="From text" xmlUrl="http://a.test/f" />'
            . '</body></opml>';

        self::assertSame('From text', $this->reader->readFeeds($xml)[0]->title);
    }

    public function testOutlinesWithoutAPositionAreSkipped(): void
    {
        $xml = '<opml version="2.0"><body>'
            . '<outline text="A folder">'
            . '<outline type="rss" position="1" xmlUrl="http://a.test/f" isSubscribed="yes" showedItems="3" />'
            . '</outline>'
            . '<outline type="rss" position="" xmlUrl="http://b.test/f" />'
            . '</body></opml>';

        $feeds = $this->reader->readFeeds($xml);

        self::assertCount(1, $feeds);
        self::assertSame('http://a.test/f', $feeds[0]->xmlUrl);
    }

    public function testOutlinesWithoutAFeedUrlAreSkipped(): void
    {
        $xml = '<opml version="2.0"><body>'
            . '<outline type="rss" position="1" xmlUrl="" />'
            . '<outline type="rss" position="2" />'
            . '</body></opml>';

        self::assertSame([], $this->reader->readFeeds($xml));
    }

    public function testIsSubscribedIsOnlyTrueForYes(): void
    {
        $xml = '<opml version="2.0"><body>'
            . '<outline position="1" xmlUrl="http://a.test/1" isSubscribed="yes" />'
            . '<outline position="2" xmlUrl="http://a.test/2" isSubscribed="Yes" />'
            . '<outline position="3" xmlUrl="http://a.test/3" isSubscribed="no" />'
            . '<outline position="4" xmlUrl="http://a.test/4" isSubscribed="true" />'
            . '</body></opml>';

        self::assertSame(
            [true, true, false, false],
            array_map(static fn ($feed): bool => $feed->subscribed, $this->reader->readFeeds($xml)),
        );
    }

    public function testNonNumericCountsFallBackToTheDefaults(): void
    {
        $xml = '<opml version="2.0"><body>'
            . '<outline position="1" xmlUrl="http://a.test/f" refreshTime="soon" showedItems="many" />'
            . '</body></opml>';

        $feed = $this->reader->readFeeds($xml)[0];

        self::assertSame(60, $feed->refreshMinutes);
        self::assertSame(0, $feed->showedItems);
    }

    public function testEntitiesInTextAreDecodedNormally(): void
    {
        $xml = '<opml version="2.0"><body>'
            . '<outline position="1" title="A &amp; B" xmlUrl="http://a.test/f?x=1&amp;y=2" />'
            . '</body></opml>';

        $feed = $this->reader->readFeeds($xml)[0];

        self::assertSame('A & B', $feed->title);
        self::assertSame('http://a.test/f?x=1&y=2', $feed->xmlUrl);
    }

    public function testMoreThanFiveHundredOutlinesAreRefused(): void
    {
        $outlines = '';
        for ($i = 1; $i <= Reader::MAX_OUTLINES + 1; ++$i) {
            $outlines .= sprintf('<outline position="%d" xmlUrl="http://a.test/%d" />', $i, $i);
        }

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('at most 500');
        $this->reader->readFeeds('<opml version="2.0"><body>' . $outlines . '</body></opml>');
    }

    public function testExactlyFiveHundredOutlinesAreAccepted(): void
    {
        $outlines = '';
        for ($i = 1; $i <= Reader::MAX_OUTLINES; ++$i) {
            $outlines .= sprintf('<outline position="%d" xmlUrl="http://a.test/%d" />', $i, $i);
        }

        self::assertCount(
            Reader::MAX_OUTLINES,
            $this->reader->readFeeds('<opml version="2.0"><body>' . $outlines . '</body></opml>'),
        );
    }

    public function testAnOversizedDocumentIsRefusedBeforeParsing(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('bytes');
        $this->reader->readFeeds(str_repeat(' ', Reader::MAX_BYTES + 1));
    }

    /** The XXE payload must not turn into the contents of a local file. */
    public function testTheXxePayloadIsRefusedAndNoFileIsRead(): void
    {
        $payload = zf_fixture_contents('payloads/xxe.xml');
        self::assertStringContainsString('file:///etc/passwd', $payload);

        $caught = null;

        try {
            $this->reader->readFeeds($payload);
        } catch (StorageException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(StorageException::class, $caught);
        self::assertStringContainsString('DTD', $caught->getMessage());
        self::assertStringNotContainsString('root:', $caught->getMessage());
    }

    public function testAnOpmlDocumentWithADoctypeIsRefused(): void
    {
        $xml = '<?xml version="1.0"?>' . "\n"
            . '<!DOCTYPE opml [<!ENTITY secret SYSTEM "file:///etc/hostname">]>' . "\n"
            . '<opml version="2.0"><head><title>&secret;</title></head><body>'
            . '<outline position="1" title="&secret;" xmlUrl="http://a.test/f" /></body></opml>';

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('DTD');
        $this->reader->readFeeds($xml);
    }

    public function testTheBillionLaughsPayloadIsRefused(): void
    {
        $this->expectException(StorageException::class);
        $this->reader->readFeeds(zf_fixture_contents('payloads/billion-laughs.xml'));
    }

    public function testMalformedXmlIsRefused(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Cannot parse');
        $this->reader->readFeeds('<opml version="2.0"><body><outline></opml>');
    }

    public function testAnEmptyDocumentIsRefused(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('empty');
        $this->reader->readFeeds('   ');
    }

    public function testADocumentThatIsNotOpmlIsRefused(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('not an OPML document');
        $this->reader->readFeeds('<rss version="2.0"><channel><title>t</title></channel></rss>');
    }

    private function legacy(string $file): string
    {
        $path = \dirname(__DIR__, 3) . '/legacy/zfeeder-1.6/newsfeeds/categories/' . $file;
        $xml = file_get_contents($path);
        self::assertIsString($xml);

        return $xml;
    }
}
