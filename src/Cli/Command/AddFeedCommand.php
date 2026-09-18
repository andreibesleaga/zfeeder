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
use Zfeeder\Exception\SecurityException;
use Zfeeder\Exception\ZfeederException;
use Zfeeder\Kernel;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * Subscribe to one feed.
 *
 * The command fetches the feed before storing it, for two reasons that are
 * worth more than the round trip: the title and description come from the feed
 * itself rather than from whatever the operator felt like typing, and a URL
 * that cannot be fetched is caught now instead of appearing as a blank block on
 * the page tomorrow. `--no-verify` exists for air-gapped installs and for
 * feeds that are temporarily down.
 *
 * Every URL goes through {@see \Zfeeder\Fetch\UrlGuard} first, including with
 * `--no-verify`: what is stored here is what cron will fetch later, so a
 * refusal must happen at the point the address enters the system.
 */
#[AsCommand(
    name: 'add',
    description: 'Subscribe to a feed and add it to a category.',
)]
final class AddFeedCommand extends Command
{
    use CommandInput;

    private const int DEFAULT_ITEMS = 3;
    private const int DEFAULT_REFRESH_MINUTES = 120;

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'The feed address (http or https).')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Category to add it to; the configured default when omitted.')
            ->addOption('items', null, InputOption::VALUE_REQUIRED, 'How many items to show.', (string) self::DEFAULT_ITEMS)
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Minutes between fetches.', (string) self::DEFAULT_REFRESH_MINUTES)
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Title to store; taken from the feed when omitted.')
            ->addOption('no-verify', null, InputOption::VALUE_NONE, 'Do not fetch the feed first. The address is still checked.')
            ->setHelp(<<<'HELP'
                Fetches the feed, reads its title and description, and appends it to a
                category. The new subscription takes the next free position.

                Worked example:

                  <info>bin/zfeeder add https://www.theregister.com/headlines.atom --category=technology --items=5 --refresh=30</info>

                  <comment>Fetched "The Register" (48 items).</comment>
                  <comment>Added to category "technology" at position 7.</comment>

                and adding a feed that is down right now, or on a machine with no network:

                  <info>bin/zfeeder add https://example.com/feed.xml --title="Example" --no-verify</info>

                Refused, both before anything is written:

                  - a URL already subscribed in the same category;
                  - a URL that resolves to a private, loopback or cloud-metadata address,
                    unless the installation is in development mode.

                Exit codes: 0 when the feed was added, 1 when it was refused or could not
                be fetched, 2 when the category name is not usable.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $url = $this->argumentString($input, 'url');
        if ($url === '') {
            $io->error('A feed address is required.');

            return self::INVALID;
        }

        $categoryName = $this->option($input, 'category') ?? $this->kernel->config()->string('default_category');
        if (!Category::isValidName($categoryName)) {
            $io->error(sprintf('"%s" is not a usable category name: use 1-40 characters from a-z, 0-9, hyphen or underscore.', $categoryName));

            return self::INVALID;
        }

        // The address rules apply whether or not we fetch it now.
        try {
            $this->kernel->urlGuard()->assertAllowed($url);
        } catch (SecurityException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $store = $this->kernel->subscriptions();
            if (!$store->has($categoryName)) {
                $store->createCategory($categoryName);
                $io->note(sprintf('Created the category "%s".', $categoryName));
            }
            $category = $store->category($categoryName);

            foreach ($category->feeds as $existing) {
                if (strcasecmp(trim($existing->xmlUrl), $url) === 0) {
                    $io->error(sprintf(
                        'Category "%s" is already subscribed to %s (position %d).',
                        $categoryName,
                        $existing->xmlUrl,
                        $existing->position,
                    ));

                    return self::FAILURE;
                }
            }

            $details = ['title' => '', 'description' => '', 'htmlUrl' => '', 'language' => ''];
            if (!$this->flag($input, 'no-verify')) {
                $details = $this->verify($io, $url);
                if ($details === null) {
                    return self::FAILURE;
                }
            }

            $title = $this->option($input, 'title') ?? $details['title'];
            $position = 0;
            foreach ($category->feeds as $feed) {
                $position = max($position, $feed->position);
            }
            ++$position;

            $feeds = $category->feeds;
            $feeds[] = new Feed(
                $url,
                $title,
                $details['description'],
                $details['htmlUrl'],
                $position,
                $this->intOption($input, 'refresh', self::DEFAULT_REFRESH_MINUTES),
                $this->intOption($input, 'items', self::DEFAULT_ITEMS),
                true,
                $details['language'],
            );

            $store->saveCategory(new Category(
                $categoryName,
                $feeds,
                $this->kernel->clock(),
                $category->ownerName,
                $category->ownerEmail,
            ));

            $io->success(sprintf(
                'Added %s to category "%s" at position %d.',
                $title !== '' ? sprintf('"%s" (%s)', $title, $url) : $url,
                $categoryName,
                $position,
            ));
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Fetch and parse the feed to fill in what the operator should not have to type.
     *
     * @return array{title: string, description: string, htmlUrl: string, language: string}|null null when the feed could not be read
     */
    private function verify(SymfonyStyle $io, string $url): ?array
    {
        // force: a URL being subscribed for the first time must be read now,
        // not taken from a cache entry another category left behind.
        $result = $this->kernel->fetcher()->fetch($url, 1, true);
        $entry = $result->entry;
        if (!$result->isSuccess() || $entry === null) {
            $io->error(sprintf('Cannot fetch %s: %s', $url, $result->message !== '' ? $result->message : 'no response'));
            $io->writeln('Add it anyway with <info>--no-verify</info> if you know the address is right.');

            return null;
        }

        try {
            $channel = $this->kernel->parser()->parse($entry->body, $url);
        } catch (ZfeederException $e) {
            $io->error(sprintf('%s does not look like a feed: %s', $url, $e->getMessage()));
            $io->writeln('Add it anyway with <info>--no-verify</info> if you know the address is right.');

            return null;
        }

        $io->writeln(sprintf(
            'Fetched <info>%s</info> (%d item%s, %s).',
            $channel->title !== '' ? $channel->title : '(untitled)',
            \count($channel->items),
            \count($channel->items) === 1 ? '' : 's',
            $channel->format !== '' ? $channel->format : 'unknown format',
        ));

        return [
            'title' => $channel->title,
            'description' => $channel->description,
            'htmlUrl' => $channel->link,
            'language' => $channel->language,
        ];
    }
}
