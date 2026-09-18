<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Exception\ConfigException;
use Zfeeder\Exception\StorageException;
use Zfeeder\Exception\TemplateException;
use Zfeeder\Http\DemoController;
use Zfeeder\Render\TemplateLocator;
use Zfeeder\Storage\PathGuard;
use Zfeeder\Subscription\Category;

/**
 * S7 — a category, a template or a template set named by the client is matched
 * against a name pattern and then proved, with `realpath()`, to resolve inside
 * the directory it belongs to. Traversal, absolute paths, null bytes and
 * symlinks pointing outwards are all refused, and the HTTP layer never answers
 * 200 with a file from elsewhere.
 *
 * Closes 1.6 defects L9 and L10: `newsfeeds/zfeeder.php:63-64` concatenated
 * `$_GET['zfcategory']` into a filesystem path and `:95-96` did the same with
 * `$_GET['zftemplate']`, with no validation of either.
 *
 * The control lives in `src/Subscription/Category.php` (`NAME_PATTERN`),
 * `src/Render/TemplateLocator.php` (pattern, known sets, `realpath()`
 * containment) and `src/Storage/PathGuard.php` (the containment check the
 * stores call for every path they build).
 *
 * Known gap, recorded rather than asserted: a traversal spec on
 * `/demos/template/{set}/{name}` is answered 500 rather than a 4xx, because
 * `Http\ErrorHandler::statusFor()` maps `TemplateException` to 500. Nothing
 * leaks — the body is the standard error page — but a client error is reported
 * as a server one.
 */
