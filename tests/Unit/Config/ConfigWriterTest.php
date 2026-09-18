<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zfeeder\Config\Config;
use Zfeeder\Config\ConfigLoader;
use Zfeeder\Config\ConfigWriter;
use Zfeeder\Config\Schema;
use Zfeeder\Exception\ConfigException;
use Zfeeder\Tests\Support\StorageTempDirectory;

#[CoversClass(ConfigWriter::class)]
final class ConfigWriterTest extends TestCase
{
    use StorageTempDirectory;

    private ConfigWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new ConfigWriter();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
        parent::tearDown();
    }

    public function testItWritesReadableJsonAndNeverPhp(): void
    {
        $path = $this->tempPath('config.json');
        $config = ConfigLoader::load($path, $this->tempDir(), [])->with('default_category', 'technology');

        $this->writer->write($config, $path);
        $raw = file_get_contents($path);

        self::assertIsString($raw);
        self::assertStringNotContainsString('<?php', $raw);
        self::assertStringContainsString("\n    \"default_category\": \"technology\"", $raw, 'Pretty printed');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('technology', $decoded['default_category']);
    }

    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        $path = $this->tempPath('config.json');
        $config = ConfigLoader::load($path, $this->tempDir(), [])
            ->with('base_url', 'https://example.org/feeds/')
            ->with('owner_name', 'Ștefan');

        $this->writer->write($config, $path);
        $raw = file_get_contents($path);

        self::assertIsString($raw);
        self::assertStringContainsString('"https://example.org/feeds/"', $raw);
        self::assertStringContainsString('Ștefan', $raw);
    }

    public function testEveryPersistableKeyIsWritten(): void
    {
        $path = $this->tempPath('config.json');
        $config = ConfigLoader::load($path, $this->tempDir(), []);

        $this->writer->write($config, $path);
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);

        $expected = Schema::keys();
        sort($expected);
        self::assertSame($expected, array_keys($decoded), 'persistableValues() is sorted for a stable diff.');
    }

    public function testValuesComingFromTheEnvironmentAreNotPersisted(): void
    {
        $path = $this->tempPath('config.json');
        $config = ConfigLoader::load($path, $this->tempDir(), [
            'ZF_STORAGE' => 'sqlite',
            'ZF_ADMIN_PASSWORD_HASH' => 'secret-hash',
        ]);

        $this->writer->write($config, $path);
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('storage', $decoded);
        self::assertArrayNotHasKey('admin_password_hash', $decoded);
        self::assertStringNotContainsString('secret-hash', $raw);
        self::assertArrayHasKey('default_category', $decoded);
    }

    public function testTheFileRoundTripsThroughTheLoader(): void
    {
        $path = $this->tempPath('config.json');
        $config = ConfigLoader::load($path, $this->tempDir(), [])
            ->with('default_category', 'technology')
            ->with('max_description_chars', 250)
            ->with('powered_by', false)
            ->with('template_set', 'modern');

        $this->writer->write($config, $path);
        $reloaded = ConfigLoader::load($path, $this->tempDir(), []);

        self::assertSame('technology', $reloaded->string('default_category'));
        self::assertSame(250, $reloaded->int('max_description_chars'));
        self::assertFalse($reloaded->bool('powered_by'));
        self::assertSame('modern', $reloaded->string('template_set'));
        self::assertSame($config->persistableValues(), $reloaded->persistableValues());
    }

    public function testSavingTwiceLeavesNoTemporaryOrPartialFiles(): void
    {
        $path = $this->tempPath('nested/config.json');
        $config = ConfigLoader::load($path, $this->tempDir(), []);

        $this->writer->write($config, $path);
        $this->writer->write($config->with('default_category', 'news'), $path);

        $leftovers = glob($this->tempPath('nested/*.tmp.*'));
        self::assertSame([], $leftovers);

        $raw = file_get_contents($path);
        self::assertIsString($raw);
        self::assertNotFalse(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
    }

    public function testTheConfigFileIsPrivateToTheOwner(): void
    {
        $path = $this->tempPath('config.json');

        $this->writer->write(ConfigLoader::load($path, $this->tempDir(), []), $path);

        $mode = fileperms($path);
        self::assertNotFalse($mode);
        self::assertSame(0o600, $mode & 0o777, 'The config file may hold the admin password hash.');
    }

    public function testAMissingDirectoryIsCreatedAndNotWorldReadable(): void
    {
        $path = $this->tempPath('deep/nested/config.json');

        $this->writer->write(ConfigLoader::load($path, $this->tempDir(), []), $path);

        self::assertDirectoryExists($this->tempPath('deep/nested'));
        $mode = fileperms($this->tempPath('deep/nested'));
        self::assertNotFalse($mode);
        self::assertSame(0, $mode & 0o007, 'The data directory must not be world readable.');
    }

    public function testAnUnwritableDirectoryIsReportedAsAConfigError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Running as root: permissions do not apply.');
        }
        $directory = $this->tempPath('readonly');
        mkdir($directory, 0o500);
        $path = $directory . '/config.json';

        try {
            $this->expectException(ConfigException::class);
            $this->writer->write(Config::forTesting([], $this->tempDir()), $path);
        } finally {
            chmod($directory, 0o750);
        }
    }
}
