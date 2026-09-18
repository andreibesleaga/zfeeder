<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Zfeeder\Admin\Controller\ImportController;

/**
 * S15 — the only upload the panel accepts is bounded by the bytes that actually
 * arrive, not by what the client said, and it is accepted or refused on what it
 * parses as, not on the content type the browser attached.
 *
 * This is a 2.0 surface: 1.6's import screen read whatever it was handed.
 *
 * The control lives in `src/Admin/Controller/ImportController::fromUpload()`,
 * which checks the declared size, then reads in 64 KiB chunks and stops the
 * moment the running total crosses `MAX_UPLOAD_BYTES`, and never consults
 * `UploadedFileInterface::getClientMediaType()`.
 */
final class S15UploadLimitsTest extends SecurityTestCase
{
    private const string VALID_OPML = '<?xml version="1.0"?><opml version="1.0"><head><title>list</title></head>'
        . '<body><outline type="rss" text="Feed" xmlUrl="https://example.com/f.xml" '
        . 'refreshTime="60" showedItems="3" isSubscribed="yes" position="1"/></body></opml>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function upload(
        string $contents,
        ?int $declaredSize = null,
        string $type = 'text/x-opml',
        string $name = 'subscriptions.opml',
    ): UploadedFileInterface {
        return new UploadedFile(
            Stream::create($contents),
            $declaredSize ?? strlen($contents),
            UPLOAD_ERR_OK,
            $name,
            $type,
        );
    }

    private function import(UploadedFileInterface $upload): ResponseInterface
    {
        return $this->send(
            'POST',
            '/admin/import',
            $this->withToken(['action' => 'import', 'category' => self::CATEGORY, 'mode' => 'replace']),
            [],
            ['opml_file' => $upload],
        );
    }

    private static function oversized(): string
    {
        return '<?xml version="1.0"?><opml version="1.0"><head><title>'
            . str_repeat('x', ImportController::MAX_UPLOAD_BYTES)
            . '</title></head><body/></opml>';
    }

    public function testAnUploadOverTheLimitIsRefusedByItsDeclaredSize(): void
    {
        $response = $this->import($this->upload(self::oversized()));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString((string) ImportController::MAX_UPLOAD_BYTES, self::bodyOf($response));
        self::assertSame([], $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAnUploadThatLiesAboutItsSizeIsStillRefusedByMeasurement(): void
    {
        // The declared size is a claim. This one says 42 bytes and sends more
        // than a megabyte; only counting what arrives catches it.
        $response = $this->import($this->upload(self::oversized(), 42));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('larger than', self::bodyOf($response));
        self::assertSame([], $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAnUploadThatDeclaresZeroBytesIsStillMeasured(): void
    {
        // "Nothing to see here" is the cheapest claim of all; the ceiling is
        // still enforced against the bytes that arrive.
        $response = $this->import($this->upload(self::oversized(), 0));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('larger than', self::bodyOf($response));
    }

    public function testAnUploadJustUnderTheLimitIsStillAccepted(): void
    {
        // The counterweight: the ceiling is a ceiling, not a refusal of every
        // upload. Padding goes in an XML comment so the document stays OPML.
        $padding = ImportController::MAX_UPLOAD_BYTES - strlen(self::VALID_OPML) - 32;
        $document = '<?xml version="1.0"?><!--' . str_repeat('x', $padding) . '-->'
            . substr(self::VALID_OPML, strlen('<?xml version="1.0"?>'));
        self::assertLessThan(ImportController::MAX_UPLOAD_BYTES, strlen($document));

        $response = $this->import($this->upload($document));

        self::assertSame(303, $response->getStatusCode(), self::bodyOf($response));
        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    /** @return iterable<string, array{string}> content types a browser might attach */
    public static function contentTypes(): iterable
    {
        yield 'opml' => ['text/x-opml'];
        yield 'xml' => ['application/xml'];
        yield 'octet stream' => ['application/octet-stream'];
        yield 'plain text' => ['text/plain'];
        yield 'html' => ['text/html'];
        yield 'php' => ['application/x-httpd-php'];
        yield 'empty' => [''];
    }

    #[DataProvider('contentTypes')]
    public function testTheClientsContentTypeIsNeverConsulted(string $type): void
    {
        // A real subscription list is imported whatever it was labelled, and
        // the label alone can neither open nor close the door.
        $response = $this->import($this->upload(self::VALID_OPML, null, $type, 'anything.bin'));

        self::assertSame(303, $response->getStatusCode(), 'refused because of the content type ' . $type);
        self::assertCount(1, $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    /** @return iterable<string, array{string}> documents that are not subscription lists */
    public static function notOpml(): iterable
    {
        yield 'php script' => ['<?php system($_GET["c"]); ?>'];
        yield 'html page' => ['<!DOCTYPE html><html><body><h1>hello</h1></body></html>'];
        yield 'an rss feed' => ['<?xml version="1.0"?><rss version="2.0"><channel><title>t</title></channel></rss>'];
        yield 'json' => ['{"version":"https://jsonfeed.org/version/1","items":[]}'];
        yield 'plain text' => ['just some text'];
        yield 'a zip header' => ["PK\x03\x04\x14\x00\x00\x00"];
        yield 'an empty file' => [''];
        yield 'whitespace only' => ["   \n\t  "];
        yield 'xml that is not opml' => ['<?xml version="1.0"?><subscriptions><feed url="https://x.example/f.xml"/></subscriptions>'];
    }

    #[DataProvider('notOpml')]
    public function testAFileThatIsNotOpmlIsRefusedHoweverItIsLabelled(string $contents): void
    {
        $response = $this->import($this->upload($contents, null, 'text/x-opml', 'subscriptions.opml'));

        self::assertSame(422, $response->getStatusCode(), 'accepted a file that is not OPML');
        self::assertSame([], $this->kernel->subscriptions()->category(self::CATEGORY)->feeds);
    }

    public function testAPhpScriptUploadedAsAListIsNeverWrittenAnywhere(): void
    {
        $before = $this->dataDirectoryFingerprint();

        $this->import($this->upload('<?php system($_GET["c"]); ?>', null, 'text/x-opml', 'shell.php'));

        self::assertSame($before, $this->dataDirectoryFingerprint(), 'the upload left something on disk');

        self::assertSame(
            [],
            self::sourceFiles($this->config->dataDir()),
            'a .php file was written into the data directory',
        );
    }

    public function testAnUploadErrorIsReportedRatherThanIgnored(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_CANT_WRITE] as $error) {
            $response = $this->import(new UploadedFile(
                Stream::create(''),
                0,
                $error,
                'subscriptions.opml',
                'text/x-opml',
            ));

            self::assertSame(422, $response->getStatusCode(), 'upload error ' . $error . ' was ignored');
        }
    }

    public function testTheCeilingIsTheSameForAnUploadAndForAUrlImport(): void
    {
        // One number, applied to both doors: `MAX_UPLOAD_BYTES` is what the
        // controller hands to `GuardedFetch` for a URL import.
        $controller = self::readFile(self::projectRoot() . '/src/Admin/Controller/ImportController.php');

        self::assertStringContainsString('$this->guardedFetch(self::MAX_UPLOAD_BYTES)', $controller);
        self::assertSame(1048576, ImportController::MAX_UPLOAD_BYTES);
    }
}
