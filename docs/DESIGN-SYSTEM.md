# zFeeder 2.0 design system

Two stylesheets, no framework, no build step, no dependencies.

| File | Scope | Rules it must obey |
|---|---|---|
| `public/assets/modern/zf.css` | the **feed output** — the markup `templates/modern/*.html` emits | everything under the `.zf` root class; **container** queries only, never viewport queries; must survive being embedded in an unknown page |
| `public/assets/modern/zf-admin.css` | the **admin panel** — a first-party app that owns its page | `:root` tokens, viewport media queries allowed, 8-pt spacing scale, two skins over one set of markup |

Supporting assets: `zfeeder-logo.svg` (wordmark, dark-mode aware),
`icons.svg` (9-symbol sprite), `htmx.min.js` (admin only — see
`public/assets/modern/VENDOR.md`).

---

## 1. Output tokens (`zf.css`)

Every token is declared on `.zf` in light values. Dark mode **re-points** each
token at a pre-declared dark value; no colour is ever defined only inside a
media query.

| Token | Light | Dark | What it is for |
|---|---|---|---|
| `--zf-bg` | `#ffffff` | `#0f1418` | the surface the feed sits on |
| `--zf-surface` | `#f5f7f9` | `#19212a` | cards, boxes, channel bars |
| `--zf-fg` | `#16202a` | `#e6edf3` | body text and headlines |
| `--zf-fg-muted` | `#516070` | `#a3b2c0` | dates, descriptions, secondary labels |
| `--zf-accent` | `#006699` | `#5fc0ef` | links, solid bars, the focus ring — **the 2004 blue, kept** |
| `--zf-accent-fg` | `#ffffff` | `#04131d` | text drawn *on* the accent |
| `--zf-border` | `#c7d2db` | `#2f3a45` | hairlines between items and around boxes |
| `--zf-radius` | `8px` | same | corner radius (`0` gives a square 2004 look) |
| `--zf-gap` | `0.75rem` | same | the rhythm between components |
| `--zf-font` | system sans stack | same | body font |
| `--zf-font-mono` | system mono stack | same | ornaments and numeric runs |
| `--zf-shadow` | two-layer soft shadow | darker two-layer | card elevation |
| `--zf-maxw` | `72rem` | same | the widest the output ever becomes |

Internal (not part of the public API, but documented so nothing surprises you):
`--zf-tint` (an 8 % accent wash over the surface, via `color-mix`, with a plain
surface fallback), `--zf-line`, `--zf-pad` (`clamp(0.6rem, 2cqi, 1rem)` — it
grows with the *container*, not the window), plus `--zf-*-l` / `--zf-*-d` pairs
that hold the two themes' raw values.

### Colour variants

A variant moves the accent only; the neutrals never move, so contrast stays
predictable. `.zf--green` (greenlogos), `.zf--aqua` (aqua), `.zf--amber`
(ampheta), `.zf--gray` (simplegray), `.zf--paper` (simplecss — also switches to
a serif face and a 2px radius).

---

## 2. Output components

| Class | What it is |
|---|---|
| `.zf` | the root of one feed. Carries the tokens, `container-type: inline-size`, `max-inline-size`, the background and the font. Every template puts it on its `<section>`. |
| `.zf-feed` | spacing between consecutive feeds |
| `.zf-bar`, `.zf-bar--flat`, `.zf-bar--solid` | the channel title bar: tinted, underlined, or filled with the accent |
| `.zf-bar__logo` `__head` `__title` `__desc` `__time` `__tools` | its parts (`__logo` collapses when `{chanlogo}` is empty) |
| `.zf-tool` | a pill link: *more*, *XML*, *hide*, *read* |
| `.zf-items`, `.zf-items--rows` | the item list; `--rows` gives the tinted alternating rows of 2004 |
| `.zf-item`, `.zf-item__title` `__time` `__desc` | one item |
| `.zf-grid` + `.zf-card` + `.zf-card__foot` | the cards layout (1 → 2 → 3 → 4 columns) |
| `.zf-list` | dense rows with the date pinned to the end |
| `.zf-box` (+ root `.zf--box`) | a narrow rail card (titlebox, headlinebox) |
| `.zf-nav` (+ root `.zf--nav`) | the sidebar rail of channel names |
| `.zf--read` | the reading pane (mainframe): 46rem measure, larger type |
| `.zf-paper` + `.zf-paper__head` (+ root `.zf--paper`) | the newspaper layout |
| `.zf-fold` | a `<details>` channel — the 2004 infojunkie JavaScript folder, without JavaScript |
| `.zf-ticker` + `__label` `__track` `__item` | the header strip |
| `.zf-sep`, `.zf-sep--dots` | the `between` separator |
| `.zf-credit`, `.zf-sr` | credit line; visually-hidden text |

