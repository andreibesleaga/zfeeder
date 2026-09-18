<?php

declare(strict_types=1);

namespace Zfeeder\Render;

/**
 * Shortens markup to a visible-character budget without producing broken HTML.
 *
 * 1.6 printed whole `<description>` bodies, which was fine when a feed item was
 * two sentences. 2026 feeds ship entire articles in `content:encoded`, so
 * `max_description_chars` exists — but naive `substr()` on HTML cuts tags and
 * entities in half and leaves elements unclosed, which corrupts the host page.
 * This walks tags, entities and text separately, counts only what a reader
 * sees, and closes whatever is still open at the cut.
 */
final class Truncator
{
    /** The ellipsis appended when, and only when, something was actually cut. */
    public const string ELLIPSIS = "\u{2026}";

    /** HTML elements that never have a closing tag. */
    private const array VOID = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * @param int $maxChars maximum number of visible characters; 0 (or less) means unlimited
     */
    public function truncate(string $html, int $maxChars): string
    {
        if ($maxChars <= 0 || $html === '') {
            return $html;
        }

        $parts = preg_split(
            '/(<!--.*?-->|<[^>]*>|&(?:#\d{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});)/su',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );
        if ($parts === false) {
            // Not valid UTF-8: fall back to a byte-safe cut that still cannot
            // split a tag, because there is nothing sensible to count.
            return mb_strimwidth($html, 0, $maxChars, self::ELLIPSIS, 'UTF-8');
        }

        $out = '';
        $used = 0;
        $truncated = false;
        /** @var list<string> $open */
        $open = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                $out .= $part;
                $this->trackTag($part, $open);
                continue;
            }
            if ($part[0] === '&' && str_ends_with($part, ';')) {
                // An entity is one character to the reader, and must never be
                // split: "&amp" without its semicolon is not an entity at all.
                if ($used + 1 > $maxChars) {
                    $truncated = true;
                    break;
                }
                $out .= $part;
                ++$used;
                continue;
            }

            $length = mb_strlen($part, 'UTF-8');
            if ($used + $length <= $maxChars) {
                $out .= $part;
                $used += $length;
                continue;
            }
            $out .= mb_substr($part, 0, $maxChars - $used, 'UTF-8');
            $truncated = true;
            break;
        }

        if (!$truncated) {
            return $html;
        }

        $out .= self::ELLIPSIS;
        foreach (array_reverse($open) as $element) {
            $out .= '</' . $element . '>';
        }

        return $out;
    }

    /**
     * Keep the stack of still-open elements up to date.
     *
     * @param list<string> $open
     */
    private function trackTag(string $tag, array &$open): void
    {
        if (str_starts_with($tag, '<!') || str_starts_with($tag, '<?')) {
            return; // comment, doctype or processing instruction
        }
        if (preg_match('~^</\s*([a-zA-Z][a-zA-Z0-9-]*)~', $tag, $m) === 1) {
            $name = strtolower($m[1]);
            $at = array_search($name, array_reverse($open, true), true);
            if ($at !== false) {
                // Drop everything opened after the element being closed, the
                // way a browser's parser would.
                $open = array_values(array_slice($open, 0, (int) $at));
            }

            return;
        }
        if (preg_match('~^<\s*([a-zA-Z][a-zA-Z0-9-]*)~', $tag, $m) !== 1) {
            return;
        }
        $name = strtolower($m[1]);
        if (in_array($name, self::VOID, true) || str_ends_with(rtrim($tag), '/>')) {
            return;
        }
        $open[] = $name;
    }
}
