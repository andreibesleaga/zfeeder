<?php

declare(strict_types=1);

/**
 * tools/preview-templates.php — render every modern template to a static page.
 *
 * Standalone on purpose: it does not autoload or touch the application. It
 * re-implements the 1.6 section split (`strpos` of the opening marker, `strpos`
 * of the closing one, `substr` between them — so the opening marker stays in the
 * output) and a `str_replace` token pass, then writes one file per template into
 * build/preview/. That is what the Playwright pass in tools/ checks, and what a
 * human looks at before believing any of the CSS.
 *
 *   php tools/preview-templates.php [--out=build/preview]
 */

$root = dirname(__DIR__);
$out = $root . '/build/preview';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = $root . '/' . substr($arg, 6);
    }
}

$templateDir = $root . '/templates/modern';
if (!is_dir($out) && !mkdir($out, 0o775, true) && !is_dir($out)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}

// --------------------------------------------------------------------------
// Sample data. Three feeds: one with a logo and a long title, one plain, one
// with markup, entities and a very long unbreakable URL in the description —
// the three things that break a layout.
// --------------------------------------------------------------------------

/** A tiny inline SVG so the preview needs no network. */
function logo(string $label, string $bg): string
{
    $svg = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="72" height="24" viewBox="0 0 72 24">'
        . '<rect width="72" height="24" rx="4" fill="%s"/>'
        . '<text x="36" y="16" font-family="Verdana,sans-serif" font-size="11" fill="#fff" text-anchor="middle">%s</text></svg>',
        $bg,
        $label,
    );

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

$now = new DateTimeImmutable('2026-09-18 09:20:00', new DateTimeZone('UTC'));

$feeds = [
    [
        'chantitle' => 'LWN.net — Linux and free software news, with a deliberately long title',
        'chanlink' => 'https://lwn.net/',
        'chandesc' => 'News and editorial content from the free software community, published since 1998.',
        'logo' => logo('LWN', '#1d4ed8'),
        'feedurl' => 'https://lwn.net/headlines/rss',
        'category' => 'news',
        'items' => [
            ['Kernel 6.19 merge window closes with a rewritten scheduler', 'https://lwn.net/Articles/1000001/', '-2 hours',
                '<p>The merge window closed on Sunday with <strong>13,402 non-merge changesets</strong>, the largest since 6.4. The headline change is the completion of the EEVDF work.</p>'],
            ['A look at the new mount API', 'https://lwn.net/Articles/1000002/', '-9 hours',
                '<p>The new mount API has been available for several years now, but adoption has been slow. We look at what it offers and why <code>fsconfig()</code> is worth the trouble.</p>'],
            ['Debian votes on init diversity, again', 'https://lwn.net/Articles/1000003/', '-1 days',
                'The project has opened a general resolution on supporting alternative init systems; the ballot has five options &amp; runs for two weeks.'],
            ['Security updates for Thursday', 'https://lwn.net/Articles/1000004/', '-2 days',
                'Updates for firefox, ghostscript, libxml2, openssl and zfeeder-php are available; see https://lwn.net/Alerts/1000004/reference/for/the/complete/unbreakable/list for the details.'],
            ['Rust in the kernel: the first driver in tree', 'https://lwn.net/Articles/1000005/', '-4 days',
                '<p>The first non-sample Rust driver has landed. Maintainers are split on what that means for the next five years of kernel development.</p>'],
        ],
    ],
    [
        'chantitle' => 'SourceForge Project News: zFeeder',
        'chanlink' => 'https://sourceforge.net/projects/zfeeder/',
        'chandesc' => 'Releases and announcements for the zFeeder RSS aggregator.',
        'logo' => '',
        'feedurl' => 'https://sourceforge.net/p/zfeeder/news/feed',
        'category' => 'zfeeder',
        'items' => [
            ['zFeeder 2.0.0 released', 'https://sourceforge.net/p/zfeeder/news/2026/09/200/', '-30 minutes',
                'A complete rebuild on PHP 8.3: the same templates, the same OPML files, none of the 2004 security holes.'],
            ['Sixteen modern templates', 'https://sourceforge.net/p/zfeeder/news/2026/09/templates/', '-5 hours',
                'Thirteen counterparts of the originals plus cards, list and ticker — all container-query based.'],
            ['zFeeder 1.6 archived', 'https://sourceforge.net/p/zfeeder/news/2026/09/archive/', '-3 days',
                'The 2004 release is archived at github.com/andreibesleaga/old-projects; the goldens still prove the output matches it.'],
        ],
    ],
    [
        'chantitle' => 'BBC News | News Front Page | UK Edition',
        'chanlink' => 'https://www.bbc.co.uk/news/',
        'chandesc' => 'Visit BBC News for up-to-the-minute news, breaking news, video, audio and feature stories.',
        'logo' => logo('BBC', '#b3261e'),
        'feedurl' => 'https://feeds.bbci.co.uk/news/rss.xml',
        'category' => 'news',
        'items' => [
            ['Asylum applications down 41%', 'https://www.bbc.co.uk/news/1', '-45 minutes',
                'Ministers castigate media coverage of the announcement on migration figures.'],
            ['Storm warning issued for the south coast', 'https://www.bbc.co.uk/news/2', '-3 hours',
                '<p>Gusts of up to 80mph are expected overnight. <em>Travel disruption is likely.</em></p>'],
            ['Markets close flat after a volatile week', 'https://www.bbc.co.uk/news/3', '-1 days',
                'The FTSE 100 ended the week almost exactly where it started, after three days of heavy swings.'],
            ['Village Voice most-read: a very very very long headline that has to wrap cleanly even inside a 320 pixel wide column', 'https://www.bbc.co.uk/news/4', '-2 days',
                'Short summary.'],
        ],
    ],
];

