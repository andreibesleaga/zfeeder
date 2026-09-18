<?php

declare(strict_types=1);

namespace Zfeeder\Subscription\Opml;

use Zfeeder\Exception\StorageException;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * Reads an OPML subscription list.
 *
 * The 1.6 files must load unchanged, so the quirks of the 2004 expat parser are
 * reproduced deliberately:
 *
 * - attribute names are matched case-insensitively (expat upper-cased them, and
 *   the files mix `xmlUrl` with `refreshTime` and `showedItems`);
 * - an outline without a `position` attribute is not a subscription at all and
 *   is skipped, exactly as `opmlStartElement()` did;
 * - missing `refreshTime` means 60 minutes, missing `showedItems` means 0 (so
 *   the feed is stored but not rendered) and a missing `isSubscribed` means no.
 *
 * What is *not* reproduced is the 2004 security posture: the document is parsed
 * with the network disabled, with entity substitution off and with any DTD
 * refused outright, which closes XXE and billion-laughs in one step.
 *
 * Usage:
 *   $category = $reader->readCategory('news', $xml);   // name comes from the file name
 *   $feeds    = $reader->readFeeds($xml);              // list<Feed>, document order
 *   $head     = $reader->readHead($xml);               // title / dateModified / owner
 */
final class Reader
{
    /** OPML import guard: a subscription list this long is an attack, not a feed list. */
    public const int MAX_OUTLINES = 500;

    /** Refuse obviously oversized documents before handing them to libxml. */
    public const int MAX_BYTES = 4_194_304;

    /** @see Feed the same defaults 1.6's opmlStartElement() applied */
    private const int DEFAULT_REFRESH_MINUTES = 60;
    private const int DEFAULT_SHOWED_ITEMS = 0;

    /**
     * The whole category: head metadata plus every usable outline.
     *
     * The category name is the caller's (the file name, or the requested
     * category), not the document's `<title>`: the file name is what the rest of
     * the program addresses a category by, and a hostile import must not be able
     * to rename itself onto another category.
     *
     * @throws StorageException on malformed, oversized or hostile documents
     */
    public function readCategory(string $name, string $xml): Category
    {
        $document = $this->parse($xml);

        $head = $this->headOf($document);

        return new Category(
            $name,
            $this->feedsOf($document),
            $head['dateModified'],
            $head['ownerName'],
            $head['ownerEmail'],
        );
    }

    /**
     * @return list<Feed> in document order; ordering by position is the caller's business
     *
     * @throws StorageException
     */
    public function readFeeds(string $xml): array
    {
        return $this->feedsOf($this->parse($xml));
    }

    /**
     * @return array{title: string, dateModified: ?\DateTimeImmutable, ownerName: string, ownerEmail: string}
     *
     * @throws StorageException
     */
    public function readHead(string $xml): array
    {
        return $this->headOf($this->parse($xml));
    }

    /** @return list<Feed> */
    private function feedsOf(\DOMDocument $document): array
    {
        $outlines = $document->getElementsByTagName('outline');
        if ($outlines->length > self::MAX_OUTLINES) {
            throw new StorageException(sprintf(
                'The subscription list has %d entries; at most %d are accepted.',
                $outlines->length,
                self::MAX_OUTLINES,
            ));
        }

        $feeds = [];
        foreach ($outlines as $outline) {
            if (!$outline instanceof \DOMElement) {
                continue;
            }
            $attributes = $this->attributesOf($outline);

            // 1.6 wrote `position` on every subscription and ignored outlines
            // without one, which is how nested OPML folders were stepped over.
            $position = trim($attributes['position'] ?? '');
            if ($position === '') {
                continue;
            }

            // A subscription with no address cannot be fetched or written back,
            // so it is dropped rather than carried along as an empty row.
            $xmlUrl = trim($attributes['xmlurl'] ?? '');
            if ($xmlUrl === '') {
                continue;
            }

            // 1.6's admin wrote `text` and `title` with the same value; other
            // readers write only one of them, so either will do.
            $title = $attributes['title'] ?? '';
            if ($title === '') {
                $title = $attributes['text'] ?? '';
            }

            $feeds[] = new Feed(
                $xmlUrl,
                $title,
                $attributes['description'] ?? '',
                $attributes['htmlurl'] ?? '',
                $this->toInt($position, 1),
                $this->toInt($attributes['refreshtime'] ?? '', self::DEFAULT_REFRESH_MINUTES),
                $this->toInt($attributes['showeditems'] ?? '', self::DEFAULT_SHOWED_ITEMS),
                strtolower(trim($attributes['issubscribed'] ?? '')) === 'yes',
                $attributes['language'] ?? '',
            );
        }

        return $feeds;
    }

