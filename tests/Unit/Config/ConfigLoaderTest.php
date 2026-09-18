<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Config\Config;
use Zfeeder\Config\ConfigLoader;
use Zfeeder\Config\Schema;
use Zfeeder\Exception\ConfigException;
use Zfeeder\Tests\Support\StorageTempDirectory;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    use StorageTempDirectory;

    protected function tearDown(): void
    {
        $this->removeTempDir();
        parent::tearDown();
    }

    public function testWithoutAFileOrEnvironmentEverythingIsTheSchemaDefault(): void
    {
        $config = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), []);

        foreach (Schema::defaults() as $key => $default) {
            self::assertSame($default, $config->get($key), 'Key ' . $key);
            self::assertSame(Config::SOURCE_DEFAULT, $config->sourceOf($key), 'Key ' . $key);
        }
    }

    public function testAMissingConfigFileIsNotAnError(): void
    {
        $config = ConfigLoader::load($this->tempPath('nowhere/config.json'), $this->tempDir(), []);

        self::assertSame('flat', $config->string('storage'));
    }

    public function testTheFileOverridesTheDefaults(): void
    {
        $path = $this->writeConfig(['default_category' => 'technology', 'max_description_chars' => 120]);

        $config = ConfigLoader::load($path, $this->tempDir(), []);

        self::assertSame('technology', $config->string('default_category'));
        self::assertSame(120, $config->int('max_description_chars'));
        self::assertSame(Config::SOURCE_FILE, $config->sourceOf('default_category'));
        self::assertSame(Config::SOURCE_DEFAULT, $config->sourceOf('powered_by'));
    }

    public function testTheEnvironmentOverridesTheFile(): void
    {
        $path = $this->writeConfig(['default_category' => 'technology', 'powered_by' => false]);

        $config = ConfigLoader::load($path, $this->tempDir(), [
            'ZF_DEFAULT_CATEGORY' => 'news',
            'ZF_POWERED_BY' => 'yes',
        ]);

        self::assertSame('news', $config->string('default_category'));
        self::assertTrue($config->bool('powered_by'));
        self::assertSame(Config::SOURCE_ENV, $config->sourceOf('default_category'));
        self::assertTrue($config->isLockedByEnvironment('powered_by'));
        self::assertFalse($config->isLockedByEnvironment('default_template'));
    }

    public function testTheRealEnvironmentIsReadWhenNoneIsSupplied(): void
    {
        putenv('ZF_DEFAULT_CATEGORY=from-getenv');

        try {
            $config = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir());

            self::assertSame('from-getenv', $config->string('default_category'));
            self::assertSame(Config::SOURCE_ENV, $config->sourceOf('default_category'));
        } finally {
            putenv('ZF_DEFAULT_CATEGORY');
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function booleanWordProvider(): array
    {
        return [
            'digit one' => ['1', true],
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'yes' => ['yes', true],
            'Yes' => ['Yes', true],
            'on' => ['on', true],
            'ON' => ['ON', true],
            'digit zero' => ['0', false],
            'false' => ['false', false],
            'False' => ['False', false],
            'no' => ['no', false],
            'off' => ['off', false],
            'OFF' => ['OFF', false],
            'padded' => ['  yes  ', true],
        ];
    }

    #[DataProvider('booleanWordProvider')]
    public function testBooleansAcceptTheUsualSpellings(string $raw, bool $expected): void
    {
        $config = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_POWERED_BY' => $raw]);

        self::assertSame($expected, $config->bool('powered_by'));
    }

    public function testBooleansAlsoAcceptRealJsonBooleansAndZeroOne(): void
    {
        $path = $this->writeConfig(['powered_by' => false, 'channel_one_bar' => 1]);

        $config = ConfigLoader::load($path, $this->tempDir(), []);

        self::assertFalse($config->bool('powered_by'));
        self::assertTrue($config->bool('channel_one_bar'));
    }

    public function testIntegersAcceptNumericStringsFromTheEnvironment(): void
    {
        $config = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_FETCH_TIMEOUT' => ' 42 ']);

        self::assertSame(42, $config->int('fetch_timeout'));
    }

    public function testEnumsAcceptOnlyTheirOwnValues(): void
    {
        $config = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_STORAGE' => 'sqlite']);

        self::assertSame('sqlite', $config->string('storage'));
    }

    public function testAnEmptyEnvironmentValueIsIgnoredForTypedOptionsButKeptForStrings(): void
    {
        $path = $this->writeConfig(['fetch_timeout' => 30, 'base_url' => 'https://example.org/']);

        $config = ConfigLoader::load($path, $this->tempDir(), ['ZF_FETCH_TIMEOUT' => '', 'ZF_URL' => '']);

        self::assertSame(30, $config->int('fetch_timeout'));
        self::assertSame(Config::SOURCE_FILE, $config->sourceOf('fetch_timeout'));
        self::assertSame('', $config->string('base_url'));
        self::assertSame(Config::SOURCE_ENV, $config->sourceOf('base_url'));
    }

    public function testAnUnknownKeyInTheFileIsNamed(): void
    {
        $path = $this->writeConfig(['default_category' => 'news', 'zf_secret_backdoor' => 1]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('zf_secret_backdoor');
        ConfigLoader::load($path, $this->tempDir(), []);
    }

    public function testMalformedJsonIsRejected(): void
    {
        $path = $this->tempPath('config.json');
        file_put_contents($path, '{"default_category": "news",}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Malformed JSON');
        ConfigLoader::load($path, $this->tempDir(), []);
    }

    public function testAJsonArrayIsNotAConfiguration(): void
    {
        $path = $this->tempPath('config.json');
        file_put_contents($path, '"just a string"');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('JSON object');
        ConfigLoader::load($path, $this->tempDir(), []);
    }

    public function testABadBooleanNamesTheKeyAndTheType(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"powered_by" expects bool');
        ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_POWERED_BY' => 'maybe']);
    }

    public function testABadIntegerNamesTheKeyAndTheType(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"fetch_timeout" expects int');
        ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_FETCH_TIMEOUT' => 'ten']);
    }

    public function testAStringKeyGivenAStructureNamesTheKeyAndTheType(): void
    {
        $path = $this->writeConfig(['default_category' => ['news']]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"default_category" expects string');
        ConfigLoader::load($path, $this->tempDir(), []);
    }

    public function testAnIntegerKeyGivenAFloatIsRejected(): void
    {
        $path = $this->writeConfig(['fetch_timeout' => 10.5]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('expects int');
        ConfigLoader::load($path, $this->tempDir(), []);
    }

    public function testAnOutOfRangeIntegerIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"fetch_timeout" must be between 1 and 120');
        ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_FETCH_TIMEOUT' => '1000']);
    }

    public function testTheLowestAndHighestAllowedIntegersAreAccepted(): void
    {
        $low = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_FETCH_TIMEOUT' => '1']);
        $high = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_FETCH_TIMEOUT' => '120']);

        self::assertSame(1, $low->int('fetch_timeout'));
        self::assertSame(120, $high->int('fetch_timeout'));
    }

    public function testAnUnknownEnumValueListsTheAllowedOnes(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"storage" must be one of: flat, sqlite');
        ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_STORAGE' => 'mysql']);
    }

    public function testADataDirectoryInsideTheWebRootIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('inside the web root');
        ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), [
            'ZF_DATA_DIR' => $this->tempPath('public/data'),
        ]);
    }

    public function testARelativeDataDirectoryInsideTheWebRootIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('inside the web root');
        ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), ['ZF_DATA_DIR' => 'public']);
    }

    public function testATraversalBackIntoTheWebRootIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('inside the web root');
        ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), [
            'ZF_DATA_DIR' => $this->tempPath('data/../public/uploads'),
        ]);
    }

    public function testACacheDirectoryInsideTheWebRootIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"cache_dir" resolves');
        ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), [
            'ZF_CACHE_DIR' => $this->tempPath('public/cache'),
        ]);
    }

    public function testADataDirectoryBesideTheWebRootIsAccepted(): void
    {
        $config = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), [
            'ZF_DATA_DIR' => $this->tempPath('data'),
        ]);

        self::assertSame($this->tempPath('data'), $config->dataDir());
        self::assertSame($this->tempPath('data/categories'), $config->categoriesDir());
        self::assertSame($this->tempPath('data/cache'), $config->cacheDir());
        self::assertSame($this->tempPath('data/zfeeder.sqlite'), $config->sqlitePath());
    }

    public function testADirectoryNamedPublicSomethingIsNotConfusedWithTheWebRoot(): void
    {
        $config = ConfigLoader::load($this->tempPath('config.json'), $this->tempDir(), [
            'ZF_DATA_DIR' => $this->tempPath('public-data'),
        ]);

        self::assertSame($this->tempPath('public-data'), $config->dataDir());
    }

    public function testTheConfigPathIsRemembered(): void
    {
        $path = $this->writeConfig(['default_category' => 'news']);

        self::assertSame($path, ConfigLoader::load($path, $this->tempDir(), [])->configPath());
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(array $values): string
    {
        $path = $this->tempPath('config.json');
        file_put_contents($path, json_encode($values, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }
}
