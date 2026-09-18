<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Zfeeder\Cli\Command\ExportCommand;
use Zfeeder\Subscription\Opml\Reader;

#[CoversClass(ExportCommand::class)]
final class ExportCommandTest extends CliTestCase
{
    public function testOneCategoryGoesToStandardOutputAsOpml(): void
    {
        $kernel = $this->kernel();
        $this->seed(
            $kernel,
            'news',
            $this->feed('https://one.example/feed.xml', 'One', 1),
            $this->feed('https://two.example/feed.xml', 'Two', 2),
        );

        $tester = $this->execute($kernel, 'export', ['--category' => 'news']);
        $document = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('<opml version="2.0">', $document);
        self::assertStringContainsString('xmlUrl="https://one.example/feed.xml"', $document);
        self::assertCount(2, (new Reader())->readFeeds($document));
    }

    public function testEveryCategoryIsMergedAndRenumbered(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml', 'One', 1));
        $this->seed($kernel, 'tech', $this->feed('https://two.example/feed.xml', 'Two', 1));

        $tester = $this->execute($kernel, 'export');
        $feeds = (new Reader())->readFeeds($tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(2, $feeds);
        self::assertSame([1, 2], array_map(static fn ($feed): int => $feed->position, $feeds));
    }

    public function testWritesToAFileWhenAskedTo(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml', 'One', 1));
        $target = $this->tempPath('backup.opml');

        $tester = $this->execute($kernel, 'export', ['--category' => 'news', '--output' => $target]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($target);
        self::assertCount(1, (new Reader())->readFeeds((string) file_get_contents($target)));
        self::assertStringContainsString('Exported 1 subscription from 1 category', $this->flatten($tester->getDisplay()));
    }

    public function testAnUnwritableTargetIsAFailure(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml', 'One', 1));

        $tester = $this->execute($kernel, 'export', [
            '--category' => 'news',
            '--output' => $this->tempPath('no/such/directory/backup.opml'),
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Cannot write the OPML file', $this->flatten($tester->getDisplay()));
    }

    public function testAnUnknownCategoryIsAUsageError(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $tester = $this->execute($kernel, 'export', ['--category' => 'missing']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
