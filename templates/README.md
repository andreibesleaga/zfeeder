# Writing a zFeeder template

A template is one HTML file cut into named sections. The renderer finds each
section with a plain string search, substitutes `{tokens}`, and echoes the
pieces in a fixed order. That is the whole engine — it was in 2004 and it still
is, which is why **every 1.x template from 2004 loads unchanged in 2.0**.

Two sets ship:

* `templates/classic/` — the fourteen 2004 originals, byte-for-byte, proven by
  the golden corpus of 49 recorded files.
* `templates/modern/` — sixteen 2026 templates: thirteen counterparts of the
  originals — every classic template except `css` — plus `cards`, `list` and
  `ticker`. They use `public/assets/modern/zf.css`; see `docs/DESIGN-SYSTEM.md`.

Thirty templates in all.

---

## 1. The section markers

```html
<!-- zFeeder template header -->   once per page, before everything   <!-- ENDzFeeder template header -->
<!-- header -->     once per feed, before its items                   <!-- ENDheader -->
<!-- channel -->    the feed's title bar                              <!-- ENDchannel -->
<!-- news -->       one item, repeated                                <!-- ENDnews -->
<!-- footer -->     once per feed, after its items                    <!-- ENDfooter -->
<!-- between -->    separator, after each feed                        <!-- ENDbetween -->
```

Rules that follow from how the sections are cut
(`substr($html, strpos($html, '<!-- news -->'), …)`):

1. **The opening marker is part of the output**, the closing one is not. Write
   every marker on a line of its own and never put markup on the marker line.
2. Whatever sits between one closing marker and the next opening marker belongs
   to the previous chunk. Stray text there will be printed.
3. All five of `header`, `channel`, `news`, `footer`, `between` must exist, even
   if empty. A missing marker is a hard error. The page-header section is
   optional in 2.0 (1.6 aborted without it).
4. The order above is the order the renderer emits them in. Anything you open in
   `header` must be closed in `footer`; `between` is *outside* that element.
5. Sections may be empty — `sidebar` has an empty `news`, `ticker` an empty
   `between`.

A minimal but complete template:

```html
<!-- zFeeder template header -->
<link rel="stylesheet" href="{scripturl}assets/modern/zf.css">
<!-- ENDzFeeder template header -->

<!-- header -->
<section class="zf zf-feed" id="zfchannel{position}">
<!-- ENDheader -->

<!-- channel -->
	<div class="zf-bar"><h2 class="zf-bar__title"><a href="{chanlink}">{chantitle}</a></h2></div>
	<ul class="zf-items">
<!-- ENDchannel -->

<!-- news -->
		<li class="zf-item"><h3 class="zf-item__title"><a href="{link}">{title}</a></h3>
		<time class="zf-item__time" datetime="{itemdate_iso}">{pubdate}</time></li>
<!-- ENDnews -->

<!-- footer -->
	</ul>
</section>
<!-- ENDfooter -->

<!-- between -->
<div class="zf"><hr class="zf-sep"></div>
<!-- ENDbetween -->
```

---

## 2. Tokens

"Where" is the section a token has a value in. A token used outside its scope
is **left in the output verbatim**, exactly as 1.6 left it — so
`{title}` written in a `footer` prints the five characters `{title}`. Unknown
names are printed verbatim too; nothing ever throws because of a typo.

### The fifteen 1.6 tokens

Every value that comes from a feed is escaped exactly once, at the moment it is
substituted. Values the renderer builds itself are not escaped again, because
they are already well formed and a second pass would turn their `&amp;` into
`&amp;amp;`.

Link-shaped tokens get more than escaping: the scheme is checked, and anything
that is not `http`, `https`, `mailto` or a relative reference becomes an empty
string. Escaping alone is no defence there, because an escaped
`javascript:alert(1)` is still a working `javascript:` link.

| Token | Where | How it is written | Example output |
|---|---|---|---|
| `{chantitle}` | all | HTML-escaped | `BBC News \| News Front Page` |
| `{chanlink}` | all | scheme-checked, then escaped | `https://www.bbc.co.uk/news/` |
| `{chandesc}` | all | HTML-escaped | `Up-to-the-minute news, video and features.` |
| `{chanlogo}` | all | complete markup built by the renderer, or empty | `<a href="…"><img src="…" alt=""></a>` |
| `{feedurl}` | all | scheme-checked, then escaped | `https://feeds.bbci.co.uk/news/rss.xml` |
| `{lastupdated}` | all | built by the renderer | `Fri, 18 Sep 2026 09:20:00 GMT` |
| `{scripturl}` | all | built by the renderer, **ends with `/`** | `https://example.org/` |
| `{hideurl}` | all | built by the renderer | `?zfposition=p1,p3` |
| `{category}` | all | HTML-escaped | `news` |
| `{moreurl}` | all | built by the renderer | `?zfmore=2&amp;zfposition=p1,p2#zfchannel2` |
| `{id}` | channel, news | integer | in `news` the item index (`0`, `1`, …); in a one-bar channel the feed index |
| `{link}` | news | scheme-checked, then escaped | `https://lwn.net/Articles/1000001/` |
| `{title}` | news | HTML-escaped | `Kernel 6.19 merge window closes` |
| `{description}` | news | sanitised HTML, then truncated | `<p>The merge window closed on Sunday…</p>` |
| `{pubdate}` | news | HTML-escaped, the feed's own wording | `Fri, 18 Sep 2026 07:20` |

