<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Config\Config;
use Zfeeder\Exception\TemplateException;
use Zfeeder\Render\TemplateLocator;

final class TemplateLocatorTest extends TestCase
{
    private function locator(): TemplateLocator
    {
        return new TemplateLocator(Config::forTesting(['template_set' => 'classic']));
    }

    public function testBareNameUsesTheConfiguredSet(): void
    {
        $resolved = $this->locator()->resolve('bluelogos');

        self::assertSame('classic', $resolved['set']);
        self::assertSame('bluelogos', $resolved['name']);
        self::assertStringEndsWith('/templates/classic/bluelogos.html', $resolved['path']);
    }

    public function testQualifiedNameSelectsTheSet(): void
    {
        self::assertSame('classic', $this->locator()->resolve('classic/simplegray')['set']);
    }

    public function testMixedCaseNameStillResolvesToTheLowerCaseFile(): void
    {
        // The 2004 template was shipped as RiJ.html; links and saved configs in
        // the wild still say "RiJ", so the spelling has to keep working.
        $resolved = $this->locator()->resolve('RiJ');

        self::assertSame('rij', $resolved['name']);
        self::assertStringEndsWith('/templates/classic/rij.html', $resolved['path']);
    }

    /** @return iterable<string, array{string}> */
    public static function traversalProvider(): iterable
    {
        yield 'parent directory' => ['../../composer'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'dot segment in name' => ['classic/../../composer'];
        yield 'encoded traversal' => ['classic/%2e%2e%2fcomposer'];
        yield 'null byte' => ["classic/bluelogos\0.html"];
        yield 'nested path' => ['classic/sub/dir'];
        yield 'extension smuggled in' => ['classic/bluelogos.html'];
        yield 'too long' => ['classic/' . str_repeat('a', 41)];
        yield 'empty' => ['   '];
    }

    #[DataProvider('traversalProvider')]
    public function testTraversalAndMalformedSpecsAreRejected(string $spec): void
    {
        $this->expectException(TemplateException::class);
        $this->locator()->resolve($spec);
    }

    public function testUnknownSetIsRejected(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Unknown template set "admin"');
        $this->locator()->resolve('admin/login');
    }

    public function testUnknownTemplateIsRejected(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Unknown template "classic/nope"');
        $this->locator()->resolve('nope');
    }

    public function testAvailableListsBothSets(): void
    {
        $available = $this->locator()->available();

        self::assertSame(['classic', 'modern'], array_keys($available));
        self::assertContains('bluelogos', $available['classic']);
        self::assertContains('rij', $available['classic']);
        self::assertSame($available['classic'], array_values($available['classic']));
        // Sorted, so the admin dropdown is stable between requests.
        $sorted = $available['classic'];
        sort($sorted);
        self::assertSame($sorted, $available['classic']);
    }

    public function testLocateReturnsTheSamePathAsResolve(): void
    {
        $locator = $this->locator();

        self::assertSame($locator->resolve('aqua')['path'], $locator->locate('aqua'));
    }
}
