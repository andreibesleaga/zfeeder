<?php

declare(strict_types=1);

namespace Zfeeder\Render;

use Zfeeder\Config\Config;
use Zfeeder\Parse\Model\Item;
use Zfeeder\Subscription\Category;

/**
 * The 1.6 output pipeline, rebuilt.
 *
 * This is a direct translation of `displayData()` + `parseTemplate()` from
 * `newsfeeds/includes/zfuncs.php` and of the feed loop in `newsfeeds/zfeeder.php`.
 * Where 2004 echoed straight to the output buffer it now returns a string, and
 * where it read superglobals it now reads a {@see RenderRequest}. The emitted
 * bytes are identical, and `tests/Golden/ClassicTemplateGoldenTest.php` proves
 * it against 49 files captured from the original running on PHP 5.6.
 *
 * Quirks reproduced on purpose are marked "1.6 quirk" throughout. They are not
 * bugs to be fixed here; fixing them would break byte compatibility, which is
 * the whole point of the classic template set.
 */
final class Renderer
{
    /** Exactly the bytes `program_end()` echoed in 1.6. */
    public const string POWERED_BY = '<span style="font-family: Verdana, Arial, Helvetica, sans-serif; font-size: xx-small;">'
        . 'powered by <a href="http://zvonnews.sourceforge.net">zFeeder</a></span>';

    /** What 1.6 printed when a category resolved to no feeds at all. */
    public const string NO_FEEDS = ' No feeds.';

    /** Tokens whose value this class builds, and which must not be escaped again. */
    private const array PRE_FORMED = ['chanlogo', 'moreurl', 'hideurl', 'lastupdated', 'scripturl'];

    /** Schemes allowed in a link that came from a feed. */
    private const array SAFE_SCHEMES = ['http', 'https', 'mailto'];

