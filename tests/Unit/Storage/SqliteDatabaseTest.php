<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zfeeder\Storage\Sqlite\Database;
use Zfeeder\Tests\Support\StorageTempDirectory;

#[CoversClass(Database::class)]
final class SqliteDatabaseTest extends TestCase
{
    use StorageTempDirectory;

    private ?Database $database = null;

    protected function tearDown(): void
    {
        $this->database?->close();
        $this->database = null;
        $this->removeTempDir();
        parent::tearDown();
    }

    public function testMigrateCreatesEverySchemaObject(): void
    {
        $database = $this->database();
        $database->migrate();

        self::assertSame(
            ['cache', 'categories', 'feeds', 'schema_version'],
            $this->tables($database),
        );
    }

    public function testMigrateRecordsTheSchemaVersion(): void
    {
        $database = $this->database();

        self::assertSame(0, $database->version());
        $database->migrate();
        self::assertSame(1, $database->version());
    }

    public function testMigrateIsIdempotent(): void
    {
        $database = $this->database();
        $database->migrate();
        $database->migrate();
        $database->migrate();

        $statement = $database->pdo()->query('SELECT COUNT(*) AS n FROM schema_version');
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['n']);
    }

    public function testTheDirectoryIsCreatedForANestedDatabasePath(): void
    {
        $this->database = new Database($this->tempPath('deep/nested/zfeeder.sqlite'));
        $this->database->migrate();

        self::assertFileExists($this->tempPath('deep/nested/zfeeder.sqlite'));
    }

    public function testForeignKeysAndWalAreOn(): void
    {
        $database = $this->database();
        $database->migrate();

        self::assertSame(1, $this->pragma($database, 'foreign_keys'));
        $statement = $database->pdo()->query('PRAGMA journal_mode');
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsArray($row);
        self::assertSame('wal', strtolower((string) $row['journal_mode']));
    }

    public function testDeletingACategoryCascadesAtTheDatabaseLevel(): void
    {
        $database = $this->database();
        $database->migrate();
        $pdo = $database->pdo();
        $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute(['news']);
        $pdo->prepare('INSERT INTO feeds (category, position, xml_url) VALUES (?, ?, ?)')
            ->execute(['news', 1, 'http://a.test/f']);

        $pdo->prepare('DELETE FROM categories WHERE name = ?')->execute(['news']);

        $statement = $pdo->query('SELECT COUNT(*) AS n FROM feeds');
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['n']);
    }

    public function testAnInMemoryDatabaseMigratesToo(): void
    {
        $this->database = new Database(Database::MEMORY);
        $this->database->migrate();

        self::assertSame(1, $this->database->version());
    }

    private function database(): Database
    {
        if (!$this->database instanceof Database) {
            $this->database = new Database($this->tempPath('zfeeder.sqlite'));
        }

        return $this->database;
    }

    /** @return list<string> */
    private function tables(Database $database): array
    {
        $statement = $database->pdo()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );
        self::assertNotFalse($statement);

        $names = [];
        /** @var mixed $row */
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    private function pragma(Database $database, string $name): int
    {
        $statement = $database->pdo()->query('PRAGMA ' . $name);
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return (int) ($row[$name] ?? 0);
    }
}
