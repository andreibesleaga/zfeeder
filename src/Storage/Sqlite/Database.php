<?php

declare(strict_types=1);

namespace Zfeeder\Storage\Sqlite;

use Zfeeder\Exception\StorageException;
use Zfeeder\Storage\AtomicFile;

/**
 * The one SQLite connection, and the migrations that shape it.
 *
 * Connection settings are not negotiable and are applied here so that no store
 * can forget one: exceptions instead of silent false, `foreign_keys=ON` so that
 * deleting a category really removes its feeds, and WAL so that a reader
 * rendering a page is never blocked by the cron job writing the cache.
 *
 * Migrations are plain `.sql` files named `NNN_description.sql`. The applied
 * versions are recorded in `schema_version`, each file runs inside a
 * transaction, and a file is never rewritten once released — a new number is
 * added instead.
 */
final class Database
{
    public const string MEMORY = ':memory:';

    private ?\PDO $pdo = null;

    private bool $migrated = false;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @throws StorageException */
    public function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        if ($this->path !== self::MEMORY) {
            AtomicFile::ensureDirectory(\dirname($this->path));
        }

        try {
            $pdo = new \PDO('sqlite:' . $this->path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // An in-memory database has no write-ahead log; asking for one is
            // harmless (SQLite answers "memory") so the call is unconditional.
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } catch (\PDOException $e) {
            throw new StorageException('Cannot open the SQLite database ' . $this->path . ': ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo = $pdo;
    }

    /** Applies every migration not yet recorded. Safe to call on every boot. */
    public function migrate(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');
        $current = $this->version();

        foreach ($this->migrations() as $version => $file) {
            if ($version <= $current) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new StorageException('Cannot read migration: ' . $file);
            }

            $pdo->beginTransaction();

            try {
                foreach ($this->statements($sql) as $statement) {
                    $pdo->exec($statement);
                }
                $record = $pdo->prepare('INSERT INTO schema_version (version) VALUES (?)');
                $record->execute([$version]);
                $pdo->commit();
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw new StorageException(sprintf('Migration %s failed: %s', basename($file), $e->getMessage()), 0, $e);
            }
        }

        $this->migrated = true;
    }

    /** Migrates once per process; the stores call this instead of guessing. */
    public function ensureMigrated(): void
    {
        if (!$this->migrated) {
            $this->migrate();
        }
    }

    /** @return int the highest applied migration, 0 for an empty database */
    public function version(): int
    {
        $pdo = $this->pdo();
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_version'");
        if ($exists === false || $exists->fetch() === false) {
            return 0;
        }
        $result = $pdo->query('SELECT COALESCE(MAX(version), 0) AS version FROM schema_version');
        $row = $result === false ? false : $result->fetch();

        return is_array($row) ? (int) ($row['version'] ?? 0) : 0;
    }

    /** Closes the connection; tests use it before deleting the database file. */
    public function close(): void
    {
        $this->pdo = null;
        $this->migrated = false;
    }

    /**
     * @return array<int, string> version => absolute path, lowest first
     *
     * @throws StorageException
     */
    private function migrations(): array
    {
        $directory = __DIR__ . '/Migrations';
        $entries = scandir($directory);
        if ($entries === false) {
            throw new StorageException('Cannot list the migrations directory: ' . $directory);
        }

        $files = [];
        foreach ($entries as $entry) {
            $matches = [];
            if (preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $entry, $matches) === 1) {
                $files[(int) $matches[1]] = $directory . '/' . $entry;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Splits a migration into single statements.
     *
     * PDO's SQLite driver executes only the first statement of a multi-statement
     * `exec()`, so the file is split here. Migrations are written without
     * semicolons inside string literals precisely so that this split is safe.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $statements = [];
        foreach (explode(';', $sql) as $chunk) {
            $lines = [];
            foreach (explode("\n", $chunk) as $line) {
                if (!str_starts_with(ltrim($line), '--')) {
                    $lines[] = $line;
                }
            }
            $statement = trim(implode("\n", $lines));
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
