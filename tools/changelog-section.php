#!/usr/bin/env php
<?php
/** Prints the CHANGELOG section for one version, for the release notes. */
declare(strict_types=1);

$tag = ltrim((string) ($argv[1] ?? ''), 'v');
$path = dirname(__DIR__) . '/CHANGELOG.md';
if (!is_file($path)) {
    echo "Release {$tag}\n";
    exit(0);
}

$lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
$out = [];
$inside = false;
foreach ($lines as $line) {
    if (preg_match('/^##\s+\[?v?([0-9][^\]\s]*)\]?/', $line, $m) === 1) {
        if ($inside) {
            break;
        }
        $inside = ($m[1] === $tag);
        continue;
    }
    if ($inside) {
        $out[] = $line;
    }
}

echo trim(implode("\n", $out)) !== '' ? trim(implode("\n", $out)) . "\n" : "Release {$tag}\n";
