# 0007. symfony/html-sanitizer with an explicit allow-list

## Status

Accepted.

## Context

1.6 printed a feed's `<description>` straight into the host page, so any subscribed feed could run script in
any site that embedded zFeeder — the worst defect in the 2004 code. Feed markup is untrusted by definition:
the administrator chooses the subscription, not the content.

Writing the sanitiser here was never seriously an option: parsing differences between a sanitiser and the
browser are the entire attack surface of this problem class.

## Decision

`symfony/html-sanitizer` (`^7.1`), wrapped in `src/Render/Sanitizer.php`, which owns the policy rather than
scattering it across callers:

- an allow-list of elements mapped to the attributes each may carry: text markup, lists, headings, tables,
  `a[href]`, `img[src alt title width height]` and `time[datetime]`;
- dangerous containers are **dropped** with their contents, not blocked — blocking keeps the children, which
  would leave `alert(1)` in the page as text. The list runs from `script`, `style`, `iframe`, `object` and
  `svg` down to `math`, `audio` and `template`;
- link schemes are limited to `http`, `https` and `mailto`; relative links and media are refused;
- `data:` is not an allowed media scheme, because `data:image/svg+xml` is how a payload gets past an image
  allow-list, and `style` is not an allowed attribute, which removes `url(javascript:…)`;
- `rel="nofollow noopener"` and `target="_blank"` are forced onto every link, and input is capped at
  5,000,000 characters.

Sanitising happens in the render layer, not the parse layer, so `Item::$summaryHtml` keeps the feed's own
markup and the policy can differ per render.

## Consequences

- `src/Render/Renderer.php` has two paths. Normally item text is sanitised; with `allow_html_in_items` off it
  is reduced to text and escaped. Under `legacyFidelity` — the mode the goldens use to reproduce 1.6 output
  byte for byte — it passes through verbatim, and that mode must never be the default for a network request.
- Sanitising is not truncation: `src/Render/Truncator.php` shortens markup to the `max_description_chars`
  budget, counting only visible characters and closing whatever is still open at the cut.
- Dropping `svg` and `data:` means some legitimate modern feed images do not render. That is the trade.
  `tests/Unit/Render/SanitizerTest.php` holds the payload cases.

## Alternatives considered

- **HTMLPurifier.** Thorough, with a larger configuration surface and a cache directory it wants to write to
  — an extra writable path in a product whose predecessor was criticised for exactly that (ADR 0000).
- **`strip_tags()` with an allowed-tag list.** It ignores attributes, so `<a href="javascript:…">` survives.
