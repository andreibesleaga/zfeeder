<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Exception\FetchException;
use Zfeeder\Exception\SecurityException;
use Zfeeder\Exception\StorageException;
use Zfeeder\Http\Responder;
use Zfeeder\Subscription\Category;

/**
 * Importing and exporting subscription lists.
 *
 * An OPML file is the most dangerous thing this panel accepts: it is XML, it
 * arrives from elsewhere, and it is full of URLs that the aggregator will
 * later fetch. Three separate limits apply, and none of them trusts the
 * client's description of what it sent:
 *
 *  - a size ceiling, checked against the declared size *and* against the bytes
 *    actually read, because `Content-Length` is a claim;
 *  - the parser's own outline ceiling and its refusal of document type
 *    declarations, which is where an entity expansion attempt dies;
 *  - UrlGuard on the URL form, because "import this list from a URL" is a
 *    request forgery primitive if it is not guarded.
 *
 * The content type the browser attached is never consulted. It is trivially
 * forged, and a file that parses as OPML is OPML whatever it was labelled.
 */
final class ImportController extends AbstractController
{
    protected const string SCREEN = 'import';

    /** One megabyte holds roughly ten thousand outlines; the parser stops at five hundred. */
    public const int MAX_UPLOAD_BYTES = 1048576;

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render('import.twig', $this->screenVars());
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfFailure($request);
        }

        $body = $this->body($request);
        $category = $this->resolveCategoryName($this->field($body, 'category'));
        $replace = $this->field($body, 'mode') === 'replace';

        try {
            $opml = $this->source($request, $body);
        } catch (SecurityException | FetchException | \RuntimeException $e) {
            return $this->failure($e->getMessage());
        }

        if ($opml === '') {
            return $this->failure('Choose a file to upload, or type the address of a subscription list.');
        }

        try {
            $imported = $this->kernel->subscriptions()->importOpml($category, $opml, $replace);
        } catch (StorageException $e) {
            return $this->failure($e->getMessage());
        }

        $this->context->addFlash(AdminContext::LEVEL_SUCCESS, sprintf(
            'Imported %d subscription%s into "%s" (%s).',
            $imported,
            $imported === 1 ? '' : 's',
            $category,
            $replace ? 'replacing the list' : 'added to the list',
        ));

        return $this->redirect('/admin/import');
    }

    /** `GET /admin/export/{category}` — the same OPML 1.6 wrote. */
    public function export(ServerRequestInterface $request, string $category): ResponseInterface
    {
        if (!Category::isValidName($category) || !$this->kernel->subscriptions()->has($category)) {
            $this->context->addFlash(AdminContext::LEVEL_ERROR, 'There is no category called "' . $category . '".');

            return $this->render('import.twig', $this->screenVars(), 404);
        }

        return Responder::xml($this->kernel->subscriptions()->exportOpml($category), 200, [
            'Content-Disposition' => sprintf('attachment; filename="%s.opml"', $category),
        ]);
    }

    // ---- where the document comes from -----------------------------------

    /**
     * @param array<string, mixed> $body
     *
     * @throws SecurityException
     * @throws FetchException
     * @throws \RuntimeException on an upload that is too large or broken
     */
    private function source(ServerRequestInterface $request, array $body): string
    {
        $upload = $request->getUploadedFiles()['opml_file'] ?? null;
        if ($upload instanceof UploadedFileInterface && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
            return $this->fromUpload($upload);
        }

        $url = $this->field($body, 'opml_url');
        if ($url === '') {
            return '';
        }

        return $this->guardedFetch(self::MAX_UPLOAD_BYTES)->get($url, [
            'Accept' => 'text/x-opml+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.1',
        ]);
    }

    /** @throws \RuntimeException */
    private function fromUpload(UploadedFileInterface $upload): string
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadError($upload->getError()));
        }

        $size = $upload->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException(sprintf(
                'That file is %d bytes; subscription lists are limited to %d bytes.',
                $size,
                self::MAX_UPLOAD_BYTES,
            ));
        }

        $stream = $upload->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        // Read in chunks and stop at the ceiling. A stream hands back what it
        // feels like per call (8 KiB for php://temp), so one big read would
        // silently accept a file that is over the limit.
        $contents = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
            if (strlen($contents) > self::MAX_UPLOAD_BYTES) {
                // The declared size was a claim; this is the measurement.
                throw new \RuntimeException(sprintf(
                    'That file is larger than the %d byte limit for subscription lists.',
                    self::MAX_UPLOAD_BYTES,
                ));
            }
        }

        if (trim($contents) === '') {
            throw new \RuntimeException('That file is empty.');
        }

        return $contents;
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than this server accepts.',
            UPLOAD_ERR_PARTIAL => 'That file only arrived in part. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server has nowhere to put the upload.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension refused the upload.',
            default => 'That file could not be read.',
        };
    }

    // ---- rendering -------------------------------------------------------

    private function failure(string $message): ResponseInterface
    {
        // Shown once, next to the controls it is about, as an alert: repeating
        // it in the flash region would announce the same sentence twice.
        return $this->render('import.twig', $this->screenVars($message), 422);
    }

    /** @return array<string, mixed> */
    private function screenVars(string $error = ''): array
    {
        $categories = $this->categoryNames();
        $exports = [];
        foreach ($categories as $name) {
            $exports[] = ['name' => $name, 'href' => $this->context->url('/admin/export/' . rawurlencode($name))];
        }

        return [
            'title' => 'Import feed list',
            'categories' => $categories,
            'selected_category' => $this->resolveCategoryName(''),
            'exports' => $exports,
            'max_bytes' => self::MAX_UPLOAD_BYTES,
            'max_outlines' => \Zfeeder\Subscription\Opml\Reader::MAX_OUTLINES,
            'error' => $error,
        ];
    }
}
