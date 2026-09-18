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
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * What is subscribed, and how it is doing.
 *
 * The subscription list and the cache are two different stores, and the
 * question people actually ask ("why is that feed not updating?") needs both:
 * the OPML row says what should happen, the cache entry says what did. They are
 * joined here so that one screen answers it.
 */
#[AsCommand(
    name: 'list-feeds',
    description: 'Show every category and its subscriptions, with the last fetch of each.',
)]
final class ListFeedsCommand extends Command
{
    use CommandInput;

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Show only this category.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print a machine readable list instead of tables.')
            ->setHelp(<<<'HELP'
                Prints one table per category: the position the feed is rendered at, its
                title, its address, how many items it shows, how often it may be fetched,
                whether it is subscribed at all, and what the cache knows about it.

                Worked example:

                  <info>bin/zfeeder list-feeds --category=news</info>

                  <comment>news (10 subscriptions)</comment>
                  <comment> # Title                URL                              Items Refresh Subscribed Last fetch        Status</comment>
                  <comment> 1 ABC News: World      http://.../world_rss093.xml          1  60 min  yes        2026-09-18 09:14  200</comment>
                  <comment> 2 BBC News             http://.../front_page/rss091.xml     1  60 min  yes        never             -</comment>

                A feed showing <comment>0</comment> items or <comment>no</comment> under Subscribed is stored but never rendered
                and never fetched, which is how zFeeder 1.6 let you park a feed without
                deleting it.

                Feeding a script instead of a person:

                  <info>bin/zfeeder list-feeds --json | jq -r '.categories[].feeds[] | select(.lastFetch == null) | .url'</info>

                Exit codes: 0 when the list was printed, 2 when the named category does
                not exist.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $wanted = $this->option($input, 'category');

        try {
            $store = $this->kernel->subscriptions();
            $names = $store->categories();
            if ($wanted !== null) {
                if (!$store->has($wanted)) {
                    $io->error(sprintf('There is no category named "%s".', $wanted));

                    return self::INVALID;
                }
                $names = [$wanted];
            }

            $categories = [];
            foreach ($names as $name) {
                $categories[] = $store->category($name);
            }
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->flag($input, 'json')) {
            $output->writeln($this->json(['categories' => array_map($this->describe(...), $categories)]));

            return self::SUCCESS;
        }

        if ($categories === []) {
            $io->warning('There are no categories yet. Add a feed with bin/zfeeder add, or import an OPML file with bin/zfeeder import.');

            return self::SUCCESS;
        }

        foreach ($categories as $category) {
            $this->renderTable($io, $category);
        }

        return self::SUCCESS;
    }

    private function renderTable(SymfonyStyle $io, Category $category): void
    {
        $io->section(sprintf('%s (%d subscription%s)', $category->name, $category->count(), $category->count() === 1 ? '' : 's'));

        if ($category->feeds === []) {
            $io->writeln('  <comment>empty</comment>');
            $io->newLine();

            return;
        }

        $rows = [];
        foreach ($this->ordered($category) as $feed) {
            $entry = $this->cacheEntry($feed->xmlUrl);
            $rows[] = [
                (string) $feed->position,
                $feed->title !== '' ? $feed->title : '(no title)',
                $feed->xmlUrl,
                (string) $feed->showedItems,
                $feed->refreshMinutes . ' min',
                $feed->subscribed ? 'yes' : 'no',
                $entry === null ? 'never' : $entry->fetchedAt->format('Y-m-d H:i'),
                $this->status($entry),
            ];
        }

        $io->table(['#', 'Title', 'URL', 'Items', 'Refresh', 'Subscribed', 'Last fetch', 'Status'], $rows);
    }

    /**
     * @return array{name: string, count: int, ownerName: string, ownerEmail: string, dateModified: ?string, feeds: list<array<string, mixed>>}
     */
    private function describe(Category $category): array
    {
        $feeds = [];
        foreach ($this->ordered($category) as $feed) {
            $entry = $this->cacheEntry($feed->xmlUrl);
            $feeds[] = [
                'position' => $feed->position,
                'title' => $feed->title,
                'url' => $feed->xmlUrl,
                'htmlUrl' => $feed->htmlUrl,
                'items' => $feed->showedItems,
                'refreshMinutes' => $feed->refreshMinutes,
                'subscribed' => $feed->subscribed,
                'renderable' => $feed->isRenderable(),
                'lastFetch' => $entry?->fetchedAt->format(\DATE_ATOM),
                'status' => $entry?->status,
                'error' => $entry === null ? null : ($entry->error !== '' ? $entry->error : null),
            ];
        }

        return [
            'name' => $category->name,
            'count' => $category->count(),
            'ownerName' => $category->ownerName,
            'ownerEmail' => $category->ownerEmail,
            'dateModified' => $category->dateModified?->format(\DATE_ATOM),
            'feeds' => $feeds,
        ];
    }

    /**
     * Rendering order — position first — even for feeds that are not rendered,
     * so the table reads the same way the page does.
     *
     * @return list<Feed>
     */
    private function ordered(Category $category): array
    {
        $feeds = $category->feeds;
        usort($feeds, static fn (Feed $a, Feed $b): int => $a->position <=> $b->position);

        return $feeds;
    }

    /**
     * A missing or unreadable cache is not an error here: the point of this
     * command is to report the state, whatever it is.
     */
    private function cacheEntry(string $url): ?CacheEntry
    {
        try {
            return $this->kernel->cache()->get($url);
        } catch (ZfeederException) {
            return null;
        }
    }

    private function status(?CacheEntry $entry): string
    {
        if ($entry === null) {
            return '-';
        }
        if ($entry->error !== '') {
            return 'error: ' . $entry->error;
        }

        return (string) $entry->status;
    }
}
