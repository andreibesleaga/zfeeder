<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Support;

use Zfeeder\Parse\Model\Channel;
use Zfeeder\Parse\Model\Enclosure;
use Zfeeder\Parse\Model\Item;

/**
 * TODO: replace with `Zfeeder\Parse\FeedParser` as soon as it exists.
 *
 * A minimal stand-in used only by the golden tests, written while the real
 * parser was still being built by another workstream. It deliberately mimics
 * the *expat* behaviour of zFeeder 1.6 (`rssCharacterData()` in
 * `newsfeeds/includes/zfuncs.php`) rather than being a good feed parser:
 *
 *  - `<description>` goes to `summaryHtml` and `<content:encoded>` to
 *    `contentHtml`; 1.6 appended both into one buffer in document order, which
 *    the renderer reproduces as `summaryHtml . contentHtml` in legacy mode.
 *  - `<pubDate>` or `<dc:date>`, whichever appears, is kept verbatim in
 *    `rawDate` - 1.6 never parsed dates, it printed the feed's own string.
 *  - RSS 0.9x/2.0 take channel metadata and items from `<channel>`; RDF 1.0
 *    takes the channel from `<channel>` but items and `<image>` from the
 *    document root, exactly as 1.6's tag-path matching did.
 *
 * When the real parser lands, this file should be deleted and the golden test
 * switched over; the goldens themselves are the contract that proves the swap
 * is faithful.
 */
final class LegacyFixtureFeedParser
{
    private const string NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const string NS_DC = 'http://purl.org/dc/elements/1.1/';

    public function parseFile(string $path): Channel
    {
        $xml = file_get_contents($path);
        if ($xml === false) {
            throw new \RuntimeException('Cannot read feed fixture: ' . $path);
        }

        return $this->parse($xml);
    }

    public function parse(string $xml): Channel
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            throw new \RuntimeException('Unparsable feed fixture.');
        }

        $isRdf = $doc->getName() === 'RDF';
        $channelNode = $doc->channel;
        if ($channelNode === null) {
            throw new \RuntimeException('Feed fixture has no <channel>.');
        }

        $imageHolder = $isRdf ? $doc : $channelNode;
        $image = $imageHolder->image;

        $itemNodes = $isRdf ? $doc->item : $channelNode->item;

        $items = [];
        foreach ($itemNodes ?? [] as $node) {
            $items[] = $this->item($node);
        }

        return new Channel(
            title: self::text($channelNode->title),
            link: self::text($channelNode->link),
            description: self::text($channelNode->description),
            language: self::text($channelNode->language),
            copyright: self::text($channelNode->copyright),
            logoUrl: $image === null ? '' : self::text($image->url),
            logoTitle: $image === null ? '' : self::text($image->title),
            logoLink: $image === null ? '' : self::text($image->link),
            lastBuild: null,
            format: $isRdf ? 'rdf' : 'rss',
            items: $items,
        );
    }

    private function item(\SimpleXMLElement $node): Item
    {
        $content = $node->children(self::NS_CONTENT);
        $dc = $node->children(self::NS_DC);

        $rawDate = self::text($node->pubDate);
        if ($rawDate === '') {
            $rawDate = self::text($dc->date ?? null);
        }

        $enclosures = [];
        foreach ($node->enclosure ?? [] as $enclosure) {
            $url = (string) ($enclosure['url'] ?? '');
            if ($url !== '') {
                $length = isset($enclosure['length']) ? (int) $enclosure['length'] : null;
                $type = isset($enclosure['type']) ? (string) $enclosure['type'] : null;
                $enclosures[] = new Enclosure($url, $type, $length);
            }
        }

        $link = self::text($node->link);
        $guid = self::text($node->guid);

        return new Item(
            id: $guid !== '' ? $guid : $link,
            title: self::text($node->title),
            link: $link,
            summaryHtml: self::text($node->description),
            contentHtml: self::text($content->encoded ?? null),
            published: self::date($rawDate),
            author: self::text($dc->creator ?? null),
            enclosures: $enclosures,
            rawDate: $rawDate,
        );
    }

    private static function text(?\SimpleXMLElement $node): string
    {
        return $node === null ? '' : (string) $node;
    }

    private static function date(string $raw): ?\DateTimeImmutable
    {
        if (trim($raw) === '' || strtotime($raw) === false) {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
