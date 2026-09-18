<?php

declare(strict_types=1);

namespace Zfeeder\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zfeeder\Exception\ZfeederException;
use Zfeeder\Kernel;
use Zfeeder\Storage\Migrator;
use Zfeeder\Storage\StoreFactory;

/**
 * Move an installation between the flat-file and SQLite backends.
 *
 * Switching storage must never be a one-way door, so this works in both
 * directions and the copy is verified by re-reading the destination
 * ({@see Migrator}). Nothing is deleted from the source: the old files or the
 * old database are left in place as the backup, and removing them is the
 * operator's decision.
 *
 * `--ensure-schema` is the container entrypoint's call. It creates or upgrades
 * the SQLite schema and nothing else, which makes it safe on every boot, and it
 * exits 0 without touching anything when the backend is `flat` — an entrypoint
 * cannot know which backend the operator configured.
 */
#[AsCommand(
    name: 'migrate',
    description: 'Move subscriptions and cache between the flat and SQLite backends.',
)]
final class MigrateCommand extends Command
{
    use CommandInput;

    /** The exit code for a configuration problem: `EX_CONFIG` from sysexits.h. */
    private const int EXIT_CONFIG = 78;

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'The destination backend: flat or sqlite.')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Print the per-category counts read back from the destination.')
            ->addOption('ensure-schema', null, InputOption::VALUE_NONE, 'Only create or upgrade the SQLite schema, then exit.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be copied without writing anything.')
            ->setHelp(<<<'HELP'
                Copies every category, every subscription and every cache entry from the
                backend currently configured into the other one, then re-reads the
                destination and compares. The source is left untouched, so it is your
                backup.

                Worked example, growing a flat-file install into SQLite:

                  <info>bin/zfeeder migrate --to=sqlite --verify</info>

                  <comment>Copied 6 categories, 63 subscriptions and 41 cache entries to sqlite.</comment>
                  <comment>news: 10 subscriptions in both.</comment>
                  <comment>...</comment>
                  <comment>Now set ZF_STORAGE=sqlite (or "storage": "sqlite" in config.json) and restart.</comment>

                and coming back out again, which is the point of having it work both ways:

                  <info>bin/zfeeder migrate --to=flat</info>

                The container entrypoint calls this instead:

                  <info>bin/zfeeder migrate --ensure-schema</info>

                which creates or upgrades the SQLite schema, is safe to run on every boot,
                and exits 0 without doing anything when the backend is flat.

                Exit codes: 0 on success, 1 when the copy or the verification failed,
                2 when <info>--to</info> is missing or names something other than flat or sqlite.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->kernel->config();
        $current = $config->string('storage');

        if ($this->flag($input, 'ensure-schema')) {
            return $this->ensureSchema($io, $current);
        }

        $to = $this->option($input, 'to');
        if ($to === null) {
            $io->error('Say where to migrate to: --to=flat or --to=sqlite. Use --ensure-schema to only create the SQLite schema.');

            return self::INVALID;
        }
        if ($to !== StoreFactory::FLAT && $to !== StoreFactory::SQLITE) {
            $io->error(sprintf('Unknown storage backend "%s". Use flat or sqlite.', $to));

            return self::INVALID;
        }
        if ($to === $current) {
            $io->warning(sprintf('The configured backend is already "%s"; there is nothing to migrate.', $to));

            return self::SUCCESS;
        }
        if ($to === StoreFactory::SQLITE && !\extension_loaded('pdo_sqlite')) {
            $io->error('The sqlite backend needs the pdo_sqlite extension, which is not loaded.');

            return self::EXIT_CONFIG;
        }

        try {
            $source = $this->kernel->stores();
            $destination = new StoreFactory($config->with('storage', $to));

            if ($this->flag($input, 'dry-run')) {
                return $this->dryRun($io, $source, $to);
            }

            $counts = (new Migrator())->migrate(
                $source->subscriptions(),
                $destination->subscriptions(),
                $source->cache(),
                $destination->cache(),
            );

            $io->success(sprintf(
                'Copied %d categor%s, %d subscription%s and %d cache entr%s to %s.',
                $counts['categories'],
                $counts['categories'] === 1 ? 'y' : 'ies',
                $counts['feeds'],
                $counts['feeds'] === 1 ? '' : 's',
                $counts['cache'],
                $counts['cache'] === 1 ? 'y' : 'ies',
                $to,
            ));

            if ($this->flag($input, 'verify')) {
                $rows = [];
                $sourceStore = $source->subscriptions();
                $destinationStore = $destination->subscriptions();
                foreach ($sourceStore->categories() as $name) {
                    $rows[] = [
                        $name,
                        (string) $sourceStore->category($name)->count(),
                        $destinationStore->has($name) ? (string) $destinationStore->category($name)->count() : 'missing',
                    ];
                }
                $io->table(['Category', $current, $to], $rows);
            }
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());
            $io->writeln('Nothing was removed from the source backend; fix the problem and run it again.');

            return self::FAILURE;
        }

        $io->writeln(sprintf(
            'Now set <info>ZF_STORAGE=%s</info> (or <info>"storage": "%s"</info> in config.json) and restart. The %s data is left in place as your backup.',
            $to,
            $to,
            $current,
        ));

        return self::SUCCESS;
    }

    /** The entrypoint path: create or upgrade the schema, or do nothing at all. */
    private function ensureSchema(SymfonyStyle $io, string $backend): int
    {
        if ($backend !== StoreFactory::SQLITE) {
            $io->writeln(sprintf('Storage backend is "%s"; there is no schema to create.', $backend));

            return self::SUCCESS;
        }

        if (!\extension_loaded('pdo_sqlite')) {
            $io->error('The sqlite backend needs the pdo_sqlite extension, which is not loaded.');

            return self::EXIT_CONFIG;
        }

        try {
            // StoreFactory::database() migrates on first use; calling migrate()
            // again is deliberate and harmless: applied migrations are skipped.
            $database = $this->kernel->stores()->database();
            $database->migrate();
            $io->writeln(sprintf('SQLite schema is at version %d (%s).', $database->version(), $database->path()));
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function dryRun(SymfonyStyle $io, StoreFactory $source, string $to): int
    {
        $store = $source->subscriptions();
        $rows = [];
        $feeds = 0;
        foreach ($store->categories() as $name) {
            $count = $store->category($name)->count();
            $feeds += $count;
            $rows[] = [$name, (string) $count];
        }
        $cache = \count($source->cache()->urls());

        $io->table(['Category', 'Subscriptions'], $rows);
        $io->note(sprintf(
            'Would copy %d categor%s, %d subscription%s and %d cache entr%s to %s. Nothing was written.',
            \count($rows),
            \count($rows) === 1 ? 'y' : 'ies',
            $feeds,
            $feeds === 1 ? '' : 's',
            $cache,
            $cache === 1 ? 'y' : 'ies',
            $to,
        ));

        return self::SUCCESS;
    }
}
