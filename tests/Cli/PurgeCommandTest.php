<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Zfeeder\Cli\Command\PurgeCommand;
use Zfeeder\Kernel;
use Zfeeder\Storage\CacheEntry;

#[CoversClass(PurgeCommand::class)]
final class PurgeCommandTest extends CliTestCase
{
    private const string OLD_URL = 'https://old.example/feed.xml';
    private const string FRESH_URL = 'https://fresh.example/feed.xml';

    public function testOlderThanRemovesOnlyTheEntriesPastTheCutoff(): void
    {
        $kernel = $this->seedCache();

        $tester = $this->execute($kernel, 'purge', ['--older-than' => '7d']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Removed 1 cache entry', $this->flatten($tester->getDisplay()));
        self::assertStringContainsString('1 kept', $this->flatten($tester->getDisplay()));
        self::assertNull($kernel->cache()->get(self::OLD_URL));
        self::assertNotNull($kernel->cache()->get(self::FRESH_URL));
    }

    public function testAShortWindowRemovesBoth(): void
    {
        $kernel = $this->seedCache();

        $tester = $this->execute($kernel, 'purge', ['--older-than' => '30m']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Removed 2 cache entries', $this->flatten($tester->getDisplay()));
        self::assertSame([], $kernel->cache()->urls());
    }

    public function testAllIgnoresTheAgeEntirely(): void
    {
        $kernel = $this->seedCache();

        $tester = $this->execute($kernel, 'purge', ['--all' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $kernel->cache()->urls());
    }

    public function testDryRunReportsTheSameSetButRemovesNothing(): void
    {
        $kernel = $this->seedCache();

        $tester = $this->execute($kernel, 'purge', ['--older-than' => '7d', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would remove 1 cache entry', $this->flatten($tester->getDisplay()));
        self::assertNotNull($kernel->cache()->get(self::OLD_URL));
        self::assertNotNull($kernel->cache()->get(self::FRESH_URL));
    }

    public function testReportsHowMuchDiskWasFreed(): void
    {
        $kernel = $this->seedCache();

        $display = $this->flatten($this->execute($kernel, 'purge', ['--all' => true])->getDisplay());

        // Two bodies of about 400 bytes each, reported as bytes; a bigger cache
        // is reported in KiB or MiB by the same helper.
        self::assertMatchesRegularExpression('/freed \d+ B\./', $display);
    }

    public function testAnEmptyCacheIsNotAnError(): void
    {
        $tester = $this->execute($this->kernel(), 'purge');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Removed 0 cache entries', $this->flatten($tester->getDisplay()));
    }

    #[DataProvider('unreadableAges')]
    public function testAnUnreadableAgeIsAUsageError(string $age): void
    {
        $tester = $this->execute($this->kernel(), 'purge', ['--older-than' => $age]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Write it as 30m, 12h or 7d', $this->flatten($tester->getDisplay()));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableAges(): iterable
    {
        yield 'a week in words' => ['one week'];
        yield 'an unknown unit' => ['7y'];
        yield 'no number' => ['d'];
        yield 'a negative age' => ['-1d'];
    }

    /** One entry a fortnight old, one an hour old, against the frozen clock. */
    private function seedCache(): Kernel
    {
        $kernel = $this->kernel();
        $cache = $kernel->cache();
        $cache->put(new CacheEntry(self::OLD_URL, self::RSS_BODY, new \DateTimeImmutable('2026-09-04T12:00:00+00:00')));
        $cache->put(new CacheEntry(self::FRESH_URL, self::RSS_BODY, new \DateTimeImmutable('2026-09-18T11:00:00+00:00')));

        return $kernel;
    }
}
