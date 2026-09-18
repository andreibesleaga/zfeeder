# 0012. Rebuild the frames demo as a CSS grid

## Status

Accepted.

## Context

`legacy/zfeeder-1.6/demo_frames.php` is the best-looking screenshot the 2004 project produced: a sources
sidebar on the left, the selected category's articles on the right, called "the aggregator" in the sidebar's
own markup. It is two `<iframe>` elements with pixel geometry — `width: 325px` for the sidebar,
`position: absolute; left: 332; width: 690px` for the main pane — on a `<body scroll="no">`.

That construction cannot be carried forward: two frames mean two page loads, a layout assuming a 1024-pixel
window, a category `<select>` driven by an inline `onChange`, and a page whose content is invisible to the
containing document. Dropping the demo was the other option, and it would remove the single image that best
explains what the product does.

## Decision

Keep the layout, discard the frames. The demo is `aggregator` in `src/Http/DemoController.php::DEMOS`,
annotated there with the 1.6 file it replaces.

`DemoController::aggregator()` emits one document: a `<section class="output aggregator">` containing an
`<aside>` with a "Sources" heading and a `<nav aria-label="Sources">` list of category links, and a
`<div class="reading">` holding the rendered feed. The current category's link carries `aria-current="page"`.
The method's own comment states it in one line: 2004 used two frames, a grid does the same job without them.

The geometry lives in `public/assets/modern/zf-site.css`, where `.output.aggregator` is a single-column grid
by default and becomes `16rem 1fr` above a 52rem viewport, so the narrow case is the base case rather than an
afterthought.

The demo renders through the `mainframe` template — the one the original main frame used — which exists in
both sets, so the page can be shown classic or modern through the `set` query parameter.

## Consequences

- Choosing a category is a link, not a `<select>` with an inline handler, so it works without JavaScript, can
  be opened in a new tab and is reachable by keyboard.
- The page is one request instead of three, and the whole layout is in the document that contains it.
- The screenshot pair in `docs/portfolio/img/pairs/` (`2004-frames-aggregator.png` beside
  `2026-frames-aggregator.png`) is honest only because the layout was reproduced rather than redesigned.

## Alternatives considered

- **Keep the iframes.** They still work in browsers, and they still cannot be made responsive, keyboard
  navigable or embeddable.
- **Drop the demo.** Less code and one fewer page to test, at the cost of the clearest picture of the
  product.

**Update, 18 September 2026.** The 1.6 tree was removed from this repository and archived at
<https://github.com/andreibesleaga/old-projects> (`zfeeder-1.6.zip`); the minimal 2004 corpus the tests
need now lives in `tests/fixtures/legacy-1.6/`.
