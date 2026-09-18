#!/usr/bin/env php
<?php
/**
 * Fails the build when line coverage of src/ falls below a floor.
 * Usage: php tools/coverage-gate.php coverage.xml 90
 */
declare(strict_types=1);

$file = $argv[1] ?? 'coverage.xml';
$floor = (float) ($argv[2] ?? '90');

if (!is_file($file)) {
    fwrite(STDERR, "coverage-gate: no report at {$file}\n");
    exit(1);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "coverage-gate: unreadable report\n");
    exit(1);
}

$metrics = $xml->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "coverage-gate: no project metrics in report\n");
    exit(1);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? ($covered / $statements) * 100 : 0.0;

printf("coverage: %.2f%% of %d statements (floor %.0f%%)\n", $percent, $statements, $floor);

if ($percent + 0.005 < $floor) {
    fwrite(STDERR, sprintf("coverage-gate: below the floor by %.2f points\n", $floor - $percent));
    exit(1);
}
