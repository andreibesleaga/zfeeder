<?php

declare(strict_types=1);

namespace Zfeeder\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zfeeder\Config\Config;
use Zfeeder\Config\Schema;
use Zfeeder\Exception\ZfeederException;
use Zfeeder\Kernel;
use Zfeeder\Render\TemplateLocator;
use Zfeeder\Storage\StoreFactory;
use Zfeeder\Version;

/**
 * The command people paste into bug reports.
 *
 * It answers two questions in one screen: what is this installation actually
 * configured to do, and does that configuration work? The first half prints
 * every option with its effective value and where the value came from, because
 * "it is set to X" and "X is what the file says" are different statements once
 * the environment can override the file. The second half runs the checks that
 * turn into support tickets: an unwritable data directory, a data directory
 * inside the web root, a missing template, a panel with no password.
 *
 * Because the output is meant to be pasted in public, the two options in
 * {@see Schema::SECRET_KEYS} are never printed. They are reported as `set` or
 * `not set`, which is the only fact a reader needs and the only one that is
 * safe to share.
 */
#[AsCommand(
    name: 'check-config',
    description: 'Print the effective configuration and check that it works.',
)]
final class CheckConfigCommand extends Command
{
    use CommandInput;

    /** What a redacted value is printed as; never the value itself. */
    private const string REDACTED_SET = 'set';
    private const string REDACTED_UNSET = 'not set';

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON.')
            ->setHelp(<<<'HELP'
                Prints every configuration option with its effective value and its source
                (<comment>default</comment>, <comment>file</comment> or <comment>env</comment>), then runs the checks that catch the
                mistakes people actually make.

                Worked example:

                  <info>bin/zfeeder check-config</info>

                  <comment>Option              Environment          Value            Source</comment>
                  <comment>storage             ZF_STORAGE           sqlite           env</comment>
                  <comment>admin_password_hash ZF_ADMIN_PASSWORD_HASH set            file</comment>
                  <comment>...</comment>
                  <comment>[OK]   Data directory exists and is writable</comment>
                  <comment>[FAIL] Default template classic/bluelogo is missing</comment>

                The password hash and the refresh key are never printed: they are shown as
                <comment>set</comment> or <comment>not set</comment>. The output is therefore safe to paste into a bug
                report, which is what it is for.

                In a deployment pipeline:

                  <info>bin/zfeeder check-config --json > config-report.json || exit 1</info>

                Exit codes: 0 when every check passed, 1 when at least one failed.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->kernel->config();

        $options = $this->describeOptions($config);
        $checks = $this->runChecks($config);
        $failed = 0;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                ++$failed;
            }
        }

        if ($this->flag($input, 'json')) {
            $output->writeln($this->json([
                'version' => Version::NUMBER,
                'php' => \PHP_VERSION,
                'configFile' => $config->configPath(),
                'projectRoot' => $config->projectRoot(),
                'options' => $options,
                'checks' => $checks,
                'failed' => $failed,
                'ok' => $failed === 0,
            ]));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $io->title(Version::full() . ' configuration');
        $io->definitionList(
            ['PHP' => \PHP_VERSION],
            ['Project root' => $config->projectRoot()],
            ['Config file' => $this->describeConfigFile($config)],
            ['Data directory' => $config->dataDir()],
        );

        $rows = [];
        foreach ($options as $option) {
            $rows[] = [$option['key'], $option['env'], $option['value'], $option['source']];
        }
        $io->table(['Option', 'Environment variable', 'Value', 'Source'], $rows);

        $io->section('Checks');
        foreach ($checks as $check) {
            $io->writeln(sprintf(
                '  %s %s%s',
                $check['ok'] ? '<info>[OK]  </info>' : '<error>[FAIL]</error>',
                $check['name'],
                $check['detail'] === '' ? '' : ' — ' . $check['detail'],
            ));
        }
        $io->newLine();

        if ($failed > 0) {
            $io->error(sprintf('%d of %d checks failed.', $failed, \count($checks)));

            return self::FAILURE;
        }

        $io->success(sprintf('All %d checks passed.', \count($checks)));

        return self::SUCCESS;
    }

    /**
     * Every option, with secrets replaced by whether they are set.
     *
     * @return list<array{key: string, env: string, value: string, source: string, secret: bool}>
     */
    private function describeOptions(Config $config): array
    {
        $out = [];
        foreach (Schema::all() as $key => $option) {
            $secret = \in_array($key, Schema::SECRET_KEYS, true);
            $out[] = [
                'key' => $key,
                'env' => $option['env'],
                'value' => $secret ? $this->redact($config->string($key)) : $this->present($config->get($key)),
                'source' => $config->sourceOf($key),
                'secret' => $secret,
            ];
        }

        return $out;
    }

    /** A secret is only ever reported as present or absent. */
    private function redact(string $value): string
    {
        return trim($value) === '' ? self::REDACTED_UNSET : self::REDACTED_SET;
    }

    private function present(string|int|bool $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value)) {
            return (string) $value;
        }

        return $value === '' ? '(empty)' : $value;
    }

    private function describeConfigFile(Config $config): string
    {
        $path = $config->configPath();
        if ($path === null) {
            return '(none: defaults and environment only)';
        }

        return is_file($path) ? $path : $path . ' (does not exist yet)';
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string}>
     */
    private function runChecks(Config $config): array
    {
        $backend = $config->string('storage');
        $dataDir = $config->dataDir();

        $checks = [];
        $checks[] = $this->checkDataDirectory($dataDir);
        $checks[] = $this->checkOutsideWebRoot($config, $dataDir);
        $checks[] = $this->checkCategories($config, $backend);
        $checks[] = $this->checkCacheDirectory($config, $backend);
        $checks[] = $this->checkSqliteFile($config, $backend);
        $checks[] = $this->checkAdminPassword($config);
        $checks[] = $this->checkBaseUrl($config);
        $checks[] = $this->checkTemplate($config);
        $checks[] = $this->checkExtensions($backend);

        return $checks;
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkDataDirectory(string $dataDir): array
    {
        if (!is_dir($dataDir)) {
            return $this->result('Data directory exists and is writable', false, $dataDir . ' does not exist; create it, or copy data-dist/ to it');
        }
        if (!is_writable($dataDir)) {
            return $this->result('Data directory exists and is writable', false, $dataDir . ' is not writable by ' . $this->currentUser());
        }

        return $this->result('Data directory exists and is writable', true, $dataDir);
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkOutsideWebRoot(Config $config, string $dataDir): array
    {
        $public = $this->normalise($config->projectRoot() . '/public');
        $resolved = $this->normalise($dataDir);
        $inside = $resolved === $public || str_starts_with($resolved, $public . '/');

        return $this->result(
            'Data directory is outside public/',
            !$inside,
            $inside ? $resolved . ' is inside the web root; subscriptions, cache and config could be downloaded' : '',
        );
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkCategories(Config $config, string $backend): array
    {
        $name = 'At least one readable category';

        if ($backend === StoreFactory::FLAT) {
            $dir = $config->categoriesDir();
            if (!is_dir($dir)) {
                return $this->result($name, false, $dir . ' does not exist');
            }
            $files = glob(rtrim($dir, '/') . '/*.opml');
            $readable = array_filter($files === false ? [] : $files, 'is_readable');
            if ($readable === []) {
                return $this->result($name, false, 'no readable .opml file in ' . $dir);
            }

            return $this->result($name, true, \count($readable) . ' in ' . $dir);
        }

        try {
            $categories = $this->kernel->subscriptions()->categories();
        } catch (ZfeederException $e) {
            return $this->result($name, false, $e->getMessage());
        }

        return $categories === []
            ? $this->result($name, false, 'the database holds no categories yet')
            : $this->result($name, true, \count($categories) . ' in ' . $config->sqlitePath());
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkCacheDirectory(Config $config, string $backend): array
    {
        $name = 'Cache directory is writable';
        if ($backend !== StoreFactory::FLAT) {
            return $this->result($name, true, 'not used by the sqlite backend');
        }

        $dir = $config->cacheDir();
        if (is_dir($dir)) {
            return is_writable($dir)
                ? $this->result($name, true, $dir)
                : $this->result($name, false, $dir . ' is not writable by ' . $this->currentUser());
        }

        $parent = \dirname($dir);

        return is_dir($parent) && is_writable($parent)
            ? $this->result($name, true, $dir . ' will be created on the first fetch')
            : $this->result($name, false, $dir . ' does not exist and cannot be created: ' . $parent . ' is not a writable directory');
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkSqliteFile(Config $config, string $backend): array
    {
        $name = 'SQLite file is writable';
        if ($backend !== StoreFactory::SQLITE) {
            return $this->result($name, true, 'not used by the flat backend');
        }

        $path = $config->sqlitePath();
        if (is_file($path)) {
            return is_writable($path)
                ? $this->result($name, true, $path)
                : $this->result($name, false, $path . ' is not writable by ' . $this->currentUser());
        }

        $parent = \dirname($path);

        // SQLite writes its -wal and -shm files beside the database, so the
        // directory has to be writable even when the file already exists.
        return is_dir($parent) && is_writable($parent)
            ? $this->result($name, true, $path . ' will be created on first use')
            : $this->result($name, false, $path . ' does not exist and cannot be created: ' . $parent . ' is not a writable directory');
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkAdminPassword(Config $config): array
    {
        $name = 'Administration password is set';
        if (!$config->bool('admin_enabled')) {
            return $this->result($name, true, 'the panel is disabled');
        }

        // The value itself is never read into the report, only its emptiness.
        return trim($config->string('admin_password_hash')) === ''
            ? $this->result($name, false, 'the panel is enabled but has no password; create one with bin/zfeeder hash-password')
            : $this->result($name, true, '');
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkBaseUrl(Config $config): array
    {
        $name = 'base_url ends with a slash';
        $url = trim($config->string('base_url'));
        if ($url === '') {
            return $this->result($name, true, 'empty: the site root is used');
        }

        return str_ends_with($url, '/')
            ? $this->result($name, true, $url)
            : $this->result($name, false, $url . ' must end with "/", or generated links will be wrong');
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkTemplate(Config $config): array
    {
        $name = 'Default template exists';
        $spec = $config->string('default_template');
        $set = $config->string('template_set');

        try {
            $resolved = (new TemplateLocator($config))->resolve($spec, $set);
        } catch (ZfeederException $e) {
            return $this->result($name, false, $e->getMessage());
        }

        return $this->result($name, true, $resolved['set'] . '/' . $resolved['name'] . '.html');
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function checkExtensions(string $backend): array
    {
        $required = ['dom', 'json', 'libxml', 'mbstring', 'simplexml'];
        if ($backend === StoreFactory::SQLITE) {
            $required[] = 'pdo_sqlite';
        }
        sort($required);

        $missing = array_values(array_filter($required, static fn (string $e): bool => !\extension_loaded($e)));

        return $this->result(
            sprintf('PHP extensions for the %s backend', $backend),
            $missing === [],
            $missing === [] ? implode(', ', $required) : 'missing: ' . implode(', ', $missing),
        );
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function result(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }

    private function currentUser(): string
    {
        $user = get_current_user();

        return $user === '' ? 'this process' : $user;
    }

    /** Absolute and `..`-free, so two paths can be compared even when neither exists. */
    private function normalise(string $path): string
    {
        $real = realpath($path);
        if (\is_string($real)) {
            return rtrim(str_replace('\\', '/', $real), '/');
        }

        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $parts);
    }
}
