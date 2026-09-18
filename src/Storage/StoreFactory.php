<?php

declare(strict_types=1);

namespace Zfeeder\Storage;

use Zfeeder\Config\Config;
use Zfeeder\Exception\StorageException;
use Zfeeder\Storage\Flat\FileCacheStore;
use Zfeeder\Storage\Flat\OpmlSubscriptionStore;
use Zfeeder\Storage\Sqlite\Database;
use Zfeeder\Storage\Sqlite\SqliteCacheStore;
use Zfeeder\Storage\Sqlite\SqliteSubscriptionStore;

/**
 * Turns the `storage` option into the pair of stores the rest of the program
 * uses. Nothing else in zFeeder names a concrete store, so adding a backend
 * means adding a branch here and nowhere else.
 *
 * The SQLite connection is created once and shared by both stores, so a request
 * opens one file handle and one WAL, not two.
 */
final class StoreFactory
{
    public const string FLAT = 'flat';
    public const string SQLITE = 'sqlite';

    private ?Database $database = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function subscriptions(): SubscriptionStoreInterface
    {
        return match ($this->backend()) {
            self::SQLITE => new SqliteSubscriptionStore($this->database()),
            default => new OpmlSubscriptionStore($this->config->categoriesDir()),
        };
    }

    public function cache(): CacheStoreInterface
    {
        return match ($this->backend()) {
            self::SQLITE => new SqliteCacheStore($this->database()),
            default => new FileCacheStore($this->config->cacheDir()),
        };
    }

    /** The shared SQLite connection, migrated on first use. */
    public function database(): Database
    {
        if (!$this->database instanceof Database) {
            $this->database = new Database($this->config->sqlitePath());
            $this->database->migrate();
        }

        return $this->database;
    }

    /** @throws StorageException on a value the schema should have rejected */
    private function backend(): string
    {
        $backend = $this->config->string('storage');
        if ($backend !== self::FLAT && $backend !== self::SQLITE) {
            throw new StorageException('Unknown storage backend: ' . $backend);
        }

        return $backend;
    }
}
