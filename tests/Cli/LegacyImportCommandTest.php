<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Zfeeder\Cli\Command\LegacyImportCommand;
use Zfeeder\Config\Config;
use Zfeeder\Config\Schema;
use Zfeeder\Kernel;
use Zfeeder\Storage\AtomicFile;
use Zfeeder\Subscription\Opml\Reader;

/**
 * The acceptance test for the upgrade path: the real 2004 `newsfeeds/`
 * directory that ships in `legacy/`, imported into a scratch installation.
 */
#[CoversClass(LegacyImportCommand::class)]
final class LegacyImportCommandTest extends CliTestCase
{
    /** The eleven category files of the 1.6 release. */
    private const array CATEGORIES_2004 = [
        'empty', 'general', 'lockergnome', 'mobile', 'news',
        'osdn', 'php', 'rss', 'software', 'technology', 'zfeeder',
    ];

    public function testImportsEveryCategoryOfTheReal2004Installation(): void
    {
        $kernel = $this->kernel();

        $tester = $this->execute($kernel, 'legacy-import', ['path' => $this->legacyPath()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(self::CATEGORIES_2004, $kernel->subscriptions()->categories());

        $display = $this->flatten($tester->getDisplay());
        self::assertStringContainsString('Imported 11 categories and', $display);

        // Spot check one file against the 2004 original, through the reader.
        $original = (string) file_get_contents($this->legacyPath() . '/categories/news.opml');
        $expected = array_map(static fn ($feed): string => $feed->xmlUrl, (new Reader())->readFeeds($original));
        $imported = array_map(static fn ($feed): string => $feed->xmlUrl, $kernel->subscriptions()->category('news')->feeds);
        self::assertSame($expected, $imported);
        self::assertCount(10, $imported);
    }

    public function testEverySubscriptionIsRewrittenAsValidOpml20(): void
    {
        $kernel = $this->kernel();
        $this->execute($kernel, 'legacy-import', ['path' => $this->legacyPath()]);

        $total = 0;
        foreach ($kernel->subscriptions()->categories() as $name) {
            $document = $kernel->subscriptions()->exportOpml($name);
            self::assertStringContainsString('<opml version="2.0">', $document);
            $total += \count((new Reader())->readFeeds($document));
        }

        self::assertGreaterThan(50, $total, 'the 2004 corpus holds far more than fifty subscriptions');
    }

    public function testReportsThatThePasswordCannotBeMigrated(): void
    {
        $display = $this->flatten(
            $this->execute($this->kernel(), 'legacy-import', ['path' => $this->legacyPath()])->getDisplay(),
        );

        self::assertStringContainsString('unsalted MD5 hash and cannot be migrated', $display);
        self::assertStringContainsString('bin/zfeeder hash-password', $display);
        self::assertStringContainsString('ZF_ADMINPASS', $display);
        // The hash itself is never echoed back.
        self::assertStringContainsString('(md5 hash, not shown)', $display);
        self::assertStringNotContainsString('d41d8cd98f00b204e9800998ecf8427e', $display);
    }

    public function testMapsTheConfigPhpDefinesToTheNewKeys(): void
    {
        $kernel = $this->kernel();

        $display = $this->flatten(
            $this->execute($kernel, 'legacy-import', ['path' => $this->legacyPath()])->getDisplay(),
        );

        self::assertStringContainsString('ZF_TEMPLATE templates/bluelogos default_template = bluelogos', $display);
        self::assertStringContainsString('ZF_CATEGORY zfeeder default_category = zfeeder', $display);
        self::assertStringContainsString('ZF_CHANLOCATION top channel_location = top', $display);
        self::assertStringContainsString('ZF_CHANONEBAR yes channel_one_bar = true', $display);
        self::assertStringContainsString('ZF_DISPLAYERROR no display_error = false', $display);
        self::assertStringContainsString('ZF_CACHEDIR cache not copied', $display);
        self::assertStringContainsString('ZF_OPMLDIR categories not copied', $display);

        $written = $this->tempPath('data/config.json');
        self::assertFileExists($written);
        /** @var array<string, mixed> $saved */
        $saved = json_decode((string) file_get_contents($written), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('bluelogos', $saved['default_template']);
        self::assertSame('zfeeder', $saved['default_category']);
        self::assertTrue($saved['channel_one_bar']);
        self::assertFalse($saved['display_error']);
        self::assertArrayNotHasKey('cache_dir', array_filter($saved, static fn (mixed $v): bool => $v !== ''));
    }

    public function testTheShippedTemplatesAreRecognisedAndNotCopied(): void
    {
        $display = $this->flatten(
            $this->execute($this->kernel(), 'legacy-import', ['path' => $this->legacyPath()])->getDisplay(),
        );

        self::assertStringContainsString('No user templates', $display);
    }

    public function testDryRunWritesNothingAtAll(): void
    {
        $kernel = $this->kernel();

        $tester = $this->execute($kernel, 'legacy-import', ['path' => $this->legacyPath(), '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run: nothing will be written', $this->flatten($tester->getDisplay()));
        self::assertStringContainsString('Would import 11 categories', $this->flatten($tester->getDisplay()));
        self::assertSame([], $kernel->subscriptions()->categories());
        self::assertFileDoesNotExist($this->tempPath('data/config.json'));
    }

    public function testCopiesAUserTemplateAndRefusesABrokenOne(): void
    {
        // A project root of its own, so the test cannot write into the repository.
        $projectRoot = $this->tempPath('install');
        AtomicFile::ensureDirectory($projectRoot . '/templates/classic');
        AtomicFile::ensureDirectory($projectRoot . '/templates/modern');
        $shipped = (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/classic/bluelogos.html');
        AtomicFile::write($projectRoot . '/templates/classic/bluelogos.html', $shipped);

        $legacy = $this->tempPath('old-newsfeeds');
        AtomicFile::ensureDirectory($legacy . '/categories');
        AtomicFile::ensureDirectory($legacy . '/templates');
        AtomicFile::write($legacy . '/categories/news.opml', $this->minimalOpml());
        AtomicFile::write($legacy . '/templates/bluelogos.html', $shipped);
        AtomicFile::write($legacy . '/templates/mysite.html', $shipped);
        AtomicFile::write($legacy . '/templates/broken.html', '<html>no section markers here</html>');
        AtomicFile::write($legacy . '/templates/wap_categ.html', '<wml/>');

        $kernel = $this->kernelWithProjectRoot($projectRoot);
        $tester = $this->execute($kernel, 'legacy-import', ['path' => $legacy]);
        $display = $this->flatten($tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($projectRoot . '/templates/classic/mysite.html');
        self::assertStringContainsString('mysite.html copied to templates/classic/mysite.html', $display);
        self::assertStringContainsString('broken.html skipped:', $display);
        self::assertFileDoesNotExist($projectRoot . '/templates/classic/broken.html');
        // A shipped template and a WAP template are not the operator's work.
        self::assertStringNotContainsString('bluelogos.html copied', $display);
        self::assertFileDoesNotExist($projectRoot . '/templates/classic/wap_categ.html');
    }

    public function testImportsThe16CacheWhenAsked(): void
    {
        $legacy = $this->tempPath('old-newsfeeds');
        AtomicFile::ensureDirectory($legacy . '/categories');
        AtomicFile::ensureDirectory($legacy . '/cache');
        AtomicFile::write($legacy . '/categories/news.opml', $this->minimalOpml());
        // The 1.6 file name: every non-alphanumeric character becomes "_".
        $legacyName = preg_replace('/[^a-zA-Z0-9]/', '_', 'https://one.example/feed.xml') . '.xml';
        AtomicFile::write($legacy . '/cache/' . $legacyName, self::RSS_BODY);

        $kernel = $this->kernel();
        $tester = $this->execute($kernel, 'legacy-import', ['path' => $legacy, '--cache' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Imported 1 cached feed', $this->flatten($tester->getDisplay()));
        $entry = $kernel->cache()->get('https://one.example/feed.xml');
        self::assertNotNull($entry);
        self::assertSame(self::RSS_BODY, $entry->body);
    }

    public function testADirectoryThatIsNotAnInstallationIsReported(): void
    {
        $empty = $this->tempPath('not-zfeeder');
        AtomicFile::ensureDirectory($empty);

        $tester = $this->execute($this->kernel(), 'legacy-import', ['path' => $empty]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not look like a zFeeder 1.x installation', $this->flatten($tester->getDisplay()));
    }

    public function testAMissingPathIsAUsageError(): void
    {
        $tester = $this->execute($this->kernel(), 'legacy-import', ['path' => $this->tempPath('nowhere')]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    private function legacyPath(): string
    {
        $path = \dirname(__DIR__, 2) . '/legacy/zfeeder-1.6/newsfeeds';
        self::assertDirectoryExists($path, 'the 1.6 corpus must ship with the repository');

        return $path;
    }

    /** A kernel whose templates/ lives in a scratch directory instead of the repository. */
    private function kernelWithProjectRoot(string $projectRoot): Kernel
    {
        $values = Schema::defaults();
        $values['env'] = 'testing';
        $values['data_dir'] = $this->tempPath('data');
        $values['allow_private_hosts'] = true;
        $sources = array_fill_keys(array_keys($values), Config::SOURCE_DEFAULT);

        $config = Config::fromResolved($values, $sources, $projectRoot, $this->tempPath('data/config.json'));
        $kernel = new Kernel($config, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW));

        return $kernel;
    }

    private function minimalOpml(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <opml version="1.0">
              <head><title>news</title></head>
              <body>
                <outline type="rss" position="1" title="One" xmlUrl="https://one.example/feed.xml" refreshTime="60" showedItems="3" isSubscribed="yes" />
              </body>
            </opml>
            XML;
    }
}