`{description}` is the one token that keeps markup. It passes through the
allow-list sanitiser, which removes scripts, event handlers, frames, forms and
dangerous URL schemes, and is then cut to `max_description_chars`.

#### The one exception: legacy fidelity

Rendering in *legacy fidelity* reproduces the 2004 output byte for byte,
including the fact that 1.6 wrote feed strings into the page unescaped. That
mode exists only so the golden tests can compare against output captured from
the original script; no served request ever uses it. If you are writing a
template, you can ignore it.

### The nine 2.0 tokens — HTML-escaped by default

New tokens have no 2004 output to stay compatible with, so they are escaped
unless you ask for `|raw`.

| Token | Where | Escaped? | Example output |
|---|---|---|---|
| `{feedid}` | all | escaped | `2` (this feed's ordinal on the page — safe for DOM ids) |
| `{position}` | all | escaped | `3` (its OPML position — what `zfposition=p3` selects) |
| `{set}` | all | escaped | `modern` |
| `{author}` | news | escaped | `staff@example.org` |
| `{summary}` | news | escaped — use `{summary\|raw}` for markup | `The merge window closed on Sunday…` |
| `{content}` | news | escaped — use `{content\|raw}` for markup | the full item body, falling back to the summary |
| `{enclosure}` | news | escaped | `https://example.org/podcast/42.mp3` |
| `{itemdate_iso}` | news | escaped | `2026-09-18T07:20:00+00:00` — for `<time datetime="">` |
| `{itemdate_rel}` | news | escaped | `2 hours ago` (empty when the item carried no date) |

Use `{itemdate_iso}` for machines and `{pubdate}` or `{itemdate_rel}` for
people:

```html
<time class="zf-item__time" datetime="{itemdate_iso}">{itemdate_rel}</time>
```

### Where "all" really means

`header`, `footer` and `between` see the channel-level tokens in 2.0. In strict
legacy-fidelity mode those three sections are echoed verbatim, tokens and all,
because that is what 1.6 did — which is also why `classic/simplecss` can put a
whole `<style>` block full of CSS braces in its `header` without the engine
touching it.

---

## 3. Filters

Syntax: `{token|filter}` or `{token|filter:argument}`. The list is closed —
three filters, fixed argument shapes, no expressions, no callables, no `eval`.
An unknown filter or a malformed argument is an error, not a silent pass.

| Filter | Argument | What it does | Example |
|---|---|---|---|
| `raw` | none | turns **off** the default escaping of a 2.0 token | `{content\|raw}` |
| `trunc` | whole number | truncates to that many characters without breaking words or leaving open tags | `{description\|trunc:200}` |
| `date` | quoted format | reformats any date the feed sent, including RFC 822; an unparsable value passes through unchanged | `{pubdate\|date:"D, d M Y"}` |

Filters chain left to right: `{content|trunc:300|raw}`.

---

## 4. Adding your own template

1. Drop `mytemplate.html` into `templates/modern/` (or `templates/classic/` for
   a 2004-style one) and select it with `zftemplate=mytemplate` or in the admin
   config. Names are validated against a strict pattern and resolved inside the
   set directory, so `../` goes nowhere.
2. Start the file with a comment naming the template, saying in one sentence
   when to use it, and — for a modern one — naming the 2004 template it
   descends from.
3. Keep the six markers on their own lines, in order.
4. For a modern template: semantic HTML only (`<section>` per feed, `<article>`
   or `<li>` per item, `<h2>` then `<h3>`, `<time datetime>`, real lists), no
   layout tables, no `<font>`, no inline `style` attributes. Put `class="zf …"`
   on the element `header` opens. Reuse the components in
   `public/assets/modern/zf.css` — see `docs/DESIGN-SYSTEM.md` for the list.
5. Assets live at `{scripturl}assets/modern/…` (modern) or
   `{scripturl}assets/classic/images/…` (classic). Always go through
   `{scripturl}`; a relative path breaks the moment the output is included from
   a page in another directory, which is the whole point of the product.
6. Render it and look at it:

   ```sh
   php tools/preview-templates.php        # → build/preview/<name>.html
   php -S 127.0.0.1:8899 -t . &
   ZF_PREVIEW_BASE=http://127.0.0.1:8899/build/preview \
   NODE_PATH=/path/to/node_modules node build/check-preview.mjs
   ```

   That renders every modern template with sample feeds, screenshots them at
   320 / 768 / 1280 / 1920 in light and dark into `build/preview/shots/`, and
   fails if anything makes the page scroll sideways.

## 5. Compatibility promise

Tokens are only ever added, never removed or renamed, and the section format
will not change. A template written in 2004 renders in 2.0 without an edit; a
template written today will keep working the same way.
