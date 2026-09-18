<?php

declare(strict_types=1);

namespace Zfeeder\Parse;

use Laminas\Feed\Reader\Feed\AbstractFeed;
use Laminas\Feed\Reader\Reader;
use Zfeeder\Exception\ParseException;
use Zfeeder\Parse\Model\Channel;
use Zfeeder\Parse\Model\Enclosure;
use Zfeeder\Parse\Model\Item;

/**
 * Feed bytes to one normalised Channel, whatever the format was.
 *
 * Two principles run through this class.
 *
 * *Be lenient about content, strict about structure.* A feed with no titles,
 * no items, or elements nobody has ever heard of is rendered as best we can:
 * 2004 feeds are full of such things and refusing them would defeat the point
 * of an aggregator. Malformed XML, malformed JSON and document type
 * declarations are fatal, because those are not oddities, they are attacks or
 * corruption.
 *
 * *Read the document twice.* laminas-feed detects the format and validates the
 * XML; the mapping below then works on the same DOM directly. That is what
 * makes `rawDate` possible — the classic templates print the publication date
 * exactly as the feed wrote it, byte for byte, and no normalising reader can
 * give that back once it has parsed the string.
 *
 * @see DateParser for why a date may come back null
 */
final class FeedParser
{
    private const string NS_ATOM = 'http://www.w3.org/2005/Atom';
    private const string NS_RSS10 = 'http://purl.org/rss/1.0/';
    private const string NS_RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const string NS_DC = 'http://purl.org/dc/elements/1.1/';
    private const string NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    /**
     * The 2004 RSS 0.91 DTDs. A document type declaration naming one of these
     * and carrying no internal subset is harmless — there is nothing in it to
     * expand — and half the feeds of that era open with one, so refusing them
     * would break the compatibility this rebuild exists to prove.
     *
     * @var list<string>
     */
    private const array HISTORIC_DOCTYPE_IDS = [
        '-//netscape communications//dtd rss 0.91//en',
        'http://my.netscape.com/publish/formats/rss-0.91.dtd',
        '-//userland//dtd rss 0.91//en',
        'http://backend.userland.com/rss091',
    ];

    /**
     * @param bool $allowHistoricDoctype false refuses every `<!DOCTYPE`, which is
     *        the stricter reading of the security requirement; the default also
     *        accepts the two subset-free RSS 0.91 declarations above
     */
    public function __construct(
        private readonly JsonFeedReader $json = new JsonFeedReader(),
        private readonly bool $allowHistoricDoctype = true,
    ) {
    }

    /** @throws ParseException when the document is not a feed we can read safely */
    public function parse(string $body, string $sourceUrl = ''): Channel
    {
        if (trim($body) === '') {
            throw new ParseException('The feed is empty.');
        }

        if (JsonFeedReader::looksLikeJson($body)) {
            return $this->json->parse($body, $sourceUrl);
        }

        $xml = $this->assertSafeXml($body);
        $feed = $this->importXml($xml);
        $dom = $feed->getDomDocument();
        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new ParseException('The feed has no root element.');
        }

        $xpath = self::xpathFor($dom);
        $format = self::formatOf($root);

