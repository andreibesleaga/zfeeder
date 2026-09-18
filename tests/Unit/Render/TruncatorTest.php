<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Zfeeder\Render\Truncator;

final class TruncatorTest extends TestCase
{
    private Truncator $truncator;

    protected function setUp(): void
    {
        $this->truncator = new Truncator();
    }

    public function testZeroMeansUnlimited(): void
    {
        $html = '<p>' . str_repeat('x', 5000) . '</p>';

        self::assertSame($html, $this->truncator->truncate($html, 0));
        self::assertSame($html, $this->truncator->truncate($html, -1));
    }

    public function testShortEnoughInputIsReturnedUnchangedWithoutEllipsis(): void
    {
        $html = '<p>exactly ten</p>';

        self::assertSame($html, $this->truncator->truncate($html, 11));
        self::assertSame($html, $this->truncator->truncate($html, 1000));
        self::assertStringNotContainsString(Truncator::ELLIPSIS, $this->truncator->truncate($html, 11));
    }

    public function testOnlyVisibleCharactersCountTowardsTheBudget(): void
    {
        // Ten visible characters wrapped in far more than ten bytes of markup.
        $html = '<p><strong>0123456789</strong></p>';

        self::assertSame($html, $this->truncator->truncate($html, 10));
    }

    public function testOpenTagsAreClosedInReverseOrder(): void
    {
        $html = '<div><p><b>abcdefghij</b></p></div>';

        self::assertSame('<div><p><b>abcde' . Truncator::ELLIPSIS . '</b></p></div>', $this->truncator->truncate($html, 5));
    }

    public function testAClosedElementIsNotClosedAgain(): void
    {
        $html = '<p>first</p><p>second</p>';

        self::assertSame('<p>first</p><p>se' . Truncator::ELLIPSIS . '</p>', $this->truncator->truncate($html, 7));
    }

    public function testVoidElementsAreNotClosed(): void
    {
        $html = '<p>ab<br><img src="https://ok.example/i.png" alt="">cdef</p>';

        $out = $this->truncator->truncate($html, 4);
        self::assertStringContainsString('<br>', $out);
        self::assertStringNotContainsString('</br>', $out);
        self::assertStringNotContainsString('</img>', $out);
        self::assertStringEndsWith('</p>', $out);
    }

    public function testSelfClosingXhtmlTagsAreNotClosed(): void
    {
        $out = $this->truncator->truncate('<p>ab<hr />cdef</p>', 3);

        self::assertStringNotContainsString('</hr>', $out);
    }

    public function testNeverCutsInsideATag(): void
    {
        $out = $this->truncator->truncate('<p><a href="https://example.org/very/long/url">abcdef</a></p>', 3);

        self::assertStringContainsString('<a href="https://example.org/very/long/url">', $out);
        self::assertSame('<p><a href="https://example.org/very/long/url">abc' . Truncator::ELLIPSIS . '</a></p>', $out);
    }

    public function testNeverCutsInsideAnEntity(): void
    {
        // Budget of 3 leaves room for "a", "&amp;" and "b"; a budget of 2 must
        // stop before the entity rather than emit a bare "&am".
        self::assertSame('a&amp;b', $this->truncator->truncate('a&amp;b', 3));

        $cut = $this->truncator->truncate('a&amp;bcd', 2);
        self::assertSame('a&amp;' . Truncator::ELLIPSIS, $cut);

        $tight = $this->truncator->truncate('ab&amp;cd', 2);
        self::assertSame('ab' . Truncator::ELLIPSIS, $tight);
        self::assertStringNotContainsString('&am', str_replace('&amp;', '', $tight));
    }

    public function testNumericEntitiesCountAsOneCharacter(): void
    {
        self::assertSame('&#233;&#x41;', $this->truncator->truncate('&#233;&#x41;', 2));
    }

    public function testUtf8IsCountedInCharactersNotBytes(): void
    {
        $html = '<p>ăîșțâ ăîșțâ</p>';

        self::assertSame($html, $this->truncator->truncate($html, 11));
        self::assertSame('<p>ăîșțâ' . Truncator::ELLIPSIS . '</p>', $this->truncator->truncate($html, 5));
    }

    public function testMultibyteIsNotSplitMidCharacter(): void
    {
        $out = $this->truncator->truncate('日本語テキスト', 3);

        self::assertSame('日本語' . Truncator::ELLIPSIS, $out);
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function testCommentsAreCarriedButNotCounted(): void
    {
        $out = $this->truncator->truncate('<!-- note -->abcdef', 3);

        self::assertSame('<!-- note -->abc' . Truncator::ELLIPSIS, $out);
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', $this->truncator->truncate('', 10));
    }
}
