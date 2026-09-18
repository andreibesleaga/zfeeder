<?php

declare(strict_types=1);

namespace Zfeeder\Render;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The allow-list sanitiser applied to every piece of markup a feed hands us.
 *
 * 1.6 printed `<description>` straight into the page, so any subscribed feed
 * could run script in the host site. That is the single worst hole in the 2004
 * code and it is closed here for every render except the deliberately
 * byte-faithful legacy mode used by the golden tests.
 *
 * Policy notes:
 *  - Dangerous containers are *dropped* (element and contents), not blocked.
 *    Blocking keeps the children, which would leave `alert(1)` as page text.
 *  - `data:` is not an allowed media scheme. `data:image/...` is convenient but
 *    it is also how SVG payloads get smuggled in, so it is excluded entirely.
 *  - `style` is not an allowed attribute, which kills `url(javascript:...)`.
 *  - Links get `rel="nofollow noopener"` and `target="_blank"` forced on: feed
 *    links point at third party sites we do not vouch for.
 */
final class Sanitizer
{
    /** Elements kept, mapped to the attributes each may carry. */
    private const array ALLOWED = [
        'p' => [], 'br' => [], 'b' => [], 'i' => [], 'em' => [], 'strong' => [],
        'u' => [], 's' => [], 'code' => [], 'pre' => [], 'blockquote' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'dl' => [], 'dt' => [], 'dd' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'span' => [], 'div' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
        'a' => ['href'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'figure' => [], 'figcaption' => [], 'time' => ['datetime'],
    ];

    /** Elements removed together with everything inside them. */
    private const array DROPPED = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'base', 'meta', 'link', 'svg',
        // Close relatives of the list above; leaving them out would be an
        // obvious gap rather than a deliberate choice.
        'applet', 'frame', 'frameset', 'noscript', 'template', 'textarea',
        'button', 'select', 'option', 'math', 'audio', 'video', 'source', 'track',
    ];

    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'nofollow noopener')
            ->forceAttribute('a', 'target', '_blank')
            // 5 MB of markup from one item is already absurd; feeds that large
            // are truncated rather than allowed to exhaust memory.
            ->withMaxInputLength(5_000_000);

        foreach (self::ALLOWED as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }
        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }
        // Event handlers never survive the allow-list above, but naming them
        // explicitly documents the intent and protects against a future
        // allowElement() call that forgets.
        foreach (self::eventAttributes() as $attribute) {
            $config = $config->dropAttribute($attribute, '*');
        }
        $config = $config->dropAttribute('style', '*');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /** Feed markup, reduced to the allow-list above. Safe to print unescaped. */
    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return $this->sanitizer->sanitize($html);
    }

    /**
     * The same markup flattened to plain text, for `allow_html_in_items=false`.
     *
     * The result is *text*, not HTML: it still has to be escaped by the caller
     * before it goes into a page. The Renderer does that.
     */
    public function toText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $safe = $this->sanitize($html);
        // Give block boundaries a space so "<p>a</p><p>b</p>" does not become "ab".
        $spaced = preg_replace('~<(?:br|/p|/div|/li|/h[1-6]|/tr|/blockquote|/pre)\b[^>]*>~i', ' ', $safe) ?? $safe;
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($collapsed);
    }

    /** @return list<string> */
    private static function eventAttributes(): array
    {
        return [
            'onabort', 'onblur', 'onchange', 'onclick', 'ondblclick', 'onerror',
            'onfocus', 'onkeydown', 'onkeypress', 'onkeyup', 'onload', 'onmousedown',
            'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup', 'onreset',
            'onresize', 'onscroll', 'onselect', 'onsubmit', 'onunload', 'ontoggle',
            'onanimationend', 'onpointerover', 'onpointerdown', 'onwheel',
        ];
    }
}