    /**
     * @return array{title: string, dateModified: ?\DateTimeImmutable, ownerName: string, ownerEmail: string}
     */
    private function headOf(\DOMDocument $document): array
    {
        return [
            'title' => $this->headValue($document, 'title'),
            'dateModified' => $this->toDate($this->headValue($document, 'dateModified')),
            'ownerName' => $this->headValue($document, 'ownerName'),
            'ownerEmail' => $this->headValue($document, 'ownerEmail'),
        ];
    }

    private function headValue(\DOMDocument $document, string $tag): string
    {
        $heads = $document->getElementsByTagName('head');
        $head = $heads->item(0);
        if (!$head instanceof \DOMElement) {
            return '';
        }
        foreach ($head->getElementsByTagName($tag) as $element) {
            return trim($element->textContent);
        }

        return '';
    }

    /**
     * Attribute names, lower-cased, so `xmlUrl`, `xmlurl` and `XMLURL` all match.
     *
     * @return array<string, string>
     */
    private function attributesOf(\DOMElement $element): array
    {
        $out = [];
        foreach ($element->attributes as $attribute) {
            if ($attribute instanceof \DOMAttr) {
                $out[strtolower($attribute->name)] = $attribute->value;
            }
        }

        return $out;
    }

    private function toInt(string $raw, int $fallback): int
    {
        $raw = trim($raw);

        return preg_match('/^[+-]?\d+$/', $raw) === 1 ? (int) $raw : $fallback;
    }

    /**
     * OPML dates are RFC 822/2822; anything unreadable is simply unknown.
     *
     * `strtotime()` rather than the DateTimeImmutable constructor: it answers
     * false instead of throwing, so a 2004 file with a typo in its date cannot
     * take a whole category down.
     */
    private function toDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @throws StorageException */
    private function parse(string $xml): \DOMDocument
    {
        if (\strlen($xml) > self::MAX_BYTES) {
            throw new StorageException(sprintf(
                'The subscription list is %d bytes; at most %d are accepted.',
                \strlen($xml),
                self::MAX_BYTES,
            ));
        }
        if (trim($xml) === '') {
            throw new StorageException('The subscription list is empty.');
        }
        // A literal `<!DOCTYPE` cannot occur in the content of a well-formed XML
        // document — `<` must be escaped there — so finding one means the
        // document really does carry a DTD. Rejecting it here, before libxml
        // sees it, gives one clear answer for XXE and billion-laughs alike
        // instead of whichever parse error the payload happens to trigger.
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new StorageException('The subscription list declares a DTD, which is not allowed.');
        }

        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        libxml_set_external_entity_loader(self::denyExternalEntities(...));

        try {
            $document = new \DOMDocument();
            // No LIBXML_NOENT: entity references are left unexpanded, so a
            // `&xxe;` in the document text can never become file contents.
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
            if ($loaded === false) {
                throw new StorageException('Cannot parse the subscription list: ' . $this->firstError());
            }
            if ($document->doctype !== null) {
                throw new StorageException('The subscription list declares a DTD, which is not allowed.');
            }
            $root = $document->documentElement;
            if ($root === null || strtolower($root->nodeName) !== 'opml') {
                throw new StorageException('The subscription list is not an OPML document.');
            }

            return $document;
        } finally {
            libxml_set_external_entity_loader(null);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function firstError(): string
    {
        $errors = libxml_get_errors();
        $first = $errors[0] ?? null;

        return $first instanceof \LibXMLError ? trim($first->message) . ' at line ' . $first->line : 'unknown error';
    }

    /**
     * Refuses every external entity. libxml consults this before touching the
     * filesystem or the network, so it is the belt to LIBXML_NONET's braces.
     *
     * @param array<string, mixed> $context
     */
    private static function denyExternalEntities(?string $publicId, ?string $systemId, array $context): null
    {
        return null;
    }
}
