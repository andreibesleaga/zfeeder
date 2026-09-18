<?php

declare(strict_types=1);

namespace Zfeeder\Storage\Flat;

use Zfeeder\Exception\StorageException;
use Zfeeder\Storage\AbstractSubscriptionStore;
use Zfeeder\Storage\AtomicFile;
use Zfeeder\Storage\PathGuard;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Opml\Reader;
use Zfeeder\Subscription\Opml\Writer;

/**
 * Subscriptions as OPML files, one per category — the 2004 layout, unchanged,
 * so a 1.6 `newsfeeds/categories/` directory can be copied in as it stands.
 *
 * Only `<name>.opml` files whose name matches the category pattern are visible,
 * which keeps backups, editor swap files and the `.lock`/`.tmp` siblings of an
 * in-flight write out of the category list.
 */
final class OpmlSubscriptionStore extends AbstractSubscriptionStore
{
    private const string FILE_PATTERN = '/^([a-z0-9_-]{1,40})\.opml$/';

    public function __construct(
        private readonly string $directory,
        Reader $reader = new Reader(),
        Writer $writer = new Writer(),
    ) {
        parent::__construct($reader, $writer);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return list<string> */
    public function categories(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $entries = scandir($this->directory);
        if ($entries === false) {
            throw new StorageException('Cannot list the categories directory: ' . $this->directory);
        }

        $names = [];
        foreach ($entries as $entry) {
            $matches = [];
            if (preg_match(self::FILE_PATTERN, $entry, $matches) === 1 && is_file($this->directory . '/' . $entry)) {
                $names[] = $matches[1];
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    public function has(string $name): bool
    {
        return Category::isValidName($name) && is_file($this->path($name));
    }

    public function category(string $name): Category
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            throw new StorageException('Unknown category: ' . $name);
        }

        $category = $this->reader->readCategory($name, AtomicFile::read($path));

        return new Category(
            $category->name,
            $this->sortByPosition($category->feeds),
            $category->dateModified,
            $category->ownerName,
            $category->ownerEmail,
        );
    }

    public function saveCategory(Category $category): void
    {
        AtomicFile::write($this->path($category->name), $this->writer->write($category));
    }

    public function createCategory(string $name): void
    {
        if ($this->has($name)) {
            throw new StorageException('Category already exists: ' . $name);
        }
        $this->saveCategory(new Category($name));
    }

    public function deleteCategory(string $name): void
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            throw new StorageException('Unknown category: ' . $name);
        }
        if (!unlink($path)) {
            throw new StorageException('Cannot delete category: ' . $name);
        }
        AtomicFile::delete($path . '.lock');
    }

    /**
     * The pattern makes `..` and `/` impossible; `PathGuard` then proves the
     * resolved file really is inside the categories directory, which also
     * catches a symlinked category pointing somewhere else.
     */
    private function path(string $name): string
    {
        Category::assertValidName($name);
        $path = rtrim($this->directory, '/') . '/' . $name . '.opml';
        PathGuard::assertInside($this->directory, $path);

        return $path;
    }
}
