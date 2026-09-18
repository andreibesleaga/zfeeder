<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

/**
 * S17 — the locked dependencies carry no known advisory, and `tools/sbom.php`
 * produces a valid CycloneDX document naming every runtime dependency in
 * `composer.lock`.
 *
 * There is no 1.6 defect to close here: 2004 zFeeder had no dependencies at
 * all. The requirement exists because 2.0 has twenty-seven of them, and an
 * aggregator that parses hostile XML is exactly the kind of program whose
 * supply chain has to be stated and checked.
 *
 * The control lives in `composer audit` (run by the **Dependency and secret
 * scan** job of `.github/workflows/ci.yml`) and `tools/sbom.php`, whose output
 * `.github/workflows/release.yml` attaches to every release.
 *
 * The audit needs the advisory database, so that one test skips with a clear
 * message when there is no network. The SBOM test never does: it reads
 * `composer.lock` and nothing else.
 */
final class S17DependencyAuditTest extends SecurityTestCase
{
    public function testComposerAuditReportsNoAdvisory(): void
    {
        $output = [];
        $status = 0;
        exec(
            'cd ' . escapeshellarg(self::projectRoot()) . ' && composer audit --format=json --no-interaction 2>&1',
            $output,
            $status,
        );
        $text = implode("\n", $output);

        $report = json_decode($text, true);
        if (!is_array($report) || !array_key_exists('advisories', $report)) {
            self::markTestSkipped(
                'composer audit could not reach the advisory database, so this test cannot say anything. '
                . 'Exit status ' . $status . '. Output: ' . substr($text, 0, 400),
            );
        }

        self::assertIsArray($report['advisories']);
        self::assertSame(
            [],
            $report['advisories'],
            'composer audit reports a known vulnerability in a locked dependency: ' . $text,
        );
        self::assertSame(0, $status, 'composer audit exited non-zero: ' . $text);

        // Abandoned packages are not vulnerabilities, but they are the reason
        // the next advisory will never be fixed.
        self::assertIsArray($report['abandoned']);
        self::assertSame([], $report['abandoned'], 'a locked dependency is abandoned: ' . $text);
    }

    public function testTheSbomToolProducesValidCycloneDx(): void
    {
        $bom = $this->sbom();

        self::assertSame('CycloneDX', $bom['bomFormat'] ?? null);
        self::assertIsString($bom['specVersion'] ?? null);
        self::assertMatchesRegularExpression('/^1\.\d+$/', $bom['specVersion']);
        self::assertIsInt($bom['version'] ?? null);

        self::assertIsArray($bom['metadata'] ?? null);
        /** @var array<string, mixed> $metadata */
        $metadata = $bom['metadata'];
        self::assertIsArray($metadata['component'] ?? null);
        /** @var array<string, mixed> $component */
        $component = $metadata['component'];
        self::assertSame('application', $component['type'] ?? null);
        self::assertSame('andreibesleaga/zfeeder', $component['name'] ?? null);
        self::assertNotSame('', $component['version'] ?? '');

        // A timestamp that is not a timestamp makes the document unusable to
        // every consumer that validates it.
        self::assertIsString($metadata['timestamp'] ?? null);
        self::assertInstanceOf(\DateTimeImmutable::class, new \DateTimeImmutable($metadata['timestamp']));
    }

    public function testTheSbomListsEveryRuntimeDependencyInTheLockFile(): void
    {
        $bom = $this->sbom();
        /** @var list<array<string, mixed>> $components */
        $components = $bom['components'] ?? [];
        self::assertNotSame([], $components, 'the SBOM lists no components at all');

        $listed = [];
        foreach ($components as $entry) {
            self::assertSame('library', $entry['type'] ?? null);
            self::assertIsString($entry['name'] ?? null);
            self::assertIsString($entry['version'] ?? null);
            self::assertIsString($entry['purl'] ?? null);
            self::assertStringStartsWith('pkg:composer/', $entry['purl']);

            $group = is_string($entry['group'] ?? null) ? $entry['group'] : '';
            $listed[$group . '/' . $entry['name']] = $entry['version'];
        }

        $expected = [];
        foreach ($this->lock()['packages'] as $package) {
            self::assertIsArray($package);
            self::assertIsString($package['name'] ?? null);
            self::assertIsString($package['version'] ?? null);
            $expected[$package['name']] = $package['version'];
        }

        ksort($expected);
        ksort($listed);
        self::assertSame($expected, $listed, 'the SBOM and composer.lock disagree about the runtime dependencies');
    }

    public function testTheSbomDoesNotListDevelopmentOnlyDependencies(): void
    {
        // phpunit and php-cs-fixer are not shipped, and naming them in a
        // released SBOM would misstate what an installation actually runs.
        $bom = $this->sbom();
        $names = [];
        foreach ($bom['components'] ?? [] as $entry) {
            self::assertIsArray($entry);
            $names[] = (is_string($entry['group'] ?? null) ? $entry['group'] : '') . '/' . (string) ($entry['name'] ?? '');
        }

        foreach ($this->lock()['packages-dev'] as $package) {
            self::assertIsArray($package);
            $name = (string) ($package['name'] ?? '');
            self::assertNotContains($name, $names, $name . ' is a development dependency but is in the SBOM');
        }
    }

    public function testEveryRuntimeDependencyIsPinnedToAnExactVersion(): void
    {
        // A lock file with a branch alias instead of a version is a supply
        // chain hole: the same lock resolves to different code over time.
        foreach ($this->lock()['packages'] as $package) {
            self::assertIsArray($package);
            $name = (string) ($package['name'] ?? '');
            $version = (string) ($package['version'] ?? '');

            self::assertNotSame('', $version, $name . ' has no version');
            self::assertStringStartsNotWith('dev-', $version, $name . ' is locked to a branch: ' . $version);
            self::assertIsArray($package['dist'] ?? null, $name . ' has no distribution reference');
        }
    }

    /** @return array<string, mixed> */
    private function sbom(): array
    {
        $output = [];
        $status = 0;
        exec('php ' . escapeshellarg(self::projectRoot() . '/tools/sbom.php') . ' 2>&1', $output, $status);

        self::assertSame(0, $status, 'tools/sbom.php failed: ' . implode("\n", $output));

        $decoded = json_decode(implode("\n", $output), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'tools/sbom.php did not produce a JSON document');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array{packages: list<mixed>, packages-dev: list<mixed>} */
    private function lock(): array
    {
        $decoded = json_decode(self::readFile(self::projectRoot() . '/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['packages'] ?? null);
        self::assertIsArray($decoded['packages-dev'] ?? null);

        /** @var array{packages: list<mixed>, packages-dev: list<mixed>} $decoded */
        return $decoded;
    }
}
