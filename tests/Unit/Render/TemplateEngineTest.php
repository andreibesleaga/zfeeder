<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Zfeeder\Exception\TemplateException;
use Zfeeder\Render\TemplateEngine;

final class TemplateEngineTest extends TestCase
{
    private const string SAMPLE = <<<'HTML'
        <!-- a comment -->
        <!-- zFeeder template header -->
        PAGE
        <!-- ENDzFeeder template header -->
        <!-- header -->
        HEAD
        <!-- ENDheader -->
          <!-- channel -->
        CHAN
          <!-- ENDchannel -->
        <!-- news -->
        NEWS
        <!-- ENDnews -->
        <!-- footer -->
        FOOT
        <!-- ENDfooter -->
        <!-- between -->
        BETW
        <!-- ENDbetween -->
        HTML;

    public function testChunkKeepsItsOpeningMarkerAndDropsTheClosingOne(): void
    {
        $template = (new TemplateEngine())->parse(self::SAMPLE);

        self::assertSame("<!-- news -->\nNEWS\n", $template->news);
        self::assertStringStartsWith('<!-- header -->', $template->header);
        self::assertStringNotContainsString('<!-- ENDheader -->', $template->header);
    }

    public function testAChunkKeepsTheIndentationOfItsClosingMarkerAndNotOfItsOpeningOne(): void
    {
        // A chunk runs from its opening marker up to, but not including, its
        // closing one. So the spaces that indent `<!-- ENDchannel -->` end up at
        // the tail of the channel chunk, while the spaces that indent
        // `<!-- channel -->` fall outside every chunk and are dropped. The
        // resulting misplaced indentation is visible in every golden.
        $template = (new TemplateEngine())->parse(self::SAMPLE);

        self::assertSame("<!-- header -->\nHEAD\n", $template->header);
        self::assertStringStartsWith('<!-- channel -->', $template->channel);
        self::assertSame("<!-- channel -->\nCHAN\n  ", $template->channel);
    }

    public function testPageHeaderIsOptional(): void
    {
        $source = str_replace(
            ["<!-- zFeeder template header -->\nPAGE\n", "<!-- ENDzFeeder template header -->\n"],
            '',
            self::SAMPLE,
        );

        self::assertNull((new TemplateEngine())->parse($source)->pageHeader);
        self::assertSame("<!-- zFeeder template header -->\nPAGE\n", (new TemplateEngine())->parse(self::SAMPLE)->pageHeader);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredSectionProvider(): iterable
    {
        foreach (['header', 'channel', 'news', 'footer', 'between'] as $section) {
            yield $section => [$section];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredSectionProvider')]
    public function testMissingOpeningMarkerThrows(string $section): void
    {
        $broken = str_replace('<!-- ' . $section . ' -->', '', self::SAMPLE);

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($section, '/') . '/');
        (new TemplateEngine())->parse($broken, 'broken');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredSectionProvider')]
    public function testMissingClosingMarkerThrows(string $section): void
    {
        $broken = str_replace('<!-- END' . $section . ' -->', '', self::SAMPLE);

        $this->expectException(TemplateException::class);
        (new TemplateEngine())->parse($broken, 'broken');
    }

    public function testClosingMarkerBeforeOpeningMarkerThrows(): void
    {
        $broken = "<!-- ENDnews -->\n" . str_replace('<!-- ENDnews -->', '', self::SAMPLE);

        $this->expectException(TemplateException::class);
        (new TemplateEngine())->parse($broken, 'inverted');
    }

    public function testLoadIsMemoisedPerFileAndMtime(): void
    {
        $engine = new TemplateEngine();
        $path = dirname(__DIR__, 3) . '/templates/classic/bluelogos.html';

        $first = $engine->load($path, 'classic/bluelogos', 'classic');
        $second = $engine->load($path, 'classic/bluelogos', 'classic');

        self::assertSame($first, $second);
        self::assertSame('classic/bluelogos', $first->name);
        self::assertSame('bluelogos', $first->shortName());
    }

    public function testUnreadableFileThrows(): void
    {
        $this->expectException(TemplateException::class);
        (new TemplateEngine())->load('/does/not/exist.html', 'x/y', 'classic');
    }
}
