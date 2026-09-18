<?php

declare(strict_types=1);

namespace Zfeeder\Parse;

/**
 * Finds the feeds a web page declares, which is how "add new feed" in the
 * admin panel turns a site address the user typed into a subscription.
 *
 * Everything here has to survive real-world HTML: unclosed tags, attributes in
 * any order and any case, `rel="alternate home"` rather than a bare
 * `alternate`, and hrefs written in all four relative forms. The parser is
 * therefore DOMDocument in HTML mode with libxml errors collected and dropped
 * — it recovers from the malformed markup that a strict parser would refuse,
 * and a page that fails to parse simply declares no feeds rather than raising.
 *
 * Nothing here fetches anything. The caller decides what to do with the URLs,
 * and passes them through UrlGuard before opening a connection.
 */
final class Autodiscovery
{
    /**
     * Link types that mean "this is a feed". `application/json` is included
     * because JSON Feed 1.0 recommended it before `application/feed+json` was
     * registered, and plenty of pages still use it.
     *
     * @var list<string>
     */
    public const array FEED_TYPES = [
        'application/rss+xml',
        'application/atom+xml',
        'application/feed+json',
        'application/json',
        'application/rdf+xml',
        'text/xml',
    ];

    /**
     * The conventional locations to try when a page declares nothing at all.
     * Ordered by how likely each is to exist, because the caller stops at the
     * first one that parses.
     *
     * @var list<string>
     */
    public const array FALLBACK_PATHS = [
        '/feed',
        '/feed/',
        '/rss',
        '/rss.xml',
        '/index.xml',
        '/atom.xml',
        '/feed.xml',
        '/feed.json',
    ];

    /**
     * @return list<array{url: string, type: string, title: string}> declared feeds,
     *         absolute, deduplicated, in document order
     */
    public function discover(string $html, string $pageUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = self::loadHtml($html);
        if ($document === null) {
            return [];
        }

        $base = self::baseUrl($document, $pageUrl);

        $found = [];
        $seen = [];
        foreach ($document->getElementsByTagName('link') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            if (!self::relIncludesAlternate($link->getAttribute('rel'))) {
                continue;
            }

            $type = strtolower(trim($link->getAttribute('type')));
            // A type parameter such as "; charset=utf-8" is legal and common.
            $type = trim(explode(';', $type)[0]);
            if (!in_array($type, self::FEED_TYPES, true)) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $url = self::resolve($href, $base);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $found[] = [
                'url' => $url,
                'type' => $type,
                'title' => trim($link->getAttribute('title')),
            ];
        }

        return $found;
    }

    /**
     * The guesses to try when discover() found nothing, already resolved
     * against the page's own origin.
     *
     * @return list<string>
     */
    public function fallbackPaths(string $pageUrl = ''): array
    {
        if (trim($pageUrl) === '') {
            return self::FALLBACK_PATHS;
        }

        $out = [];
        foreach (self::FALLBACK_PATHS as $path) {
            $out[] = self::resolve($path, $pageUrl);
        }

        return $out;
    }

    // ---- internals -------------------------------------------------------

    private static function loadHtml(string $html): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            // The meta hint keeps libxml from guessing Latin-1 for a UTF-8 page,
            // which would mangle non-ASCII feed titles.
            $prefix = stripos($html, '<meta') === false || stripos($html, 'charset') === false
                ? '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
                : '';
            $ok = $document->loadHTML(
                $prefix . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );

            return $ok ? $document : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** `rel` is a space separated token list: "alternate home" counts. */
    private static function relIncludesAlternate(string $rel): bool
    {
        $tokens = preg_split('/\s+/', strtolower(trim($rel)));

        return is_array($tokens) && in_array('alternate', $tokens, true);
    }

    /** `<base href>` wins over the page URL for every relative reference on the page. */
    private static function baseUrl(\DOMDocument $document, string $pageUrl): string
    {
        foreach ($document->getElementsByTagName('base') as $base) {
            if (!$base instanceof \DOMElement) {
                continue;
            }
            $href = trim($base->getAttribute('href'));
            if ($href !== '') {
                return self::resolve($href, $pageUrl);
            }
        }

        return $pageUrl;
    }

    /**
     * Resolves a reference against a base URL: absolute, scheme-relative
     * (`//host/x`), root-relative (`/x`), path-relative (`x`, `../x`) and the
     * empty reference all appear in real `<link href>` attributes.
     */
    private static function resolve(string $reference, string $base): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return $base;
        }
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $reference) === 1) {
            return $reference;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['host'])) {
            return $reference;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? $parts['scheme'] : 'http';
        $authority = $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        if (str_starts_with($reference, '//')) {
            return $scheme . ':' . $reference;
        }
        if (str_starts_with($reference, '/')) {
            return $scheme . '://' . $authority . self::normalise($reference);
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        $slash = strrpos($path, '/');
        $directory = $slash === false ? '/' : substr($path, 0, $slash + 1);

        return $scheme . '://' . $authority . self::normalise($directory . $reference);
    }

    /** Collapses `.` and `..` so two spellings of one path deduplicate. */
    private static function normalise(string $path): string
    {
        $query = '';
        $mark = strpos($path, '?');
        if ($mark !== false) {
            $query = substr($path, $mark);
            $path = substr($path, 0, $mark);
        }

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }

        $normalised = '/' . implode('/', $out);
        if (str_ends_with($path, '/') && !str_ends_with($normalised, '/')) {
            $normalised .= '/';
        }

        return $normalised . $query;
    }
}
