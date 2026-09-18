<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Exception\TemplateException;
use Zfeeder\Render\Filters;
use Zfeeder\Render\Tokens;

final class FiltersTest extends TestCase
{
    /** @param array<string, string> $values */
    private function render(string $template, array $values = []): string
    {
        return (new Tokens())->substitute($template, $values);
    }

    public function testNewTokensAreHtmlEscapedByDefault(): void
    {
        $out = $this->render('{author}', ['author' => 'Smith & <b>Jones</b>']);

        self::assertSame('Smith &amp; &lt;b&gt;Jones&lt;/b&gt;', $out);
    }

    public function testRawFilterSkipsTheDefaultEscaping(): void
    {
        $out = $this->render('{content|raw}', ['content' => '<p>hi</p>']);

        self::assertSame('<p>hi</p>', $out);
    }

    public function testTruncFilter(): void
    {
        $out = $this->render('{summary|raw|trunc:5}', ['summary' => '<p>abcdefgh</p>']);

        self::assertSame("<p>abcde\u{2026}</p>", $out);
    }

    public function testTruncThenEscapeStillEscapes(): void
    {
        $out = $this->render('{author|trunc:3}', ['author' => 'a<bcdef']);

        self::assertSame('a&lt;b…', $out);
    }

    public function testDateFilterReformatsAFeedDate(): void
    {
        $out = $this->render('{itemdate_iso|date:"D, d M Y"}', ['itemdate_iso' => '2026-09-14T22:10:00+00:00']);

        self::assertSame('Mon, 14 Sep 2026', $out);
    }

    public function testDateFilterPassesThroughUnparsableInput(): void
    {
        $out = $this->render('{author|date:"Y"}', ['author' => 'not a date at all']);

        self::assertSame('not a date at all', $out);
    }

    public function testDateFilterOnEmptyValueGivesEmptyString(): void
    {
        self::assertSame('', $this->render('{itemdate_iso|date:"Y"}', ['itemdate_iso' => '']));
    }

    /** @return iterable<string, array{string}> */
    public static function badFilterProvider(): iterable
    {
        yield 'unknown name' => ['{author|upper}'];
        yield 'php function' => ['{author|system}'];
        yield 'expression-looking' => ['{author|trunc:1+1}'];
        yield 'trunc without argument' => ['{author|trunc}'];
        yield 'trunc with word argument' => ['{author|trunc:lots}'];
        yield 'date without format' => ['{author|date}'];
        yield 'chained unknown' => ['{author|raw|nope}'];
    }

    #[DataProvider('badFilterProvider')]
    public function testUnknownOrMalformedFilterThrows(string $template): void
    {
        $this->expectException(TemplateException::class);
        $this->render($template, ['author' => 'x']);
    }

    public function testFiltersChain(): void
    {
        $out = $this->render('{content|trunc:4|raw}', ['content' => '<b>abcdef</b>']);

        self::assertSame("<b>abcd\u{2026}</b>", $out);
    }

    public function testLegacyTokensMayAlsoCarryFilters(): void
    {
        // A legacy token with a filter is handled by this pass; a *bare* legacy
        // token is left for the renderer's 1.6-ordered str_replace sequence.
        self::assertSame('{title}', $this->render('{title}', ['title' => 'x']));
        self::assertSame("ab\u{2026}", $this->render('{title|trunc:2}', ['title' => 'abcdef']));
    }

    public function testUnknownTokensAreLeftLiteralExactlyAs16Did(): void
    {
        self::assertSame('{nosuchtoken}', $this->render('{nosuchtoken}', []));
        self::assertSame('{author}', $this->render('{author}', []), 'a token with no value stays literal');
    }

    public function testCssAndJavascriptBracesAreNotMistakenForTokens(): void
    {
        $css = "a.link1:link {color: #333333; text-decoration: none;}\nfunction f( x )\n{\n return x;\n}";

        self::assertSame($css, $this->render($css, ['author' => 'x']));
    }

    public function testKnownFilterList(): void
    {
        self::assertSame(['raw', 'trunc', 'date'], Filters::NAMES);
        self::assertTrue(Filters::exists('raw'));
        self::assertFalse(Filters::exists('eval'));
    }

    public function testTokenTable(): void
    {
        self::assertCount(15, Tokens::LEGACY);
        self::assertCount(9, Tokens::MODERN);
        self::assertCount(24, Tokens::all());
        self::assertTrue(Tokens::isLegacy('description'));
        self::assertFalse(Tokens::isLegacy('summary'));
        self::assertTrue(Tokens::escapesByDefault('summary'));
        self::assertFalse(Tokens::escapesByDefault('description'));
        self::assertFalse(Tokens::isKnown('whatever'));
    }
}