// --------------------------------------------------------------------------
// The engine, re-implemented in ~60 lines exactly as documented.
// --------------------------------------------------------------------------

/** 1.6's splitTemplate(): opening marker included, closing marker excluded. */
function section(string $html, string $name): ?string
{
    $start = strpos($html, '<!-- ' . $name . ' -->');
    $end = strpos($html, '<!-- END' . $name . ' -->');
    if ($start === false || $end === false || $end < $start) {
        return null;
    }

    return substr($html, $start, $end - $start);
}

const LEGACY_TOKENS = [
    'chanlogo', 'chanlink', 'chandesc', 'chantitle', 'feedurl', 'lastupdated',
    'scripturl', 'hideurl', 'category', 'id', 'link', 'pubdate', 'title',
    'description', 'moreurl',
];
const MODERN_TOKENS = [
    'author', 'summary', 'content', 'enclosure', 'itemdate_iso', 'itemdate_rel',
    'feedid', 'position', 'set',
];

function truncate(string $value, int $limit): string
{
    $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (mb_strlen($text) <= $limit) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $cut = mb_substr($text, 0, $limit);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $limit - 20) {
        $cut = mb_substr($cut, 0, $space);
    }

    return htmlspecialchars($cut, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '…';
}

/**
 * Expand `{name}` and `{name|filter:arg}`.
 *
 * @param array<string, string> $values
 */
