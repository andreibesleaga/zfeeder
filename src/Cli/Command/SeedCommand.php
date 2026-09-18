<?php

declare(strict_types=1);

namespace Zfeeder\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zfeeder\Kernel;
use Zfeeder\Subscription\Opml\Reader;

/**
 * Puts the shipped subscription lists into whichever storage backend is in use.
 *
 * The container entrypoint used to copy `data-dist/categories/*.opml` into the
 * data directory, which only works for the flat backend: a SQLite installation
 * started with nothing to show and every page rendered an error. Seeding
 * through the store interface makes a first boot look the same either way.
 */
#[AsCommand(
    name: 'seed',
    description: 'Load the shipped example subscriptions into the configured storage backend.',
)]
final class SeedCommand extends Command
{
    use CommandInput;

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Directory of OPML files; data-dist/categories by default.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace categories that already exist.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be done and change nothing.')
            ->setHelp(<<<'HELP'
                Reads every OPML file in a directory and creates one category per file,
                named after the file. Categories that already exist are left alone unless
                --force is given, so running this on every boot is safe.

                  <info>bin/zfeeder seed</info>
                  <info>bin/zfeeder seed --from=/srv/my-feeds --force</info>

                This is what the container entrypoint runs on a first start. It works
                with both storage backends, because it writes through the same interface
                the admin panel uses.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $this->flag($input, 'force');
        $dryRun = $this->flag($input, 'dry-run');

        $directory = $this->option($input, 'from')
            ?? $this->kernel->config()->projectRoot() . '/data-dist/categories';

        if (!is_dir($directory)) {
            $io->error('No such directory: ' . $directory);

            return Command::FAILURE;
        }

        $found = glob(rtrim($directory, '/') . '/*.opml');
        $files = $found === false ? [] : $found;
        if ($files === []) {
            $io->warning('No OPML files in ' . $directory . '; nothing to seed.');

            return Command::SUCCESS;
        }

        $store = $this->kernel->subscriptions();
        $reader = new Reader();
        $created = 0;
        $skipped = 0;
        $feeds = 0;

        foreach ($files as $file) {
            $name = strtolower(pathinfo($file, PATHINFO_FILENAME));
            if (!\Zfeeder\Subscription\Category::isValidName($name)) {
                $io->warning(sprintf('Skipping %s: "%s" is not a usable category name.', basename($file), $name));
                $skipped++;
                continue;
            }
            if ($store->has($name) && !$force) {
                $skipped++;
                continue;
            }

            $xml = file_get_contents($file);
            if ($xml === false) {
                $io->warning('Cannot read ' . $file);
                $skipped++;
                continue;
            }

            $category = $reader->readCategory($name, $xml);
            $feeds += $category->count();

            if ($dryRun) {
                $io->writeln(sprintf(
                    '  would seed <info>%s</info> with %d feed(s)',
                    $name,
                    $category->count(),
                ));
                $created++;
                continue;
            }

            if (!$store->has($name)) {
                $store->createCategory($name);
            }
            $store->saveCategory($category);
            $created++;
        }

        $io->success(sprintf(
            '%s %d categor%s (%d subscriptions); %d left alone.',
            $dryRun ? 'Would seed' : 'Seeded',
            $created,
            $created === 1 ? 'y' : 'ies',
            $feeds,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
