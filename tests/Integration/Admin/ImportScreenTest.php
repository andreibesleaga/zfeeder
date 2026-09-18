<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Admin\Controller\ImportController;
use Zfeeder\Subscription\Feed;
use Zfeeder\Subscription\Opml\Reader;

/**
 * Importing a subscription list, which is the panel's widest door: XML from
 * elsewhere, full of addresses this installation will later fetch.
 */
final class ImportScreenTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private static function opml(int $outlines): string
    {
        $body = '';
        for ($i = 1; $i <= $outlines; ++$i) {
            $body .= sprintf(
                '<outline type="rss" text="Feed %1$d" title="Feed %1$d" xmlUrl="https://example.com/%1$d.xml" '
                . 'htmlUrl="https://example.com/%1$d" refreshTime="60" showedItems="3" isSubscribed="yes" position="%1$d" />',
                $i,
            );
        }

        return '<?xml version="1.0"?><opml version="1.0"><head><title>list</title></head><body>' . $body . '</body></opml>';
    }

    private static function upload(string $contents, ?int $declaredSize = null): UploadedFileInterface
    {
        return new UploadedFile(
            Stream::create($contents),
            $declaredSize ?? strlen($contents),
            UPLOAD_ERR_OK,
            'subscriptions.opml',
            'text/x-opml',
        );
    }

    public function testTheScreenOffersUploadUrlAndExport(): void
    {
        $body = self::bodyOf($this->send('GET', '/admin/import'));

        self::assertStringContainsString('for="opml_file"', $body);
        self::assertStringContainsString('for="opml_url"', $body);
        self::assertStringContainsString('/admin/export/zfeeder', $body);
    }

    public function testAnUploadedListIsImported(): void
    {
        $response = $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY, 'mode' => 'append']),
            [],
            ['opml_file' => self::upload(self::opml(3))],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertCount(3, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testImportingCanAppendOrReplace(): void
    {
        $this->withFeeds([new Feed('https://kept.example/feed.xml', 'Kept')]);

        $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY, 'mode' => 'append']),
            [],
            ['opml_file' => self::upload(self::opml(2))],
        );
        self::assertCount(3, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);

        $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY, 'mode' => 'replace']),
            [],
            ['opml_file' => self::upload(self::opml(2))],
        );
        self::assertCount(2, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testTheNumberImportedIsReported(): void
    {
        $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY, 'mode' => 'append']),
            [],
            ['opml_file' => self::upload(self::opml(4))],
        );

        self::assertStringContainsString('Imported 4 subscriptions', self::bodyOf($this->send('GET', '/admin/import')));
    }

    public function testAnOversizedUploadIsRefused(): void
    {
        $oversized = str_pad('<?xml version="1.0"?><opml version="1.0"><head/><body>', ImportController::MAX_UPLOAD_BYTES + 100, ' ');

        $response = $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY]),
            [],
            ['opml_file' => self::upload($oversized)],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('limited to', self::bodyOf($response));
        self::assertSame([], $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAnUploadThatLiesAboutItsSizeIsStillRefused(): void
    {
        $oversized = str_repeat('x', ImportController::MAX_UPLOAD_BYTES + 100);

        $response = $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY]),
            [],
            ['opml_file' => self::upload($oversized, 10)],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('larger than', self::bodyOf($response));
    }

    public function testAListWithTooManyOutlinesIsRefused(): void
    {
        $tooMany = self::opml(600);
        self::assertGreaterThan(Reader::MAX_OUTLINES, 600);

        $response = $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY]),
            [],
            ['opml_file' => self::upload($tooMany)],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testADocumentTypeDeclarationIsRefused(): void
    {
        $hostile = '<?xml version="1.0"?><!DOCTYPE opml [<!ENTITY x "boom">]><opml version="1.0"><head/><body/></opml>';

        $response = $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY]),
            [],
            ['opml_file' => self::upload($hostile)],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('DTD', self::bodyOf($response));
    }

    public function testAnEmptyFormSaysWhatIsMissing(): void
    {
        $response = $this->send('POST', '/admin/import', $this->withToken([
            'action' => 'import',
            'category' => self::CATEGORY,
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Choose a file', self::bodyOf($response));
    }

    public function testAListCanBeImportedFromAnAddress(): void
    {
        $this->mockHttp([new MockResponse(self::opml(2), ['response_headers' => ['content-type' => 'text/xml']])]);

        $response = $this->send('POST', '/admin/import', $this->withToken([
            'action' => 'import',
            'category' => self::CATEGORY,
            'mode' => 'append',
            'opml_url' => 'https://example.com/subscriptions.opml',
        ]));

        self::assertSame(303, $response->getStatusCode());
        self::assertCount(2, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAPrivateAddressIsRefused(): void
    {
        $this->bootKernel(['allow_private_hosts' => false]);
        $this->signIn();

        $response = $this->send('POST', '/admin/import', $this->withToken([
            'action' => 'import',
            'category' => self::CATEGORY,
            'opml_url' => 'http://10.0.0.5/subscriptions.opml',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('private', self::bodyOf($response));
    }

    public function testACategoryCanBeExported(): void
    {
        $this->withFeeds([new Feed('https://example.com/feed.xml', 'Example', 'An example', 'https://example.com/')]);

        $response = $this->send('GET', '/admin/export/zfeeder');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('opml', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment; filename="zfeeder.opml"', $response->getHeaderLine('Content-Disposition'));

        $body = self::bodyOf($response);
        self::assertStringContainsString('<opml', $body);
        self::assertStringContainsString('https://example.com/feed.xml', $body);
    }

    public function testExportingAnUnknownCategoryIsANotFound(): void
    {
        self::assertSame(404, $this->send('GET', '/admin/export/nothing-here')->getStatusCode());
    }
}
