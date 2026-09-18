<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Subscription\Opml\Reader;
use Zfeeder\Subscription\Opml\Writer;

#[CoversClass(Writer::class)]
final class OpmlWriterTest extends TestCase
{
    private Writer $writer;

    private Reader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new Writer();
        $this->reader = new Reader();
    }

    public function testTheDocumentIsOpmlTwoPointZero(): void
    {
        $opml = $this->writer->write(new Category('news'));

        self::assertStringStartsWith('<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<opml version="2.0">', $opml);
        self::assertStringEndsWith('</opml>' . "\n", $opml);
    }

    public function testTheHeadCarriesTheCategoryAndOwner(): void
    {
        $category = new Category(
            'news',
            [],
            new \DateTimeImmutable('2004-02-25 14:00:00', new \DateTimeZone('UTC')),
            'Andrei',
            'owner@example.org',
        );

        $opml = $this->writer->write($category);

        self::assertStringContainsString('<title>news</title>', $opml);
        self::assertStringContainsString('<dateModified>Wed, 25 Feb 2004 14:00:00 GMT</dateModified>', $opml);
        self::assertStringContainsString('<ownerName>Andrei</ownerName>', $opml);
        self::assertStringContainsString('<ownerEmail>owner@example.org</ownerEmail>', $opml);
    }

    public function testDatesAreWrittenInGmtWhateverTheSourceTimezone(): void
    {
        $category = new Category('news', [], new \DateTimeImmutable('2004-02-25 16:00:00', new \DateTimeZone('Europe/Bucharest')));

        self::assertStringContainsString('<dateModified>Wed, 25 Feb 2004 14:00:00 GMT</dateModified>', $this->writer->write($category));
    }

    public function testTheClockIsOnlyUsedWhenTheCategoryHasNoDate(): void
    {
        $now = new \DateTimeImmutable('2026-09-18 08:30:00', new \DateTimeZone('UTC'));

        self::assertStringContainsString(
            '<dateModified>Fri, 18 Sep 2026 08:30:00 GMT</dateModified>',
            $this->writer->write(new Category('news'), $now),
        );
        self::assertStringContainsString(
            '<dateModified>Wed, 25 Feb 2004 14:00:00 GMT</dateModified>',
            $this->writer->write(
                new Category('news', [], new \DateTimeImmutable('2004-02-25 14:00:00', new \DateTimeZone('UTC'))),
                $now,
            ),
        );
    }

    public function testAnUnknownDateIsOmittedRatherThanInvented(): void
    {
        self::assertStringNotContainsString('dateModified', $this->writer->write(new Category('news')));
    }

    public function testOutlineAttributesAreWrittenInAFixedOrder(): void
    {
        $opml = $this->writer->write(new Category('news', [
            new Feed('http://a.test/f', 'A', 'D', 'http://a.test/', 1, 120, 5, true, 'en'),
        ]));

        self::assertStringContainsString(
            '<outline type="rss" position="1" text="A" title="A" description="D"'
            . ' xmlUrl="http://a.test/f" htmlUrl="http://a.test/" refreshTime="120" showedItems="5"'
            . ' isSubscribed="yes" language="en" />',
            $opml,
        );
    }

    public function testUnsubscribedFeedsAreMarkedNo(): void
    {
        $opml = $this->writer->write(new Category('news', [new Feed('http://a.test/f', 'A', subscribed: false)]));

        self::assertStringContainsString('isSubscribed="no"', $opml);
    }

    public function testMarkupInFieldsIsEscaped(): void
    {
        $opml = $this->writer->write(new Category('news', [
            new Feed('http://a.test/f?x=1&y=2', 'A & <b>B</b>', 'He said "hi" & \'bye\'', '', 1),
        ]));

        self::assertStringContainsString('xmlUrl="http://a.test/f?x=1&amp;y=2"', $opml);
        self::assertStringContainsString('title="A &amp; &lt;b&gt;B&lt;/b&gt;"', $opml);
        self::assertStringContainsString('description="He said &quot;hi&quot; &amp; &apos;bye&apos;"', $opml);
        self::assertNotFalse(simplexml_load_string($opml));
    }

    public function testControlCharactersAreStrippedSoTheOutputStaysWellFormed(): void
    {
        $opml = $this->writer->write(new Category('news', [
            new Feed('http://a.test/f', "Bell\x07 and null\x00 inside", position: 1),
        ]));

        self::assertStringContainsString('title="Bell and null inside"', $opml);
        self::assertNotFalse(simplexml_load_string($opml));
    }

    public function testOutputIsByteStable(): void
    {
        $category = new Category(
            'news',
            [new Feed('http://a.test/f', 'A', 'D', 'http://a.test/', 1, 120, 5, true, 'en')],
            new \DateTimeImmutable('2004-02-25 14:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame($this->writer->write($category), $this->writer->write($category));
        self::assertSame($this->writer->write($category), (new Writer())->write($category));
    }

    public function testWhatIsWrittenIsWhatIsReadBack(): void
    {
        $category = new Category(
            'news',
            [
                new Feed('http://a.test/f', 'A & co', 'Desc', 'http://a.test/', 1, 120, 5, true, 'en'),
                new Feed('http://b.test/f', '', '', '', 2, 60, 0, false, ''),
            ],
            new \DateTimeImmutable('2004-02-25 14:00:00', new \DateTimeZone('UTC')),
            'Andrei',
            'owner@example.org',
        );

        $again = $this->reader->readCategory('news', $this->writer->write($category));

        self::assertEquals($category, $again);
    }

    /** @return array<string, array{string}> */
    public static function legacyFileProvider(): array
    {
        $files = glob(\dirname(__DIR__, 3) . '/tests/fixtures/legacy-1.6/newsfeeds/categories/*.opml');
        $cases = [];
        foreach ($files === false ? [] : $files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    /** Reader → Writer → Reader must lose nothing from a real 2004 file. */
    #[DataProvider('legacyFileProvider')]
    public function testRoundTrippingALegacyFileIsLossless(string $file): void
    {
        $name = basename($file, '.opml');
        $xml = file_get_contents($file);
        self::assertIsString($xml);

        $original = $this->reader->readCategory($name, $xml);
        $written = $this->writer->write($original);
        $again = $this->reader->readCategory($name, $written);

        self::assertEquals($original, $again);
        self::assertSame($written, $this->writer->write($again));
    }
}
