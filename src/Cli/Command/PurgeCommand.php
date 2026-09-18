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

/**
 * Drop cache entries.
 *
 * Two jobs, one command: the housekeeping one — a feed removed from every
 * category leaves its body behind forever, because nothing ever asks for it
 * again — and the debugging one, `--all`, for when a feed is being rendered
 * from a stale copy and you want to see it re-fetched.
 *
 * The selection is made here rather than in the store so that `--dry-run` can
 * report exactly the set that would be removed, and so the same rule applies to
 * both backends.
 */
#[AsCommand(
    name: 'purge',
    description: 'Remove cached feed bodies.',
)]
final class PurgeCommand extends Command
{
    use CommandInput;

    private const string DEFAULT_AGE = '7d';

    /** Suffix => seconds. Minutes, hours and days; anything longer is days. */
    private const array UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Remove entries older than this: 30m, 12h, 7d.', self::DEFAULT_AGE)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Remove every cache entry, whatever its age.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be removed without removing it.')
            ->setHelp(<<<'HELP'
                Removes cached feed bodies and reports how many went and how much disk
                came back.

                Worked example, the weekly housekeeping job:

                  <info>0 4 * * 0 /var/www/zfeeder/bin/zfeeder purge --older-than=30d --quiet</info>

                and by hand, when a feed is being served from something stale:

                  <info>bin/zfeeder purge --all</info>

                  <comment>Removed 41 cache entries, freeing 3.7 MiB.</comment>

                Look before you leap:

                  <info>bin/zfeeder purge --older-than=12h --dry-run</info>

                Ages are written as a number and a unit: <comment>s</comment>, <comment>m</comment>, <comment>h</comment> or <comment>d</comment>. Purging is
                always safe: the next refresh fetches whatever was removed.

                Exit codes: 0 when the purge ran, 1 when the cache could not be read,
                2 when the age cannot be understood.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $all = $this->flag($input, 'all');
        $dryRun = $this->flag($input, 'dry-run');

        $raw = $this->option($input, 'older-than') ?? self::DEFAULT_AGE;
        $seconds = $this->parseAge($raw);
        if ($seconds === null) {
            $io->error(sprintf('Cannot read "%s" as an age. Write it as 30m, 12h or 7d.', $raw));

            return self::INVALID;
        }

        $cutoff = $this->kernel->clock()->getTimestamp() - $seconds;
        $removed = 0;
        $kept = 0;
        $bytes = 0;

        try {
            $cache = $this->kernel->cache();
            foreach ($cache->urls() as $url) {
                $entry = $cache->get($url);
                if ($entry === null) {
                    continue;
                }
                if (!$all && $entry->fetchedAt->getTimestamp() >= $cutoff) {
                    ++$kept;

                    continue;
                }
                ++$removed;
                $bytes += \strlen($entry->body);
                if (!$dryRun) {
                    $cache->delete($url);
                }
            }
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $message = sprintf(
            '%s %d cache entr%s, %s %s. %d kept.',
            $dryRun ? 'Would remove' : 'Removed',
            $removed,
            $removed === 1 ? 'y' : 'ies',
            $dryRun ? 'freeing' : 'freed',
            $this->formatBytes($bytes),
            $kept,
        );

        if ($removed === 0) {
            $io->writeln($message);

            return self::SUCCESS;
        }

        $io->success($message);

        return self::SUCCESS;
    }

    /** @return int|null seconds, or null when the age cannot be read */
    private function parseAge(string $raw): ?int
    {
        $raw = strtolower(trim($raw));
        if (preg_match('/^(\d+)\s*([smhd]?)$/', $raw, $match) !== 1) {
            return null;
        }
        $unit = $match[2] === '' ? 's' : $match[2];

        return (int) $match[1] * self::UNITS[$unit];
    }
}