function substitute(string $chunk, array $values): string
{
    return (string) preg_replace_callback(
        '/\{([A-Za-z_][A-Za-z0-9_]*)(\|[^{}]*)?\}/',
        static function (array $m) use ($values): string {
            $name = $m[1];
            $known = in_array($name, LEGACY_TOKENS, true) || in_array($name, MODERN_TOKENS, true);
            if (!$known || !array_key_exists($name, $values)) {
                return $m[0];
            }
            $value = $values[$name];
            $escape = in_array($name, MODERN_TOKENS, true);
            $filters = $m[2] ?? '';
            foreach (array_filter(explode('|', $filters)) as $filter) {
                [$fname, $arg] = array_pad(explode(':', $filter, 2), 2, null);
                $arg = $arg === null ? null : trim($arg, '"\'');
                if ($fname === 'raw') {
                    $escape = false;
                } elseif ($fname === 'trunc') {
                    $value = truncate($value, (int) ($arg ?? 200));
                    $escape = false; // truncate() already escaped
                } elseif ($fname === 'date') {
                    $ts = strtotime($value);
                    $value = $ts === false ? $value : date($arg ?? 'D, d M Y H:i', $ts);
                }
            }

            return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
        },
        $chunk,
    );
}

/** @return array{0: string, 1: string} [body, template name] */
function render(string $source, string $name, string $scripturl, array $feeds, DateTimeImmutable $now): string
{
    $page = section($source, 'zFeeder template header');
    $header = section($source, 'header') ?? '';
    $channel = section($source, 'channel') ?? '';
    $news = section($source, 'news') ?? '';
    $footer = section($source, 'footer') ?? '';
    $between = section($source, 'between') ?? '';

    // The once-per-page chunk only ever has installation-level tokens in it.
    $outHtml = substitute($page ?? '', ['scripturl' => $scripturl, 'set' => 'modern', 'category' => 'news']);
    foreach ($feeds as $index => $feed) {
        $position = $index + 1;
        $logo = $feed['logo'] === ''
            ? ''
            : sprintf(
                '<a href="%s"><img src="%s" alt="%s" width="72" height="24"></a>',
                htmlspecialchars($feed['chanlink'], ENT_QUOTES),
                $feed['logo'],
                htmlspecialchars($feed['chantitle'], ENT_QUOTES),
            );
        $base = [
            'chanlogo' => $logo,
            'chanlink' => htmlspecialchars($feed['chanlink'], ENT_QUOTES),
            'chandesc' => $feed['chandesc'],
            'chantitle' => $feed['chantitle'],
            'feedurl' => htmlspecialchars($feed['feedurl'], ENT_QUOTES),
            'lastupdated' => $now->format('D, d M Y H:i:s') . ' GMT',
            'scripturl' => $scripturl,
            'hideurl' => '?zfposition=p' . $position,
            'category' => $feed['category'],
            'moreurl' => '?zfmore=p' . $position . '#zfchannel' . $position,
            'id' => (string) $position,
            'feedid' => (string) $position,
            'position' => (string) $position,
            'set' => 'modern',
        ];

        $outHtml .= substitute($header, $base);
        $outHtml .= substitute($channel, $base);

        foreach ($feed['items'] as $i => $item) {
            [$title, $link, $when, $description] = $item;
            $published = $now->modify($when);
            $values = $base + [
                'id' => (string) $i,
                'title' => htmlspecialchars($title, ENT_QUOTES),
                'link' => htmlspecialchars($link, ENT_QUOTES),
                'pubdate' => $published->format('D, d M Y H:i'),
                'description' => $description,
                'summary' => strip_tags($description),
                'content' => $description,
                'author' => 'staff@example.org',
                'enclosure' => '',
                'itemdate_iso' => $published->format(DateTimeInterface::ATOM),
                'itemdate_rel' => relative($published, $now),
            ];
            $outHtml .= substitute($news, $values);
        }

        $outHtml .= substitute($footer, $base);
        $outHtml .= substitute($between, $base);
    }

    return $outHtml;
}

function relative(DateTimeImmutable $when, DateTimeImmutable $now): string
{
    $seconds = $now->getTimestamp() - $when->getTimestamp();
    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$unit, $label]) {
        if ($seconds >= $unit) {
            $n = intdiv($seconds, $unit);

            return $n . ' ' . $label . ($n === 1 ? '' : 's') . ' ago';
        }
    }

    return 'just now';
}