        return $format === 'atom-1.0'
            ? $this->mapAtom($root, $xpath, $sourceUrl)
            : $this->mapRss($root, $xpath, $format, $sourceUrl);
    }

    // ---- security ------------------------------------------------------

    /**
     * Refuses document type declarations before any parser sees the bytes.
     *
     * This is the XXE and billion-laughs gate. Nothing below it relies on
     * libxml flags being right: the internal subset that both attacks need is
     * rejected as text, so the entity declarations never exist. The libxml
     * settings in importXml() are the second line, not the first.
     *
     * Returns the document with a permitted historic declaration removed,
     * because laminas-feed refuses any DOCTYPE at all.
     *
     * @throws ParseException
     */
    private function assertSafeXml(string $body): string
    {
        $doctype = self::findPrologDoctype($body);
        if ($doctype === null) {
            return $body;
        }

        [$offset, $length, $declaration] = $doctype;

        if (str_contains($declaration, '[')) {
            throw new ParseException(
                'Refusing a document type declaration with an internal subset: entity declarations are never accepted.',
            );
        }

        if (!$this->allowHistoricDoctype) {
            throw new ParseException('Refusing a document type declaration.');
        }

        $lower = strtolower($declaration);
        foreach (self::HISTORIC_DOCTYPE_IDS as $known) {
            if (str_contains($lower, $known)) {
                return substr($body, 0, $offset) . substr($body, $offset + $length);
            }
        }

        throw new ParseException('Refusing an unrecognised document type declaration.');
    }

    /**
     * The `<!DOCTYPE ...>` of the prolog, as [offset, length, text].
     *
     * Scanning rather than matching: `<!DOCTYPE` inside a CDATA section or an
     * item description is ordinary content, and a naive `str_contains` would
     * reject perfectly good feeds about XML.
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private static function findPrologDoctype(string $body): ?array
    {
        $length = strlen($body);
        $i = 0;

        while ($i < $length) {
            if (ctype_space($body[$i])) {
                ++$i;

                continue;
            }
            if ($body[$i] !== '<') {
                return null; // Not XML at all; let the parser complain.
            }

            if (str_starts_with(substr($body, $i, 4), '<!--')) {
                $end = strpos($body, '-->', $i);
                if ($end === false) {
                    return null;
                }
                $i = $end + 3;

                continue;
            }

            if (str_starts_with(substr($body, $i, 2), '<?')) {
                $end = strpos($body, '?>', $i);
                if ($end === false) {
                    return null;
                }
                $i = $end + 2;

                continue;
            }

            if (strcasecmp(substr($body, $i, 9), '<!DOCTYPE') === 0) {
                $end = self::endOfDoctype($body, $i);
                if ($end === null) {
                    throw new ParseException('Refusing an unterminated document type declaration.');
                }

                return [$i, $end - $i + 1, substr($body, $i, $end - $i + 1)];
            }

            return null; // The root element starts here; the prolog is over.
        }

        return null;
    }

    /** Index of the `>` that closes the declaration, internal subset included. */
    private static function endOfDoctype(string $body, int $start): ?int
    {
        $length = strlen($body);
        $inSubset = false;

        for ($i = $start; $i < $length; ++$i) {
            $char = $body[$i];
            if ($char === '[') {
                $inSubset = true;
            } elseif ($char === ']') {
                $inSubset = false;
            } elseif ($char === '>' && !$inSubset) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Hands the document to laminas-feed with entity loading nailed shut.
     *
     * `libxml_set_external_entity_loader` is the only reliable switch left in
     * PHP 8: `libxml_disable_entity_loader` is gone, and LIBXML_NONET only
     * stops network fetches, not `file:///etc/passwd`. Returning null from the
     * loader makes every external reference fail, whatever flags are in force.
     *
     * @return AbstractFeed<\Laminas\Feed\Reader\Entry\Rss|\Laminas\Feed\Reader\Entry\Atom>
     *
     * @throws ParseException
     */
    private function importXml(string $xml): AbstractFeed
    {
        $previousErrors = libxml_use_internal_errors(true);
        /**
         * Returning null makes libxml fail every external reference. PHP 8.3
         * offers no way to read the loader back, so the finally block restores
         * the default (null) rather than a previous value — nothing else in
         * this application installs one.
         *
         * @param array<string, mixed> $context
         */
        libxml_set_external_entity_loader(
            static fn (?string $publicId, ?string $systemId, array $context): null => null,
        );

        try {
            $feed = Reader::importString($xml);
            if (!$feed instanceof AbstractFeed) {
                throw new ParseException('The document is not an RSS, RDF or Atom feed.');
            }

            return $feed;
        } catch (\Laminas\Feed\Reader\Exception\ExceptionInterface | \Laminas\Feed\Exception\ExceptionInterface $e) {
            throw new ParseException('The feed could not be parsed: ' . $e->getMessage(), 0, $e);
        } finally {
            libxml_set_external_entity_loader(null);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    // ---- format detection ----------------------------------------------

    /** Best effort mapping onto the closed set of format tokens. */
    private static function formatOf(\DOMElement $root): string
    {
        $local = strtolower((string) $root->localName);

        if ($local === 'feed' && $root->namespaceURI === self::NS_ATOM) {
            return 'atom-1.0';
        }

        if ($local === 'rdf') {
            return 'rss-1.0';
        }

        return match ($root->getAttribute('version')) {
            '0.91' => 'rss-0.91',
            '0.92', '0.93', '0.94' => 'rss-0.92',
            default => 'rss-2.0',
        };
    }

    private static function xpathFor(\DOMDocument $dom): \DOMXPath
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('atom', self::NS_ATOM);
        $xpath->registerNamespace('rss10', self::NS_RSS10);
        $xpath->registerNamespace('rdf', self::NS_RDF);
        $xpath->registerNamespace('dc', self::NS_DC);
        $xpath->registerNamespace('content', self::NS_CONTENT);

        return $xpath;
    }

    // ---- RSS 0.91 / 0.92 / 1.0 / 2.0 ------------------------------------

    private function mapRss(\DOMElement $root, \DOMXPath $xpath, string $format, string $sourceUrl): Channel
    {
        $isRdf = $format === 'rss-1.0';
        $channel = $isRdf
            ? self::firstNode($xpath, '/rdf:RDF/rss10:channel|/rdf:RDF/*[local-name()="channel"]', $root)
            : self::firstNode($xpath, '/rss/channel|/*[local-name()="channel"]', $root);
        $channel ??= $root;

        $image = self::child($channel, 'image');
        $lastBuild = self::text(self::child($channel, 'lastBuildDate'));
        if ($lastBuild === '') {
            $lastBuild = self::text(self::childNs($channel, self::NS_DC, 'date'));
        }

        $itemNodes = $isRdf
            ? self::nodes($xpath, '/rdf:RDF/rss10:item|/rdf:RDF/*[local-name()="item"]', $root)
            : self::nodes($xpath, 'item|*[local-name()="item"]', $channel);

        $items = [];
        foreach ($itemNodes as $node) {
            $items[] = $this->mapRssItem($node, $sourceUrl);
        }

        $language = self::text(self::child($channel, 'language'));
        if ($language === '') {
            $language = self::text(self::childNs($channel, self::NS_DC, 'language'));
        }

        return new Channel(
            title: self::text(self::child($channel, 'title')),
            link: self::text(self::child($channel, 'link')),
            description: self::text(self::child($channel, 'description')),
            language: $language,
            copyright: self::text(self::child($channel, 'copyright')),
            logoUrl: $image !== null ? self::text(self::child($image, 'url')) : '',
            logoTitle: $image !== null ? self::text(self::child($image, 'title')) : '',
            logoLink: $image !== null ? self::text(self::child($image, 'link')) : '',
            lastBuild: DateParser::parse($lastBuild),
            format: $format,
            items: $items,
        );
    }

    private function mapRssItem(\DOMElement $node, string $sourceUrl): Item
    {
        $title = self::text(self::child($node, 'title'));
        $link = self::text(self::child($node, 'link'));
        if ($link === '') {
            // RSS 1.0 items identify themselves with rdf:about.
            $link = trim($node->getAttributeNS(self::NS_RDF, 'about'));
        }

        $rawDate = self::text(self::child($node, 'pubDate'));
        if ($rawDate === '') {
            $rawDate = self::text(self::childNs($node, self::NS_DC, 'date'));
        }

        $author = self::text(self::childNs($node, self::NS_DC, 'creator'));
        if ($author === '') {
            $author = self::text(self::child($node, 'author'));
        }

        $guid = self::text(self::child($node, 'guid'));
        $id = $guid !== '' ? $guid : trim($node->getAttributeNS(self::NS_RDF, 'about'));
        if ($id === '') {
            $id = self::fallbackId($link !== '' ? $link : $sourceUrl, $title);
        }

        return new Item(
            id: $id,
            title: $title,
            link: $link,
            summaryHtml: self::text(self::child($node, 'description')),
            contentHtml: self::text(self::childNs($node, self::NS_CONTENT, 'encoded')),
            published: DateParser::parse($rawDate),
            author: $author,
            enclosures: self::rssEnclosures($node),
            rawDate: $rawDate,
        );
    }

    /** @return list<Enclosure> */
    private static function rssEnclosures(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || strtolower((string) $child->localName) !== 'enclosure') {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url === '') {
                continue;
            }
            $type = trim($child->getAttribute('type'));
            $length = trim($child->getAttribute('length'));
            $out[] = new Enclosure($url, $type !== '' ? $type : null, ctype_digit($length) ? (int) $length : null);
        }

        return $out;
    }

    // ---- Atom 1.0 --------------------------------------------------------

    private function mapAtom(\DOMElement $root, \DOMXPath $xpath, string $sourceUrl): Channel
    {
        $logo = self::text(self::childNs($root, self::NS_ATOM, 'logo'));
        if ($logo === '') {
            $logo = self::text(self::childNs($root, self::NS_ATOM, 'icon'));
        }
        $title = self::text(self::childNs($root, self::NS_ATOM, 'title'));
        $link = self::atomLink($root);

        $items = [];
        foreach (self::nodes($xpath, 'atom:entry|*[local-name()="entry"]', $root) as $entry) {
            $items[] = $this->mapAtomEntry($entry, $sourceUrl);
        }

        $language = trim($root->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang'));

        return new Channel(
            title: $title,
            link: $link,
            description: self::text(self::childNs($root, self::NS_ATOM, 'subtitle')),
            language: $language,
            copyright: self::text(self::childNs($root, self::NS_ATOM, 'rights')),
            logoUrl: $logo,
            logoTitle: $title,
            logoLink: $link,
            lastBuild: DateParser::parse(self::text(self::childNs($root, self::NS_ATOM, 'updated'))),
            format: 'atom-1.0',
            items: $items,
        );
    }

    private function mapAtomEntry(\DOMElement $node, string $sourceUrl): Item
    {
        $title = self::text(self::childNs($node, self::NS_ATOM, 'title'));
        $link = self::atomLink($node);

        $rawDate = self::text(self::childNs($node, self::NS_ATOM, 'published'));
        if ($rawDate === '') {
            $rawDate = self::text(self::childNs($node, self::NS_ATOM, 'updated'));
        }

        $id = self::text(self::childNs($node, self::NS_ATOM, 'id'));
        if ($id === '') {
            $id = self::fallbackId($link !== '' ? $link : $sourceUrl, $title);
        }

        $author = '';
        $authorNode = self::childNs($node, self::NS_ATOM, 'author');
        if ($authorNode !== null) {
            $author = self::text(self::childNs($authorNode, self::NS_ATOM, 'name'));
        }

        return new Item(
            id: $id,
            title: $title,
            link: $link,
            summaryHtml: self::text(self::childNs($node, self::NS_ATOM, 'summary')),
            contentHtml: self::atomContent($node),
            published: DateParser::parse($rawDate),
            author: $author,
            enclosures: self::atomEnclosures($node),
            rawDate: $rawDate,
        );
    }

    /**
     * The `<content>` of an entry as markup.
     *
     * `type="xhtml"` wraps real elements that must be serialised; every other
     * type is character data, which the DOM has already decoded for us.
     */
    private static function atomContent(\DOMElement $node): string
    {
        $content = self::childNs($node, self::NS_ATOM, 'content');
        if ($content === null) {
            return '';
        }

        if (strtolower(trim($content->getAttribute('type'))) !== 'xhtml') {
            return self::text($content);
        }

        $document = $content->ownerDocument;
        if ($document === null) {
            return '';
        }

        $html = '';
        foreach ($content->childNodes as $child) {
            $serialised = $document->saveXML($child);
            if (is_string($serialised)) {
                $html .= $serialised;
            }
        }

        return trim($html);
    }

    /** The alternate link: `rel="alternate"` when stated, otherwise a bare link. */
    private static function atomLink(\DOMElement $node): string
    {
        $fallback = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || strtolower((string) $child->localName) !== 'link') {
                continue;
            }
            $rel = strtolower(trim($child->getAttribute('rel')));
            $href = trim($child->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            if ($rel === 'alternate') {
                return $href;
            }
            if ($rel === '' && $fallback === '') {
                $fallback = $href;
            }
        }

        return $fallback;
    }

    /** @return list<Enclosure> */
    private static function atomEnclosures(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || strtolower((string) $child->localName) !== 'link') {
                continue;
            }
            if (strtolower(trim($child->getAttribute('rel'))) !== 'enclosure') {
                continue;
            }
            $url = trim($child->getAttribute('href'));
            if ($url === '') {
                continue;
            }
            $type = trim($child->getAttribute('type'));
            $length = trim($child->getAttribute('length'));
            $out[] = new Enclosure($url, $type !== '' ? $type : null, ctype_digit($length) ? (int) $length : null);
        }

        return $out;
    }

    // ---- DOM helpers -----------------------------------------------------

    private static function child(\DOMElement $parent, string $name): ?\DOMElement
    {
        $wanted = strtolower($name);
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower((string) $child->localName) === $wanted) {
                return $child;
            }
        }

        return null;
    }

    private static function childNs(\DOMElement $parent, string $namespace, string $name): ?\DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->namespaceURI === $namespace
                && strtolower((string) $child->localName) === strtolower($name)
            ) {
                return $child;
            }
        }

        return null;
    }

    private static function text(?\DOMElement $node): string
    {
        return $node === null ? '' : trim($node->textContent);
    }

    private static function firstNode(\DOMXPath $xpath, string $query, \DOMElement $context): ?\DOMElement
    {
        $nodes = self::nodes($xpath, $query, $context);

        return $nodes[0] ?? null;
    }

    /** @return list<\DOMElement> */
    private static function nodes(\DOMXPath $xpath, string $query, \DOMElement $context): array
    {
        $list = $xpath->query($query, $context);
        if ($list === false) {
            return [];
        }

        $out = [];
        foreach ($list as $node) {
            if ($node instanceof \DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private static function fallbackId(string $link, string $title): string
    {
        return sha1($link . '|' . $title);
    }
}
