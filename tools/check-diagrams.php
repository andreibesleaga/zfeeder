<?php

/**
 * Checks the committed diagram SVGs.
 *
 * Rendering needs Mermaid and a browser, which CI does not install. Reading the
 * result back needs neither, and the defects that reached the repository once
 * were all visible in the output file:
 *
 *   - invalid XML, so the file could not be opened as an image at all;
 *   - HTML labels in a foreignObject, which GitHub's sanitiser removes, so the
 *     diagram arrives on the site with its text missing;
 *   - an entity escape that did not resolve, printed literally in the picture;
 *   - a word from the source split across two lines by the text wrapper.
 *
 * Run tools/render-diagrams.sh after editing a .mmd; this says whether what
 * came out is fit to commit.
 */
declare(strict_types=1);

$dir = $argv[1] ?? __DIR__ . '/../docs/diagrams';
$sources = glob($dir . '/*.mmd');
sort($sources);

if ($sources === false || $sources === []) {
    fwrite(STDERR, "check-diagrams: no .mmd sources in {$dir}\n");
    exit(1);
}

$failures = 0;

foreach ($sources as $source) {
    $name = basename($source, '.mmd');
    $svg = $dir . '/' . $name . '.svg';
    $problems = [];

    if (!is_file($svg)) {
        report($name, ['no rendered .svg; run tools/render-diagrams.sh']);
        $failures++;
        continue;
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $wellFormed = $document->loadXML((string) file_get_contents($svg));
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$wellFormed) {
        $first = $errors !== [] ? trim($errors[0]->message) : 'unknown parse error';
        report($name, ["not well-formed XML: {$first}"]);
        $failures++;
        continue;
    }

    if ($document->getElementsByTagName('foreignObject')->length > 0) {
        $problems[] = 'contains foreignObject; GitHub strips it and the labels disappear';
    }

    // One entry per drawn line. A <text> that holds <tspan>s must not
    // contribute its own concatenated content: joining the lines back together
    // is precisely what would hide a word split between them.
    $rendered = '';
    foreach ($document->getElementsByTagName('text') as $node) {
        $spans = $node->getElementsByTagName('tspan');
        if ($spans->length === 0) {
            $rendered .= $node->textContent . "\n";
            continue;
        }
        foreach ($spans as $span) {
            $rendered .= $span->textContent . "\n";
        }
    }

    if (preg_match('/[&#][0-9a-z]+;/i', $rendered, $match) === 1) {
        $problems[] = "an escape was printed literally: {$match[0]}";
    }

    foreach (splitWords($source, $rendered) as $word) {
        $problems[] = "'{$word}' is broken across lines; shorten the label or add a <br/>";
    }

    if ($problems !== []) {
        report($name, $problems);
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\ncheck-diagrams: {$failures} of " . count($sources) . " diagrams need attention\n");
    exit(1);
}

echo 'check-diagrams: clean (' . count($sources) . " diagrams)\n";

/**
 * Words from the source that the renderer did not keep in one piece.
 *
 * Native SVG text wraps at a fixed width without regard for word boundaries, so
 * a long identifier can come out as two fragments. A word that survived appears
 * contiguously somewhere in the rendered text.
 *
 * @return list<string>
 */
function splitWords(string $source, string $rendered): array
{
    // A label is what sits between quotes in a Mermaid source.
    preg_match_all('/"([^"]*)"/', (string) file_get_contents($source), $matches);

    $split = [];
    foreach ($matches[1] as $label) {
        foreach (preg_split('/\s+|<br\/>/', $label) ?: [] as $word) {
            $word = trim($word, '.,;:');
            // Short words never reach the wrapping width; anything with a
            // space in it was not one word to begin with.
            if (preg_match('/^[A-Za-z][A-Za-z0-9_()\\\\\\/.-]{7,}$/', $word) !== 1) {
                continue;
            }
            if (!str_contains($rendered, $word)) {
                $split[$word] = true;
            }
        }
    }

    return array_keys($split);
}

/** @param list<string> $problems */
function report(string $name, array $problems): void
{
    foreach ($problems as $problem) {
        fwrite(STDERR, sprintf("  %-24s %s\n", $name . '.svg', $problem));
    }
}
