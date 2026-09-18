<?php

declare(strict_types=1);

namespace Zfeeder\Parse;

use Zfeeder\Exception\ParseException;
use Zfeeder\Parse\Model\Channel;
use Zfeeder\Parse\Model\Enclosure;
use Zfeeder\Parse\Model\Item;

/**
 * JSON Feed 1.0 and 1.1 to the same Channel/Item model the XML formats produce.
 *
 * The two versions differ in exactly one place that matters here: 1.0 has a
 * single `author` object, 1.1 has an `authors` array. Both are read, newest
 * spelling first, so a feed that carries both stays consistent with what a
 * 1.1 reader would show.
 *
 * Plain-text fields (`content_text`, `summary`) are HTML-escaped on the way in.
 * Everything downstream treats Item's *Html fields as markup, and quietly
 * passing raw text through would turn an ampersand in a title into broken
 * output — or worse, let a `<script>` in a text field be read as markup.
 */
final class JsonFeedReader
{
    /** The closed format token for JSON Feed, whatever minor version was declared. */
    public const string FORMAT = 'json-1.1';

    private const array VERSION_PREFIXES = [
        'https://jsonfeed.org/version/1',
        'http://jsonfeed.org/version/1',
    ];

    /**
     * @param string $sourceUrl only used to make a stable item id when the feed
     *                          supplies neither an id nor a url
     *
     * @throws ParseException on anything that is not a JSON Feed document
     */
    public function parse(string $body, string $sourceUrl = ''): Channel
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ParseException('The feed is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        // A JSON Feed is an object. `{}` decodes to an empty array too, so the
        // emptiness is what separates it from a top-level `[...]`.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ParseException('The feed is not a JSON object.');
        }

        $version = self::str($decoded, 'version');
        if ($version !== '' && !self::isKnownVersion($version)) {
            throw new ParseException('Unsupported JSON Feed version: ' . $version);
        }

        $link = self::str($decoded, 'home_page_url');
        $logo = self::str($decoded, 'icon');
        if ($logo === '') {
            $logo = self::str($decoded, 'favicon');
        }

        $rawItems = $decoded['items'] ?? [];
        $items = [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $raw) {
                if (is_array($raw)) {
                    $items[] = $this->toItem($raw, $sourceUrl, $decoded);
                }
            }
        }

        return new Channel(
            title: self::str($decoded, 'title'),
            link: $link,
            description: self::str($decoded, 'description'),
            language: self::str($decoded, 'language'),
            copyright: '',
            logoUrl: $logo,
            logoTitle: self::str($decoded, 'title'),
            logoLink: $link,
            lastBuild: null,
            format: self::FORMAT,
            items: $items,
        );
    }

    /** A cheap look at the first meaningful byte; `{` can only start JSON here. */
    public static function looksLikeJson(string $body): bool
    {
        $trimmed = ltrim($body, " \t\n\r\0\x0B\u{FEFF}");

        return str_starts_with($trimmed, '{');
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param array<array-key, mixed> $feed
     */
    private function toItem(array $raw, string $sourceUrl, array $feed): Item
    {
        $link = self::str($raw, 'url');
        if ($link === '') {
            $link = self::str($raw, 'external_url');
        }
        $title = self::str($raw, 'title');

        $contentHtml = self::str($raw, 'content_html');
        $contentText = self::str($raw, 'content_text');
        if ($contentHtml === '' && $contentText !== '') {
            $contentHtml = self::escape($contentText);
        }

        $summary = self::str($raw, 'summary');
        $summaryHtml = $summary !== '' ? self::escape($summary) : ($contentText !== '' ? self::escape($contentText) : '');

        $rawDate = self::str($raw, 'date_published');
        if ($rawDate === '') {
            $rawDate = self::str($raw, 'date_modified');
        }

        $id = self::str($raw, 'id');
        if ($id === '') {
            $id = self::fallbackId($link !== '' ? $link : $sourceUrl, $title);
        }

        $author = self::authorName($raw);
        if ($author === '') {
            $author = self::authorName($feed);
        }

        return new Item(
            id: $id,
            title: $title,
            link: $link,
            summaryHtml: $summaryHtml,
            contentHtml: $contentHtml,
            published: DateParser::parse($rawDate),
            author: $author,
            enclosures: self::attachments($raw),
            rawDate: $rawDate,
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return list<Enclosure>
     */
    private static function attachments(array $raw): array
    {
        $attachments = $raw['attachments'] ?? null;
        if (!is_array($attachments)) {
            return [];
        }

        $out = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $url = self::str($attachment, 'url');
            if ($url === '') {
                continue;
            }
            $type = self::str($attachment, 'mime_type');
            $size = $attachment['size_in_bytes'] ?? null;
            $out[] = new Enclosure(
                $url,
                $type !== '' ? $type : null,
                is_int($size) ? $size : (is_string($size) && ctype_digit($size) ? (int) $size : null),
            );
        }

        return $out;
    }

    /** @param array<array-key, mixed> $node 1.1 `authors[0].name`, falling back to 1.0 `author.name` */
    private static function authorName(array $node): string
    {
        $authors = $node['authors'] ?? null;
        if (is_array($authors)) {
            foreach ($authors as $author) {
                if (is_array($author)) {
                    $name = self::str($author, 'name');
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        }

        $author = $node['author'] ?? null;
        if (is_array($author)) {
            return self::str($author, 'name');
        }

        return is_string($author) ? trim($author) : '';
    }

    private static function isKnownVersion(string $version): bool
    {
        foreach (self::VERSION_PREFIXES as $prefix) {
            if (str_starts_with($version, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $node */
    private static function str(array $node, string $key): string
    {
        $value = $node[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function fallbackId(string $link, string $title): string
    {
        return sha1($link . '|' . $title);
    }
}
