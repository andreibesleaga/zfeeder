<?php

declare(strict_types=1);

namespace Zfeeder\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zfeeder\Config\ConfigWriter;
use Zfeeder\Config\Schema;
use Zfeeder\Exception\ZfeederException;
use Zfeeder\Kernel;
use Zfeeder\Legacy\LegacyCacheLocator;
use Zfeeder\Render\TemplateEngine;
use Zfeeder\Render\TemplateLocator;
use Zfeeder\Storage\AtomicFile;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Opml\Reader;

/**
 * Upgrade a 2004 installation in place.
 *
 * Point it at the old `newsfeeds/` directory and it brings across the three
 * things that were the operator's, not the program's: the subscription lists,
 * the settings, and any templates they wrote themselves. Nothing in the old
 * tree is modified.
 *
 * Two rules shape the implementation.
 *
 * *The old `config.php` is never executed.* It is a PHP file from an untrusted
 * backup; running it to read its `define()` calls would be the same mistake
 * 1.6's admin panel made when it wrote executable configuration. It is read as
 * text and matched with a pattern.
 *
 * *The MD5 password cannot come across.* `md5($password)` is not reversible and
 * is not acceptable as a stored credential in 2026, so the operator is told,
 * loudly, to create a new one with `bin/zfeeder hash-password`.
 */
#[AsCommand(
    name: 'legacy-import',
    description: 'Import subscriptions, settings and templates from a zFeeder 1.x newsfeeds/ directory.',
)]
final class LegacyImportCommand extends Command
{
    use CommandInput;

    /**
     * 1.6 `define()` name => 2.0 configuration key.
     *
     * `ZF_CACHEDIR` and `ZF_OPMLDIR` are deliberately absent: 2.0 keeps both
     * under the data directory, outside the web root, so the 2004 values would
     * point at directories that must no longer be served. They are reported
     * instead of copied. `ZF_LOGINTYPE` and `ZF_USEOPML` are gone: 2.0 always
     * uses sessions and always uses OPML.
     */
    private const array MAPPING = [
        'ZF_URL' => 'base_url',
        'ZF_CATEGORY' => 'default_category',
        'ZF_TEMPLATE' => 'default_template',
        'ZF_DISPLAYERROR' => 'display_error',
        'ZF_CHANLOCATION' => 'channel_location',
        'ZF_CHANONEBAR' => 'channel_one_bar',
        'ZF_OWNERNAME' => 'owner_name',
        'ZF_OWNEREMAIL' => 'owner_email',
        'ZF_REFRESHKEY' => 'refresh_key',
        'ZF_ADMINNAME' => 'admin_user',
    ];

    /** Reported, never copied, because the 2.0 layout puts them elsewhere. */
    private const array NOTED_ONLY = ['ZF_CACHEDIR', 'ZF_OPMLDIR'];

    /** 1.6's WAP templates have no 2.0 equivalent, so they are not user templates. */
    private const string WAP_PREFIX = 'wap_';

