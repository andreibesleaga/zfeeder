#!/usr/bin/env php
<?php
/**
 * Writes a CycloneDX 1.5 software bill of materials from composer.lock.
 * Kept in-repo rather than added as a dependency: the format is small and
 * one more build-time package is not worth it.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$lockFile = $root . '/composer.lock';
if (!is_file($lockFile)) {
    fwrite(STDERR, "sbom: composer.lock not found\n");
    exit(1);
}

$lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

// The product version has one home: src/Version.php.
require_once $root . '/src/Version.php';
$productVersion = Zfeeder\Version::NUMBER;

$components = [];
foreach ($lock['packages'] ?? [] as $package) {
    $name = (string) $package['name'];
    [$vendor, $short] = array_pad(explode('/', $name, 2), 2, '');
    $version = (string) $package['version'];
    $components[] = array_filter([
        'type' => 'library',
        'bom-ref' => 'pkg:composer/' . $name . '@' . $version,
        'group' => $vendor,
        'name' => $short,
        'version' => $version,
        'description' => $package['description'] ?? null,
        'purl' => 'pkg:composer/' . $name . '@' . $version,
        'licenses' => array_map(
            static fn (string $id): array => ['license' => ['id' => $id]],
            (array) ($package['license'] ?? []),
        ) ?: null,
        'externalReferences' => isset($package['source']['url'])
            ? [['type' => 'vcs', 'url' => $package['source']['url']]]
            : null,
    ], static fn (mixed $v): bool => $v !== null && $v !== []);
}

$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'version' => 1,
    'metadata' => [
        'timestamp' => gmdate('c'),
        'tools' => [['vendor' => 'zFeeder', 'name' => 'tools/sbom.php', 'version' => '1.0']],
        'component' => [
            'type' => 'application',
            'bom-ref' => 'pkg:composer/' . $manifest['name'],
            'name' => $manifest['name'],
            'version' => $productVersion,
            'description' => $manifest['description'] ?? '',
            'licenses' => [['license' => ['id' => 'GPL-2.0-or-later']]],
        ],
    ],
    'components' => $components,
];

echo json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
