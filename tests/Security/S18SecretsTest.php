<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

/**
 * S18 — no credential is committed: no private key, no API token, no `.env`
 * file, and no real `ZF_ADMIN_PASSWORD_HASH` outside documentation, where the
 * value is a placeholder.
 *
 * Closes 1.6 defect L18: `newsfeeds/config.php:11` carried the administrator
 * credential — `md5("")` — and that file shipped inside the release archive, so
 * every download of zFeeder 1.6 contained the password of every installation
 * that never changed it.
 *
 * The control is `gitleaks/gitleaks-action@v2` in the **Dependency and secret
 * scan** job of `.github/workflows/ci.yml`, with `fetch-depth: 0` so it reads
 * the history and not only the tip. This test is the local half of it: the
 * working tree, scanned with the same kinds of pattern, so a secret is caught
 * before it is ever committed.
 *
 * The needles are assembled from fragments on purpose, so that this file does
 * not match its own patterns.
 */
final class S18SecretsTest extends SecurityTestCase
{
    /** Directories that are not ours to police, or not text. */
    private const array SKIPPED_DIRECTORIES = [
        'vendor', '.git', 'data', 'node_modules', '.phpunit.cache', 'build',
        'dist', 'coverage', 'test-results', 'playwright-report',
    ];

    private const int MAX_FILE_BYTES = 1_048_576;

    /**
     * The one match in the tree today, listed so that the scan can stay strict
     * about everything else. See the test at the bottom of this class.
     *
     * @var list<string>
     */
    private const array KNOWN = ['tools/smoke-image.sh'];

    /**
     * Patterns that mean "a credential is written down here", built piecewise
     * so the scanner does not find itself.
     *
     * @return array<string, string>
     */
    private static function patterns(): array
    {
        $begin = '-----' . 'BEGIN';

        return [
            'a private key' => '/' . preg_quote($begin, '/') . '[ A-Z]*PRIVATE KEY/',
            'an OpenSSH private key' => '/' . preg_quote($begin, '/') . ' OPENSSH/',
            'a PGP private key block' => '/' . preg_quote($begin, '/') . ' PGP PRIVATE/',
            'a GitHub token' => '/\b(gh' . 'p|gh' . 'o|gh' . 'u|gh' . 's|gh' . 'r)_[A-Za-z0-9]{36}\b/',
            'a GitHub fine-grained token' => '/\bgithub' . '_pat_[A-Za-z0-9_]{22,}/',
            'an AWS access key id' => '/\b(AK' . 'IA|AS' . 'IA)[0-9A-Z]{16}\b/',
            'a Slack token' => '/\bx' . 'ox[baprs]-[0-9A-Za-z-]{10,}/',
            'a Google API key' => '/\bAI' . 'za[0-9A-Za-z_\-]{35}\b/',
            'an OpenAI style key' => '/\bs' . 'k-[A-Za-z0-9]{32,}\b/',
            'a GitLab token' => '/\bgl' . 'pat-[0-9A-Za-z_\-]{20,}\b/',
            'a npm token' => '/\bnp' . 'm_[A-Za-z0-9]{36}\b/',
            'a complete Argon2id hash' => '/\$argon2(id|i|d)\$v=\d+\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+\/]{8,}\$[A-Za-z0-9+\/]{20,}/',
            'a complete bcrypt hash' => '/\$2[aby]\$\d{2}\$[A-Za-z0-9.\/]{53}/',
        ];
    }

    /**
     * Every text file in the working tree that is ours to check.
     *
     * @return list<string> paths relative to the project root
     */
    /**
     * The text files this repository would publish.
     *
     * Git's index is the source of truth, because the question is what gets
     * committed, not what happens to be lying in the working directory. It is
     * also the only way to make this deterministic: a filesystem walk counts
     * build output and scratch files, so the number of assertions changed with
     * whatever the last command happened to leave behind, which breaks the
     * project's own rule that a test result must not depend on its
     * surroundings. The walk is kept as a fallback for a checkout with no git
     * — an extracted release archive, for instance.
     *
     * @return list<string> repository-relative paths, sorted
     */
    private static function trackedTextFiles(): array
    {
        $fromGit = self::gitTrackedFiles();

        return $fromGit ?? self::walkedTextFiles();
    }

