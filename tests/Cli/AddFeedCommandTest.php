<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Cli\Command\AddFeedCommand;

#[CoversClass(AddFeedCommand::class)]
final class AddFeedCommandTest extends CliTestCase
{
    public function testFetchesTheFeedAndTakesItsTitle(): void
    {
        $kernel = $this->kernel([], $this->http([$this->feedResponse()]));

        $tester = $this->execute($kernel, 'add', [
            'url' => 'https://one.example/feed.xml',
            '--category' => 'news',
            '--items' => '5',
            '--refresh' => '30',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Fetched Example Feed (2 items', $this->flatten($tester->getDisplay()));

        $category = $kernel->subscriptions()->category('news');
        self::assertCount(1, $category->feeds);
        $feed = $category->feeds[0];
        self::assertSame('https://one.example/feed.xml', $feed->xmlUrl);
        self::assertSame('Example Feed', $feed->title);
        self::assertSame('https://example.com/', $feed->htmlUrl);
        self::assertSame(5, $feed->showedItems);
        self::assertSame(30, $feed->refreshMinutes);
        self::assertSame(1, $feed->position);
    }

    public function testTakesTheNextPositionAndKeepsTheExistingFeeds(): void
    {
        $kernel = $this->kernel([], $this->http([$this->feedResponse()]));
        $this->seed($kernel, 'news', $this->feed('https://old.example/feed.xml', 'Old', 4));

        $tester = $this->execute($kernel, 'add', [
            'url' => 'https://new.example/feed.xml',
            '--category' => 'news',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $feeds = $kernel->subscriptions()->category('news')->feeds;
        self::assertCount(2, $feeds);
        self::assertSame(5, $feeds[1]->position);
    }

    public function testRefusesAUrlAlreadySubscribedInTheSameCategory(): void
    {
        // No responses: the duplicate must be caught before any request.
        $kernel = $this->kernel([], $this->http([]));
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml', 'One', 3));

        $tester = $this->execute($kernel, 'add', [
            'url' => 'https://one.example/feed.xml',
            '--category' => 'news',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'Category "news" is already subscribed to https://one.example/feed.xml (position 3)',
            $this->flatten($tester->getDisplay()),
        );
        self::assertCount(1, $kernel->subscriptions()->category('news')->feeds);
    }

    public function testRefusesAPrivateAddressBeforeFetchingAnything(): void
    {
        $kernel = $this->kernel(['allow_private_hosts' => false], $this->http([]));

        $tester = $this->execute($kernel, 'add', [
            'url' => 'http://127.0.0.1:8080/feed.xml',
            '--category' => 'news',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('loopback', $this->flatten($tester->getDisplay()));
        self::assertFalse($kernel->subscriptions()->has('news'));
    }

    public function testRefusesAPrivateAddressEvenWithNoVerify(): void
    {
        $kernel = $this->kernel(['allow_private_hosts' => false], $this->http([]));

        $tester = $this->execute($kernel, 'add', [
            'url' => 'http://169.254.169.254/latest/meta-data/',
            '--category' => 'news',
            '--no-verify' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('metadata', $this->flatten($tester->getDisplay()));
    }

    public function testNoVerifyStoresTheFeedWithoutAnyRequest(): void
    {
        $kernel = $this->kernel([], $this->http([]));

        $tester = $this->execute($kernel, 'add', [
            'url' => 'https://offline.example/feed.xml',
            '--category' => 'news',
            '--title' => 'Offline',
            '--no-verify' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('Offline', $kernel->subscriptions()->category('news')->feeds[0]->title);
    }

    public function testAFeedThatCannotBeFetchedIsNotStored(): void
    {
        $kernel = $this->kernel([], $this->http([new MockResponse('', ['http_code' => 503])]));

        $tester = $this->execute($kernel, 'add', [
            'url' => 'https://down.example/feed.xml',
            '--category' => 'news',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--no-verify', $tester->getDisplay());
        self::assertCount(0, $kernel->subscriptions()->category('news')->feeds);
    }

    public function testAnUnusableCategoryNameIsAUsageError(): void
    {
        $kernel = $this->kernel([], $this->http([]));

        $tester = $this->execute($kernel, 'add', [
            'url' => 'https://one.example/feed.xml',
            '--category' => '../escape',
        ]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('is not a usable category name', $this->flatten($tester->getDisplay()));
    }
}
