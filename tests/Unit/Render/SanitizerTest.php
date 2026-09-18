<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Render\Sanitizer;

final class SanitizerTest extends TestCase
{
    /**
     * The whole `tests/fixtures/payloads/xss-corpus.json`, one test per case.
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function corpusProvider(): iterable
    {
        /** @var array{cases: list<array{name: string, input: string, mustNotContain?: list<string>, mustContain?: list<string>}>} $corpus */
        $corpus = json_decode(zf_fixture_contents('payloads/xss-corpus.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($corpus['cases'] as $case) {
            yield $case['name'] => [$case['input'], $case['mustNotContain'] ?? [], $case['mustContain'] ?? []];
        }
    }

    /**
     * @param list<string> $mustNotContain
     * @param list<string> $mustContain
     */
    #[DataProvider('corpusProvider')]
    public function testCorpusCase(string $input, array $mustNotContain, array $mustContain): void
    {
        $output = (new Sanitizer())->sanitize($input);

        foreach ($mustNotContain as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $output, 'Sanitised output still contains ' . $needle);
        }
        foreach ($mustContain as $needle) {
            self::assertStringContainsString($needle, $output, 'Sanitised output lost ' . $needle);
        }
    }

    public function testCorpusIsNotEmpty(): void
    {
        self::assertGreaterThanOrEqual(14, iterator_count(self::corpusProvider()));
    }

    public function testDangerousContainersAreDroppedWithTheirContents(): void
    {
        // Blocking would keep the children and leave the payload as page text;
        // dropping removes the element and everything inside it.
        $output = (new Sanitizer())->sanitize('<div>before<script>steal()</script>after</div>');

        self::assertStringNotContainsString('steal()', $output);
        self::assertStringContainsString('before', $output);
        self::assertStringContainsString('after', $output);
    }

    public function testDataImageSourcesAreRejected(): void
    {
        $output = (new Sanitizer())->sanitize('<img src="data:image/svg+xml;base64,PHN2Zz4=" alt="x">');

        self::assertStringNotContainsString('data:', $output);
    }

    public function testLinksGetNofollowNoopenerAndTargetBlank(): void
    {
        $output = (new Sanitizer())->sanitize('<a href="https://ok.example/a">link</a>');

        self::assertStringContainsString('rel="nofollow noopener"', $output);
        self::assertStringContainsString('target="_blank"', $output);
    }

    public function testAllowedFormattingSurvives(): void
    {
        $input = '<h2>Title</h2><ul><li><em>one</em></li></ul>'
            . '<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>c</td></tr></tbody></table>'
            . '<figure><img src="https://ok.example/i.png" alt="i" width="10" height="10"><figcaption>cap</figcaption></figure>'
            . '<blockquote><pre><code>x</code></pre></blockquote><time datetime="2026-01-01">then</time>';

        $output = (new Sanitizer())->sanitize($input);

        foreach (['<h2>', '<ul>', '<li>', '<em>', '<table>', '<thead>', '<th>', '<td>',
            '<figure>', '<figcaption>', '<blockquote>', '<pre>', '<code>', '<time'] as $tag) {
            self::assertStringContainsString($tag, $output, 'Lost ' . $tag);
        }
        self::assertStringContainsString('width="10"', $output);
    }

    public function testStyleAttributeIsDropped(): void
    {
        $output = (new Sanitizer())->sanitize('<p style="position:fixed;top:0">x</p>');

        self::assertStringNotContainsString('style=', $output);
        self::assertStringContainsString('x', $output);
    }

    public function testEmptyInputIsCheap(): void
    {
        self::assertSame('', (new Sanitizer())->sanitize(''));
        self::assertSame('', (new Sanitizer())->toText(''));
    }

    public function testToTextFlattensMarkupAndKeepsWordBoundaries(): void
    {
        $sanitizer = new Sanitizer();

        self::assertSame('one two', $sanitizer->toText('<p>one</p><p>two</p>'));
        self::assertSame('a b', $sanitizer->toText('a<br>b'));
        self::assertSame('a & b', $sanitizer->toText('<p>a &amp; b</p>'));
        self::assertSame('kept', $sanitizer->toText("  <div>\n kept \n</div>  "));
    }

    public function testToTextDropsScriptPayloadsEntirely(): void
    {
        self::assertSame('safe', (new Sanitizer())->toText('<p>safe</p><script>alert(1)</script>'));
    }
}