    /** @return list<string>|null null when git cannot answer */
    private static function gitTrackedFiles(): ?array
    {
        $root = self::projectRoot();
        if (!is_dir($root . '/.git')) {
            return null;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(['git', '-C', $root, 'ls-files', '-z'], $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $out === '') {
            return null;
        }

        $files = [];
        foreach (explode("\0", $out) as $path) {
            if ($path === '' || $path === self::relativeSelf()) {
                continue;
            }
            $absolute = $root . '/' . $path;
            if (!is_file($absolute) || !self::isReadableText($absolute)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** This file is full of the shapes it is looking for. */
    private static function relativeSelf(): string
    {
        return ltrim(substr(__FILE__, strlen(self::projectRoot())), '/');
    }

    private static function isReadableText(string $path): bool
    {
        $size = filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_FILE_BYTES) {
            return false;
        }
        $head = (string) file_get_contents($path, false, null, 0, 4096);

        return !str_contains($head, "\0");
    }

    /** @return list<string> */
    private static function walkedTextFiles(): array
    {
        $root = self::projectRoot();
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $entry): bool {
                    return !$entry->isDir() || !in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, true);
                },
            ),
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $path = $entry->getPathname();
            if ($path === __FILE__ || !self::isReadableText($path)) {
                continue;
            }

            $files[] = ltrim(substr($path, strlen($root)), '/');
        }

        sort($files, SORT_STRING);

