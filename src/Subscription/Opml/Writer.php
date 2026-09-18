<?php

declare(strict_types=1);

namespace Zfeeder\Subscription\Opml;

use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * Writes a category as an OPML 2.0 subscription list.
 *
 * The document is built as text rather than through DOM on purpose: the
 * attribute order, the indentation and the line endings are then fixed, so
 * saving an unchanged category produces byte-identical output. That is what
 * makes the file diffable in a backup, and what lets the golden tests compare
 * bytes instead of trees.
 *
 * The attribute set and spelling are 1.6's, so a file written here still opens
 * in the 2004 script.
 */
final class Writer
{
    private const string INDENT = '  ';

    /**
     * @param \DateTimeImmutable|null $now used for `dateModified` when the
     *                                     category carries no date of its own;
     *                                     passed in so tests have a fixed clock
     */
    public function write(Category $category, ?\DateTimeImmutable $now = null): string
    {
        $modified = $category->dateModified ?? $now;

        $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $out .= '<opml version="2.0">' . "\n";
        $out .= self::INDENT . '<head>' . "\n";
        $out .= $this->element('title', $category->name, 2);
        if ($modified instanceof \DateTimeImmutable) {
            $out .= $this->element('dateModified', $this->rfc2822($modified), 2);
        }
        $out .= $this->element('ownerName', $category->ownerName, 2);
        $out .= $this->element('ownerEmail', $category->ownerEmail, 2);
        $out .= self::INDENT . '</head>' . "\n";
        $out .= self::INDENT . '<body>' . "\n";
        foreach ($category->feeds as $feed) {
            $out .= $this->outline($feed);
        }
        $out .= self::INDENT . '</body>' . "\n";
        $out .= '</opml>' . "\n";

        return $out;
    }

    private function outline(Feed $feed): string
    {
        // Fixed order; `text` repeats `title` because 1.6 read whichever it found.
        $attributes = [
            'type' => 'rss',
            'position' => (string) $feed->position,
            'text' => $feed->title,
            'title' => $feed->title,
            'description' => $feed->description,
            'xmlUrl' => $feed->xmlUrl,
            'htmlUrl' => $feed->htmlUrl,
            'refreshTime' => (string) $feed->refreshMinutes,
            'showedItems' => (string) $feed->showedItems,
            'isSubscribed' => $feed->subscribed ? 'yes' : 'no',
            'language' => $feed->language,
        ];

        $pairs = '';
        foreach ($attributes as $name => $value) {
            $pairs .= sprintf(' %s="%s"', $name, $this->escape($value));
        }

        return str_repeat(self::INDENT, 2) . '<outline' . $pairs . ' />' . "\n";
    }

    private function element(string $tag, string $value, int $depth): string
    {
        return str_repeat(self::INDENT, $depth) . sprintf('<%s>%s</%s>', $tag, $this->escape($value), $tag) . "\n";
    }

    /** OPML dates are RFC 2822 in GMT, the format 1.6's gmdate() call produced. */
    private function rfc2822(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT';
    }

    /**
     * Escapes text and, first, drops the control characters that XML 1.0 forbids:
     * a 2004 file or a hostile feed title can contain them, and they would make
     * the document we just wrote unparseable.
     */
    private function escape(string $value): string
    {
        $clean = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        if (!is_string($clean)) {
            // Invalid UTF-8 defeats the /u pattern; fall back to stripping bytes.
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        }

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
