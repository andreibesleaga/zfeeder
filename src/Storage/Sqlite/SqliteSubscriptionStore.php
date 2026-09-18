<?php

declare(strict_types=1);

namespace Zfeeder\Storage\Sqlite;

use Zfeeder\Exception\StorageException;
use Zfeeder\Storage\AbstractSubscriptionStore;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Subscription\Opml\Reader;
use Zfeeder\Subscription\Opml\Writer;

/**
 * Subscriptions in SQLite. Same contract, same OPML import and export as the
 * flat store; the difference is one file instead of many and a real transaction
 * around a save.
 *
 * Every value reaches the database through a bound parameter. There is no
 * string interpolation of user data anywhere in this class, which is the whole
 * reason the 2004 code's "escape it and hope" pattern is gone.
 */
final class SqliteSubscriptionStore extends AbstractSubscriptionStore
{
    public function __construct(
        private readonly Database $database,
        Reader $reader = new Reader(),
        Writer $writer = new Writer(),
    ) {
        parent::__construct($reader, $writer);
    }

    /** @return list<string> */
    public function categories(): array
    {
        $statement = $this->pdo()->query('SELECT name FROM categories ORDER BY name');
        if ($statement === false) {
            throw new StorageException('Cannot list categories.');
        }

        $names = [];
        /** @var mixed $row */
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    public function has(string $name): bool
    {
        if (!Category::isValidName($name)) {
            return false;
        }
        $statement = $this->pdo()->prepare('SELECT 1 FROM categories WHERE name = ?');
        $statement->execute([$name]);

        return $statement->fetch() !== false;
    }

    public function category(string $name): Category
    {
        Category::assertValidName($name);

        $head = $this->pdo()->prepare('SELECT date_modified, owner_name, owner_email FROM categories WHERE name = ?');
        $head->execute([$name]);
        $row = $head->fetch();
        if (!is_array($row)) {
            throw new StorageException('Unknown category: ' . $name);
        }

        $feeds = $this->pdo()->prepare(
            'SELECT position, title, xml_url, html_url, description, language, refresh_minutes, showed_items, subscribed'
            . ' FROM feeds WHERE category = ? ORDER BY position, id',
        );
        $feeds->execute([$name]);

        $list = [];
        /** @var mixed $feedRow */
        foreach ($feeds->fetchAll() as $feedRow) {
            if (is_array($feedRow)) {
                $list[] = $this->toFeed($feedRow);
            }
        }

        return new Category(
            $name,
            $list,
            $this->toDate($row['date_modified'] ?? null),
            is_string($row['owner_name'] ?? null) ? $row['owner_name'] : '',
            is_string($row['owner_email'] ?? null) ? $row['owner_email'] : '',
        );
    }

    /** One transaction: the category row, then its feeds replaced wholesale. */
    public function saveCategory(Category $category): void
    {
        Category::assertValidName($category->name);
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $upsert = $pdo->prepare(
                'INSERT INTO categories (name, date_modified, owner_name, owner_email) VALUES (?, ?, ?, ?)'
                . ' ON CONFLICT(name) DO UPDATE SET date_modified = excluded.date_modified,'
                . ' owner_name = excluded.owner_name, owner_email = excluded.owner_email',
            );
            $upsert->execute([
                $category->name,
                $category->dateModified?->getTimestamp(),
                $category->ownerName,
                $category->ownerEmail,
            ]);

            $delete = $pdo->prepare('DELETE FROM feeds WHERE category = ?');
            $delete->execute([$category->name]);

            $insert = $pdo->prepare(
                'INSERT INTO feeds (category, position, title, xml_url, html_url, description, language,'
                . ' refresh_minutes, showed_items, subscribed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            foreach ($category->feeds as $feed) {
                $insert->execute([
                    $category->name,
                    $feed->position,
                    $feed->title,
                    $feed->xmlUrl,
                    $feed->htmlUrl,
                    $feed->description,
                    $feed->language,
                    $feed->refreshMinutes,
                    $feed->showedItems,
                    $feed->subscribed ? 1 : 0,
                ]);
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new StorageException('Cannot save category ' . $category->name . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function createCategory(string $name): void
    {
        Category::assertValidName($name);
        if ($this->has($name)) {
            throw new StorageException('Category already exists: ' . $name);
        }
        $this->saveCategory(new Category($name));
    }

    public function deleteCategory(string $name): void
    {
        Category::assertValidName($name);
        // ON DELETE CASCADE removes the feeds, which is why foreign_keys is on.
        $statement = $this->pdo()->prepare('DELETE FROM categories WHERE name = ?');
        $statement->execute([$name]);
        if ($statement->rowCount() === 0) {
            throw new StorageException('Unknown category: ' . $name);
        }
    }

    private function pdo(): \PDO
    {
        $this->database->ensureMigrated();

        return $this->database->pdo();
    }

    /** @param array<array-key, mixed> $row */
    private function toFeed(array $row): Feed
    {
        return new Feed(
            is_string($row['xml_url'] ?? null) ? $row['xml_url'] : '',
            is_string($row['title'] ?? null) ? $row['title'] : '',
            is_string($row['description'] ?? null) ? $row['description'] : '',
            is_string($row['html_url'] ?? null) ? $row['html_url'] : '',
            (int) ($row['position'] ?? 1),
            (int) ($row['refresh_minutes'] ?? 60),
            (int) ($row['showed_items'] ?? 0),
            (int) ($row['subscribed'] ?? 0) === 1,
            is_string($row['language'] ?? null) ? $row['language'] : '',
        );
    }

    private function toDate(mixed $timestamp): ?\DateTimeImmutable
    {
        if (!is_int($timestamp) && !is_string($timestamp)) {
            return null;
        }
        if (!is_numeric($timestamp)) {
            return null;
        }

        return (new \DateTimeImmutable('@' . (string) (int) $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }
}
