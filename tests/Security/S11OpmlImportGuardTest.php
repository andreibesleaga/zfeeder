<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Admin\Controller\ImportController;
use Zfeeder\Exception\StorageException;
use Zfeeder\Subscription\Feed;
use Zfeeder\Subscription\Opml\Reader;

/**
 * S11 — importing a subscription list is bounded in three ways at once: at most
 * `Reader::MAX_OUTLINES` entries, at most `ImportController::MAX_UPLOAD_BYTES`
 * of document, and a URL import goes through the address guard. An import that
 * fails changes nothing.
 *
 * Closes 1.6 defect L15: `newsfeeds/includes/importlist.php:53` onwards read
 * whatever it was given, with no outline limit and no size limit, and fetched a
 * remote list with the same unguarded `fopen()` as everything else.
 *
 * The control lives in `src/Subscription/Opml/Reader.php` (`MAX_OUTLINES`,
 * `MAX_BYTES`), `src/Admin/Controller/ImportController.php` (the upload ceiling
 * and the guarded fetch for a URL) and
 * `src/Storage/AbstractSubscriptionStore::importOpml()`, which parses before it
 * writes.
 */
final class S11OpmlImportGuardTest extends SecurityTestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootKernel(['allow_private_hosts' => false]);
        $this->seedCategory();
        $this->signIn();
        $this->http = new MockHttpClient([]);
        $this->kernel->withHttpClient($this->http);
    }

    private static function opml(int $outlines): string
    {
        $body = '';
        for ($i = 1; $i <= $outlines; ++$i) {
            $body .= sprintf(
                '<outline type="rss" text="Feed %1$d" xmlUrl="https://example.com/%1$d.xml" '
                . 'refreshTime="60" showedItems="3" isSubscribed="yes" position="%1$d" />',
                $i,
            );
        }

        return '<?xml version="1.0"?><opml version="1.0"><head><title>list</title></head><body>' . $body . '</body></opml>';
    }

    private static function upload(string $contents, ?int $declaredSize = null, string $type = 'text/x-opml'): UploadedFileInterface
    {
        return new UploadedFile(
            Stream::create($contents),
            $declaredSize ?? strlen($contents),
            UPLOAD_ERR_OK,
            'subscriptions.opml',
            $type,
        );
    }

    /** @param array<string, mixed> $body */
    private function import(array $body, ?UploadedFileInterface $upload = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY, 'mode' => 'replace'] + $body),
            [],
            $upload === null ? [] : ['opml_file' => $upload],
        );
    }

    public function testAListOfSixHundredOutlinesIsRefused(): void
    {
        $this->withFeeds([new Feed('https://kept.example/feed.xml', 'Kept', '', '', 1, 60, 3, true)]);

        $response = $this->import([], self::upload(self::opml(600)));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('at most ' . Reader::MAX_OUTLINES, self::bodyOf($response));
        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds, 'the category was replaced anyway');
    }

    public function testExactlyFiveHundredOutlinesIsStillAccepted(): void
    {
        // The counterweight: the limit is a limit, not a refusal of everything.
        $response = $this->import([], self::upload(self::opml(Reader::MAX_OUTLINES)));

        self::assertSame(303, $response->getStatusCode());
        self::assertCount(Reader::MAX_OUTLINES, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testTheReaderItselfCountsOutlinesBeforeBuildingAnything(): void
    {
        $reader = new Reader();

        self::assertCount(Reader::MAX_OUTLINES, $reader->readFeeds(self::opml(Reader::MAX_OUTLINES)));

        $this->expectException(StorageException::class);
        $reader->readFeeds(self::opml(Reader::MAX_OUTLINES + 1));
    }

    public function testATwoMegabyteUploadIsRefused(): void
    {
        $this->withFeeds([new Feed('https://kept.example/feed.xml', 'Kept', '', '', 1, 60, 3, true)]);

        $padding = str_repeat(' ', 2 * 1024 * 1024);
        $oversized = '<?xml version="1.0"?><opml version="1.0"><head><title>x</title>' . $padding . '</head><body/></opml>';
        self::assertGreaterThan(2_000_000, strlen($oversized));

        $response = $this->import([], self::upload($oversized));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString((string) ImportController::MAX_UPLOAD_BYTES, self::bodyOf($response));
        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testARemoteImportGoesThroughTheAddressGuard(): void
    {
        $this->withFeeds([new Feed('https://kept.example/feed.xml', 'Kept', '', '', 1, 60, 3, true)]);

        foreach (['http://127.0.0.1/list.opml', 'file:///etc/passwd', 'http://169.254.169.254/'] as $url) {
            $response = $this->import(['opml_url' => $url]);

            self::assertSame(422, $response->getStatusCode(), $url . ' was imported');
            self::assertSame(0, $this->http->getRequestsCount(), $url . ' reached the transport');
            self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
        }
    }

    public function testARemoteImportFromAPublicAddressStillWorks(): void
    {
        $http = new MockHttpClient([new MockResponse(self::opml(3))]);
        $this->kernel->withHttpClient($http);

        $response = $this->import(['opml_url' => 'http://203.0.113.9/list.opml']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(1, $http->getRequestsCount());
        self::assertCount(3, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testARemoteImportIsBoundedByTheSameCeilingAsAnUpload(): void
    {
        $padding = str_repeat(' ', ImportController::MAX_UPLOAD_BYTES + 1024);
        $http = new MockHttpClient([new MockResponse('<opml version="1.0"><head/>' . $padding . '<body/></opml>')]);
        $this->kernel->withHttpClient($http);

        $response = $this->import(['opml_url' => 'http://203.0.113.9/list.opml']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAnImportThatFailsLeavesTheExistingCategoryExactlyAsItWas(): void
    {
        $original = [
            new Feed('https://one.example/feed.xml', 'One', 'first', 'https://one.example/', 1, 30, 5, true),
            new Feed('https://two.example/feed.xml', 'Two', 'second', 'https://two.example/', 2, 90, 2, true),
        ];
        $this->withFeeds($original);
        $before = $this->kernel->subscriptions()->exportOpml(self::CATEGORY);

        $failures = [
            'too many outlines' => self::opml(Reader::MAX_OUTLINES + 1),
            'a document type declaration' => '<?xml version="1.0"?><!DOCTYPE opml><opml version="1.0"><head/><body/></opml>',
            'not OPML at all' => '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title></channel></rss>',
            'not XML at all' => 'this is not a subscription list',
        ];

        foreach ($failures as $why => $document) {
            $response = $this->import([], self::upload($document));

            self::assertSame(422, $response->getStatusCode(), $why . ' was accepted');
            self::assertSame($before, $this->kernel->subscriptions()->exportOpml(self::CATEGORY), 'the category changed after ' . $why);
        }
    }

    public function testAnImportedListCannotRenameItselfOntoAnotherCategory(): void
    {
        // The category is the caller's name, never the document's `<title>`, so
        // an import cannot be aimed at a category the administrator did not
        // choose.
        $document = '<?xml version="1.0"?><opml version="1.0"><head><title>../../elsewhere</title></head>'
            . '<body><outline text="f" xmlUrl="https://x.example/f.xml" position="1" isSubscribed="yes" showedItems="3"/></body></opml>';

        $response = $this->import([], self::upload($document));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame([self::CATEGORY], $this->kernel->subscriptions()->categories());
    }
}
