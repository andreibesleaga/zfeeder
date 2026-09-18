<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Golden;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Config\Config;
use Zfeeder\Render\Renderer;
use Zfeeder\Render\RenderRequest;
use Zfeeder\Render\TemplateEngine;
use Zfeeder\Render\TemplateLocator;
use Zfeeder\Tests\Support\FixtureSubscriptions;

/**
 * The acceptance test of the whole classic template set.
 *
 * `tests/fixtures/goldens/` holds real output captured by `tools/record-goldens.sh`
 * from zFeeder 1.6 running on `php:5.6-apache` against fixed fixture feeds. Every
 * one of those files must come back byte for byte out of the 2026 renderer, so
 * the assertion is `assertSame` on the whole string - never a normalised or
 * whitespace-insensitive comparison. The captured files use CRLF, exactly as the
 * 2004 templates do; `.gitattributes` pins both with `-text`.
 *
 * Determinism:
 *  - the recorder pre-placed the feed bodies in 1.6's cache with a fixed far
 *    future mtime, so nothing was ever fetched and `{lastupdated}` is a constant
 *    (see FixtureSubscriptions::CACHE_FETCHED_AT);
 *  - `{scripturl}` is `base_url`, set to the recorder's `ZF_URL`;
 *  - `{moreurl}` / `{hideurl}` start from `$_SERVER['PHP_SELF']`, which was
 *    `/golden.php` in the harness page;
 *  - `legacyFidelity` is on, so item text is passed through verbatim rather
 *    than sanitised and truncated.
 */
final class ClassicTemplateGoldenTest extends TestCase
{
    /** What the recorder's harness page was called; 1.6 read it from PHP_SELF. */
    private const string SELF_URL = '/golden.php';

    /** The recorder's ZF_URL. */
    private const string BASE_URL = 'http://zf.test/newsfeeds/';

    /** @return iterable<string, array{string}> */
    public static function goldenProvider(): iterable
    {
        $files = glob(self::goldensDir() . '/*.html');
        self::assertIsArray($files);
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            yield $name => [$name];
        }
    }

    #[DataProvider('goldenProvider')]
    public function testGoldenIsReproducedByteForByte(string $goldenFile): void
    {
        $expected = file_get_contents(self::goldensDir() . '/' . $goldenFile);
        self::assertIsString($expected);

        [$template, $category, $variant] = self::parseName($goldenFile);

        $overrides = [
            'base_url' => self::BASE_URL,
            'template_set' => 'classic',
            'channel_location' => 'top',
            'channel_one_bar' => true,
            'powered_by' => true,
        ];
        $positions = null;
        $moreFeed = null;
        $showPoweredBy = true;

        switch (true) {
            case $variant === null:
                break;
            case $variant === 'nolink':
                // ?zf_link=off suppressed program_end() without touching config.
                $showPoweredBy = false;
                break;
            case $variant === 'pos2':
                $positions = 'p2';
                break;
            case $variant === 'more0':
                $moreFeed = 0;
                break;
            case str_starts_with($variant, 'chan-'):
                [, $location, $oneBar] = explode('-', $variant, 3);
                $overrides['channel_location'] = $location;
                $overrides['channel_one_bar'] = $oneBar === 'yes';
                break;
            default:
                self::fail('Unrecognised golden variant: ' . $variant);
        }

        $config = Config::forTesting($overrides);
        $fixtures = new FixtureSubscriptions(self::fixturesDir());
        $categoryModel = $fixtures->category($category);

        $renderer = new Renderer($config, new TemplateLocator($config), new TemplateEngine());
        $actual = $renderer->render(
            $categoryModel,
            $fixtures->renderedFeeds($categoryModel),
            new RenderRequest(
                category: $category,
                template: $template,
                positions: $positions,
                moreFeed: $moreFeed,
                showPoweredBy: $showPoweredBy,
                selfUrl: self::SELF_URL,
                legacyFidelity: true,
            ),
        );

        self::assertSame($expected, $actual, sprintf('Golden %s does not match byte for byte.', $goldenFile));
    }

    /**
     * Guards the hard-coded cache timestamp: every `{lastupdated}` in the
     * corpus must be the single instant the recorder stamped, so the constant
     * can never drift away from the captured files without a failure here.
     */
    public function testEveryGoldenUsesTheRecordedCacheTimestamp(): void
    {
        $expected = new \DateTimeImmutable(FixtureSubscriptions::CACHE_FETCHED_AT);
        $formatted = $expected->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T');

        $found = [];
        $files = glob(self::goldensDir() . '/*.html');
        self::assertIsArray($files);
        foreach ($files as $file) {
            $html = file_get_contents($file);
            self::assertIsString($html);
            if (preg_match_all('/[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT/', $html, $m) > 0) {
                foreach ($m[0] as $match) {
                    $found[$match] = true;
                }
            }
        }

        self::assertArrayHasKey($formatted, $found, 'No golden contains the recorded cache timestamp.');
    }

    public function testEveryClassicTemplateIsCovered(): void
    {
        $locator = new TemplateLocator(Config::forTesting(['template_set' => 'classic']));
        $available = $locator->available()['classic'];

        self::assertCount(14, $available, 'All 13 original templates plus css.html must be ported.');
        foreach (['ampheta', 'aqua', 'bluelogos', 'css', 'greenlogos', 'headlinebox', 'infojunkie',
            'mainframe', 'rij', 'sidebar', 'simpleblue', 'simplecss', 'simplegray', 'titlebox'] as $name) {
            self::assertContains($name, $available);
        }
        // The 2004 spelling still resolves even though the file is lower case.
        self::assertSame('rij', $locator->resolve('RiJ')['name']);
    }

    /** @return array{string, string, string|null} template, category, variant */
    private static function parseName(string $file): array
    {
        $parts = explode('.', basename($file, '.html'));
        self::assertGreaterThanOrEqual(2, count($parts), 'Unexpected golden file name: ' . $file);

        return [$parts[0], $parts[1], $parts[2] ?? null];
    }

    private static function fixturesDir(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    private static function goldensDir(): string
    {
        return self::fixturesDir() . '/goldens';
    }
}