### Container queries, not viewport queries

The output is embedded in someone else's column, so the window width says
nothing about the space available. `.zf` declares
`container-type: inline-size; container-name: zf` and every layout decision is
an `@container zf (min-width: …)` rule at **30rem**, **48rem** and **68rem**.
A `@supports not (container-type: inline-size)` block keeps old browsers on the
single-column shape the markup already produces.

---

## 3. Admin tokens and components (`zf-admin.css`)

Spacing is an 8-pt scale with a 4px half-step: `--zfa-s-half: 4px`,
`--zfa-s1: 8px`, `--zfa-s2: 16px`, `--zfa-s3: 24px`, `--zfa-s4: 32px`,
`--zfa-s5: 48px`, `--zfa-s6: 64px`. Nothing in the admin uses a spacing value
that is not on that scale.

| Token | Light | Dark | For |
|---|---|---|---|
| `--zfa-bg` | `#eef2f6` | `#0d1216` | page |
| `--zfa-surface` | `#ffffff` | `#12181f` | cards, inputs, toasts |
| `--zfa-surface-2` | `#f5f7f9` | `#19212a` | table headers, hovers, notes |
| `--zfa-fg` | `#16202a` | `#e6edf3` | text |
| `--zfa-muted` | `#516070` | `#a3b2c0` | help text |
| `--zfa-accent` | `#006699` | `#5fc0ef` | the one accent: links, primary button, headings, focus ring |
| `--zfa-accent-fg` | `#ffffff` | `#04131d` | text on the accent |
| `--zfa-border` | `#ccd6de` | `#2b3640` | borders |
| `--zfa-danger` | `#b3261e` | `#ff8a80` | destructive actions, errors |
| `--zfa-ok` | `#1b6b3a` | `#6ee7a0` | success |
| `--zfa-warn` | `#8a4b00` | `#f0b45c` | the "notes" rail |
| `--zfa-radius` / `--zfa-radius-sm` | `10px` / `6px` | same | shape |
| `--zfa-tap` | `44px` | same | minimum touch target — every control uses it |
| `--zfa-maxw` | `76rem` | same | shell width |

`--zfa-inset` (the background of a disabled field or a read-only value) and
`--zfa-shadow` complete the set.

The **class names come from the markup**, not from this file: `zf-admin.css`
styles `templates-admin/` exactly as it is written, and every rule is scoped
under `.zf-admin` so it can never meet zf.css, which shares the `zf-` prefix.

