<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Config\Config;
use Zfeeder\Config\ConfigLoader;
use Zfeeder\Config\ConfigWriter;
use Zfeeder\Exception\ConfigException;

/**
 * S8 — the configuration is JSON in the data directory and nothing else. A
 * value containing PHP, a newline or a quote comes back as the same string, the
 * file is private to its owner, it is never inside the web root, and a failed
 * write leaves the previous file untouched.
 *
 * Closes 1.6 defects L11 and L12: `newsfeeds/includes/changeconfig.php:35-59`
 * rebuilt `config.php` with twenty `fwrite()` calls that interpolated `$_POST`
 * into `define()` statements — a config screen bug was code execution — and the
 * whole data set (`config.php`, `categories/`, `cache/`) lived inside the web
 * root.
 *
 * The control lives in `src/Config/ConfigWriter.php` (JSON only, 0600, write to
 * a temporary file then `rename()`), `src/Config/Schema.php` (unknown keys are
 * refused) and `src/Config/ConfigLoader::assertDataOutsideWebRoot()`.
 */
final class S08ConfigIsNotPhpTest extends SecurityTestCase
{
    /** @return iterable<string, array{string}> values a hostile config screen might submit */
    public static function hostileValues(): iterable
    {
        yield 'php open tag' => ['<?php system("id"); ?>'];
        yield 'short open tag' => ['<?= `id` ?>'];
        yield 'closing tag first' => ['?> <?php echo 1;'];
        yield 'newline and a define' => ["zFeeder\");\ndefine(\"ZF_ADMINPASS\", \"owned"];
        yield 'double quote' => ['a "quoted" agent'];
        yield 'single quote' => ["it's an agent"];
        yield 'backslash' => ['back\\slash\\'];
        yield 'null byte' => ["nul\0byte"];
        yield 'unicode' => ['zFeeder — 2.0 ✓'];
        yield 'json breakout' => ['", "admin_password_hash": "owned'];
        yield 'html' => ['<script>alert(1)</script>'];
    }

    private function writtenPath(): string
    {
        return $this->tempPath('config.json');
    }

    #[DataProvider('hostileValues')]
    public function testAHostileValueRoundTripsAsDataAndNeverBecomesCode(string $value): void
    {
        $path = $this->writtenPath();
        $config = Config::forTesting([], $this->tempDir())->with('fetch_user_agent', $value);

        (new ConfigWriter())->write($config, $path);

        $raw = self::readFile($path);
        self::assertStringStartsWith('{', ltrim($raw), 'the configuration file is not a JSON document');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($value, $decoded['fetch_user_agent'] ?? null, 'the value did not survive as data');

        // It is not enough that the bytes are there: the loader must read the
        // same string back, so the value stays a value all the way round.
        $reloaded = ConfigLoader::load($path, self::projectRoot());
        self::assertSame($value, $reloaded->string('fetch_user_agent'));
    }

    public function testTheFileIsJsonOnlyAndNotAPhpDocument(): void
    {
        $path = $this->writtenPath();
        (new ConfigWriter())->write(Config::forTesting([], $this->tempDir()), $path);

        $raw = self::readFile($path);

        self::assertSame('.json', substr($path, -5));
        self::assertStringStartsWith('{', $raw);
        self::assertStringEndsWith("}\n", $raw);
        self::assertStringNotContainsString('<?php', $raw);
        self::assertStringNotContainsString('define(', $raw);
        self::assertIsArray(json_decode($raw, true, 32, JSON_THROW_ON_ERROR));
    }