    private readonly Reader $reader;

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
        $this->reader = new Reader();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'The old newsfeeds/ directory.')
            ->addOption('cache', null, InputOption::VALUE_NONE, 'Also import the 1.x cache directory, so the first page load is warm.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be imported without writing anything.')
            ->setHelp(<<<'HELP'
                Reads a zFeeder 1.x <comment>newsfeeds/</comment> directory and brings it into this
                installation:

                  - every <comment>categories/*.opml</comment> file, through the 2.0 OPML reader and writer,
                    so a malformed 2004 file is reported instead of being copied blindly;
                  - the <comment>config.php</comment> settings that still exist in 2.0, written to config.json;
                  - any template in <comment>templates/</comment> that is not one of the shipped ones, copied
                    into templates/classic/ after checking that it still parses;
                  - with <info>--cache</info>, the old cache directory, read with the 1.6 file naming.

                Worked example:

                  <info>bin/zfeeder legacy-import /var/www/old-site/newsfeeds --cache</info>

                  <comment>Imported 11 categories and 92 subscriptions.</comment>
                  <comment>ZF_TEMPLATE  templates/bluelogos  ->  default_template = bluelogos</comment>
                  <comment>ZF_CACHEDIR  cache                ->  not copied: 2.0 keeps the cache in the data directory</comment>
                  <comment>Copied 1 user template: mysite.html</comment>
                  <comment>[WARNING] The 1.x administrator password is an unsalted MD5 hash and cannot be migrated.</comment>

                Try it first with <info>--dry-run</info>, which writes nothing.

                The old directory is never modified, and the old config.php is read as
                text, never executed.

                Exit codes: 0 when the import finished, 1 when the directory is not a
                zFeeder installation or a file could not be read, 2 when the path is
                missing.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $this->flag($input, 'dry-run');

        $path = $this->argumentString($input, 'path');
        if ($path === '' || !is_dir($path)) {
            $io->error(sprintf('"%s" is not a directory. Point me at the old newsfeeds/ directory.', $path));

            return self::INVALID;
        }
        $root = rtrim(str_replace('\\', '/', $path), '/');

        $defines = $this->readLegacyDefines($root . '/config.php');
        $categoriesDir = $root . '/' . ($defines['ZF_OPMLDIR'] ?? 'categories');
        if (!is_dir($categoriesDir)) {
            $io->error(sprintf(
                'No subscriptions directory at %s. This does not look like a zFeeder 1.x installation.',
                $categoriesDir,
            ));

            return self::FAILURE;
        }

        if ($dryRun) {
            $io->note('Dry run: nothing will be written.');
        }

        try {
            $imported = $this->importCategories($io, $categoriesDir, $dryRun);
            $this->reportSettings($io, $defines, $dryRun);
            $this->importTemplates($io, $root . '/templates', $dryRun);
            if ($this->flag($input, 'cache')) {
                $this->importCache($io, $root . '/' . ($defines['ZF_CACHEDIR'] ?? 'cache'), $dryRun);
            }
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        // The one thing that cannot be migrated, said plainly and last, so it is
        // the message still on screen when the command finishes.
        $io->warning([
            'The zFeeder 1.x administrator password is an unsalted MD5 hash and cannot be migrated.',
            'Create a new one:  bin/zfeeder hash-password',
            'then put the printed ZF_ADMIN_PASSWORD_HASH line in your environment, or the config.json line in your config file.',
        ]);

        $io->success(sprintf(
            '%s %d categor%s and %d subscription%s from %s.',
            $dryRun ? 'Would import' : 'Imported',
            $imported['categories'],
            $imported['categories'] === 1 ? 'y' : 'ies',
            $imported['feeds'],
            $imported['feeds'] === 1 ? '' : 's',
            $root,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{categories: int, feeds: int}
     *
     * @throws ZfeederException
     */
    private function importCategories(SymfonyStyle $io, string $directory, bool $dryRun): array
    {
        $files = glob(rtrim($directory, '/') . '/*.opml');
        $files = $files === false ? [] : $files;
        sort($files, SORT_STRING);

        $store = $this->kernel->subscriptions();
        $rows = [];
        $categories = 0;
        $feeds = 0;

        foreach ($files as $file) {
            $name = strtolower(basename($file, '.opml'));
            if (!Category::isValidName($name)) {
                $rows[] = [basename($file), '-', 'skipped: "' . $name . '" is not a usable category name'];

                continue;
            }
            $opml = @file_get_contents($file);
            if ($opml === false) {
                $rows[] = [basename($file), '-', 'skipped: cannot read the file'];

                continue;
            }

            try {
                // The document goes through the 2.0 reader either way: a 2004
                // file that will not parse must be reported now, not on the
                // first page load. Outside a dry run, importOpml re-reads it and
                // writes it back through the 2.0 writer, so what lands on disk
                // is validated OPML 2.0 rather than a copied file.
                $count = $dryRun
                    ? \count($this->reader->readFeeds($opml))
                    : $store->importOpml($name, $opml, true);
            } catch (ZfeederException $e) {
                $rows[] = [basename($file), '-', 'skipped: ' . $e->getMessage()];

                continue;
            }

            ++$categories;
            $feeds += $count;
            $rows[] = [basename($file), (string) $count, $dryRun ? 'would import' : 'imported'];
        }

        $io->section('Subscriptions');
        $io->table(['File', 'Subscriptions', 'Result'], $rows);

        return ['categories' => $categories, 'feeds' => $feeds];
    }

    /**
     * @param array<string, string> $defines
     *
     * @throws ZfeederException
     */
    private function reportSettings(SymfonyStyle $io, array $defines, bool $dryRun): void
    {
        $io->section('Settings');
        if ($defines === []) {
            $io->writeln('  No readable config.php: nothing to map.');

            return;
        }

        $config = $this->kernel->config();
        $rows = [];
        $applied = 0;

        foreach (self::MAPPING as $legacy => $key) {
            if (!isset($defines[$legacy])) {
                continue;
            }
            $value = $this->translate($key, $defines[$legacy]);
            if ($value === null) {
                $rows[] = [$legacy, $defines[$legacy], 'skipped: no 2.0 equivalent for this value'];

                continue;
            }
            if ($config->isLockedByEnvironment($key)) {
                $rows[] = [$legacy, $defines[$legacy], $key . ' is pinned by the environment; not changed'];

                continue;
            }
            $config = $config->with($key, $value);
            ++$applied;
            $rows[] = [$legacy, $defines[$legacy], sprintf('%s = %s', $key, $this->present($value))];
        }

        foreach (self::NOTED_ONLY as $legacy) {
            if (isset($defines[$legacy])) {
                $rows[] = [$legacy, $defines[$legacy], 'not copied: 2.0 keeps this under the data directory, outside the web root'];
            }
        }
        if (isset($defines['ZF_ADMINPASS'])) {
            $rows[] = ['ZF_ADMINPASS', '(md5 hash, not shown)', 'CANNOT be migrated: see the warning below'];
        }

        $io->table(['1.x define', '1.x value', '2.0 result'], $rows);

        if ($applied === 0) {
            return;
        }

        $target = $config->configPath() ?? rtrim($config->dataDir(), '/\\') . '/config.json';
        if ($dryRun) {
            $io->writeln(sprintf('  Would write %d setting%s to %s.', $applied, $applied === 1 ? '' : 's', $target));

            return;
        }

        (new ConfigWriter())->write($config, $target);
        $io->writeln(sprintf('  Wrote %d setting%s to %s.', $applied, $applied === 1 ? '' : 's', $target));
    }

    /**
     * A 1.x value in the 2.0 spelling, or null when 2.0 has no equivalent.
     *
     * The three interesting conversions: the template loses its `templates/`
     * prefix because 2.0 addresses templates by name within a set; `yes`/`no`
     * become real booleans; and a channel location 1.6 did not recognise meant
     * "do not draw the bar", which 2.0 spells `none`.
     */
    private function translate(string $key, string $raw): string|bool|null
    {
        $value = trim($raw);

        if ($key === 'default_template') {
            $value = preg_replace('#^templates/#i', '', $value) ?? $value;
            $value = basename($value);

            return $value === '' ? null : strtolower($value);
        }

        if ($key === 'channel_location') {
            $value = strtolower($value);

            return \in_array($value, ['top', 'bottom'], true) ? $value : 'none';
        }

        $type = Schema::all()[$key]['type'] ?? Schema::TYPE_STRING;
        if ($type === Schema::TYPE_BOOL) {
            return \in_array(strtolower($value), ['yes', 'true', '1', 'on'], true);
        }

        if ($key === 'default_category') {
            $value = strtolower($value);

            return Category::isValidName($value) ? $value : null;
        }

        return $value;
    }

    private function present(string|int|bool $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === '' ? '(empty)' : (string) $value;
    }

    /**
     * Copies templates the operator wrote. A template that no longer parses is
     * reported rather than copied: a broken template in templates/classic/ is a
     * fatal error the next time someone selects it.
     */
    private function importTemplates(SymfonyStyle $io, string $directory, bool $dryRun): void
    {
        $io->section('Templates');
        if (!is_dir($directory)) {
            $io->writeln('  No templates directory in the old installation.');

            return;
        }

        $shipped = $this->shippedTemplateNames();
        $files = glob(rtrim($directory, '/') . '/*.html');
        $files = $files === false ? [] : $files;
        sort($files, SORT_STRING);

        $target = rtrim($this->kernel->config()->templatesDir(), '/') . '/classic';
        $engine = new TemplateEngine();
        $rows = [];
        $copied = 0;

        foreach ($files as $file) {
            $name = strtolower(basename($file, '.html'));
            if (str_starts_with($name, self::WAP_PREFIX)) {
                continue;
            }
            if (\in_array($name, $shipped, true)) {
                continue;
            }

            $source = @file_get_contents($file);
            if ($source === false) {
                $rows[] = [basename($file), 'skipped: cannot read the file'];

                continue;
            }
            try {
                $engine->parse($source, $name, 'classic');
            } catch (ZfeederException $e) {
                $rows[] = [basename($file), 'skipped: ' . $e->getMessage()];

                continue;
            }

            if ($dryRun) {
                $rows[] = [basename($file), 'would copy to templates/classic/' . $name . '.html'];
                ++$copied;

                continue;
            }

            AtomicFile::ensureDirectory($target);
            AtomicFile::write($target . '/' . $name . '.html', $source);
            ++$copied;
            $rows[] = [basename($file), 'copied to templates/classic/' . $name . '.html'];
        }

        if ($rows === []) {
            $io->writeln('  No user templates: every template in the old installation is one of the shipped ones.');

            return;
        }

        $io->table(['Template', 'Result'], $rows);
        $io->writeln(sprintf('  %d user template%s.', $copied, $copied === 1 ? '' : 's'));
    }

    /**
     * The templates 2.0 ships, lower-cased. Anything else in the old tree is
     * the operator's own work and is worth carrying across.
     *
     * @return list<string>
     */
    private function shippedTemplateNames(): array
    {
        $names = [];
        foreach ((new TemplateLocator($this->kernel->config()))->available() as $set) {
            foreach ($set as $name) {
                $names[] = strtolower($name);
            }
        }

        return array_values(array_unique($names));
    }

    /** @throws ZfeederException */
    private function importCache(SymfonyStyle $io, string $directory, bool $dryRun): void
    {
        $io->section('Cache');
        if (!is_dir($directory)) {
            $io->writeln('  No cache directory in the old installation.');

            return;
        }

        $locator = new LegacyCacheLocator();
        $cache = $this->kernel->cache();
        $store = $this->kernel->subscriptions();
        $found = 0;
        $seen = [];

        foreach ($store->categories() as $name) {
            foreach ($store->category($name)->feeds as $feed) {
                if (isset($seen[$feed->xmlUrl])) {
                    continue;
                }
                $seen[$feed->xmlUrl] = true;

                $body = $locator->read($directory, $feed->xmlUrl);
                if ($body === null) {
                    continue;
                }
                ++$found;
                if ($dryRun) {
                    continue;
                }
                // 1.6 had no metadata sidecar; the file's mtime was the
                // freshness record, so it becomes fetchedAt.
                $cache->put(new CacheEntry(
                    $feed->xmlUrl,
                    $body,
                    $locator->fetchedAt($directory, $feed->xmlUrl) ?? $this->kernel->clock(),
                    null,
                    null,
                    200,
                ));
            }
        }

        $io->writeln(sprintf(
            '  %s %d cached feed%s from %s.',
            $dryRun ? 'Would import' : 'Imported',
            $found,
            $found === 1 ? '' : 's',
            $directory,
        ));
    }

    /**
     * The `define()` calls of a 1.x config.php, read as text.
     *
     * The file is never included: it comes from a backup of someone else's
     * server and including it would execute whatever is in it.
     *
     * @return array<string, string>
     */
    private function readLegacyDefines(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $source = @file_get_contents($path);
        if ($source === false) {
            return [];
        }

        $found = [];
        $pattern = '/define\s*\(\s*([\'"])(ZF_[A-Z_]+)\1\s*,\s*([\'"])(.*?)\3\s*\)/s';
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($matches as $match) {
            $found[$match[2]] = $match[4];
        }

        return $found;
    }
}
