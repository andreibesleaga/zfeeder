<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Zfeeder\Cli\Command\SeedCommand;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * The container entrypoint runs `seed` on every boot, so its two important
 * properties are that it works against either storage backend and that running
 * it repeatedly changes nothing. Both are tested here.
 */
#[CoversClass(SeedCommand::class)]
final class SeedCommandTest extends CliTestCase
{
    public function testItLoadsTheShippedCategories(): void
    {
        $kernel = $this->kernel();

        $tester = $this->execute($kernel, 'seed');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $categories = $kernel->subscriptions()->categories();
        self::assertContains('zfeeder', $categories);
        self::assertContains('news', $categories);
        self::assertGreaterThan(0, $kernel->subscriptions()->category('zfeeder')->count());
    }

    public function testItWorksTheSameAgainstTheSqliteBackend(): void
    {
        // Copying OPML files into the data directory only ever worked for the
        // flat backend; a SQLite installation started empty and errored on
        // every page. Seeding through the store interface is the fix, so both
        // backends must end up with the same subscriptions.
        $flat = $this->kernel(['storage' => 'flat']);
        $this->execute($flat, 'seed');
        $flatCategories = $flat->subscriptions()->categories();

        $sqlite = $this->kernel(['storage' => 'sqlite']);
        $tester = $this->execute($sqlite, 'seed');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($flatCategories, $sqlite->subscriptions()->categories());
        self::assertSame(
            $flat->subscriptions()->category('zfeeder')->count(),
            $sqlite->subscriptions()->category('zfeeder')->count(),
        );
    }

    public function testRunningItAgainLeavesExistingCategoriesAlone(): void
    {
        $kernel = $this->kernel();
        $this->execute($kernel, 'seed');

        // Stand in for an operator who has edited a shipped category.
        $kernel->subscriptions()->saveCategory(new Category('zfeeder', [
            new Feed(xmlUrl: 'https://mine.example/feed.xml', title: 'Mine', position: 1, showedItems: 5),
        ]));

        $tester = $this->execute($kernel, 'seed');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('left alone', $this->flatten($tester->getDisplay()));
        $feeds = $kernel->subscriptions()->category('zfeeder')->feeds;
        self::assertCount(1, $feeds, 'the operator edit survived a second seed');
        self::assertSame('https://mine.example/feed.xml', $feeds[0]->xmlUrl);
    }

    public function testForceReplacesWhatIsAlreadyThere(): void
    {
        $kernel = $this->kernel();
        $this->execute($kernel, 'seed');
        $kernel->subscriptions()->saveCategory(new Category('zfeeder', [
            new Feed(xmlUrl: 'https://mine.example/feed.xml', position: 1, showedItems: 5),
        ]));

        $this->execute($kernel, 'seed', ['--force' => true]);

        $feeds = $kernel->subscriptions()->category('zfeeder')->feeds;
        self::assertGreaterThan(1, count($feeds), 'the shipped list was restored');
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $kernel = $this->kernel();

        $tester = $this->execute($kernel, 'seed', ['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would seed', $this->flatten($tester->getDisplay()));
        self::assertSame([], $kernel->subscriptions()->categories(), 'nothing was written');
    }

    public function testAMissingSourceDirectoryIsAnError(): void
    {
        $tester = $this->execute($this->kernel(), 'seed', ['--from' => '/no/such/directory']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No such directory', $this->flatten($tester->getDisplay()));
    }

    public function testADirectoryWithNoOpmlIsReportedRatherThanFailing(): void
    {
        $empty = $this->tempDir() . '/empty-seed';
        mkdir($empty, 0o755, true);

        $tester = $this->execute($this->kernel(), 'seed', ['--from' => $empty]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('nothing to seed', $this->flatten($tester->getDisplay()));
    }

    public function testAFileNameThatIsNotAUsableCategoryNameIsSkipped(): void
    {
        $source = $this->tempDir() . '/odd-seed';
        mkdir($source, 0o755, true);
        file_put_contents($source . '/Not A Category.opml', self::MINIMAL_OPML);
        file_put_contents($source . '/good.opml', self::MINIMAL_OPML);

        $kernel = $this->kernel();
        $tester = $this->execute($kernel, 'seed', ['--from' => $source]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['good'], $kernel->subscriptions()->categories());
    }

    private const string MINIMAL_OPML = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <opml version="2.0">
          <head><title>seed</title></head>
          <body>
            <outline type="rss" position="1" text="Example" title="Example"
                     xmlUrl="https://example.com/feed.xml" htmlUrl="https://example.com/"
                     refreshTime="120" showedItems="3" isSubscribed="yes" language="en" />
          </body>
        </opml>
        XML;
}