    public function testNothingInTheSourceTreeEverWritesPhp(): void
    {
        // The 1.6 shape — a writer that emits executable code — must not exist
        // anywhere, not only in ConfigWriter.
        $offenders = [];
        foreach (self::sourceFiles(self::projectRoot() . '/src') as $file) {
            $source = self::readFile($file);
            foreach (['file_put_contents', 'fwrite'] as $call) {
                if (preg_match('/' . $call . '\s*\([^;]{0,200}<\?php/', $source) === 1) {
                    $offenders[] = $file . ' writes a PHP open tag';
                }
            }
            if (preg_match('/\$[A-Za-z_]+\s*\.?=\s*[\'"]<\?php/', $source) === 1) {
                $offenders[] = $file . ' builds PHP source';
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    public function testTheWrittenFileIsPrivateToItsOwner(): void
    {
        $path = $this->writtenPath();
        (new ConfigWriter())->write(Config::forTesting([], $this->tempDir()), $path);

        clearstatcache(true, $path);
        $mode = fileperms($path);
        self::assertIsInt($mode);
        self::assertSame(0o600, $mode & 0o777, sprintf('the configuration is mode %o', $mode & 0o777));
    }

    public function testTheConfigurationIsNotWrittenInsideTheWebRoot(): void
    {
        $path = $this->writtenPath();
        (new ConfigWriter())->write(Config::forTesting([], $this->tempDir()), $path);

        $public = realpath(self::projectRoot() . '/public');
        $written = realpath($path);
        self::assertIsString($public);
        self::assertIsString($written);

        $temporary = realpath($this->tempDir());
        self::assertIsString($temporary);

        self::assertStringStartsNotWith($public . DIRECTORY_SEPARATOR, $written);
        self::assertStringStartsWith($temporary, $written);
    }

    public function testADataDirectoryInsideTheWebRootIsRefusedAtBoot(): void
    {
        // The other end of the same rule: even if someone points the data
        // directory at public/, the program will not start.
        $inside = self::projectRoot() . '/public/data-should-not-be-here';

        $this->expectException(ConfigException::class);
        ConfigLoader::load(null, self::projectRoot(), ['ZF_DATA_DIR' => $inside]);
    }

    public function testAnUnknownKeyIsRefusedRatherThanStored(): void
    {
        $this->expectException(ConfigException::class);
        Config::forTesting(['admin_pass' => 'plaintext'], $this->tempDir());
    }

    public function testAFailedWriteLeavesThePreviousFileIntact(): void
    {
        $directory = $this->tempPath('locked');
        mkdir($directory, 0o750, true);
        $path = $directory . '/config.json';

        $writer = new ConfigWriter();
        $writer->write(Config::forTesting([], $this->tempDir())->with('fetch_user_agent', 'first'), $path);
        $before = self::readFile($path);
        self::assertStringContainsString('"first"', $before);

        // Simulate the failure: the directory the temporary file would be
        // written into is no longer writable, so the rename never happens.
        self::assertTrue(chmod($directory, 0o500));

        try {
            $writer->write(Config::forTesting([], $this->tempDir())->with('fetch_user_agent', 'second'), $path);
            self::fail('the write succeeded although the directory was read-only');
        } catch (ConfigException $e) {
            self::assertStringContainsString('configuration', $e->getMessage());
        } finally {
            chmod($directory, 0o750);
        }

        self::assertSame($before, self::readFile($path), 'the previous configuration was damaged');
        self::assertIsArray(json_decode(self::readFile($path), true, 32, JSON_THROW_ON_ERROR));

        // And no half-written temporary file was left behind.
        $leftovers = glob($directory . '/config.json.tmp.*');
        self::assertSame([], $leftovers === false ? [] : $leftovers);
    }

    public function testValuesThatCameFromTheEnvironmentAreNeverPersisted(): void
    {
        // A secret set in the environment must not be copied into a file on
        // disk by the act of saving the config screen.
        $config = Config::fromResolved(
            ['env' => 'testing', 'data_dir' => $this->tempDir(), 'admin_password_hash' => 'from-env', 'powered_by' => true],
            ['env' => Config::SOURCE_DEFAULT, 'data_dir' => Config::SOURCE_FILE, 'admin_password_hash' => Config::SOURCE_ENV, 'powered_by' => Config::SOURCE_FILE],
            self::projectRoot(),
        );

        $path = $this->writtenPath();
        (new ConfigWriter())->write($config, $path);

        $raw = self::readFile($path);
        self::assertStringNotContainsString('from-env', $raw);
        self::assertStringNotContainsString('admin_password_hash', $raw);
        self::assertStringContainsString('powered_by', $raw);
    }
}
