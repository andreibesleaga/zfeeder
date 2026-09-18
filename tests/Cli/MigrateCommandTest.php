<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Console\Command\Command;
use Zfeeder\Cli\Command\MigrateCommand;
use Zfeeder\Storage\CacheEntry;

#[CoversClass(MigrateCommand::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class MigrateCommandTest extends CliTestCase
{
    public function testFlatToSqliteAndBackRoundTripsARealCategorySet(): void
    {
        $flat = $this->kernel();
        $this->seed(
            $flat,
            'news',
            $this->feed('https://one.example/feed.xml', 'One', 1, 5, 30),
            $this->feed('https://two.example/feed.xml', 'Two', 2, 1, 240),
        );
        $this->seed($flat, 'tech', $this->feed('https://three.example/feed.xml', 'Three', 1));
        $flat->cache()->put(new CacheEntry(
            'https://one.example/feed.xml',
            self::RSS_BODY,
            new \DateTimeImmutable(self::NOW),
            '"etag-1"',
        ));

        $before = [
            'news' => $flat->subscriptions()->exportOpml('news'),
            'tech' => $flat->subscriptions()->exportOpml('tech'),
        ];

        $out = $this->execute($flat, 'migrate', ['--to' => 'sqlite', '--verify' => true]);
        self::assertSame(Command::SUCCESS, $out->getStatusCode());
        self::assertStringContainsString(
            'Copied 2 categories, 3 subscriptions and 1 cache entry to sqlite.',
            $this->flatten($out->getDisplay()),
        );

        // Throw the flat files away, so what comes back can only come from SQLite.
        self::removeTree($this->tempPath('data/categories'));
        self::removeTree($this->tempPath('data/cache'));

        $sqlite = $this->kernel(['storage' => 'sqlite']);
        $back = $this->execute($sqlite, 'migrate', ['--to' => 'flat']);
        self::assertSame(Command::SUCCESS, $back->getStatusCode());

        $restored = $this->kernel();
        self::assertSame(['news', 'tech'], $restored->subscriptions()->categories());
        self::assertSame($before['news'], $restored->subscriptions()->exportOpml('news'));
        self::assertSame($before['tech'], $restored->subscriptions()->exportOpml('tech'));

        $entry = $restored->cache()->get('https://one.example/feed.xml');
        self::assertNotNull($entry);
        self::assertSame(self::RSS_BODY, $entry->body);
        self::assertSame('"etag-1"', $entry->etag);
    }

    public function testEnsureSchemaCreatesTheDatabaseAndIsSafeToRepeat(): void
    {
        $kernel = $this->kernel(['storage' => 'sqlite']);

        $first = $this->execute($kernel, 'migrate', ['--ensure-schema' => true]);
        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        self::assertStringContainsString('SQLite schema is at version', $first->getDisplay());
        self::assertFileExists($this->tempPath('data/zfeeder.sqlite'));

        $second = $this->execute($this->kernel(['storage' => 'sqlite']), 'migrate', ['--ensure-schema' => true]);
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
    }

    public function testEnsureSchemaDoesNothingAndSucceedsOnTheFlatBackend(): void
    {
        $tester = $this->execute($this->kernel(), 'migrate', ['--ensure-schema' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('there is no schema to create', $this->flatten($tester->getDisplay()));
        self::assertFileDoesNotExist($this->tempPath('data/zfeeder.sqlite'));
    }

    public function testDryRunWritesNothing(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml', 'One', 1));

        $tester = $this->execute($kernel, 'migrate', ['--to' => 'sqlite', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would copy 1 category, 1 subscription', $this->flatten($tester->getDisplay()));
        self::assertFileDoesNotExist($this->tempPath('data/zfeeder.sqlite'));
    }

    public function testMissingDestinationIsAUsageError(): void
    {
        $tester = $this->execute($this->kernel(), 'migrate');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--to=flat or --to=sqlite', $this->flatten($tester->getDisplay()));
    }

    public function testAnUnknownDestinationIsAUsageError(): void
    {
        $tester = $this->execute($this->kernel(), 'migrate', ['--to' => 'mysql']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Unknown storage backend "mysql"', $this->flatten($tester->getDisplay()));
    }

    public function testMigratingToTheBackendAlreadyInUseIsANoOp(): void
    {
        $tester = $this->execute($this->kernel(), 'migrate', ['--to' => 'flat']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('there is nothing to migrate', $this->flatten($tester->getDisplay()));
    }
}
