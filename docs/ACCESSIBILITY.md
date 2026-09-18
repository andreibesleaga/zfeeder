# Accessibility

## The commitment

zFeeder 2.0 targets **WCAG 2.2 Level AA** for the surfaces it renders itself:

| Surface | Where it lives | Commitment |
|---|---|---|
| The demonstration site | `src/Http/DemoController.php`, `public/assets/modern/zf-site.css` | WCAG 2.2 AA |
| The modern template set | `templates/modern/*.html`, `public/assets/modern/zf.css` | WCAG 2.2 AA |
| The administration panel | `templates-admin/`, `public/assets/modern/zf-admin.css` | WCAG 2.2 AA |
| The classic template set | `templates/classic/*.html` | **Exempt, deliberately.** See [The classic set](#the-classic-set) |

The commitment covers the markup zFeeder produces. It cannot cover the text inside a feed:
if a publisher writes a headline in all capitals or ships an image with no alternative text,
that reaches the page as the publisher wrote it.

## What is checked automatically

The browser suite is [Playwright](https://playwright.dev/) driving
[`@axe-core/playwright`](https://www.npmjs.com/package/@axe-core/playwright). It lives in
`tests/e2e/` and runs against a real server — the built container image by default.

| File | What it contains |
|---|---|
| `tests/e2e/playwright.config.js` | Three projects: `desktop` (Chrome, 1280×900), `mobile` (Pixel 7), `dark` (Chrome, `colorScheme: 'dark'`). Every test runs in all three. |
| `tests/e2e/helpers.js` | `expectNoAccessibilityViolations()` — runs axe with the tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa` and fails on any violation. `expectNoHorizontalOverflow()` — fails if `documentElement.scrollWidth` exceeds the viewport. |
| `tests/e2e/specs/public.spec.js` | The demonstration site and the HTTP endpoints. |
| `tests/e2e/specs/templates.spec.js` | Every template in both sets. |
| `tests/e2e/specs/admin.spec.js` | The administration panel: sign-in, all six screens, and a keyboard-only walk. |

### Pages that axe checks

| Page | Spec | Projects |
|---|---|---|
| `/` | `public.spec.js` | desktop, mobile, dark |
| `/demos/one-line`, `/demos/css`, `/demos/multiple`, `/demos/positions`, `/demos/categories`, `/demos/aggregator` | `public.spec.js` | desktop, mobile, dark |
| `/demos/template/modern/<name>` for all sixteen modern templates | `templates.spec.js` | desktop, mobile, dark |
| `/demos/template/modern/cards` with `colorScheme: 'dark'` forced | `templates.spec.js` | all three |
| `/admin/login` | `admin.spec.js` | desktop, mobile, dark |
| `/admin`, `/admin/add-new`, `/admin/subscriptions`, `/admin/config`, `/admin/import`, `/admin/updates`, signed in | `admin.spec.js` | desktop, mobile, dark |

The sixteen names are `cards`, `list`, `ticker`, `bluelogos`, `greenlogos`, `aqua`, `ampheta`,
`simpleblue`, `simplegray`, `titlebox`, `headlinebox`, `simplecss`, `infojunkie`, `rij`,
`sidebar` and `mainframe`. The threshold is zero violations; there is no allow-list of
accepted findings.

The result, on the run of 18 September 2026 (`ZF_MODE=builtin tools/run-e2e.sh`, Chromium
through Playwright): **258 browser tests passed, zero failures, zero axe violations.** That
is 78 tests from `public.spec.js`, 114 from `templates.spec.js` and 66 from
`admin.spec.js`, each spec's tests run once in each of the three projects.

### Pages that axe does not check

- **The classic template set.** `templates.spec.js` loads thirteen classic pages and
  asserts only that they render. This is a deliberate exemption, not a gap: the set
  reproduces 2004 markup and cannot pass. See [The classic set](#the-classic-set).
- **`templates/classic/css.html`.** The `CLASSIC` list in `templates.spec.js` has thirteen
  entries and the directory has fourteen files, so this one template is loaded by no
  browser test at all. It is covered by the golden suite.

### Other checks in the same suite

| Check | Where | Covers |
|---|---|---|
| Skip link moves to the content | `public.spec.js` | The first Tab on `/` focuses "Skip to content" and `Enter` reveals `#main`. |
| Skip link comes first in the panel | `admin.spec.js` | A keyboard-only walk of `/admin` reaches more than three controls, and the first name it sees matches `/skip/i`. |
| Every form control is labelled | `admin.spec.js` | On `/admin/config`, no non-hidden `input`, `select` or `textarea` lacks a `<label for>`, `aria-label` or `aria-labelledby`. |
| One current-page marker | `admin.spec.js` | `[aria-current="page"]` appears exactly once on `/admin/config`. |
| No inline script | `admin.spec.js` | Zero `<script>` elements without `src` and with content, which the nonce policy would block. |
| No horizontal overflow at 320, 768, 1280 and 1920 px | `public.spec.js` (`/`), `templates.spec.js` (`/demos/template/modern/cards`), `admin.spec.js` (all six screens) | Reflow |
| No `<font>` elements, `<article>` present | `templates.spec.js` | The modern set does not inherit 2004 markup |
| `time[datetime]` parses as a date | `templates.spec.js` | Machine-readable dates in `modern/list` |
| The body is not white under `prefers-color-scheme: dark` | `templates.spec.js` | Dark mode actually applies |

There is **no Lighthouse run and no 200 % zoom test**, although both appear in
`project/PLAN.md` §B8 and §B9. Neither is implemented. The overflow check at 640 CSS
pixels would be the same measurement as 200 % zoom at 1280, but no test sets a zoom
level, so reflow under zoom is reasoned about rather than observed.

## What was checked by reading the source

These are properties of the code rather than results of a test run.

- **Contrast.** The header of `public/assets/modern/zf.css` records the measured sRGB
  contrast ratio of every token pair in both themes: body text 16.48:1 light and 15.68:1
  dark, muted text 6.45:1 and 8.55:1, accent 6.25:1 and 9.06:1. The lowest recorded pair is
  5.82:1. axe recomputes contrast from the rendered page on every checked page, so the two
  agree or the suite fails.
- **Focus indicator.** `.zf :focus-visible` is a 3 px solid outline in the accent colour with
  a 2 px offset (`zf.css`). The accent doubles as the ring, so the ring inherits the ratios
  above.
- **Motion.** `zf.css` declares transitions only inside
  `@media (prefers-reduced-motion: no-preference)`; there is no `@keyframes` rule and no
  auto-scrolling content. The `ticker` template scrolls with `overflow-x: auto`, under the
  reader's control.
- **Reflow.** Modern component layout is driven by three `@container` queries rather than
  viewport media queries, because the output usually lives in someone else's column.
- **Live regions.** `templates-admin/shared/partials/flash.twig` is a single
  `role="status" aria-live="polite"` region; error paragraphs use `role="alert"`.
- **The sign-in form.** `templates-admin/shared/login.twig` has a `<label>` per field,
  `autocomplete="username"` and `autocomplete="current-password"`, and adds `aria-invalid`
  plus `aria-describedby` pointing at the error paragraph when a sign-in fails.
- **Bypass blocks.** Both admin layouts (`templates-admin/modern/layout.twig`,
  `templates-admin/classic/layout.twig`) open with a skip link to `#zf-main`, which carries
  `tabindex="-1"`. The demonstration site has the same link to `#main`, but `#main` there has
  no `tabindex`, so the browser may not move focus with it.
- **Target size.** `zf-admin.css` defines `--zfa-tap: 44px` and applies it as a minimum size
  to buttons, inputs, menu links and icon buttons.
- **Forced colours.** Both `zf.css` and `zf-admin.css` carry a
  `@media (forced-colors: active)` block, so borders survive Windows high contrast mode.

[DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) lists the same rules from the other direction, as
constraints on anyone writing a new template, and records the measured ratio of every colour
variant.

**No screen reader pass has been performed or recorded**, with any of NVDA, JAWS, VoiceOver
or Orca. Treat every "Met" below that depends on announcement as reasoning from the markup,
not as an observation.

## WCAG 2.2 checklist

This applies to the demonstration site and the modern template set, which are the surfaces
the automated checks cover. The administration panel is assessed separately below.

| # | Criterion | Level | Status | Note |
|---|---|---|---|---|
| 1.1.1 | Non-text Content | A | Partly met | Every image carries an `alt` attribute and axe finds none missing. The channel logo is emitted by `Renderer::chanLogo()` with the literal `alt="[logo]"`, inherited from 1.6. The site wordmark uses `alt=""` beside its text. |
| 1.2.1 | Audio-only and Video-only | A | Not applicable | No media is shipped, and `Render\Sanitizer` drops `audio`, `video`, `source`, `track`, `object`, `embed` and `iframe` from feed content. |
| 1.2.2 | Captions (Prerecorded) | A | Not applicable | As 1.2.1. |
| 1.2.3 | Audio Description or Media Alternative | A | Not applicable | As 1.2.1. |
| 1.2.4 | Captions (Live) | AA | Not applicable | As 1.2.1. |
| 1.2.5 | Audio Description (Prerecorded) | AA | Not applicable | As 1.2.1. |
| 1.3.1 | Info and Relationships | A | Met | Headings, lists, `<article>`, `<time datetime>`, `<nav>`, `<main>`. `templates.spec.js` asserts the modern set has no `<font>` element and does emit `<article>`. axe covers the rest. |
| 1.3.2 | Meaningful Sequence | A | Met | Source order is the reading order; no CSS reorders content. |
| 1.3.3 | Sensory Characteristics | A | Met | No instruction refers to shape, size or position. |
| 1.3.4 | Orientation | AA | Met | No orientation media query and no lock anywhere in the CSS. |
| 1.3.5 | Identify Input Purpose | AA | Met | The only field with a WCAG-defined purpose is the sign-in pair, and both carry `autocomplete`. |
| 1.4.1 | Use of Color | A | Met | Links in feed text keep the browser underline; `zf.css` only tunes thickness and offset. Standalone links styled `text-decoration: none` (`.zf-tool`, item title headings) are not inside a block of text. |
| 1.4.2 | Audio Control | A | Not applicable | Nothing plays. |
| 1.4.3 | Contrast (Minimum) | AA | Met | Ratios recorded in `zf.css`, rechecked by axe on every checked page in light and dark. |
| 1.4.4 | Resize Text | AA | Partly met | Everything inside `.zf` is sized in `rem`, so browser zoom works. `zf-site.css` sets `font-size: 16px` on `body.zf-site`, so the demonstration site does not follow a changed browser default font size. |
| 1.4.5 | Images of Text | AA | Met | The only image of text is the wordmark, which the criterion exempts as a logotype. |
| 1.4.10 | Reflow | AA | Partly met | Tested at 320 px for `/` and `modern/cards` only. The other fifteen modern templates are checked by axe at the Pixel 7 viewport but have no overflow assertion. |
| 1.4.11 | Non-text Contrast | AA | Met | The focus ring is the accent colour, measured at 6.25:1 light and 9.06:1 dark. Not machine-checked: axe does not test this criterion. |
| 1.4.12 | Text Spacing | AA | Not verified | No test applies the WCAG text-spacing overrides. |
| 1.4.13 | Content on Hover or Focus | AA | Met | Nothing appears on hover or focus except native `title` tooltips, which the criterion does not cover. |
| 2.1.1 | Keyboard | A | Partly met | Every control is a native link, button or form field, and there is no custom key handling. The only scripted keyboard check is the skip link on `/`. |
| 2.1.2 | No Keyboard Trap | A | Met | No modal, no custom focus management, no embedded plug-in. Not machine-checked. |
| 2.1.4 | Character Key Shortcuts | A | Not applicable | None are defined. |
| 2.2.1 | Timing Adjustable | A | Partly met | The panel signs out an idle session after `ZF_SESSION_IDLE` (default 1800 s, configurable from 60 s to 86400 s). There is no warning before it expires and no in-page way to extend it. |
| 2.2.2 | Pause, Stop, Hide | A | Not applicable | Nothing moves, blinks or auto-updates. The `ticker` strip scrolls only when the reader scrolls it. |
| 2.3.1 | Three Flashes or Below Threshold | A | Met | Nothing flashes. |
| 2.4.1 | Bypass Blocks | A | Met | A skip link is the first focusable element on the site and in both admin skins, and `public.spec.js` asserts it. |
| 2.4.2 | Page Titled | A | Met | Every page sets a distinct `<title>`. |
| 2.4.3 | Focus Order | A | Met | Focus order is DOM order; no positive `tabindex` exists in the tree. Not machine-checked. |
| 2.4.4 | Link Purpose (In Context) | A | Met | Card "Read" links append the item title in a visually hidden `.zf-sr` span. axe checks discernible names. |
| 2.4.5 | Multiple Ways | AA | Partly met | Navigation is the only route. There is no search and no site map, and 2.x will not add search — see [ROADMAP.md](ROADMAP.md). |
| 2.4.6 | Headings and Labels | AA | Met | One `<h1>` per page, channels at `<h2>`, items at `<h3>`. |
| 2.4.7 | Focus Visible | AA | Met | `.zf :focus-visible`, 3 px outline, 2 px offset. |
| 2.4.11 | Focus Not Obscured (Minimum) | AA | Not verified | Relevant only to the panel; see below. |
| 2.5.1 | Pointer Gestures | A | Not applicable | No multipoint or path-based gesture. |
| 2.5.2 | Pointer Cancellation | A | Met | Activation is native `click` on links and buttons. |
| 2.5.3 | Label in Name | A | Met | Visible text is the accessible name; no `aria-label` contradicts it. |
| 2.5.4 | Motion Actuation | A | Not applicable | Nothing responds to device motion. |
| 2.5.7 | Dragging Movements | AA | Not applicable | Nothing is drag-operated; there is no `draggable` attribute in the tree. |
| 2.5.8 | Target Size (Minimum) | AA | Partly met | Panel controls are at least 44 px. Links inside feed text are inline links in a sentence, which the criterion excepts. |
| 3.1.1 | Language of Page | A | Met | `<html lang="en">` on the site and both admin layouts. |
| 3.1.2 | Language of Parts | AA | **Not met** | Feed items are rendered without a `lang` attribute even when the subscription records one. The `feeds` table has a `language` column and the OPML dialect carries it, but no template emits it. |
| 3.2.1 | On Focus | A | Met | Focus changes nothing. |
| 3.2.2 | On Input | A | Met | The one input-triggered behaviour is the panel's category selector (`hx-trigger="change"`), which replaces a table in place. Location and focus do not change. |
| 3.2.3 | Consistent Navigation | AA | Met | The same navigation, in the same order, on every page. |
| 3.2.4 | Consistent Identification | AA | Met | The same control has the same name everywhere. |
| 3.2.6 | Consistent Help | A | Not applicable | No help mechanism is offered on any page. |
| 3.3.1 | Error Identification | A | Met | Errors are `role="alert"` paragraphs, and the offending field gets `aria-invalid` and `aria-describedby`. |
| 3.3.2 | Labels or Instructions | A | Met | Every field has a `<label for=…>`. |
| 3.3.3 | Error Suggestion | AA | Partly met | The sign-in error deliberately does not say which half was wrong, because naming it turns the form into an account-enumeration oracle. This is a considered trade-off, documented in `LoginController`. |
| 3.3.4 | Error Prevention (Legal, Financial, Data) | AA | Not applicable | No legal or financial transaction exists. |
| 3.3.7 | Redundant Entry | A | Met | A failed sign-in redisplays the user name that was submitted. |
| 3.3.8 | Accessible Authentication (Minimum) | AA | Met | One user name and one password, with `autocomplete` set so a password manager can fill them. No CAPTCHA, no puzzle, no transcription step, no paste restriction. |
| 4.1.2 | Name, Role, Value | A | Met | Native elements throughout; axe covers this on every checked page. |
| 4.1.3 | Status Messages | AA | Met | `role="status" aria-live="polite"` for confirmations, `role="alert"` for errors. |

`4.1.1 Parsing` is not listed: it was removed in WCAG 2.2.

### The administration panel

The panel is built from the same tokens and the same rules, and
`tests/e2e/specs/admin.spec.js` runs axe over the sign-in form and all six screens in all
three projects, with zero violations.

`admin.spec.js` runs against one skin. Every screen lives in `templates-admin/shared/`;
the two skins are a single layout file each, `templates-admin/modern/layout.twig` and
`templates-admin/classic/layout.twig`, and `ZF_ADMIN_SKIN` chooses between them. Neither
`tools/run-e2e.sh` nor `admin.spec.js` sets `ZF_ADMIN_SKIN`, so what the CI suite sees is
the default, `modern`.

The classic skin is covered by a second script, `build/check-admin.mjs`, which signs in
and walks the six screens for **both** skins at 1280 and 375 pixels in light and dark,
asserting no horizontal overflow, that the stylesheet is applied, and — at 1280 — zero axe
violations. It takes the axe bundle and a Playwright install through environment
variables:

```sh
ZF_AXE=/path/to/axe.min.js NODE_PATH=/path/to/node_modules node build/check-admin.mjs
```

It is not part of CI, so the classic skin's axe result is as fresh as the last time
somebody ran that script, while the modern skin's is re-checked on every push.

One further point is open:

- **2.4.11 Focus Not Obscured.** `zf-admin.css` gives the masthead `position: sticky` at the
  top and the action bar `position: sticky` at the bottom. No `scroll-padding` is set, so a
  control scrolled into view by the Tab key can end up underneath one of them. axe does not
  test this criterion, and no test in the suite does either.

## The classic set

`templates/classic/*.html` are the fourteen 2004 templates, reproduced so exactly that
`tests/Golden/` compares the output byte for byte against the original script running on PHP
5.6. They are not accessible and they are not going to be. Verified by reading the files:

| Template | `<table>` elements | `<font>` elements |
|---|---|---|
| `bluelogos.html` | 2 | 5 |
| `greenlogos.html` | 2 | 5 |
| `simplegray.html` | 2 | 4 |
| `simplecss.html` | 1 | 4 |
| `aqua.html` | 1 | 3 |
| `titlebox.html` | 3 | 3 |
| `simpleblue.html` | 2 | 4 |
| `headlinebox.html` | 3 | 2 |
| `ampheta.html` | 3 | 1 |
| `css.html`, `infojunkie.html`, `mainframe.html`, `rij.html`, `sidebar.html` | 0 | 0 |

The `<table>` elements are layout tables with no `<th>`, no caption and no scope, which fails
1.3.1. The `<font>` elements set `face="Verdana, Arial, Helvetica, sans-serif"` and
`size="1"` or `size="2"`, which is presentation in markup, and `size="1"` is small text the
reader cannot influence through the page. `bgcolor` attributes carry the colour scheme.
`templates/modern/*.html` contain zero `<table>` and zero `<font>` elements, and
`templates.spec.js` asserts both.

**Why the classic set is kept.** The point of this project is that a 2004 installation can be
carried forward rather than replaced. A site that has run `bluelogos` since 2004 gets the same
page from 2.0, which is the whole compatibility promise in [VERSIONING.md](../VERSIONING.md);
`tests/Golden/` exists to prove it, and `CONTRIBUTING.md` freezes the files. Making them
accessible would mean changing their output, which is the one thing they exist not to do.

**What to use instead.** Every classic template has a same-named counterpart in
`templates/modern/`, built from the same tokens with semantic markup. Switching set is a
configuration change, not a rewrite of your page. `modern` is the default and the set the
commitment above applies to. Use `classic` only when byte-identical 2004 output is the
requirement.

## Running the checks

The suite needs Docker (for the default container mode) and Node 22.

```
tools/run-e2e.sh                                  # build the image, run everything
ZF_MODE=builtin tools/run-e2e.sh                  # use php -S instead; faster locally
tools/run-e2e.sh specs/templates.spec.js          # one spec file
tools/run-e2e.sh --project=desktop                # one project
tools/run-e2e.sh --headed                         # watch it
```

Arguments after the script name are passed through to `playwright test`.

To test an instance that is already deployed, set both variables — the script only skips
starting its own server when `ZF_BASE_URL_EXTERNAL` is set:

```
ZF_BASE_URL=https://example.org ZF_BASE_URL_EXTERNAL=1 tools/run-e2e.sh
```

The script pre-seeds the cache from the test fixtures through `tools/seed-e2e-cache.php`, so
the results do not depend on a live feed or on what today's headlines happen to say.

In CI the suite is the job named **Browser tests and accessibility** in
`.github/workflows/ci.yml`. It depends on the `image` job, installs Chromium, runs
`tools/run-e2e.sh`, and uploads `test-results/` as an artefact whether it passed or failed.
[CONTRIBUTING.md](../CONTRIBUTING.md) requires it to be green before a pull request is
merged.

## Reporting a problem

Open an issue at <https://github.com/andreibesleaga/zfeeder/issues>. Include the page, the
template set, the browser and assistive technology with versions, and what you expected to
happen. A barrier in the modern set, the site or the panel is a bug. A barrier in the classic
set is documented behaviour, and the answer will be to switch sets.
