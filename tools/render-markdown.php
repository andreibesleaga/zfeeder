#!/usr/bin/env php
<?php
/**
 * The small subset of Markdown the portfolio pages use, rendered to a styled
 * HTML page. Deliberately not a full implementation: it handles exactly the
 * constructs in docs/portfolio/index.md and docs/HISTORY.md, and anything else
 * passes through escaped.
 *
 * Usage: php tools/render-markdown.php <file.md> "<page title>" > out.html
 */
declare(strict_types=1);

$source = $argv[1] ?? null;
$title = $argv[2] ?? 'zFeeder';
if ($source === null || !is_file($source)) {
    fwrite(STDERR, "usage: render-markdown.php <file.md> \"<title>\"\n");
    exit(1);
}

$markdown = (string) file_get_contents($source);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/** Inline constructs, applied to already-escaped text. */
$inline = static function (string $text) use ($e): string {
    $text = $e($text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
    $text = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)\)/', '<img src="$2" alt="$1" loading="lazy">', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a href="$2">$1</a>', $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<![\w*])\*([^*]+)\*(?![\w*])/', '<em>$1</em>', $text) ?? $text;

    return $text;
};

$lines = preg_split('/\r?\n/', $markdown) ?: [];
$html = [];
$inCode = false;
$inList = false;
$inTable = false;
$tableHeaderDone = false;
$paragraph = [];

$flushParagraph = static function () use (&$paragraph, &$html, $inline): void {
    if ($paragraph !== []) {
        $html[] = '<p>' . $inline(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    }
};
$closeList = static function () use (&$inList, &$html): void {
    if ($inList) {
        $html[] = '</ul>';
        $inList = false;
    }
};
$closeTable = static function () use (&$inTable, &$tableHeaderDone, &$html): void {
    if ($inTable) {
        $html[] = '</tbody></table></div>';
        $inTable = false;
        $tableHeaderDone = false;
    }
};

foreach ($lines as $line) {
    if (str_starts_with(trim($line), '```')) {
        $flushParagraph();
        $closeList();
        $closeTable();
        $html[] = $inCode ? '</code></pre>' : '<pre><code>';
        $inCode = !$inCode;
        continue;
    }
    if ($inCode) {
        $html[] = $e($line);
        continue;
    }

    $trimmed = trim($line);

    if ($trimmed === '') {
        $flushParagraph();
        $closeList();
        $closeTable();
        continue;
    }

    if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
        $flushParagraph();
        $closeList();
        $closeTable();
        $level = strlen($m[1]);
        $html[] = "<h{$level}>" . $inline($m[2]) . "</h{$level}>";
        continue;
    }

    if ($trimmed === '---' || $trimmed === '***') {
        $flushParagraph();
        $closeList();
        $closeTable();
        $html[] = '<hr>';
        continue;
    }

    if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m) === 1) {
        $flushParagraph();
        $closeTable();
        if (!$inList) {
            $html[] = '<ul>';
            $inList = true;
        }
        $html[] = '<li>' . $inline($m[1]) . '</li>';
        continue;
    }

    if (str_starts_with($trimmed, '|')) {
        $flushParagraph();
        $closeList();
        $cells = array_map('trim', explode('|', trim($trimmed, '|')));
        // The |---|---| separator row only tells us the header is finished.
        if (preg_match('/^[\s|:-]+$/', $trimmed) === 1) {
            if ($inTable && !$tableHeaderDone) {
                $html[] = '</thead><tbody>';
                $tableHeaderDone = true;
            }
            continue;
        }
        if (!$inTable) {
            $html[] = '<div class="table-wrap"><table><thead>';
            $inTable = true;
            $tableHeaderDone = false;
        }
        $tag = $tableHeaderDone ? 'td' : 'th';
        $row = '<tr>';
        foreach ($cells as $cell) {
            $row .= "<{$tag}>" . $inline($cell) . "</{$tag}>";
        }
        $html[] = $row . '</tr>';
        continue;
    }

    $closeList();
    $closeTable();
    $paragraph[] = $trimmed;
}
$flushParagraph();
$closeList();
$closeTable();
if ($inCode) {
    $html[] = '</code></pre>';
}

$body = implode("\n", $html);
$safeTitle = $e($title);
$year = gmdate('Y');

echo <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <meta name="description" content="zFeeder, the 2004 PHP feed aggregator, rebuilt for 2026.">
    <style>
      :root { color-scheme: light dark; --bg:#fff; --fg:#16202a; --muted:#55636f; --accent:#006699; --border:#d8dee4; --surface:#f6f8fa; }
      @media (prefers-color-scheme: dark) {
        :root { --bg:#1c2430; --fg:#eaf0f6; --muted:#adbac8; --accent:#79bbff; --border:#3d4a5a; --surface:#273140; }
      }
      * { box-sizing: border-box; }
      body { margin:0; background:var(--bg); color:var(--fg); font:16px/1.65 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
      main { max-width: 56rem; margin: 0 auto; padding: clamp(1.5rem,5vw,3rem) clamp(1rem,4vw,2rem) 5rem; }
      h1 { font-size: clamp(2rem,5vw,2.8rem); line-height:1.15; letter-spacing:-0.02em; margin:0 0 .5rem; }
      h2 { font-size:1.5rem; margin:2.5rem 0 .75rem; padding-bottom:.3rem; border-bottom:1px solid var(--border); }
      h3 { font-size:1.15rem; margin:1.75rem 0 .5rem; }
      a { color: var(--accent); }
      a:focus-visible { outline:3px solid var(--accent); outline-offset:2px; border-radius:3px; }
      img { max-width:100%; height:auto; border:1px solid var(--border); border-radius:8px; display:block; margin:1rem 0; }
      code { font-family: ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.9em; background:var(--surface); padding:.15em .4em; border-radius:4px; }
      pre { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:1rem; overflow-x:auto; }
      pre code { background:none; padding:0; }
      .table-wrap { overflow-x:auto; margin:1rem 0; }
      table { border-collapse:collapse; width:100%; font-size:.95rem; }
      th, td { text-align:left; padding:.5rem .75rem; border-bottom:1px solid var(--border); vertical-align:top; }
      th { background:var(--surface); font-weight:600; }
      ul { padding-left:1.25rem; }
      li { margin:.25rem 0; }
      hr { border:0; border-top:1px solid var(--border); margin:2.5rem 0; }
      footer { border-top:1px solid var(--border); color:var(--muted); font-size:.9rem; padding:1.25rem clamp(1rem,4vw,2rem); }
      footer p { max-width:56rem; margin:0 auto; }
    </style>
    </head>
    <body>
    <main>
    {$body}
    </main>
    <footer><p>zFeeder &middot; <a href="https://github.com/andreibesleaga/zfeeder">source code</a> &middot;
    <a href="https://sourceforge.net/projects/zvonnews/">the 2004 original on SourceForge</a> &middot;
    &copy; 2003&ndash;{$year} Andrei N. Besleaga &middot; GPL-2.0-or-later</p></footer>
    </body>
    </html>
    HTML;
