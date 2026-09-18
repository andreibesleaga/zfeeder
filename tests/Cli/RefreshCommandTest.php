<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Cli\Command\RefreshCommand;

/**
 * The cron command: its report, its exit codes and its lock.
 */
#[CoversClass(RefreshCommand::class)]
final class RefreshCommandTest extends CliTestCase
{
    public function testEveryFeedFetchedReportsTheLegacyLinesAndExitsZero(): void
    {
        $kernel = $this->kernel([], $this->http([$this->feedResponse(), $this->feedResponse()]));
        $this->seed(
            $kernel,
            'news',
            $this->feed('https://one.example/feed.xml', 'One', 1),
            $this->feed('https://two.example/feed.xml', 'Two', 2),
        );

        $tester = $this->execute($kernel, 'refresh');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('https://one.example/feed.xml - cached', $display);
        self::assertStringContainsString('https://two.example/feed.xml - cached', $display);
        self::assertStringContainsString('2 feeds: 2 cached, 0 still fresh, 0 failed.', $this->flatten($display));
    }

    public function testAFailedFeedExitsOneAndKeepsTheLegacyWording(): void
    {
        $kernel = $this->kernel([], $this->http([
            $this->feedResponse(),
            new MockResponse('', ['http_code' => 500]),
        ]));
        $this->seed(
            $kernel,
            'news',
            $this->feed('https://ok.example/feed.xml', 'Fine', 1),
            $this->feed('https://broken.example/feed.xml', 'Broken', 2),
        );

        $tester = $this->execute($kernel, 'refresh');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'https://broken.example/feed.xml - NOT cached; check connection',
            $tester->getDisplay(),
        );
        self::assertStringContainsString('HTTP 500', $tester->getDisplay());
    }

    public function testASecondRunFindsTheLockHeldAndExitsZeroWithoutFetching(): void
    {
        // No responses at all: if the command fetched anything, the mock
        // transport would throw, so this also proves nothing was requested.
        $kernel = $this->kernel([], $this->http([]));
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $lockPath = $this->tempPath('data/refresh.lock');
        $held = fopen($lockPath, 'c');
        self::assertIsResource($held);
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB), 'the test must hold the lock first');

        try {
            $tester = $this->execute($kernel, 'refresh');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Another refresh is already running', $this->flatten($tester->getDisplay()));
    }

    public function testTheLockIsReleasedSoTheNextRunProceeds(): void
    {
        $kernel = $this->kernel([], $this->http([$this->feedResponse(), $this->feedResponse()]));
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        self::assertSame(Command::SUCCESS, $this->execute($kernel, 'refresh')->getStatusCode());

        $second = $this->execute($kernel, 'refresh', ['--force' => true]);
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('https://one.example/feed.xml - cached', $second->getDisplay());
    }

    public function testAFeedSubscribedTwiceIsFetchedOnce(): void
    {
        // One response for two subscriptions: a second request would exhaust
        // the mock transport and fail the run.
        $kernel = $this->kernel([], $this->http([$this->feedResponse()]));
        $this->seed($kernel, 'news', $this->feed('https://shared.example/feed.xml', 'Shared', 1));
        $this->seed($kernel, 'tech', $this->feed('https://shared.example/feed.xml', 'Shared', 1));

        $tester = $this->execute($kernel, 'refresh');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 feed: 1 cached', $this->flatten($tester->getDisplay()));
    }

    public function testNotExpiredFeedsAreReportedAsStillFresh(): void
    {
        $kernel = $this->kernel([], $this->http([$this->feedResponse()]));
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml', 'One', 1, 3, 600));

        $this->execute($kernel, 'refresh');
        $tester = $this->execute($kernel, 'refresh');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('https://one.example/feed.xml - not expired yet', $tester->getDisplay());
    }

    public function testJsonReportCarriesTheOutcomeOfEveryFeed(): void
    {
        $kernel = $this->kernel([], $this->http([
            $this->feedResponse(),
            new MockResponse('', ['http_code' => 404]),
        ]));
        $this->seed(
            $kernel,
            'news',
            $this->feed('https://ok.example/feed.xml', 'Fine', 1),
            $this->feed('https://gone.example/feed.xml', 'Gone', 2),
        );

        $tester = $this->execute($kernel, 'refresh', ['--json' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $report = $this->decode($tester->getDisplay());
        self::assertFalse($report['ok']);
        self::assertSame(['total' => 2, 'cached' => 1, 'notExpired' => 0, 'failed' => 1], $report['summary']);
        self::assertIsArray($report['feeds']);
        self::assertCount(2, $report['feeds']);
    }

    public function testAnUnknownCategoryIsAUsageError(): void
    {
        $kernel = $this->kernel([], $this->http([]));
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $tester = $this->execute($kernel, 'refresh', ['--category' => 'nope']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('There is no category named "nope"', $this->flatten($tester->getDisplay()));
    }
}