| Area | Classes |
|---|---|
| frame | `.zf-admin` `.zf-skin-modern` `.zf-skin-classic` `.zf-skip` `.zf-main` `.zf-heading` `.zf-subheading` `.zf-lede` `.zf-footer` `.zf-footer-line` |
| masthead | `.zf-header` `.zf-brand` `.zf-logo` `.zf-brand-name` `.zf-signout` `.zf-signed-in` |
| navigation | `.zf-nav` `.zf-nav-list` `.zf-nav-item` `.zf-nav-link` (+ `.is-current`) |
| content | `.zf-panel` `.zf-note` (+ `.zf-note-locked`, `.zf-note-attention`) `.zf-error` `.zf-empty` `.zf-help` `.zf-value` |
| announcements | `.zf-flash` `.zf-flash-message` `.zf-flash-success` `.zf-flash-error` `.zf-flash-info` |
| status | `.zf-status` `.zf-status-item` |
| lists | `.zf-screen-list` `.zf-screen-item` `.zf-screen-link` `.zf-screen-description` `.zf-export-list` `.zf-export-item` `.zf-export-link` `.zf-external` `.zf-discovery` `.zf-found-list` `.zf-found-item` `.zf-found-form` `.zf-found-title` `.zf-found-type` `.zf-bookmarklet` |
| forms | `.zf-form` `.zf-form-login` `.zf-form-table` `.zf-toolbar` `.zf-fieldset` `.zf-legend` `.zf-field` (+ `.zf-field-check`, `.zf-field-inline`, `.is-locked`) `.zf-label` `.zf-input` `.zf-input-number` `.zf-input-file` `.zf-select` `.zf-checkbox` `.zf-radio` |
| actions | `.zf-actions` `.zf-actions-sticky` `.zf-button` `.zf-button-primary` `.zf-button-danger` `.zf-button-quiet` `.zf-button-icon` |
| table | `.zf-subscriptions` `.zf-table` `.zf-table-caption` `.zf-th-note` `.zf-row` (+ `.is-unsubscribed`) `.zf-cell` `.zf-cell-check` `.zf-cell-channel` `.zf-cell-order` `.zf-channel-title` `.zf-channel-language` `.zf-channel-status` `.zf-channel-error` `.zf-channel-description` `.zf-channel-address` `.zf-feed-link` |
| utility | `.zf-sr-only` |

Three admin details worth knowing:

* **Nav without JavaScript.** `.zf-nav-list` is a wrapping flex row of links.
  It needs no disclosure, no script and no breakpoint gymnastics: on a phone it
  becomes two or three rows of 44px targets.
