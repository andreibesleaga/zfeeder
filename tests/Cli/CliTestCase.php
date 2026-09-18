<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zfeeder\Cli\Application;
use Zfeeder\Config\Config;
use Zfeeder\Kernel;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Tests\Support\StorageTempDirectory;

/**
 * What every CLI test needs: a kernel pointed at a scratch data directory, a
 * fixed clock, a transport that never opens a socket, and a tester built the
 * way `bin/zfeeder` builds the real thing.
 *
 * The kernel is assembled through {@see Application}, not by handing the tester
 * a bare command, so the tests exercise the wiring the binary uses — including
 * the helper set, which is what makes the hidden password prompt testable.
 */
abstract class CliTestCase extends TestCase
{
    use StorageTempDirectory;

    /** The frozen clock. Nothing in these tests depends on the real time. */
    protected const string NOW = '2026-09-18T12:00:00+00:00';

    protected const string RSS_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <rss version="2.0"><channel>
          <title>Example Feed</title>
          <link>https://example.com/</link>
          <description>An example feed.</description>
          <item><title>First</title><link>https://example.com/1</link><description>One.</description></item>
          <item><title>Second</title><link>https://example.com/2</link><description>Two.</description></item>
        </channel></rss>
        XML;

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /**
     * @param array<string, string|int|bool> $overrides configuration keys, as in the schema
     * @param string|null                    $now       overrides the frozen clock
     */
    protected function kernel(array $overrides = [], ?HttpClientInterface $http = null, ?string $now = null): Kernel
    {
        // Private hosts are allowed by default so that a test feed URL is not
        // resolved against real DNS; the tests that assert the guard refuses
        // one turn this back off explicitly.
        $config = Config::forTesting(
            array_merge(['allow_private_hosts' => true], $overrides),
            $this->tempPath('data'),
        );

        $instant = $now ?? self::NOW;
        $kernel = new Kernel($config, static fn (): \DateTimeImmutable => new \DateTimeImmutable($instant));
        $kernel->withLogger(new NullLogger());

        if ($http instanceof HttpClientInterface) {
            $kernel->withHttpClient($http);
        }

        return $kernel;
    }

    /** @param list<MockResponse> $responses handed out in request order */
    protected function http(array $responses): MockHttpClient
    {
        return new MockHttpClient($responses);
    }

    protected function feedResponse(string $body = self::RSS_BODY): MockResponse
    {
        return new MockResponse($body, ['response_headers' => ['content-type' => 'application/rss+xml']]);
    }

    /**
     * Runs a command the way the binary would.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options passed through to CommandTester
     */
    protected function execute(Kernel $kernel, string $name, array $input = [], array $options = []): CommandTester
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $tester = new CommandTester($application->find($name));
        $tester->execute($input, $options);

        return $tester;
    }

    /** Writes a category straight through the store, bypassing the commands. */
    protected function seed(Kernel $kernel, string $name, Feed ...$feeds): void
    {
        $kernel->subscriptions()->saveCategory(new Category(
            $name,
            array_values($feeds),
            new \DateTimeImmutable(self::NOW),
        ));
    }

    protected function feed(string $url, string $title = 'Example', int $position = 1, int $items = 3, int $refresh = 60): Feed
    {
        return new Feed($url, $title, '', '', $position, $refresh, $items, true);
    }

    /** The display with runs of spaces collapsed, so table assertions are readable. */
    protected function flatten(string $display): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $display));
    }

    /** @return array<string, mixed> */
    protected function decode(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
