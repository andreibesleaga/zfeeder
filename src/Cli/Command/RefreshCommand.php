<?php

declare(strict_types=1);

namespace Zfeeder\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zfeeder\Exception\StorageException;
use Zfeeder\Exception\ZfeederException;
use Zfeeder\Fetch\FetchResult;
use Zfeeder\Kernel;
use Zfeeder\Storage\AtomicFile;

/**
 * The offline refresh: fetch every subscription and leave the results in the
 * cache, so that rendering a page never has to wait for a network round trip.
 *
 * This is the command cron runs, which drives three design decisions:
 *
 *  - the report keeps the 1.6 wording, byte for byte, because sites have log
 *    scrapers and MOTD scripts built on those lines ({@see FetchResult::legacyLine()});
 *  - a lock file makes overlapping runs impossible. A slow feed plus a five
 *    minute cron is all it takes for two refreshes to meet, and the second one
 *    would fetch everything a second time for nothing;
 *  - finding the lock held is *not* an error. The other run is doing the work,
 *    so the exit code is 0 and cron stays quiet.
 */
#[AsCommand(
    name: 'refresh',
    description: 'Fetch every subscription and store the result in the cache.',
)]
final class RefreshCommand extends Command
{
    use CommandInput;

    /** Lives in the data directory, beside the cache it protects. */
    private const string LOCK_FILE = 'refresh.lock';

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Refresh only this category; every category when omitted.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Fetch even when the cached copy has not expired yet.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print a machine readable report instead of the 1.6 lines.')
            ->setHelp(<<<'HELP'
                Fetches every subscribed feed and writes what comes back to the cache.
                A feed subscribed in several categories is fetched once per run.

                The report is the wording zFeeder 1.6 printed:

                  <info>https://example.com/feed.xml - cached</info>
                  <info>https://example.com/other.xml - not expired yet</info>
                  <info>https://broken.example/feed.xml - NOT cached; check connection</info>

                Worked example, the hourly cron job of a site whose feeds are refreshed
                offline:

                  <info>17 * * * * /var/www/zfeeder/bin/zfeeder refresh --quiet || logger -t zfeeder "refresh failed"</info>

                and the same thing by hand, for one category, ignoring the per-feed
                refresh interval and reporting as JSON:

                  <info>bin/zfeeder refresh --category=news --force --json</info>

                Exit codes: 0 when every feed was fetched or was still fresh, 1 when at
                least one feed failed, 2 when the named category does not exist.

                Two runs cannot overlap: the second one finds the lock file held, says so
                and exits 0, because the first run is already doing the work. The global
                <info>-q, --quiet</info> switch silences the report; the exit code still tells cron
                what happened.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = $this->flag($input, 'json');
        $force = $this->flag($input, 'force');
        $category = $this->option($input, 'category');

        try {
            if ($category !== null && !$this->kernel->subscriptions()->has($category)) {
                $io->error(sprintf('There is no category named "%s". Run bin/zfeeder list-feeds to see them.', $category));

                return self::INVALID;
            }

            $lockPath = rtrim($this->kernel->config()->dataDir(), '/\\') . '/' . self::LOCK_FILE;
            AtomicFile::ensureDirectory(\dirname($lockPath));
            $lock = $this->acquireLock($lockPath);
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if ($lock === null) {
            // Not a failure: the other process is refreshing the same feeds.
            if ($asJson) {
                $output->writeln($this->json(['locked' => true, 'lock' => $lockPath, 'feeds' => [], 'ok' => true]));
            } else {
                $io->note('Another refresh is already running (' . $lockPath . '); leaving it to finish.');
            }

            return self::SUCCESS;
        }

        try {
            $results = $this->kernel->feeds()->refresh($category, $force);
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $asJson
            ? $this->reportJson($output, $category, $results)
            : $this->reportText($io, $results);
    }

    /**
     * @param list<FetchResult> $results
     */
    private function reportText(SymfonyStyle $io, array $results): int
    {
        foreach ($results as $result) {
            $io->writeln($result->legacyLine());
        }

        $counts = $this->tally($results);
        $summary = sprintf(
            '%d feed%s: %d cached, %d still fresh, %d failed.',
            $counts['total'],
            $counts['total'] === 1 ? '' : 's',
            $counts['cached'],
            $counts['notExpired'],
            $counts['failed'],
        );

        if ($counts['total'] === 0) {
            $io->warning('Nothing to refresh: no category holds a subscribed feed.');

            return self::SUCCESS;
        }

        if ($counts['failed'] > 0) {
            $io->error($summary);
            foreach ($results as $result) {
                if (!$result->isSuccess() && $result->message !== '') {
                    $io->writeln(sprintf('  %s: %s', $result->url, $result->message));
                }
            }

            return self::FAILURE;
        }

        $io->success($summary);

        return self::SUCCESS;
    }

    /**
     * @param list<FetchResult> $results
     */
    private function reportJson(OutputInterface $output, ?string $category, array $results): int
    {
        $feeds = [];
        foreach ($results as $result) {
            $feeds[] = [
                'url' => $result->url,
                'outcome' => $result->outcome,
                'success' => $result->isSuccess(),
                'message' => $result->message,
                'line' => $result->legacyLine(),
            ];
        }

        $counts = $this->tally($results);
        $output->writeln($this->json([
            'locked' => false,
            'category' => $category,
            'feeds' => $feeds,
            'summary' => $counts,
            'ok' => $counts['failed'] === 0,
        ]));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param list<FetchResult> $results
     *
     * @return array{total: int, cached: int, notExpired: int, failed: int}
     */
    private function tally(array $results): array
    {
        $counts = ['total' => \count($results), 'cached' => 0, 'notExpired' => 0, 'failed' => 0];
        foreach ($results as $result) {
            match ($result->outcome) {
                FetchResult::NOT_EXPIRED => ++$counts['notExpired'],
                FetchResult::FAILED => ++$counts['failed'],
                default => ++$counts['cached'],
            };
        }

        return $counts;
    }

    /**
     * @return resource|null the held lock, or null when another run holds it
     *
     * @throws ZfeederException when the lock file cannot be opened at all
     */
    private function acquireLock(string $path)
    {
        // Silenced: an unwritable data directory is an expected operating
        // condition and is reported as a message, not as a PHP warning.
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new StorageException(
                'Cannot open the refresh lock file: ' . $path . '. Is the data directory writable?',
            );
        }

        // LOCK_NB: waiting would only queue cron runs up behind each other.
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }
}
