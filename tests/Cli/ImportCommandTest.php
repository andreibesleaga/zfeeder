<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Cli\Command\ImportCommand;

#[CoversClass(ImportCommand::class)]
final class ImportCommandTest extends CliTestCase
{
    private const string OPML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <opml version="2.0">
          <head><title>imported</title></head>
          <body>
            <outline type="rss" position="1" title="Alpha" xmlUrl="https://alpha.example/feed.xml" refreshTime="60" showedItems="3" isSubscribed="yes" />
            <outline type="rss" position="2" title="Beta" xmlUrl="https://beta.example/feed.xml" refreshTime="60" showedItems="3" isSubscribed="yes" />
          </body>
        </opml>
        XML;

    public function testImportsFromAFileAndAppends(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://existing.example/feed.xml', 'Existing', 1));
        $path = $this->tempPath('subscriptions.opml');
        file_put_contents($path, self::OPML);

        $tester = $this->execute($kernel, 'import', ['source' => $path, '--category' => 'news']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Imported 2 subscriptions into "news" (1 before, 3 now)', $this->flatten($tester->getDisplay()));
        self::assertCount(3, $kernel->subscriptions()->category('news')->feeds);
    }

    public function testReplaceThrowsAwayTheOldList(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://existing.example/feed.xml', 'Existing', 1));
        $path = $this->tempPath('subscriptions.opml');
        file_put_contents($path, self::OPML);

        $tester = $this->execute($kernel, 'import', ['source' => $path, '--category' => 'news', '--replace' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $urls = array_map(static fn ($feed): string => $feed->xmlUrl, $kernel->subscriptions()->category('news')->feeds);
        self::assertSame(['https://alpha.example/feed.xml', 'https://beta.example/feed.xml'], $urls);
    }

    public function testImportsFromAUrlThroughTheHttpClient(): void
    {
        $kernel = $this->kernel([], $this->http([
            new MockResponse(self::OPML, ['response_headers' => ['content-type' => 'text/x-opml']]),
        ]));

        $tester = $this->execute($kernel, 'import', [
            'source' => 'https://example.com/feeds.opml',
            '--category' => 'news',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(2, $kernel->subscriptions()->category('news')->feeds);
    }

    public function testARemoteSourceOnAPrivateAddressIsRefused(): void
    {
        $kernel = $this->kernel(['allow_private_hosts' => false], $this->http([]));

        $tester = $this->execute($kernel, 'import', [
            'source' => 'http://127.0.0.1/feeds.opml',
            '--category' => 'news',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('loopback', $this->flatten($tester->getDisplay()));
    }

    public function testAMissingFileIsAFailure(): void
    {
        $tester = $this->execute($this->kernel(), 'import', [
            'source' => $this->tempPath('nowhere.opml'),
            '--category' => 'news',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no such file', $this->flatten($tester->getDisplay()));
    }

    public function testAMalformedDocumentIsReportedAndNothingIsWritten(): void
    {
        $kernel = $this->kernel();
        $path = $this->tempPath('broken.opml');
        file_put_contents($path, '<opml version="2.0"><body><outline');

        $tester = $this->execute($kernel, 'import', ['source' => $path, '--category' => 'news']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Cannot parse the subscription list', $this->flatten($tester->getDisplay()));
    }

    public function testAnUnusableCategoryNameIsAUsageError(): void
    {
        $tester = $this->execute($this->kernel(), 'import', [
            'source' => $this->tempPath('anything.opml'),
            '--category' => 'Not A Name',
        ]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
