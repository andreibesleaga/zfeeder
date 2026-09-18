<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use Zfeeder\Storage\AbstractSubscriptionStore;
use Zfeeder\Storage\AtomicFile;
use Zfeeder\Storage\Flat\OpmlSubscriptionStore;
use Zfeeder\Storage\PathGuard;
use Zfeeder\Storage\SubscriptionStoreInterface;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Tests\Support\SubscriptionStoreContractTestCase;

#[CoversClass(OpmlSubscriptionStore::class)]
#[CoversClass(AbstractSubscriptionStore::class)]
#[CoversClass(AtomicFile::class)]
#[CoversClass(PathGuard::class)]
final class FlatSubscriptionStoreTest extends SubscriptionStoreContractTestCase
{
    protected function createStore(): SubscriptionStoreInterface
    {
        return new OpmlSubscriptionStore($this->tempPath('categories'));
    }

    public function testCategoryFilesAreNamedAfterTheCategory(): void
    {
        $this->store->createCategory('news');

        self::assertFileExists($this->tempPath('categories/news.opml'));
    }

    public function testOnlyWellNamedOpmlFilesAreListed(): void
    {
        $directory = $this->tempPath('categories');
        AtomicFile::ensureDirectory($directory);
        file_put_contents($directory . '/news.opml', '<opml version="2.0"><body/></opml>');
        file_put_contents($directory . '/News.opml', '<opml version="2.0"><body/></opml>');
        file_put_contents($directory . '/news.opml.bak', 'x');
        file_put_contents($directory . '/news.opml.lock', '');
        file_put_contents($directory . '/notes.txt', 'x');
        mkdir($directory . '/subdir.opml');

        self::assertSame(['news'], $this->store->categories());
    }

    public function testASaveLeavesNoTemporaryFilesBehind(): void
    {
        $this->store->createCategory('news');
        $this->store->saveCategory(new Category('news', [new Feed('http://a.test/f', 'A')]));

        $leftovers = glob($this->tempPath('categories/*.tmp.*'));

        self::assertSame([], $leftovers);
    }

    public function testCategoryFilesAreNotWorldReadable(): void
    {
        $this->store->createCategory('news');

        $mode = fileperms($this->tempPath('categories/news.opml'));
        self::assertNotFalse($mode);
        self::assertSame(0, $mode & 0o007, 'The subscription file must not be world readable.');
    }

    public function testAnExistingLegacyDirectoryIsReadInPlace(): void
    {
        $directory = $this->tempPath('categories');
        AtomicFile::ensureDirectory($directory);
        $legacy = \dirname(__DIR__, 3) . '/legacy/zfeeder-1.6/newsfeeds/categories/technology.opml';
        copy($legacy, $directory . '/technology.opml');

        $category = $this->store->category('technology');

        self::assertSame('technology', $category->name);
        self::assertCount(45, $category->feeds);
        self::assertNotNull($category->dateModified);
    }

    public function testASymlinkedCategoryPointingOutsideIsRefused(): void
    {
        $directory = $this->tempPath('categories');
        AtomicFile::ensureDirectory($directory);
        $outside = $this->tempPath('outside');
        mkdir($outside);
        symlink($outside, $directory . '/escape');

        // The link itself cannot be addressed as a category file, and the
        // containment check refuses anything resolving outside the directory.
        self::assertFalse(PathGuard::isInside($directory, $directory . '/escape/news.opml'));
        self::assertSame([], $this->store->categories());
    }
}