* **Stacked table.** Below 40rem the subscriptions table becomes one card per
  feed (`.zf-row` turns into a flex column, `.zf-cell-channel` gets `order: -1`
  so the feed's name heads its own card, and `<thead>` is visually hidden).
  Each control in the markup carries its own `.zf-sr-only` label, and those
  labels become the *visible* ones in that layout — so nothing is left without
  a name once the column headers are gone. The arrow buttons keep their labels
  hidden, because there the arrow is the visible label.
* **Announcements.** `<div class="zf-flash" role="status" aria-live="polite">`
  is a permanent region in the layout; htmx swaps messages into it out of band,
  so nothing moves focus. It collapses to nothing when empty.

### The classic skin

`<body class="zf-admin zf-skin-classic">` re-declares the same tokens as the
2004 panel: Verdana, `#D0ECFD` bars, `#006699` headings, square corners, no
shadows, compact rows, centred headings. **The HTML does not change** — no
`<font>` tags, no layout tables, same semantics, same ARIA. It is a light-only
skin, as it was in 2004. Pick it with `ZF_ADMIN_SKIN=classic` (or the
`admin_skin` option).

### How the admin is verified

`build/check-admin.mjs` signs in to a running panel, walks the six screens at
1280 and 375 in both colour schemes for both skins, asserts there is no
horizontal overflow, proves the stylesheet is actually applied, screenshots
everything into `build/preview/admin-shots/`, and runs axe-core on every
screen:

```sh
ZF_AXE=/path/to/axe.min.js NODE_PATH=/path/to/node_modules node build/check-admin.mjs
```

---

## 4. Accessibility rules, with the numbers

Measured with the WCAG 2.2 relative-luminance formula on sRGB values.

**Output, light** — fg/bg 16.48, fg/surface 15.35, muted/bg 6.45,
muted/surface 6.00, accent/bg 6.25, accent/surface 5.82, accent-fg on accent
6.25.
**Output, dark** — fg/bg 15.68, fg/surface 13.75, muted/bg 8.55,
muted/surface 7.50, accent/bg 9.06, accent/surface 7.95, accent-fg on accent
9.20.
**Variant accents** — green 5.94 / 10.84, aqua 5.66 / 10.36, amber 6.32 /
10.10, gray 7.65 / 10.52, paper 8.64 / 9.55 (light / dark).
**Admin, light** — fg 16.48, muted 6.45, accent 6.25, danger 6.54, ok 6.54.
**Admin, dark** — fg 14.85, muted 8.24, accent 8.73, danger 7.82, ok 11.56.
**Classic skin** — body text on the `#D0ECFD` bar 13.42, `#006699` heading on
the same bar 5.09, body on white 16.48, muted on white 7.00.

Everything above is ≥ 4.5:1, so body *and* muted text pass AA in both themes.
The focus ring is the accent at 3px with a 2px offset: 6.25:1 (light) and
9.06:1 (dark) against the backdrop — comfortably over the 3:1 that 1.4.11 asks
for.

The rest of the checklist, all of it enforced in the CSS rather than asserted:

* Semantic markup only: `<section>` per feed, `<article>`/`<li>` per item,
  `<h2>` then `<h3>`, `<time datetime="{itemdate_iso}">`, real `<ul>`/`<ol>`.
  No layout tables, no `<font>`, no inline `style` attributes.
* **No `!important` anywhere**, in either file.
* Motion is declared only inside `@media (prefers-reduced-motion: no-preference)`,
  so a reduced-motion user has nothing to switch off.
* No fixed heights; images are `max-width: 100%; height: auto`.
* 44×44 CSS px minimum for every admin control and for the `<summary>` of a
  collapsible feed.
* Long titles and unbreakable URLs use `overflow-wrap: anywhere`; the ticker
  scrolls *inside itself* and never widens the page.
* Verified at 320 / 768 / 1280 / 1920 in both colour schemes with
  `document.documentElement.scrollWidth <= window.innerWidth` — 200 % zoom at
  1280 is the same measurement as 100 % at 640, which is covered.
* `@media (forced-colors: active)` keeps every border visible in Windows high
  contrast; `@media print` drops shadows, tool pills and chrome.

---

## 5. How to add a template

1. Copy the closest existing file in `templates/modern/`. Keep it small: if you
   need 200 lines of markup, the design is wrong.
2. The first thing in the file is a comment with the template's name, one
   sentence about when to use it, and the 2004 template it descends from.
3. Keep the six section markers, each on its own line, in order:
   `zFeeder template header`, `header`, `channel`, `news`, `footer`, `between`.
   The renderer prints the *opening* marker, so never put markup on that line.
4. Put `class="zf …"` on the element the `header` section opens, and close it in
   `footer`. The `between` section is outside that element, so if it draws
   anything, wrap it in its own `<div class="zf">`.
5. Use the existing components and a variant class; add CSS to `zf.css` only
   when the shape genuinely does not exist yet, and add it as a `@container`
   rule, not a media query.
6. Render and look at it:

   ```sh
   php tools/preview-templates.php
   php -S 127.0.0.1:8899 -t . &            # icons and sprites need HTTP
   ZF_PREVIEW_BASE=http://127.0.0.1:8899/build/preview \
   NODE_PATH=/path/to/node_modules node build/check-preview.mjs
   ```

   The script writes `build/preview/<name>.html` plus screenshots at four widths
   in both themes into `build/preview/shots/`, and fails on any horizontal
   overflow. Open the pictures; the check only proves the page does not
   overflow, it cannot tell you the design is ugly.

## 6. How to theme the output from a host page

Override the custom properties on `.zf`. Nothing else is public API — class
names may change between 2.x releases, tokens will not.

```css
/* the host page's own stylesheet */
.zf {
  --zf-accent: #7b2ff7;     /* links, bars, focus ring */
  --zf-accent-fg: #ffffff;  /* text drawn on the accent */
  --zf-radius: 0;           /* square, like 2004 */
  --zf-font: "Inter", system-ui, sans-serif;
  --zf-maxw: 40rem;         /* narrower column */
  --zf-gap: 1rem;
}
```

Scope it further if only one feed should change:
`#zfchannel3 { --zf-accent: … }` — every feed section carries
`id="zfchannel{position}"`, which is also what the `{moreurl}` anchor targets.

Forcing a theme, on the `.zf` element or on any ancestor (the host page's own
theme switch can set it on `<html>`):

```html
<html data-zf-theme="dark">     <!-- or "light"; absent = follow the OS -->
```

An explicit `light` always beats `prefers-color-scheme: dark`; an explicit
`dark` always wins. If the host page paints its own background, set
`--zf-bg: transparent` — the tokens are the only place the output touches
colour.
