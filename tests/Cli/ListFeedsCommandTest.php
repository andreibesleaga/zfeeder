<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Zfeeder\Cli\Command\ListFeedsCommand;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Feed;

#[CoversClass(ListFeedsCommand::class)]
final class ListFeedsCommandTest extends CliTestCase
{
    public function testTableShowsSubscriptionsAndTheirCacheState(): void
    {
        $kernel = $this->kernel();
        $this->seed(
            $kernel,
            'news',
            $this->feed('https://one.example/feed.xml', 'One', 1, 5, 30),
            new Feed('https://two.example/feed.xml', 'Two', '', '', 2, 60, 0, false),
        );
        $kernel->cache()->put(new CacheEntry(
            'https://one.example/feed.xml',
            self::RSS_BODY,
            new \DateTimeImmutable('2026-09-18T09:14:00+00:00'),
        ));

        $tester = $this->execute($kernel, 'list-feeds');
        $display = $this->flatten($tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('news (2 subscriptions)', $display);
        self::assertStringContainsString('One https://one.example/feed.xml 5 30 min yes 2026-09-18 09:14 200', $display);
        // Never fetched, unsubscribed, zero items: all three visible at a glance.
        self::assertStringContainsString('Two https://two.example/feed.xml 0 60 min no never -', $display);
    }

    public function testEmptyInstallationSaysSoInsteadOfPrintingNothing(): void
    {
        $tester = $this->execute($this->kernel(), 'list-feeds');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('There are no categories yet', $this->flatten($tester->getDisplay()));
    }

    public function testJsonJoinsTheSubscriptionWithItsLastFetch(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml', 'One'));
        $kernel->cache()->put(new CacheEntry(
            'https://one.example/feed.xml',
            self::RSS_BODY,
            new \DateTimeImmutable(self::NOW),
        ));

        $tester = $this->execute($kernel, 'list-feeds', ['--json' => true]);
        $report = $this->decode($tester->getDisplay());

        self::assertIsArray($report['categories']);
        self::assertCount(1, $report['categories']);
        $category = $report['categories'][0];
        self::assertIsArray($category);
        self::assertSame('news', $category['name']);
        self::assertIsArray($category['feeds']);
        $feed = $category['feeds'][0];
        self::assertIsArray($feed);
        self::assertSame('https://one.example/feed.xml', $feed['url']);
        self::assertSame('2026-09-18T12:00:00+00:00', $feed['lastFetch']);
        self::assertSame(200, $feed['status']);
    }

    public function testAnUnknownCategoryIsAUsageError(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $tester = $this->execute($kernel, 'list-feeds', ['--category' => 'missing']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('There is no category named "missing"', $this->flatten($tester->getDisplay()));
    }
}