    public function __construct(
        private readonly Config $config,
        private readonly TemplateLocator $locator,
        private readonly TemplateEngine $engine = new TemplateEngine(),
        private readonly Sanitizer $sanitizer = new Sanitizer(),
        private readonly Truncator $truncator = new Truncator(),
        private readonly Tokens $tokens = new Tokens(),
        /** Fixed "now" for `{itemdate_rel}`; injected so output stays deterministic in tests. */
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    /**
     * @param list<RenderedFeed> $feeds already-resolved content for the category's subscriptions
     */
    public function render(Category $category, array $feeds, RenderRequest $request): string
    {
        return $this->renderTemplate($this->template($request), $category, $feeds, $request);
    }

    /**
     * The same render against an already-parsed template.
     *
     * Kept public so the admin preview screen and the tests can render a
     * template that is not (yet) a file on disk under `templates/`.
     *
     * @param list<RenderedFeed> $feeds
     */
    public function renderTemplate(Template $template, Category $category, array $feeds, RenderRequest $request): string
    {
        // 1.6 ordered feeds by their OPML position and dropped unsubscribed
        // ones, zero-item ones and ones with no URL. `renderableFeeds()` is the
        // same rule; we apply it to the resolved list so a caller cannot change
        // the order by handing them over shuffled.
        $renderable = array_values(array_filter($feeds, static fn (RenderedFeed $f): bool => $f->feed->isRenderable()));
        usort($renderable, static fn (RenderedFeed $a, RenderedFeed $b): int => $a->feed->position <=> $b->feed->position);

        if ($renderable === []) {
            return self::NO_FEEDS;
        }

        // `$zf_showedPositions` in 1.6: built from every renderable feed of the
        // category, *then* overwritten by `?zfposition` inside parseTemplate().
        $shownPositions = '';
        foreach ($renderable as $rendered) {
            $shownPositions .= 'p' . $rendered->feed->position . ',';
        }
        if ($request->positions !== null && trim($request->positions) !== '') {
            $shownPositions = $request->positions;
        }

        $filter = $request->positionFilter();
        $selected = $filter === null
            ? $renderable
            : $this->inRequestedOrder($renderable, $filter);

        // 1.6 echoed the once-per-page header verbatim, and legacy mode keeps
        // doing that because the goldens record it. A modern template needs
        // {scripturl} here, though - it is where a template links its own
        // stylesheet - so outside legacy mode the page-independent tokens are
        // expanded. No golden observes this chunk with a token in it.
        $pageHeader = $template->pageHeader ?? '';
        if ($pageHeader !== '' && !$request->legacyFidelity) {
            $pageHeader = strtr($pageHeader, [
                '{scripturl}' => $this->scriptUrl(),
                '{category}' => htmlspecialchars($category->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{set}' => htmlspecialchars($this->setName($request), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ]);
        }
        $out = $pageHeader;
        foreach ($selected as $index => $rendered) {
            $out .= $this->renderFeed($template, $category, $rendered, $request, $shownPositions, $index);
        }

        if ($request->showPoweredBy && $this->config->bool('powered_by')) {
            $out .= self::POWERED_BY;
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // one feed
    // -----------------------------------------------------------------

    private function renderFeed(
        Template $template,
        Category $category,
        RenderedFeed $rendered,
        RenderRequest $request,
        string $shownPositions,
        int $ordinal,
    ): string {
        $location = $this->config->string('channel_location');
        $oneBar = $this->config->bool('channel_one_bar');
        $feedIndex = $rendered->feed->position;
        $items = $rendered->items();
        $showedItems = $rendered->feed->showedItems;
        $more = $request->wantsMore($feedIndex);

        $base = $this->channelValues($category, $rendered, $request, $shownPositions, $feedIndex, $ordinal, $template);

        // 1.6 quirk: header, footer and between are echoed verbatim -
        // `displayData()` never passed them through `parseTemplate()`, so a
        // token written there stays literal. Classic templates rely on this
        // (simplecss puts a whole <style> block with CSS braces in `header`).
        // Outside legacy mode we do expand channel-level tokens there, which
        // modern templates need and no golden can observe.
        $out = $this->frame($template->header, $base, $request);

        if ($oneBar && $location === 'top') {
            $out .= $this->channelChunk($template, $base, $rendered, $feedIndex, $request);
        }

        for ($i = 0; $i < count($items); ++$i) {
            if ($location === 'top' && !$oneBar) {
                $out .= $this->channelChunk($template, $base, $rendered, null, $request);
            }
            $out .= $this->newsChunk($template, $base, $rendered, $i, $request);
            if ($location === 'bottom' && !$oneBar) {
                $out .= $this->channelChunk($template, $base, $rendered, null, $request);
            }
            // 1.6's exact break condition. `$i + 2 > $showedItems` stops after
            // `$showedItems` items because the test runs *after* the item was
            // emitted. `zfmore` for this feed suppresses the break entirely.
            if (($i + 2 > $showedItems) && !$more) {
                break;
            }
        }

        if ($oneBar && $location === 'bottom') {
            $out .= $this->channelChunk($template, $base, $rendered, $feedIndex, $request);
        }

        $out .= $this->frame($template->footer, $base, $request);
        $out .= $this->frame($template->between, $base, $request);

        return $out;
    }

    /**
     * header / footer / between: verbatim in legacy mode, channel tokens expanded otherwise.
     *
     * @param array<string, string> $values
     */
    private function frame(string $chunk, array $values, RenderRequest $request): string
    {
        if ($request->legacyFidelity) {
            return $chunk;
        }

        return $this->renderChunk($chunk, $values, null, null, $request);
    }

    /**
     * @param array<string, string> $values
     * @param int|null              $feedIndex the `$i` 1.6 passed to `parseTemplate()`: the feed index in
     *                                         one-bar mode, or null (1.6's -1) when the bar repeats per item
     */
    private function channelChunk(
        Template $template,
        array $values,
        RenderedFeed $rendered,
        ?int $feedIndex,
        RenderRequest $request,
    ): string {
        if ($feedIndex === null) {
            // 1.6 quirk: with `$i === -1` the item tokens - including
            // `{moreurl}` - were never substituted, so they are printed
            // literally in the per-item channel bar. `{hideurl}` was built from
            // "p-1," which matches nothing, so it lists every position.
            return $this->renderChunk($template->channel, $values, null, null, $request);
        }

        // 1.6 quirk: the one-bar channel calls parseTemplate() with the *feed*
        // index, which then indexes `$zf_items` with it. `{id}` therefore means
        // "feed index" here, while `{title}`, `{link}`, `{pubdate}` and
        // `{description}` silently pick up whichever item happens to sit at
        // that offset - or nothing at all when the feed is shorter.
        $item = $rendered->items()[$feedIndex] ?? null;

        return $this->renderChunk($template->channel, $values, $feedIndex, $item, $request);
    }

    /** @param array<string, string> $values */
    private function newsChunk(
        Template $template,
        array $values,
        RenderedFeed $rendered,
        int $itemIndex,
        RenderRequest $request,
    ): string {
        $item = $rendered->items()[$itemIndex] ?? null;

        return $this->renderChunk($template->news, $values, $itemIndex, $item, $request);
    }

    // -----------------------------------------------------------------
    // token values
    // -----------------------------------------------------------------

    /**
     * The nine channel-level tokens, plus the request-level ones.
     *
     * @return array<string, string>
     */
    private function channelValues(
        Category $category,
        RenderedFeed $rendered,
        RenderRequest $request,
        string $shownPositions,
        int $feedIndex,
        int $ordinal,
        Template $template,
    ): array {
        $channel = $rendered->channel;
        $self = $request->selfUrl;

        $hideUrl = $self . '?zfposition=' . str_replace('p' . $feedIndex . ',', '', $shownPositions);
        $moreUrl = $self . '?zfmore=' . $feedIndex;
        if ($request->template !== null && $request->template !== '') {
            $moreUrl .= '&amp;zftemplate=' . $request->template;
            $hideUrl .= '&amp;zftemplate=' . $request->template;
        }
        // `$zf_category` in 1.6 is the *requested* category string when one was
        // asked for and the OPML file existed, otherwise the configured
        // default. Both `{category}` and the zfcategory link parameter use it.
        $categoryName = $request->category ?? $category->name;
        if ($request->category !== null && $request->category !== '') {
            $moreUrl .= '&amp;zfcategory=' . $categoryName;
            $hideUrl .= '&amp;zfcategory=' . $categoryName;
        }
        $moreUrl .= '&amp;zfposition=' . $shownPositions . '#zfchannel' . $feedIndex;

        // Values are kept unescaped here, exactly as 1.6 held them: escaping
        // happens once, at substitution, where it is known whether the token
        // is being expanded by the legacy pass or the filter pass. Escaping in
        // both places is what produced `&amp;#039;` in the output.
        //
        // A URL is different: escaping `javascript:alert(1)` yields a string
        // that is still a working `javascript:` href, so the scheme has to be
        // checked rather than merely encoded.
        return [
            'chanlogo' => $this->chanLogo($rendered, $request),
            'chanlink' => $this->safeUrl($channel === null ? '' : $channel->link, $request),
            'chandesc' => $channel === null ? '' : $channel->description,
            'chantitle' => $channel === null ? '' : $channel->title,
            'feedurl' => $this->safeUrl($rendered->feed->xmlUrl, $request),
            'lastupdated' => $this->lastUpdated($rendered),
            'scripturl' => $this->scriptUrl(),
            'hideurl' => $hideUrl,
            'category' => $categoryName,
            // 2.0 tokens that do not depend on an item
            'feedid' => (string) $ordinal,
            'position' => (string) $feedIndex,
            'set' => $template->set,
            'moreurl' => $moreUrl,
        ];
    }

    /**
     * `{chanlogo}`: 1.6 emitted this exact markup, with the feed's own strings
     * dropped into attributes unescaped. Outside legacy mode the three values
     * are escaped, which is the only difference.
     */
    private function chanLogo(RenderedFeed $rendered, RenderRequest $request): string
    {
        $channel = $rendered->channel;
        if ($channel === null || $channel->logoUrl === '') {
            return '';
        }
        // The logo's link and image URL come from the feed like any other, so
        // they get the same scheme check: escaping a `javascript:` logo link
        // would leave a working `javascript:` link wrapped around an image.
        $link = $this->safeUrl($channel->logoLink, $request);
        $url = $this->safeUrl($channel->logoUrl, $request);
        $title = $channel->logoTitle;
        if (!$request->legacyFidelity) {
            $link = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $url = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($request->legacyFidelity) {
            return '<a href="' . $link . '"><img src="' . $url . '" border="0" alt="[logo]" title="' . $title . '" /></a>';
        }

        // The channel title always sits next to the logo, so the image itself
        // carries no information: an empty alt stops a screen reader announcing
        // the name twice, and a publisher whose logo has moved leaves a gap
        // rather than the words "[logo]". The link still needs a name of its
        // own, though - a link whose only content is a decorative image has
        // none - so it gets an aria-label. `border="0"` was a 2004 necessity
        // and is dropped; `loading="lazy"` costs nothing on a page that may
        // hold twenty feeds.
        $label = $channel->title !== '' ? $channel->title : $channel->logoTitle;
        $label = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = $label !== '' ? $label : 'this feed';

        return '<a href="' . $link . '" class="zf-chanlogo" aria-label="Visit ' . $label
            . '"><img src="' . $url . '" alt="" title="' . $title
            . '" loading="lazy" decoding="async" /></a>';
    }

    /** `{lastupdated}`: 1.6 used `gmdate("D, d M Y H:i:s \G\M\T", filemtime($cacheFile))`. */
    private function lastUpdated(RenderedFeed $rendered): string
    {
        if ($rendered->cache === null) {
            return '';
        }

        return $rendered->cache->fetchedAt
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s \G\M\T');
    }

    /**
     * One chunk, substituted exactly the way `parseTemplate()` did it.
     *
     * The 2.0 pass (filters and the nine new tokens) runs *first*, on the
     * template text only. Everything after it is 1.6's sequence of
     * `str_replace()` calls, in 1.6's order - which is observable, because a
     * value substituted early can contain a token that a later call expands.
     * Running the 2.0 pass first also means feed content can never be
     * re-scanned for tokens, closing an injection route 1.6 left open.
     *
     * `$idToken === null` reproduces `parseTemplate($html, $url)` with its
     * default `$i = -1`: the six item tokens are simply not replaced and stay
     * literal in the output.
     *
     * 1.6 quirk: when `$i` *is* given but points past the end of the feed - the
     * one-bar channel bar of a feed at position 2 that only has two items -
     * 1.6 read an undefined array offset, emitted a suppressed notice and
     * substituted empty strings. `$item === null` reproduces exactly that: the
     * tokens are still replaced, just with nothing.
     *
     * @param array<string, string> $values  channel-level values from channelValues()
     * @param int|null              $idToken what `{id}` becomes: the item index, or the feed index in
     *                                       one-bar mode; null means "no item context" (1.6's -1)
     * @param RenderRequest         $request never null: header, channel, news and footer chunks all
     *                                       carry it, because all of them can contain feed-derived text
     */
    private function renderChunk(
        string $chunk,
        array $values,
        ?int $idToken,
        ?Item $item,
        RenderRequest $request,
    ): string {
        $html = $this->tokens->substitute(
            $chunk,
            $this->modernValues($values, $item) + $this->filterableLegacyValues($values, $idToken, $item, $request),
        );

        $legacy = $request->legacyFidelity;
        foreach (Tokens::LEGACY as $token) {
            if (in_array($token, Tokens::LEGACY_ITEM, true)) {
                continue;
            }
            $html = str_replace('{' . $token . '}', self::present($token, $values[$token], $legacy), $html);
        }

        if ($idToken === null) {
            // 1.6 only expanded {moreurl} when an item was in scope, so a
            // template that put it in a header or footer got a literal token.
            // Modern templates put it exactly there - it is per-feed navigation,
            // not per-item - so outside legacy mode it is expanded. Legacy mode
            // keeps the 2004 behaviour the goldens record.
            if (!$legacy) {
                $html = str_replace('{moreurl}', self::present('moreurl', $values['moreurl'] ?? '', $legacy), $html);
            }

            return $html;
        }

        // 1.6 on PHP 5.6 called htmlspecialchars() with its default ENT_COMPAT,
        // which leaves single quotes alone. PHP 8 defaults to ENT_QUOTES, so we
        // pin the 2004 flags in legacy mode and take the stricter modern ones
        // everywhere else.
        $rawLink = $item === null ? '' : $item->link;
        $link = $request->legacyFidelity
            ? htmlspecialchars($rawLink, ENT_COMPAT | ENT_HTML401)
            : htmlspecialchars($this->safeUrl($rawLink, $request), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 1.6 printed {title} and {pubdate} straight from the feed, so a hostile
        // feed could close the surrounding tag. Legacy mode keeps that byte for
        // byte because the goldens record it; every other render escapes them.
        // (Deliberate, documented deviation - it is the same hole S5 of the
        // plan exists to close, and no golden observes the escaped form.)
        $rawTitle = $item === null ? '' : $item->title;
        $rawDate = $item === null ? '' : $item->rawDate;
        $title = $request->legacyFidelity
            ? $rawTitle
            : htmlspecialchars($rawTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pubdate = $request->legacyFidelity
            ? $rawDate
            : htmlspecialchars($rawDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $description = $item === null ? '' : $this->itemBody($item, $request);

        $html = str_replace('{id}', (string) $idToken, $html);
        $html = str_replace('{link}', $link, $html);
        $html = str_replace('{pubdate}', $pubdate, $html);
        $html = str_replace('{title}', $title, $html);
        $html = str_replace('{description}', $description, $html);

        return str_replace('{moreurl}', self::present('moreurl', $values['moreurl'], $legacy), $html);
    }

    /**
     * `{description}`.
     *
     * In legacy mode this is the 1.6 value byte for byte: the SAX parser
     * appended both `<description>` and `<content:encoded>` into one buffer, in
     * document order, and printed the result unescaped. `summaryHtml .
     * contentHtml` reproduces that concatenation (one of them is normally
     * empty).
     *
     * Otherwise the body is reduced to the configured policy: sanitised markup,
     * or escaped plain text when `allow_html_in_items` is off, then truncated
     * to `max_description_chars`.
     */
    private function itemBody(Item $item, RenderRequest $request): string
    {
        if ($request->legacyFidelity) {
            return $item->summaryHtml . $item->contentHtml;
        }

        $body = $item->bodyHtml();
        $body = $this->config->bool('allow_html_in_items')
            ? $this->sanitizer->sanitize($body)
            : htmlspecialchars($this->sanitizer->toText($body), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $this->truncator->truncate($body, $this->config->int('max_description_chars'));
    }

    /**
     * How a 1.6 token is written into the page.
     *
     * Legacy mode prints everything exactly as 2004 did. Otherwise the values
     * that carry text from a feed are escaped, and the four the renderer builds
     * itself are not: `{moreurl}` and `{hideurl}` already contain `&amp;`,
     * `{chanlogo}` is markup this class assembled, and `{lastupdated}` is a
     * formatted date. Escaping those again would corrupt them.
     */
    private static function present(string $token, string $value, bool $legacy): string
    {
        if ($legacy || in_array($token, self::PRE_FORMED, true)) {
            return $value;
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A URL that is safe to put in an `href`.
     *
     * `htmlspecialchars('javascript:alert(1)')` is unchanged and still runs
     * when a browser follows the link, so encoding alone is not a defence:
     * the scheme has to be checked. Anything that is not http, https, mailto
     * or a relative reference becomes an empty string, which renders as a dead
     * link rather than an executable one. Legacy mode keeps the 2004 value so
     * the goldens still match.
     */
    private function safeUrl(string $url, ?RenderRequest $request): string
    {
        if ($request !== null && $request->legacyFidelity) {
            return $url;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        // A scheme is everything before the first colon, when that colon comes
        // before any slash, question mark or hash. Control characters and
        // whitespace are stripped before the check, because a newline or a tab
        // inside the word "javascript" is the classic way past a naive filter.
        $cleaned = (string) preg_replace('/[\x00-\x20]+/', '', $trimmed);
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $cleaned, $matches) !== 1) {
            return $trimmed; // relative reference
        }

        return in_array(strtolower($matches[1]), self::SAFE_SCHEMES, true) ? $trimmed : '';
    }

    /**
     * What `{scripturl}` becomes.
     *
     * 1.6 required the operator to type the installation URL into the config
     * screen, and the templates break quietly when it is wrong. An unset value
     * now means "site root", which is correct for every deployment that serves
     * zFeeder at the top of a domain - the container, the development server,
     * Railway - and an installation in a subdirectory still sets ZF_URL.
     */
    private function scriptUrl(): string
    {
        $configured = trim($this->config->string('base_url'));
        if ($configured === '') {
            return '/';
        }

        return str_ends_with($configured, '/') ? $configured : $configured . '/';
    }

    /** The template set actually in use, for `{set}`. */
    private function setName(RenderRequest $request): string
    {
        $spec = $request->template;
        if ($spec !== null && str_contains($spec, '/')) {
            return explode('/', $spec, 2)[0];
        }

        return $this->config->string('template_set');
    }

    /**
     * The 1.6 tokens, made available to the filter pass.
     *
     * An unfiltered legacy token never reaches here: `Tokens::substitute()`
     * leaves it alone so the `str_replace()` sequence below can handle it in
     * 1.6's exact order, which the golden tests depend on. A *filtered* one -
     * `{description|trunc:200}`, which a modern template will write - has no
     * 1.6 meaning at all, so resolving it here costs nothing and is the only
     * way the filter can see a value.
     *
     * @param  array<string, string> $values
     * @return array<string, string>
     */
    private function filterableLegacyValues(
        array $values,
        ?int $idToken,
        ?Item $item,
        RenderRequest $request,
    ): array {
        $out = [];
        foreach (Tokens::LEGACY as $token) {
            if (!in_array($token, Tokens::LEGACY_ITEM, true) && array_key_exists($token, $values)) {
                $out[$token] = $values[$token];
            }
        }

        if ($idToken === null) {
            return $out;
        }

        $out['id'] = (string) $idToken;
        $out['moreurl'] = $values['moreurl'] ?? '';
        $out['link'] = $item === null ? '' : $item->link;
        $out['title'] = $item === null ? '' : $item->title;
        $out['pubdate'] = $item === null ? '' : $item->rawDate;
        $out['description'] = $item === null ? '' : $this->itemBody($item, $request);

        return $out;
    }

    /**
     * Values for the nine 2.0 tokens. Item tokens are only present when an item
     * is in scope; a token with no value is left literal, as 1.6 left unknown
     * tokens.
     *
     * @param  array<string, string> $values
     * @return array<string, string>
     */
    private function modernValues(array $values, ?Item $item): array
    {
        $out = [
            'feedid' => $values['feedid'],
            'position' => $values['position'],
            'set' => $values['set'],
        ];
        if ($item === null) {
            return $out;
        }
        $enclosure = $item->enclosures[0] ?? null;

        return $out + [
            'author' => $item->author,
            'summary' => $item->summaryHtml,
            'content' => $item->contentHtml !== '' ? $item->contentHtml : $item->summaryHtml,
            'enclosure' => $enclosure === null ? '' : $enclosure->url,
            'itemdate_iso' => $item->published?->format(\DateTimeInterface::ATOM) ?? '',
            'itemdate_rel' => $this->relativeDate($item->published),
        ];
    }

    /** A short English "2 days ago"; empty when the item carried no usable date. */
    private function relativeDate(?\DateTimeImmutable $when): string
    {
        if ($when === null) {
            return '';
        }
        $now = $this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $seconds = $now->getTimestamp() - $when->getTimestamp();
        $future = $seconds < 0;
        $seconds = abs($seconds);

        $units = [
            ['year', 31_536_000],
            ['month', 2_592_000],
            ['week', 604_800],
            ['day', 86_400],
            ['hour', 3_600],
            ['minute', 60],
        ];
        foreach ($units as [$name, $size]) {
            if ($seconds >= $size) {
                $count = intdiv($seconds, $size);
                $label = $count . ' ' . $name . ($count === 1 ? '' : 's');

                return $future ? 'in ' . $label : $label . ' ago';
            }
        }

        return 'just now';
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function template(RenderRequest $request): Template
    {
        $spec = $request->template ?? $this->config->string('default_template');
        $resolved = $this->locator->resolve($spec);

        return $this->engine->load($resolved['path'], $resolved['set'] . '/' . $resolved['name'], $resolved['set']);
    }

    /**
     * 1.6 iterated `?zfposition` in the order the *request* gave, not in feed
     * order, so `zfposition=p3,p1` really did render feed 3 first.
     *
     * @param  list<RenderedFeed> $renderable
     * @param  list<int>          $positions
     * @return list<RenderedFeed>
     */
    private function inRequestedOrder(array $renderable, array $positions): array
    {
        $byPosition = [];
        foreach ($renderable as $rendered) {
            $byPosition[$rendered->feed->position] = $rendered;
        }
        $out = [];
        foreach ($positions as $position) {
            if (isset($byPosition[$position])) {
                $out[] = $byPosition[$position];
            }
        }

        return $out;
    }
}