function page(string $name, string $body): string
{
    return <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>zFeeder modern preview — {$name}</title>
    <style>
      :root { color-scheme: light dark; --host-bg: #eceff2; --host-fg: #223; }
      @media (prefers-color-scheme: dark) { :root { --host-bg: #05080b; --host-fg: #dde; } }
      body { margin: 0; padding: 1rem; background: var(--host-bg); color: var(--host-fg);
             font: 16px/1.5 system-ui, sans-serif; }
      h1 { font-size: 0.8125rem; text-transform: uppercase; letter-spacing: .08em;
           margin: 0 0 .75rem; font-weight: 700; }
      main { max-width: 100%; }
    </style>
    </head>
    <body>
    <h1>{$name}</h1>
    <main>
    {$body}
    </main>
    </body>
    </html>
    HTML;
}

$scripturl = '../../public/';
$rendered = [];
foreach (glob($templateDir . '/*.html') ?: [] as $file) {
    $name = basename($file, '.html');
    $source = (string) file_get_contents($file);
    foreach (['zFeeder template header', 'header', 'channel', 'news', 'footer', 'between'] as $required) {
        if (section($source, $required) === null) {
            fwrite(STDERR, "FAIL {$name}: missing section '{$required}'\n");
            exit(1);
        }
    }
    $body = render($source, $name, $scripturl, $feeds, $now);
    if (str_contains($body, '{') && preg_match('/\{(' . implode('|', array_merge(LEGACY_TOKENS, MODERN_TOKENS)) . ')[|}]/', $body, $m)) {
        fwrite(STDERR, "FAIL {$name}: token {$m[0]} left unsubstituted\n");
        exit(1);
    }
    file_put_contents($out . '/' . $name . '.html', page($name, $body));
    $rendered[] = $name;
    printf("wrote %-14s %6d bytes\n", $name . '.html', strlen($body));
}

// An index page, and a page that proves the explicit [data-zf-theme] override
// beats the OS preference in both directions.
$links = implode('', array_map(
    static fn (string $n): string => sprintf('<li><a href="%s.html">%s</a></li>', $n, $n),
    $rendered,
));
file_put_contents($out . '/index.html', page('index', "<ul>{$links}<li><a href=\"theme-attr.html\">theme-attr</a></li></ul>"));

$sample = <<<'HTML'
<section class="zf zf-feed" data-zf-theme="light" id="forced-light">
<div class="zf-bar"><div class="zf-bar__head"><h2 class="zf-bar__title">Forced light</h2>
<span class="zf-bar__time">data-zf-theme="light" on the .zf root</span></div></div></section>
<div class="zf"><hr class="zf-sep"></div>
<section class="zf zf-feed" data-zf-theme="dark" id="forced-dark">
<div class="zf-bar"><div class="zf-bar__head"><h2 class="zf-bar__title">Forced dark</h2>
<span class="zf-bar__time">data-zf-theme="dark" on the .zf root</span></div></div></section>
<div class="zf"><hr class="zf-sep"></div>
<div data-zf-theme="light"><section class="zf zf-feed" id="ancestor-light">
<div class="zf-bar"><div class="zf-bar__head"><h2 class="zf-bar__title">Forced light by an ancestor</h2>
<span class="zf-bar__time">data-zf-theme="light" on a wrapper</span></div></div></section></div>
<div class="zf"><hr class="zf-sep"></div>
<section class="zf zf-feed" id="auto"><div class="zf-bar"><div class="zf-bar__head">
<h2 class="zf-bar__title">Follows the OS</h2><span class="zf-bar__time">no data-zf-theme anywhere</span></div></div></section>
HTML;
file_put_contents(
    $out . '/theme-attr.html',
    page('theme-attr', '<link rel="stylesheet" href="' . $scripturl . 'assets/modern/zf.css">' . $sample),
);

printf("\n%d templates rendered into %s\n", count($rendered), $out);
