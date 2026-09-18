<?php

declare(strict_types=1);

namespace Zfeeder\Parse;

/**
 * Turns whatever a feed calls a date into a DateTimeImmutable, or nothing.
 *
 * Feed dates are the least reliable field in syndication. RSS asks for RFC 822
 * with a four digit year, Atom for RFC 3339, and twenty years of publishing
 * software has produced every near-miss in between: single digit days, missing
 * seconds, `GMT+00:00`, `UT`, bare `Z`, and plain ISO strings with a space
 * instead of a `T`. A wrong date sorts an item to the wrong place; a fatal
 * error over one loses the whole feed. So this never throws: an unreadable
 * date becomes null and the item keeps its verbatim `rawDate` for display.
 */
final class DateParser
{
    /**
     * Tried in order, strictest first. Each is applied with a leading `!`,
     * which resets every field the format does not mention to the epoch:
     * without it a date-only value would silently pick up the current time
     * and two runs over the same feed would disagree.
     *
     * @var list<string>
     */
    private const array FORMATS = [
        \DateTimeInterface::RFC2822,      // Sun, 25 Apr 2004 11:15:55 +0000
        'D, d M Y H:i:s T',               // ... GMT
        'D, d M Y H:i T',                 // no seconds
        'D, j M Y H:i:s T',               // single digit day
        'D, j M Y H:i:s O',
        'd M Y H:i:s T',                  // no weekday
        'j M Y H:i:s T',
        \DateTimeInterface::RFC3339,      // 2026-09-14T22:10:00+00:00
        \DateTimeInterface::RFC3339_EXTENDED,
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s\Z',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i:s',
        'Y-m-d',
    ];

    public static function parse(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        foreach (self::FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw);
            // createFromFormat still returns an object when the input merely
            // *starts* with something matching, so "2026-09-14T22:10:00Z" would
            // otherwise be accepted by 'Y-m-d' and lose its time. The warning
            // count is the only way to tell a full match from a partial one.
            if ($parsed instanceof \DateTimeImmutable && self::matchedCleanly()) {
                return $parsed;
            }
        }

        // Last resort: the general parser, which handles the oddities above
        // and a great deal else, but also happily reads "next tuesday", so it
        // only runs once every explicit shape has been rejected.
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function matchedCleanly(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors === false) {
            return true;
        }

        return ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0;
    }
}
