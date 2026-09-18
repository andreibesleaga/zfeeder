<?php

declare(strict_types=1);

namespace Zfeeder\Render;

use Zfeeder\Exception\TemplateException;

/**
 * The closed filter list for `{token|filter}` syntax.
 *
 * Deliberately not an expression language: there is no `eval`, no callable
 * lookup, no user supplied PHP. A template is data that an administrator (or a
 * 2004 template author) supplies, so the only safe design is a fixed list of
 * filters with fixed argument shapes. Anything else throws.
 */
final class Filters
{
    /** Every filter that exists. Order is the documentation order. */
    public const array NAMES = ['raw', 'trunc', 'date'];

    public function __construct(private readonly Truncator $truncator = new Truncator())
    {
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    /**
     * Apply one filter.
     *
     * @param string      $value  the token's value, already computed
     * @param string      $name   filter name, e.g. `trunc`
     * @param string|null $arg    the raw argument text after the colon, or null when there was none
     * @param bool        $escape set to false by `raw`, telling the caller to skip default escaping
     */
    public function apply(string $value, string $name, ?string $arg, bool &$escape): string
    {
        return match ($name) {
            'raw' => $this->raw($value, $escape),
            'trunc' => $this->trunc($value, $arg),
            'date' => $this->date($value, $arg),
            default => throw new TemplateException(sprintf(
                'Unknown template filter "%s". Known filters: %s.',
                $name,
                implode(', ', self::NAMES),
            )),
        };
    }

    private function raw(string $value, bool &$escape): string
    {
        $escape = false;

        return $value;
    }

    private function trunc(string $value, ?string $arg): string
    {
        if ($arg === null || preg_match('/^\d{1,6}$/', trim($arg)) !== 1) {
            throw new TemplateException('The "trunc" filter needs a whole-number argument, e.g. {description|trunc:120}.');
        }

        return $this->truncator->truncate($value, (int) trim($arg));
    }

    /**
     * Reformat a date. The input may be anything `DateTimeImmutable` accepts,
     * which includes the raw RFC 822 strings feeds use; an unparsable value is
     * passed through unchanged rather than blanking the page.
     */
    private function date(string $value, ?string $arg): string
    {
        $format = $this->unquote($arg);
        if ($format === null || $format === '') {
            throw new TemplateException('The "date" filter needs a format argument, e.g. {pubdate|date:"D, d M Y"}.');
        }
        if (trim($value) === '') {
            return '';
        }
        // strtotime() is the same parser DateTimeImmutable uses but reports
        // failure by returning false, so the common "this is not a date" path
        // costs no exception at all. The catch is still there because some
        // debug extensions turn DateMalformedStringException into an Error
        // while constructing it.
        if (strtotime($value) === false) {
            return $value;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return $value;
        }

        return $date->format($format);
    }

    /** Strip the optional surrounding double quotes of a filter argument. */
    private function unquote(?string $arg): ?string
    {
        if ($arg === null) {
            return null;
        }
        $arg = trim($arg);
        if (strlen($arg) >= 2 && $arg[0] === '"' && str_ends_with($arg, '"')) {
            $arg = substr($arg, 1, -1);
        }

        return str_replace('\\"', '"', $arg);
    }
}
