<?php

declare(strict_types=1);

namespace Zfeeder\Storage;

use Zfeeder\Subscription\Category;

/**
 * Where subscriptions live. Two implementations ship: flat OPML files and SQLite.
 * Both behave identically; one abstract test case is run against each.
 */
interface SubscriptionStoreInterface
{
    /** @return list<string> category names, sorted */
    public function categories(): array;

    public function has(string $name): bool;

    /** @throws \Zfeeder\Exception\StorageException when the category does not exist */
    public function category(string $name): Category;

    public function saveCategory(Category $category): void;

    public function createCategory(string $name): void;

    public function deleteCategory(string $name): void;

    /** OPML 2.0 document for this category. */
    public function exportOpml(string $name): string;

    /** @return int number of subscriptions imported */
    public function importOpml(string $name, string $opml, bool $replace): int;
}
