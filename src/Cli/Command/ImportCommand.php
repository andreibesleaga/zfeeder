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
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Zfeeder\Exception\SecurityException;
use Zfeeder\Exception\ZfeederException;
use Zfeeder\Kernel;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Opml\Reader;

/**
 * Import an OPML subscription list into a category.
 *
 * The source may be a file or a URL, and a URL is the dangerous case: an
 * operator pasting a link from a forum is exactly the request-forgery vector
 * {@see \Zfeeder\Fetch\UrlGuard} exists for, so remote sources are checked
 * before the connection and the download is size-capped. The document itself is
 * then read by {@see Reader}, which refuses DTDs and external entities, so a
 * hostile OPML file cannot read the server's filesystem on its way in.
 */
#[AsCommand(
    name: 'import',
    description: 'Import an OPML subscription list from a file or a URL.',
)]
final class ImportCommand extends Command
{
    use CommandInput;

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'An OPML file path, or an http/https URL.')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Category to import into; the configured default when omitted.')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Replace the category instead of appending to it.')
            ->setHelp(<<<'HELP'
                Reads an OPML 2.0 (or 1.0) subscription list and adds its feeds to a
                category. Appended feeds continue the existing numbering, so an import can
                never reorder what is already subscribed; <info>--replace</info> throws the old list away
                instead.

                Worked example, moving a reading list over from another aggregator:

                  <info>bin/zfeeder import ~/Downloads/subscriptions.opml --category=news</info>

                  <comment>Imported 23 subscriptions into "news" (31 in total).</comment>

                and from a URL, replacing whatever was there:

                  <info>bin/zfeeder import https://example.com/feeds.opml --category=news --replace</info>

                A remote source is checked against the address rules first, is refused if
                it points at a private or loopback address, and is read up to the
                configured feed size limit. The document is parsed with DTDs and external
                entities disabled.

                Exit codes: 0 when the list was imported, 1 when the source could not be
                read or parsed, 2 when the category name is not usable.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $this->argumentString($input, 'source');
        if ($source === '') {
            $io->error('A source file or URL is required.');

            return self::INVALID;
        }

        $categoryName = $this->option($input, 'category') ?? $this->kernel->config()->string('default_category');
        if (!Category::isValidName($categoryName)) {
            $io->error(sprintf('"%s" is not a usable category name: use 1-40 characters from a-z, 0-9, hyphen or underscore.', $categoryName));

            return self::INVALID;
        }

        $opml = $this->readSource($io, $source);
        if ($opml === null) {
            return self::FAILURE;
        }

        try {
            $store = $this->kernel->subscriptions();
            $before = $store->has($categoryName) ? $store->category($categoryName)->count() : 0;
            $imported = $store->importOpml($categoryName, $opml, $this->flag($input, 'replace'));
            $after = $store->category($categoryName)->count();
        } catch (ZfeederException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf(
            'Imported %d subscription%s into "%s" (%d before, %d now).',
            $imported,
            $imported === 1 ? '' : 's',
            $categoryName,
            $before,
            $after,
        ));

        return self::SUCCESS;
    }

    /** @return string|null the document, or null when it could not be read */
    private function readSource(SymfonyStyle $io, string $source): ?string
    {
        if (preg_match('#^https?://#i', $source) === 1) {
            return $this->readRemote($io, $source);
        }

        if (!is_file($source) || !is_readable($source)) {
            $io->error(sprintf('Cannot read "%s": no such file. Give a readable path or an http/https URL.', $source));

            return null;
        }

        $contents = file_get_contents($source);
        if ($contents === false) {
            $io->error('Cannot read the file: ' . $source);

            return null;
        }

        return $contents;
    }

    private function readRemote(SymfonyStyle $io, string $url): ?string
    {
        try {
            $this->kernel->urlGuard()->assertAllowed($url);
        } catch (SecurityException $e) {
            $io->error($e->getMessage());

            return null;
        }

        $limit = $this->kernel->config()->int('fetch_max_bytes');

        try {
            $response = $this->kernel->httpClient()->request('GET', $url);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $io->error(sprintf('%s answered HTTP %d.', $url, $status));

                return null;
            }
            $body = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            $io->error(sprintf('Cannot download %s: %s', $url, $e->getMessage()));

            return null;
        }

        if (\strlen($body) > $limit) {
            $io->error(sprintf(
                '%s returned %d bytes; at most %d are accepted (fetch_max_bytes).',
                $url,
                \strlen($body),
                $limit,
            ));

            return null;
        }

        return $body;
    }
}