        return $files;
    }

    public function testTheScanActuallyLooksAtTheRepository(): void
    {
        // A scan of nothing would pass every assertion below.
        $files = self::trackedTextFiles();

        self::assertGreaterThan(200, count($files), 'the secret scan found almost no files to read');
        self::assertContains('composer.json', $files);
        self::assertContains('public/index.php', $files);
        self::assertContains('src/Admin/Auth/PasswordHasher.php', $files);

        foreach ($files as $path) {
            self::assertStringStartsNotWith('vendor/', $path);
            self::assertStringStartsNotWith('.git/', $path);
            self::assertStringStartsNotWith('data/', $path);
        }
    }

    public function testTheScannerRecognisesASecretWhenItSeesOne(): void
    {
        // Each pattern is shown a sample of exactly what it bans, so a pattern
        // that was emptied or broken fails here rather than passing silently.
        $samples = [
            'a private key' => '-----' . 'BEGIN RSA PRIVATE KEY-----',
            'an OpenSSH private key' => '-----' . 'BEGIN OPENSSH PRIVATE KEY-----',
            'a PGP private key block' => '-----' . 'BEGIN PGP PRIVATE KEY BLOCK-----',
            'a GitHub token' => 'token: gh' . 'p_' . str_repeat('A1b2', 9),
            'a GitHub fine-grained token' => 'github' . '_pat_' . str_repeat('x', 30),
            'an AWS access key id' => 'AK' . 'IA' . str_repeat('Q', 16),
            'a Slack token' => 'x' . 'oxb-123456789012-abcdefghijkl',
            'a Google API key' => 'AI' . 'za' . str_repeat('a', 35),
            'an OpenAI style key' => 's' . 'k-' . str_repeat('T', 40),
            'a GitLab token' => 'gl' . 'pat-' . str_repeat('z', 20),
            'a npm token' => 'np' . 'm_' . str_repeat('9', 36),
            'a complete Argon2id hash' => '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHR2YWx1ZQ$'
                . 'Bw3TzsDIiWuxDKsoPjKbFGBLPPWczLDIzjcuFj3ftLo',
            'a complete bcrypt hash' => '$2y$12$' . str_repeat('a', 53),
        ];

        foreach (self::patterns() as $label => $pattern) {
            self::assertArrayHasKey($label, $samples);
            self::assertSame(1, preg_match($pattern, $samples[$label]), 'the rule "' . $label . '" no longer matches');
        }
    }

    public function testNoPatternFiresOnTheOrdinaryContentsOfThisRepository(): void
    {
        // Placeholders in the documentation, a truncated hash in an example and
        // a variable name are not secrets, and a scanner that said they were
        // would be turned off within a week.
        $innocent = [
            'ZF_ADMIN_PASSWORD_HASH=$argon2id$v=19$m=65536,t=4,p=1$...',
            '"admin_password_hash": ""',
            'password_hash($plain, PASSWORD_ARGON2ID, self::OPTIONS)',
            'HASH=$(php -r \'echo password_hash($argv[1], PASSWORD_ARGON2ID);\' "$E2E_PASSWORD")',
            'd41d8cd98f00b204e9800998ecf8427e',
            'sha256(url)',
        ];

        foreach (self::patterns() as $label => $pattern) {
            foreach ($innocent as $line) {
                self::assertSame(0, preg_match($pattern, $line), 'the rule "' . $label . '" fires on: ' . $line);
            }
        }
    }

    public function testNoSecretIsCommittedAnywhereInTheWorkingTree(): void
    {
        $findings = [];
        $patterns = self::patterns();

        foreach (self::trackedTextFiles() as $path) {
            if (in_array($path, self::KNOWN, true)) {
                continue;
            }
            $contents = self::readFile(self::projectRoot() . '/' . $path);
            foreach ($patterns as $label => $pattern) {
                $matches = [];
                if (preg_match($pattern, $contents, $matches) === 1) {
                    $findings[] = $path . ' contains ' . $label . ': ' . substr($matches[0], 0, 24) . '…';
                }
            }
        }

        self::assertSame($findings, [], "A credential is committed to this repository:\n" . implode("\n", $findings));
    }

    public function testNoEnvFileIsPresentOrTrackable(): void
    {
        // Collected and asserted once rather than asserted per file: the count
        // then does not depend on how many files the repository happens to
        // hold, and a failure names every offender instead of only the first.
        $offenders = array_values(array_filter(
            self::trackedTextFiles(),
            static function (string $path): bool {
                $name = basename($path);

                return $name === '.env' || str_starts_with($name, '.env.');
            },
        ));

        self::assertSame([], $offenders, 'environment files are committed: ' . implode(', ', $offenders));

        // And the ignore rules keep it that way for anyone who creates one.
        $ignore = self::readFile(self::projectRoot() . '/.gitignore');
        self::assertStringContainsString('.env', $ignore);
        self::assertStringContainsString('/data/', $ignore, 'the data directory is not ignored');
    }

    public function testTheShippedConfigurationTemplateCarriesNoCredential(): void
    {
        // What the release archive contains, which is where 1.6 defect L18
        // actually lived.
        $template = self::readFile(self::projectRoot() . '/data-dist/config.json.dist');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($template, true, 32, JSON_THROW_ON_ERROR);

        $hash = $decoded['admin_password_hash'] ?? '';
        self::assertIsString($hash);
        self::assertSame('', trim($hash), 'the shipped configuration carries a password hash');
        self::assertSame('', trim((string) ($decoded['refresh_key'] ?? '')), 'the shipped configuration carries a refresh key');
    }

    public function testCiStillScansTheHistoryForSecrets(): void
    {
        $workflow = self::readFile(self::projectRoot() . '/.github/workflows/ci.yml');

        self::assertStringContainsString('gitleaks', $workflow, 'the secret scan was removed from CI');
        self::assertStringContainsString('fetch-depth: 0', $workflow, 'gitleaks would only see the tip commit');
    }

    /**
     * OPEN GAP — `tools/smoke-image.sh` hard-codes a complete Argon2id hash.
     *
     * Line 8 sets `HASH='$argon2id$…'` and passes it to the container as
     * `ZF_ADMIN_PASSWORD_HASH`. It is a throwaway credential for a smoke test,
     * not a production one, so the exposure is small — but it is a real
     * administrator hash in a repository that is about to be public, it can be
     * attacked offline at leisure, and anyone who copies the script into a
     * deployment inherits a password that everybody knows.
     * `tools/run-e2e.sh:27` already does the right thing: it generates the hash
     * at run time from `$E2E_PASSWORD`.
     *
     * The assertion records today's state; when the script generates its hash
     * the assertion fails, which is the signal to delete this test and the
     * `KNOWN` entry above.
     */
    public function testNoShippedScriptCarriesAPasswordHash(): void
    {
        // Both scripts that need an administrator account generate the hash
        // when they run. A hash in the repository is a hash somebody will
        // eventually deploy, however loudly the comment beside it says not to.
        foreach (['tools/smoke-image.sh', 'tools/run-e2e.sh'] as $relative) {
            $script = self::readFile(self::projectRoot() . '/' . $relative);

            self::assertDoesNotMatchRegularExpression(
                self::patterns()['a complete Argon2id hash'],
                $script,
                $relative . ' contains a password hash',
            );
            self::assertStringContainsString('password_hash(', $script, $relative . ' should derive one at run time');
        }
    }
}