final class S07PathTraversalTest extends SecurityTestCase
{
    /** @return iterable<string, array{string}> names that must never become a path */
    public static function hostileNames(): iterable
    {
        yield 'parent directory' => ['../secret'];
        yield 'deep traversal' => ['../../../../etc/passwd'];
        yield 'encoded slash' => ['..%2f..%2fetc%2fpasswd'];
        yield 'encoded backslash' => ['..%5c..%5cwindows'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'absolute windows path' => ['C:\\windows\\win.ini'];
        yield 'null byte' => ["zfeeder\0.opml"];
        yield 'null byte mid name' => ["zf\0eeder"];
        yield 'doubled dots and slashes' => ['....//....//etc/passwd'];
        yield 'trailing dot slash' => ['./././secret'];
        yield 'bare dots' => ['..'];
        yield 'single dot' => ['.'];
        yield 'empty' => [''];
        yield 'newline' => ["zfeeder\n../etc"];
        yield 'space and slash' => [' /etc/passwd'];
    }

    // ---- the name patterns ------------------------------------------------

    #[DataProvider('hostileNames')]
    public function testAHostileCategoryNameIsNotAValidName(string $name): void
    {
        self::assertFalse(Category::isValidName($name), $name . ' passed the category name pattern');
    }

    public function testAnOrdinaryCategoryNameStillPasses(): void
    {
        // Without this the pattern could be `/^$/` and every test above would
        // still be green.
        foreach (['zfeeder', 'news', 'my-feeds', 'my_feeds', 'a', str_repeat('a', 40)] as $name) {
            self::assertTrue(Category::isValidName($name), $name . ' was refused');
        }
        self::assertFalse(Category::isValidName(str_repeat('a', 41)), 'the length limit is not enforced');
    }

    // ---- the flat store ---------------------------------------------------

    #[DataProvider('hostileNames')]
    public function testTheStoreRefusesAHostileCategoryName(string $name): void
    {
        $store = $this->kernel->subscriptions();

        self::assertFalse($store->has($name), $name . ' was reported as an existing category');

        $this->expectException(ConfigException::class);
        $store->category($name);
    }

    #[DataProvider('hostileNames')]
    public function testTheStoreRefusesToSaveUnderAHostileCategoryName(string $name): void
    {
        $this->expectException(ConfigException::class);
        $this->kernel->subscriptions()->createCategory($name);
    }

    public function testACategoryThatIsASymlinkPointingOutOfTheDirectoryIsRefused(): void
    {
        $outside = $this->tempPath('outside.opml');
        file_put_contents($outside, '<opml version="1.0"><head/><body/></opml>');

        $categories = $this->config->categoriesDir();
        self::assertDirectoryExists($categories);

        // The name is perfectly valid; it is the resolved path that escapes.
        // This is the case the name pattern cannot catch and `realpath()` can.
        self::assertTrue(symlink($outside, $categories . '/escape.opml'), 'the test needs symlink support');

        try {
            $this->kernel->subscriptions()->has('escape');
            self::fail('a symlinked category pointing outside the directory was accepted');
        } catch (StorageException $e) {
            self::assertStringContainsString('outside', $e->getMessage());
        }
    }

    public function testAnOrdinaryCategoryIsStillReadableThroughTheSameCode(): void
    {
        self::assertTrue($this->kernel->subscriptions()->has(self::CATEGORY));
        self::assertSame(self::CATEGORY, $this->kernel->subscriptions()->category(self::CATEGORY)->name);
    }

    // ---- the template locator ---------------------------------------------

    #[DataProvider('hostileNames')]
    public function testTheTemplateLocatorRefusesAHostileName(string $name): void
    {
        $locator = new TemplateLocator($this->config);

        $this->expectException(TemplateException::class);
        $locator->resolve($name);
    }

    #[DataProvider('hostileNames')]
    public function testTheTemplateLocatorRefusesAHostileSet(string $set): void
    {
        $locator = new TemplateLocator($this->config);

        $this->expectException(TemplateException::class);
        $locator->resolve($set . '/bluelogos');
    }

    public function testTheTemplateLocatorRefusesAnUnknownSetAndATemplateOutsideIt(): void
    {
        $locator = new TemplateLocator($this->config);

        foreach (['admin/layout', 'classic/../../composer', 'modern/../classic/rij', 'classic/rij/../../x'] as $spec) {
            try {
                $locator->resolve($spec);
                self::fail($spec . ' resolved to a file');
            } catch (TemplateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testARealTemplateStillResolvesInsideTheTemplatesDirectory(): void
    {
        $locator = new TemplateLocator($this->config);
        $resolved = $locator->resolve('classic/bluelogos');

        $root = realpath($this->config->templatesDir());
        self::assertIsString($root);
        self::assertStringStartsWith($root . DIRECTORY_SEPARATOR, $resolved['path']);
        self::assertFileExists($resolved['path']);
    }

    // ---- the containment check itself --------------------------------------

    public function testThePathGuardRefusesAnythingThatResolvesOutsideTheBase(): void
    {
        $base = $this->tempPath('base');
        mkdir($base, 0o750, true);

        foreach (['/../escape', '/sub/../../escape', '/./../escape'] as $suffix) {
            self::assertFalse(PathGuard::isInside($base, $base . $suffix), $suffix . ' was judged to be inside');
        }

        self::assertTrue(PathGuard::isInside($base, $base . '/inside.opml'));
        self::assertTrue(PathGuard::isInside($base, $base . '/sub/inside.opml'));
        self::assertTrue(PathGuard::isInside($base, $base));

        // A sibling whose name merely starts with the base name is outside.
        self::assertFalse(PathGuard::isInside($base, $base . '-other/inside.opml'));
    }

    public function testThePathGuardFollowsASymlinkBeforeJudgingIt(): void
    {
        $base = $this->tempPath('guarded');
        mkdir($base, 0o750, true);
        $outside = $this->tempPath('target.txt');
        file_put_contents($outside, 'secret');
        self::assertTrue(symlink($outside, $base . '/link.txt'));

        self::assertFalse(PathGuard::isInside($base, $base . '/link.txt'), 'the symlink was judged by its name');

        $this->expectException(StorageException::class);
        PathGuard::assertInside($base, $base . '/link.txt');
    }

    // ---- the HTTP layer ----------------------------------------------------

    public function testTheAdminExportRouteAnswers4xxForATraversalCategory(): void
    {
        $this->signIn();

        foreach (['..', '../../etc/passwd', '..%2f..%2fetc%2fpasswd', '.'] as $name) {
            $response = $this->send('GET', '/admin/export/' . rawurlencode($name));

            self::assertGreaterThanOrEqual(400, $response->getStatusCode(), $name . ' was exported');
            self::assertLessThan(500, $response->getStatusCode(), $name . ' produced a server error');
            self::assertStringNotContainsString('root:', self::bodyOf($response));
        }
    }

    public function testTheDemoTemplateRouteNeverAnswers200WithAFileFromElsewhere(): void
    {
        $controller = new DemoController($this->kernel);
        $request = new ServerRequest('GET', 'http://zfeeder.test/demos/template/classic/x');

        foreach (['../../composer', '../../../etc/passwd', '....//....//composer', '/etc/passwd'] as $name) {
            $response = $controller->template($request, 'classic', $name);
            $body = self::bodyOf($response);

            self::assertNotSame(200, $response->getStatusCode(), $name . ' was rendered');
            self::assertGreaterThanOrEqual(400, $response->getStatusCode());
            self::assertStringNotContainsString('root:', $body);
            self::assertStringNotContainsString('"autoload"', $body, 'composer.json was read');
            self::assertStringNotContainsString('<?php', $body);
        }
    }

    public function testTheDemoTemplateRouteStillRendersARealTemplate(): void
    {
        $controller = new DemoController($this->kernel);
        $request = new ServerRequest('GET', 'http://zfeeder.test/demos/template/classic/bluelogos');

        $response = $controller->template($request, 'classic', 'bluelogos');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAHostileCategoryInAQueryStringFallsBackAndReadsNothing(): void
    {
        // The public renderer takes `zfcategory` from the query string, as 1.6
        // did. An unreadable name falls back to the default category instead of
        // becoming part of a path.
        $service = $this->kernel->feeds();

        foreach (['../../etc/passwd', '/etc/passwd', "zf\0eeder"] as $name) {
            self::assertSame(self::CATEGORY, $service->resolveCategory($name)->name, $name . ' was resolved');
        }
    }
}
