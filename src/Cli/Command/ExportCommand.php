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
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Subscription\Opml\Writer;

/**
 * Export subscriptions as OPML 2.0.
 *
 * Getting the data back out is a feature, not an afterthought: an aggregator
 * you cannot leave is a trap. The document is written by the same
 * {@see Writer} the storage layer uses, so an exported file is byte-identical
 * to the one on disk and can be imported by any other reader.
 */
#[AsCommand(
    name: 'export',
    description: 'Write subscriptions as an OPML 2.0 document.',
)]
final class ExportCommand extends Command
{
    use CommandInput;

    /** The `<title>` of a document holding every category at once. */
    private const string ALL_CATEGORIES = 'all';

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Export one category; every subscription when omitted.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write to this file instead of standard output.')
            ->setHelp(<<<'HELP'
                Writes an OPML 2.0 subscription list. With no <info>--category</info> every category is
                merged into one document, renumbered from 1, titled "all".

                Worked example, a nightly backup of the whole subscription list:

                  <info>bin/zfeeder export --output=/var/backups/zfeeder-$(date +%F).opml</info>

                  <comment>Exported 63 subscriptions from 6 categories to /var/backups/zfeeder-2026-09-18.opml.</comment>

                and one category down a pipe:

                  <info>bin/zfeeder export --category=news | xmllint --format -</info>

                The document keeps the 1.6 attribute spelling (<comment>refreshTime</comment>, <comment>showedItems</comment>,
                <comment>isSubscribed</comment>), so it opens both in the 2004 script and in any modern
                reader.

                Exit codes: 0 when the document was written, 1 when the output file cannot
                be written, 2 when the named category does not exist.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $wanted = $this->option($input, 'category');
        $target = $this->option($input, 'output');

        try {
            $store = $this->kernel->subscriptions();

            if ($wanted !== null) {
                if (!$store->has($wanted)) {
                    $io->error(sprintf('There is no category named "%s".', $wanted));

                    return self::INVALID;
                }
                $document = $store->exportOpml($wanted);
                $count = $store->category($wanted)->count();
                $categories = 1;
            } else {
                $names = $store->categories();
                $feeds = [];
                $position = 0;
                foreach ($names as $name) {
                    foreach ($store->category($name)->feeds as $feed) {
                        ++$position;
                        $feeds[] = new Feed(
                            $feed->xmlUrl,
                            $feed->title,
                            $feed->description,
                            $feed->htmlUrl,
                            $position,
                            $feed->refreshMinutes,
                            $feed->showedItems,
                            $feed->subscribed,
                            $feed->language,
                        );
                    }
                }
                $config = $this->kernel->config();
                $document = (new Writer())->write(new Category(
                    self::ALL_CATEGORIES,
                    $feeds,
                    $this->kernel->clock(),
                    $config->string('owner_name'),
                    $config->string('owner_email'),
                ));
                $count = \count($feeds);
                $categories = \count($names);
            }
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if ($target === null) {
            // Raw: the document is the output, and Console must not decorate it.
            $output->write($document, false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if (@file_put_contents($target, $document) === false) {
            $io->error('Cannot write the OPML file: ' . $target);

            return self::FAILURE;
        }

        $io->success(sprintf(
            'Exported %d subscription%s from %d categor%s to %s.',
            $count,
            $count === 1 ? '' : 's',
            $categories,
            $categories === 1 ? 'y' : 'ies',
            $target,
        ));

        return self::SUCCESS;
    }
}
