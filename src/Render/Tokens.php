<?php

declare(strict_types=1);

namespace Zfeeder\Render;

use Zfeeder\Exception\TemplateException;

/**
 * The token table and the `{name}` / `{name|filter}` substitution pass.
 *
 * Two generations of tokens live here and they escape differently:
 *
 *  - The **fifteen 1.6 tokens** keep 2004 behaviour exactly. `{link}` arrives
 *    already `htmlspecialchars()`-ed by the Renderer; `{title}`, `{description}`
 *    and `{pubdate}` are printed raw, straight from the feed. That is unsafe in
 *    the abstract, which is why the Renderer sanitises the *values* before they
 *    get here — but the substitution itself must not add escaping or the
 *    goldens stop matching.
 *  - The **nine 2.0 tokens** (`author summary content enclosure itemdate_iso
 *    itemdate_rel feedid position set`) are HTML-escaped by default, because a
 *    new token has no 2004 output to be compatible with and safe-by-default is
 *    the right choice. Use `{content|raw}` when markup is wanted.
 *
 * The legacy tokens are substituted by the Renderer with sequential
 * `str_replace()` calls, in 1.6's order; this class only handles filtered
 * tokens and the new bare tokens, and it runs *before* that legacy pass so a
 * value coming out of a feed can never be re-interpreted as a token.
 */
final class Tokens
{
    /**
     * The 1.6 tokens, in `parseTemplate()` replacement order. Order matters:
     * 1.6 replaced them one after another, so a value substituted early could
     * itself contain a later token and be expanded again.
     */
    public const array LEGACY = [
        'chanlogo', 'chanlink', 'chandesc', 'chantitle', 'feedurl',
        'lastupdated', 'scripturl', 'hideurl', 'category',
        'id', 'link', 'pubdate', 'title', 'description', 'moreurl',
    ];

    /** The item tokens 1.6 only substituted when rendering a news item. */
    public const array LEGACY_ITEM = ['id', 'link', 'pubdate', 'title', 'description', 'moreurl'];

    /** Added in 2.0. HTML-escaped unless `|raw` is used. */
    public const array MODERN = [
        'author', 'summary', 'content', 'enclosure',
        'itemdate_iso', 'itemdate_rel', 'feedid', 'position', 'set',
    ];

    /** Matches `{name}` and `{name|filter}` / `{name|filter:arg}`. */
    private const string TOKEN_PATTERN = '/\{([A-Za-z_][A-Za-z0-9_]*)(\|[^{}]*)?\}/';

    public function __construct(private readonly Filters $filters = new Filters())
    {
    }

    /** @return list<string> every token name the engine knows */
    public static function all(): array
    {
        return array_values(array_merge(self::LEGACY, self::MODERN));
    }

    public static function isLegacy(string $name): bool
    {
        return in_array($name, self::LEGACY, true);
    }

    public static function isKnown(string $name): bool
    {
        return in_array($name, self::LEGACY, true) || in_array($name, self::MODERN, true);
    }

    /** True when the token is HTML-escaped unless `|raw` says otherwise. */
    /**
     * Tokens whose value carries HTML that is already safe, and so must not be
     * escaped again: the item body has been through the sanitiser, and the
     * channel logo is markup this code built itself.
     */
    public const array RAW_BY_DEFAULT = ['description', 'chanlogo'];

    /**
     * Only consulted for the 2.0 pass - a bare legacy token has already
     * returned by the time this is called - so escaping a *filtered* legacy
     * token changes nothing the goldens observe, and stops a modern template
     * that writes `{title|trunc:60}` from printing a hostile feed's markup.
     */
    public static function escapesByDefault(string $name): bool
    {
        return !in_array($name, self::RAW_BY_DEFAULT, true);
    }

    /**
     * Expand the 2.0 syntax: every `{name|filter}` (legacy or new) and every
     * bare new token. Bare legacy tokens are left untouched for the Renderer's
     * sequential `str_replace()` pass, and unknown names are left verbatim
     * exactly as 1.6 left them.
     *
     * @param array<string, string> $values token name => value
     */
    public function substitute(string $html, array $values): string
    {
        if (!str_contains($html, '{')) {
            return $html;
        }

        $result = preg_replace_callback(
            self::TOKEN_PATTERN,
            function (array $m) use ($values): string {
                $name = $m[1];
                $filterPart = $m[2] ?? '';

                if (!self::isKnown($name)) {
                    return $m[0]; // unknown token: printed literally, as in 1.6
                }
                if ($filterPart === '' && self::isLegacy($name)) {
                    return $m[0]; // handled by the legacy str_replace pass
                }
                if (!array_key_exists($name, $values)) {
                    // A token that is not available in this context (an item
                    // token inside a channel chunk, say) stays literal.
                    return $m[0];
                }

                $value = $values[$name];
                $escape = self::escapesByDefault($name);
                if ($filterPart !== '') {
                    foreach ($this->parseFilters($filterPart) as [$filterName, $arg]) {
                        $value = $this->filters->apply($value, $filterName, $arg, $escape);
                    }
                }

                return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $html,
        );

        return $result ?? $html;
    }

    /**
     * Split `|trunc:120|raw` into [['trunc','120'], ['raw', null]].
     *
     * @return list<array{string, string|null}>
     */
    private function parseFilters(string $filterPart): array
    {
        $out = [];
        // Split on "|" that is not inside a double-quoted argument.
        $segments = preg_split('/\|(?=(?:[^"]*"[^"]*")*[^"]*$)/', ltrim($filterPart, '|'));
        if ($segments === false) {
            throw new TemplateException('Malformed filter expression: ' . $filterPart);
        }
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $colon = strpos($segment, ':');
            $name = $colon === false ? $segment : substr($segment, 0, $colon);
            $arg = $colon === false ? null : substr($segment, $colon + 1);
            $name = strtolower(trim($name));
            if (!Filters::exists($name)) {
                throw new TemplateException(sprintf(
                    'Unknown template filter "%s". Known filters: %s.',
                    $name,
                    implode(', ', Filters::NAMES),
                ));
            }
            $out[] = [$name, $arg];
        }

        return $out;
    }
}
