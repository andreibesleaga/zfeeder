# Templates

A zFeeder template is an HTML file cut into sections by HTML comments. The renderer
repeats one of those sections per feed and another per item, replacing `{token}`
placeholders as it goes. There is no expression language, no loops you can write, no
includes and no PHP: a template is data, and the engine only ever does substring
search, `substr()`, `preg_replace_callback()` and `str_replace()`.

That format was designed in 2004 and it has not changed. The templates shipped in
`templates/classic/` are the 2004 files, byte for byte, and they are proven against
output captured from zFeeder 1.6 running on PHP 5.6. What 2.0 adds is nine new
tokens, three filters, an optional once-per-page section, and escaping.

[`templates/README.md`](../templates/README.md) is the short reference that sits next
to the files, with the house style for writing a modern one. This page is the long
one: the rules the engine actually implements, and why.

**Contents**

- [Where templates live](#where-templates-live)
- [The file format](#the-file-format)
- [The section marker rule, exactly](#the-section-marker-rule-exactly)
- [What is rendered, and in what order](#what-is-rendered-and-in-what-order)
- [Tokens](#tokens)
- [Escaping](#escaping)
- [Filters](#filters)
- [The two template sets](#the-two-template-sets)
- [Writing your own template](#writing-your-own-template)
- [2004 templates still work](#2004-templates-still-work)
- [Not implemented yet](#not-implemented-yet)

---

## Where templates live

```
templates/
  classic/   14 files — the 2004 originals, unmodified
  modern/    16 files — the 2026 responsive set
```

`classic` and `modern` are the only two sets. `TemplateLocator::SETS` is a closed
list, and a request naming any other set is rejected.

A template is addressed as `set/name` or as a bare `name`:

| spec | resolves to |
|---|---|
| `modern/cards` | `templates/modern/cards.html` |
| `bluelogos` | `templates/<template_set>/bluelogos.html` |
| `RiJ` | `templates/<template_set>/rij.html` |

A bare name is looked up in the set named by the `template_set` option
(`ZF_TEMPLATE_SET`), which defaults to `classic`. See
[Configuration](CONFIGURATION.md).

The name must match `^[a-z0-9_-]{1,40}$` case-insensitively. It is tried as written
first and then lower-cased, which is how the 2004 spelling `RiJ` still finds
`rij.html`. The resolved path is then checked with `realpath()` and must sit under
`templates/`; 1.6 concatenated `$_GET['zftemplate']` straight into a path, and that
hole is what these two checks close.

A template is selected, in order of precedence:

1. per request — `?zftemplate=modern/cards`, or `template` in the query string of
   `/embed`, or `'template' => 'modern/cards'` passed to `zfeeder()`;
2. by configuration — `default_template` (`ZF_DEFAULT_TEMPLATE`) inside
   `template_set` (`ZF_TEMPLATE_SET`).

## The file format

A template file is plain HTML with six sections. Five are required and one is
optional:

| section | opening marker | emitted |
|---|---|---|
| page header | `<!-- zFeeder template header -->` | once per page, before the first feed. Optional. |
| header | `<!-- header -->` | once per feed, first |
| channel | `<!-- channel -->` | the channel bar; how often depends on two options |
| news | `<!-- news -->` | once per item |
| footer | `<!-- footer -->` | once per feed |
| between | `<!-- between -->` | once per feed, after the footer |

Each closing marker is the opening marker with `END` glued to the front of the name:
`<!-- ENDheader -->`, `<!-- ENDzFeeder template header -->`.

Anything outside the markers — a leading comment block, whitespace between one
section's closing marker and the next section's opening marker — is never emitted.
Every shipped template starts with a comment naming the template and its author;
that comment is documentation for whoever opens the file, not output.

A missing marker is fatal: `TemplateEngine::section()` throws `TemplateException`
naming the template and the section. In 2004 the same condition printed
"Error: template file could not be formatted" and called `exit`.

## The section marker rule, exactly

This is the whole of it, from `TemplateEngine::find()`:

```php
$start = strpos($html, '<!-- ' . $delimiter . ' -->');
$end   = strpos($html, '<!-- END' . $delimiter . ' -->');
if ($start === false || $end === false || $end < $start) {
    return null;          // -> TemplateException
}
return substr($html, $start, $end - $start);
```

Five consequences, all of them observable in the output:

**The marker is matched as a literal substring, not parsed.** It is exactly `<!--`,
one space, the name, one space, `-->`. Two spaces, a tab, a newline inside the
marker, or `<!--header-->` will not be found, and the template fails to load.

**The opening marker is part of the section and is printed.** `substr()` starts at
the offset of the opening marker, so every rendered page contains `<!-- header -->`,
`<!-- channel -->` and one `<!-- news -->` per item. That is what 2004 pages looked
like and what the golden corpus asserts. Do not try to strip them.

**The closing marker is not part of the section.** The chunk ends at the byte before
`<!-- ENDheader -->`.

**Text between a closing marker and the next opening marker is discarded.** In
`templates/classic/bluelogos.html` the `header` section ends with the `<table>` line;
the space after `<!-- ENDheader -->` and the two spaces before `<!-- channel -->` are
in no section and never reach the page. Indentation therefore moves around in the
output in a way that looks wrong and is correct.

**The first occurrence wins.** `strpos()` returns the first match, so writing
`<!-- news -->` twice silently uses the first one. Markers reversed in the file —
the closing one before the opening one — are reported as missing.

One name is a prefix of another: `<!-- zFeeder template header -->` and
`<!-- header -->`. They do not collide, because the search string for the short one
begins with `<!-- ` and in the long marker `header` is preceded by a space, not by
`<!-- `.

## What is rendered, and in what order

Given a category, `Renderer::render()` emits:

1. the **page header** section, once, if the template has one;
2. for each feed, in order: `header`, the channel bar and the items, `footer`,
   `between`;
3. the powered-by line, if `powered_by` is on and the request did not pass
   `zf_link=off`.

Feeds are filtered and ordered before any of that. A feed is rendered only if it is
subscribed, has a non-empty URL and has `showedItems` greater than zero
(`Feed::isRenderable()`), and feeds are sorted by their OPML `position`. If nothing
survives, the entire output is the string `" No feeds."`, leading space and all, and
nothing else — no page header, no powered-by.

`?zfposition=p3,p1` both filters and reorders: feeds are emitted in the order the
request listed them, not in position order.

### The item loop

```
for ($i = 0; $i < count($items); ++$i) {
    …emit the item…
    if (($i + 2 > $showedItems) && !$more) { break; }
}
```

The test runs after the item has been emitted, so a feed with `showedItems = 3`
renders three items. `?zfmore=<position>` suppresses the break for that one feed and
renders everything the cache holds. `showedItems` is a per-subscription value held in
the OPML file, not a configuration option.

### Where the channel bar goes

Two options decide it: `channel_location` (`top`, `bottom`, `none`) and
`channel_one_bar`.

| `channel_location` | `channel_one_bar` | result |
|---|---|---|
| `top` | on (default) | one bar, before all the items of the feed |
| `bottom` | on | one bar, after all the items of the feed |
| `top` | off | the bar is repeated before every item |
| `bottom` | off | the bar is repeated after every item |
| `none` | either | the `channel` section is never emitted |

The section must still exist in the file even when `channel_location` is `none`;
the engine parses all five sections before the renderer decides what to use.

The two one-bar settings are not just a layout choice: they change which tokens
work inside the `channel` section. See the next part.

## Tokens

A token is `{name}`. Names are matched by
`/\{([A-Za-z_][A-Za-z0-9_]*)(\|[^{}]*)?\}/`, so a brace that is not followed by an
identifier is left alone — which is why `templates/classic/simplecss.html` can put a
whole `<style>` block full of CSS braces in its `header` section without the engine
touching it.

**An unknown token is printed literally**, exactly as 1.6 printed it. `{frooble}`
comes out as `{frooble}`. Nothing warns you; check your spelling against the tables
below.

### The 1.6 tokens

Fifteen, unchanged since 2004.

| token | value |
|---|---|
| `{chantitle}` | the channel's title, from the feed |
| `{chanlink}` | the channel's link, from the feed |
| `{chandesc}` | the channel's description, from the feed |
| `{chanlogo}` | `<a href="…"><img src="…" border="0" alt="[logo]" title="…" /></a>`, built by the renderer from the channel's image; empty when the feed has none |
| `{feedurl}` | the subscription's XML URL, from the OPML file |
| `{lastupdated}` | when the cache entry was fetched, as `D, d M Y H:i:s GMT` in UTC; empty when nothing is cached |
| `{scripturl}` | the installation base URL with a trailing slash — `base_url` (`ZF_URL`), or `/` when that is unset |
| `{category}` | the requested category name, or the resolved category's name when none was requested |
| `{hideurl}` | a link back to the current page with this feed's position removed from `zfposition` |
| `{moreurl}` | a link back to the current page with `zfmore=<position>`, ending in `#zfchannel<position>` |
| `{id}` | the item's index within the feed, counting from zero |
| `{title}` | the item's title |
| `{link}` | the item's link |
| `{pubdate}` | the item's publication date, as the feed wrote it |
| `{description}` | the item's body: sanitised HTML, or plain text, capped — see [Escaping](#escaping) |

`{hideurl}` and `{moreurl}` are built from the URL the page was requested with, plus
`zftemplate` and `zfcategory` when the request carried them. They join their
parameters with `&amp;`, so they are already safe to put in an `href`.

### The 2.0 tokens

Nine, added in 2.0. They have no 1.6 behaviour to be compatible with, so they are
HTML-escaped by default.

| token | value |
|---|---|
| `{author}` | the item's author, plain text; empty when the feed gives none |
| `{summary}` | the item's `<description>` / `summary` markup, **unsanitised and uncapped** |
| `{content}` | the item's `content:encoded` / `content_html` markup, falling back to the summary; **unsanitised and uncapped** |
| `{enclosure}` | the URL of the item's first enclosure; empty when there is none |
| `{itemdate_iso}` | the item's date as RFC 3339, e.g. `2026-09-14T23:07:56+00:00`; empty when the feed gave no parsable date |
| `{itemdate_rel}` | a short English interval: `just now`, `3 days ago`, `in 2 hours`; empty when there is no date |
| `{feedid}` | the feed's ordinal in this render, counting from zero |
| `{position}` | the feed's `position` attribute in the OPML file |
| `{set}` | `classic` or `modern` — the set the current template came from |

`{feedid}` and `{position}` are different numbers. `{feedid}` counts the feeds
actually emitted by this render; `{position}` is the stable identifier the OPML file
carries and the one `{moreurl}`, `{hideurl}` and the `#zfchannel…` anchor use. Use
`{position}` for anchors and `{feedid}` for `id`/`aria-labelledby` pairs.

### Which tokens work in which section

This is the part that surprises people, and most of it is inherited behaviour that
the golden tests pin in place.

| section | channel tokens | item tokens | 2.0 item tokens |
|---|---|---|---|
| page header | only `{scripturl}`, `{category}` and `{set}` | no | no |
| header, footer, between | yes | no, printed literally | no |
| channel, `channel_one_bar` on | yes | yes, with a caveat below | yes |
| channel, `channel_one_bar` off | yes | no, printed literally | no |
| news | yes | yes | yes |

"Channel tokens" means the nine that do not depend on an item: `chanlogo`,
`chanlink`, `chandesc`, `chantitle`, `feedurl`, `lastupdated`, `scripturl`,
`hideurl`, `category`, plus `{feedid}`, `{position}` and `{set}`.

Three things to keep in mind:

**The page header sees three tokens and no more.** It is emitted once, before any
feed, so no feed and no item is in scope. Outside legacy fidelity mode the renderer
runs a three-entry `strtr()` over it — `{scripturl}`, `{category}` and `{set}` — which
is what lets a template link its own stylesheet with
`href="{scripturl}assets/modern/zf.css"`. Every other token in that section is
printed literally. Two details: `{category}` there is the *resolved* category's name,
not the requested spelling that `{category}` gives you elsewhere, and `{category}`
and `{set}` are HTML-escaped while `{scripturl}` is not. In legacy fidelity mode the
section is emitted verbatim, tokens and all.

**With `channel_one_bar` off, item tokens in the channel bar stay literal.** 1.6
called `parseTemplate()` with no item index for the repeated bar, so `{title}`,
`{link}`, `{moreurl}` and the rest were never replaced and appeared as text in the
page. 2.0 reproduces that. If your channel section uses `{moreurl}`, it only works
with `channel_one_bar` on.

**With `channel_one_bar` on, `{id}` in the channel bar means the feed's position, not
an item index** — and the item tokens beside it pick up whichever item happens to sit
at that offset in the feed, or nothing at all when the feed is shorter than the
position number. This is a 2004 bug, reproduced deliberately because the goldens
record it. Do not rely on item tokens in a one-bar channel section.

## Escaping

Two passes run over every section, in this order:

1. the **2.0 pass** (`Tokens::substitute()`), which handles every `{name|filter}` and
   every bare 2.0 token, and escapes by default;
2. the **1.6 pass** (`Renderer::renderChunk()`), a sequence of `str_replace()` calls
   in 1.6's exact order, which handles the bare 1.6 tokens.

The 2.0 pass runs first and only over the template text, so a value coming out of a
feed can never be rescanned and expanded as a token. That closes an injection route
1.6 left open.

What each token ends up as, in normal rendering:

| token | escaping |
|---|---|
| `{title}`, `{pubdate}` | `htmlspecialchars()`, `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8 |
| `{chantitle}`, `{chandesc}`, `{category}` | the same, applied once in `Renderer::present()` for the 1.6 pass and once in `Tokens::substitute()` for the filter pass |
| `{link}`, `{chanlink}`, `{feedurl}` | `Renderer::safeUrl()` first, then `htmlspecialchars()` with the same flags |
| `{description}` | run through the allow-list sanitiser when `allow_html_in_items` is on, or flattened to text and escaped when it is off; then truncated to `max_description_chars`. Inserted as markup, not escaped again. |
| `{chanlogo}` | markup the renderer builds. Both URLs inside it go through `safeUrl()`, and the URLs and the title are escaped before the markup is assembled; the assembled markup is not escaped again. |
| `{scripturl}`, `{lastupdated}`, `{hideurl}`, `{moreurl}` | inserted as-is. `{scripturl}` comes from your own configuration, `{lastupdated}` is a formatted date, and `{hideurl}` and `{moreurl}` are built by the renderer and already contain `&amp;` separators — escaping them again would print `&amp;amp;`. |
| `{id}` | a counter |
| the nine 2.0 tokens | `htmlspecialchars()`, `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8, unless `\|raw` is used |
| any token with a filter | escaped after the filters run — except `{description}` and `{chanlogo}`, whose values are already safe markup (`Tokens::RAW_BY_DEFAULT`) |

The list of tokens the renderer builds itself, and therefore does not escape a
second time, is `Renderer::PRE_FORMED`: `chanlogo`, `moreurl`, `hideurl`,
`lastupdated`, `scripturl`.

### Why a URL is not just escaped

`htmlspecialchars('javascript:alert(1)')` returns `javascript:alert(1)`
unchanged, and a browser still runs it when the link is followed. Escaping
protects the surrounding markup; it does nothing about the scheme. So every URL
that came out of a feed — `{chanlink}`, `{feedurl}`, an item's `{link}`, and both
URLs inside `{chanlogo}` — goes through `Renderer::safeUrl()` first:

1. control characters and whitespace (`\x00-\x20`) are stripped, because a
   newline or a tab inside the word `javascript` is the classic way past a naive
   filter;
2. if what is left has no scheme — no colon before the first `/`, `?` or `#` —
   it is a relative reference and is kept;
3. otherwise the scheme must be one of `Renderer::SAFE_SCHEMES`: `http`,
   `https`, `mailto`. Anything else becomes the empty string, which renders as a
   dead link rather than an executable one.

Three consequences worth stating plainly:

- **A feed cannot put a `javascript:` or `data:` URL in your page** through any
  token, and it cannot close a tag through `{chantitle}`, `{chandesc}`,
  `{title}` or `{pubdate}` either. This is the one place where 2.0 deliberately
  does not reproduce 2004 behaviour; the classic templates still render the same
  bytes for everything else, and no golden file observes the difference.
- `{summary|raw}` and `{content|raw}` bypass the sanitiser completely and print the
  publisher's markup. `{description}` does not: it is sanitised and capped. Prefer
  `{description}` unless you know why you want the other.
- `{description}` respects `max_description_chars` (default 600, `0` means no limit);
  `{summary}` and `{content}` do not. Cap them yourself with `|trunc:N`.

### Legacy fidelity mode

`RenderRequest::$legacyFidelity` turns all of that off: item text is passed through
verbatim, `safeUrl()` returns the feed's URL unexamined, `{link}` is escaped with the
2004 `ENT_COMPAT | ENT_HTML401` flags, and the
`header`, `footer` and `between` sections are emitted with no substitution at all,
as `displayData()` did in 2004. It exists so `tests/Golden/ClassicTemplateGoldenTest.php`
can compare against 2004 bytes. **Nothing in the web or CLI paths sets it**; it is
reachable only from a test, because turning it on reopens the 2004 XSS hole.

## Filters

```
{token|filter}
{token|filter:argument}
{token|filter:"argument with spaces"}
{token|filter|filter}
```

Filters apply left to right. They work on 1.6 tokens and 2.0 tokens alike. There are
exactly three of them, and the list is closed: an unknown name throws
`TemplateException` listing what does exist. There is no expression syntax, no
arithmetic, no conditionals and no way to call PHP.

| filter | argument | does |
|---|---|---|
| `raw` | none | turns off the default HTML escaping for this token |
| `trunc` | a whole number, 1 to 6 digits | shortens to that many visible characters |
| `date` | a PHP `date()` format string, optionally double-quoted | reformats a date |

**`trunc`** counts what a reader sees. Tags are copied and cost nothing; an HTML
entity counts as one character and is never split; a multi-byte character counts as
one. If anything was actually cut, a `…` (U+2026) is appended and every element still
open at the cut is closed, so truncating `<p>a <b>bcd</b></p>` cannot leave a dangling
`<b>`. A missing or non-numeric argument is an error, not a no-op.

**`date`** accepts anything `DateTimeImmutable` understands, which includes the RFC
822 strings feeds actually ship, and any PHP `date()` format. A value it cannot parse
is passed through unchanged rather than blanking the page; an empty value stays
empty. Escape a literal `"` in the format as `\"`.

```html
{description|trunc:220}
{summary|trunc:160}
{content|raw}
{pubdate|date:"D, d M Y"}
{itemdate_iso|date:"Y-m-d"}
{chandesc|trunc:140}
```

Note the difference between the last two lines of the escaping rules above:
`{description|trunc:220}` stays unescaped because the value was already sanitised,
while `{chandesc|trunc:140}` becomes escaped because it was not.

## The two template sets

### classic — 14 files

`ampheta`, `aqua`, `bluelogos`, `css`, `greenlogos`, `headlinebox`, `infojunkie`,
`mainframe`, `rij`, `sidebar`, `simpleblue`, `simplecss`, `simplegray`, `titlebox`.

These are the 2004 files, unmodified, CRLF line endings and all. `.gitattributes`
pins `templates/classic/*.html` with `-text` so no checkout can rewrite them.
`bluelogos` is the default.

They are **frozen**. Changing one changes bytes that a golden test asserts, and the
test will fail. New ideas belong in `templates/modern/`; see
[CONTRIBUTING.md](../CONTRIBUTING.md).

They are also 2004 HTML: `<table>` layout, `<font>` elements, `bgcolor`, fixed pixel
widths, no viewport meta. That is the point of the set.

Five of them expect a stylesheet the template does not link itself — the 2004
instructions told the operator to add it to the host page's `<head>`. The files are
published under `/assets/classic/css/`:

| template | stylesheet |
|---|---|
| `css` | `/assets/classic/css/css.css` |
| `rij` | `/assets/classic/css/RiJ.css` |
| `infojunkie` | `/assets/classic/css/infojunkie.css` |
| `mainframe` | `/assets/classic/css/mainframe.css` |
| `sidebar` | `/assets/classic/css/sidebar.css` |

Several classic templates also reference icons as `{scripturl}images/more.png` and
similar. Those files are served from the web root at `/images/`, exactly as in 2004,
and published a second time under `/assets/classic/images/`.

### modern — 16 files

`ampheta`, `aqua`, `bluelogos`, `cards`, `greenlogos`, `headlinebox`, `infojunkie`,
`list`, `mainframe`, `rij`, `sidebar`, `simpleblue`, `simplecss`, `simplegray`,
`ticker`, `titlebox`.

Thirteen are redesigns that keep the intent of the classic template of the same name
— the comment at the top of each file names its ancestor. Three are new: `cards`,
`list` and `ticker`. `classic/css` has no modern counterpart: it exists to be styled
entirely from a stylesheet you supply, which is what every modern template already
does.

They use semantic elements, no tables, CSS custom properties and container queries,
and they carry a `<link rel="stylesheet">` to `assets/modern/zf.css` in their page
header section. They use the 2.0 tokens and the filters freely.

### The differences that matter

| | classic | modern |
|---|---|---|
| files | 14 | 16 |
| markup | 2004 HTML tables, `<font>`, `bgcolor` | semantic HTML, no tables |
| responsive | no | yes, on the container's width |
| dark mode | no | yes |
| tokens used | the fifteen 1.6 tokens | 1.6 plus the nine 2.0 tokens |
| filters used | none | `trunc`, `date`, `raw` |
| stylesheet | five templates need one from the host page | `assets/modern/zf.css` |
| frozen | yes, by golden tests | no |
| changing one | breaks a test, by design | ordinary change |

## Writing your own template

Save it as `templates/modern/<name>.html` — or `templates/classic/`, though anything
you put there will be 2004-looking by association rather than by rule. The name has
to match `^[a-z0-9_-]{1,40}$`.

The smallest template that loads:

```html
<!--
	zFeeder 2.0 template: mysite
	One paragraph saying what it is for.
-->

<!-- zFeeder template header -->
<link rel="stylesheet" href="{scripturl}assets/modern/zf.css">
<!-- ENDzFeeder template header -->

<!-- header -->
<section class="zf zf-feed" id="zfchannel{position}">
<!-- ENDheader -->

<!-- channel -->
	<h2><a href="{chanlink}">{chantitle}</a></h2>
	<p>{chandesc|trunc:140}</p>
<!-- ENDchannel -->

<!-- news -->
	<article>
		<h3><a href="{link}">{title}</a></h3>
		<div>{description|trunc:220}</div>
		<time datetime="{itemdate_iso}">{itemdate_rel}</time>
	</article>
<!-- ENDnews -->

<!-- footer -->
</section>
<!-- ENDfooter -->

<!-- between -->
<hr>
<!-- ENDbetween -->
```

All five required sections must be present even if they are empty. `between` is
usually where the separator goes, because it is emitted after the footer of every
feed, including the last one.

Then look at it:

```bash
php bin/zfeeder check-config                 # confirm the template set and default
php -S 127.0.0.1:8080 -t public              # then open /demos/template/modern/mysite
```

`php tools/preview-templates.php` renders every file in `templates/modern/` to static
pages under `build/preview/` using sample data and no network. It is a standalone
script that re-implements the section split rather than using the engine, so treat it
as a look at the CSS, not as proof that the template renders correctly in the
application.

A few rules that are easier to learn from this page than from a stack trace:

- Do not touch the marker comments. One wrong space and the template will not load.
- Reach assets through `{scripturl}`, never a relative path: the output is included
  into somebody else's page, which may live in another directory.
- In the page header section, only `{scripturl}`, `{category}` and `{set}` work.
- Use `{position}` for the `#zfchannel…` anchor, so `{moreurl}` lands on the right feed.
- Cap `{summary}` and `{content}` yourself; only `{description}` is capped for you.
- Test with a feed that has no logo, no author and no date. All three tokens go empty,
  and a layout that assumes they are present will collapse.

## 2004 templates still work

A template written for zFeeder 1.x in 2004 runs in 2.0 unchanged. Drop it in
`templates/classic/` and select it. Nothing needs converting, and the 2.0 additions
are all optional.

This is not an aspiration; it is tested. `tools/record-goldens.sh` builds a
`php:5.6-apache` container, downloads `zfeeder-1.6.zip` from
<https://github.com/andreibesleaga/old-projects> and installs the original 1.6 tree in it
with fixture feeds pre-placed in the 1.6 cache at a fixed far-future mtime so nothing
is fetched, and curls the output of every classic template across three categories
plus the `zfposition`, `zfmore`, `zf_link` and channel-location variants. The result
is 49 files in `tests/fixtures/goldens/`.
`tests/Golden/ClassicTemplateGoldenTest.php` then renders the same inputs through the
2026 renderer and compares with `assertSame` on the whole string — byte for byte, no
whitespace normalisation, CRLF included. The same test asserts that all fourteen
classic templates exist and that `RiJ` still resolves.

Two 1.x things do not come across:

- **WAP templates.** `wap_categ.html`, `wap_categ_CDATA.html`, `wap_sources.html` and
  `wap_sources_CDATA.html` rendered WML for 2004 mobile phones. WML is gone and so is
  the WAP output path. `bin/zfeeder legacy-import` skips any file whose name starts
  with `wap_`.
- **Template-directory stylesheets.** 1.6 kept them in
  `newsfeeds/templates/css/`; 2.0 serves them from `public/assets/classic/css/`.
  Update the `<link>` in your host page.

Bringing a whole 2004 installation across, including templates you wrote yourself, is
[Upgrading from 1.6](UPGRADING-FROM-1.6.md). The 2004 format itself, and why it was
built this way, is in [the history](HISTORY.md).

## Not implemented yet

Stated here so you do not spend an afternoon finding out:

- **The page header understands three tokens, not the whole table.** `{scripturl}`,
  `{category}` and `{set}` are substituted there; anything else you write in that
  section reaches the page as literal text. There is no warning.
- **There is no template validation command.** `bin/zfeeder legacy-import` parses the
  templates it copies and reports the ones that fail, but there is no standalone
  `validate` verb. Selecting a broken template raises `TemplateException`, which
  `Http\ErrorHandler` turns into a **404** — a template name that does not exist, or one
  that is a traversal attempt, is a bad request rather than a broken server.
- **`display_error` has no effect on rendering.** The option exists in the schema and
  the 1.6 importer maps `ZF_DISPLAYERROR` onto it, but no code reads it, so a feed
  that cannot be read renders as an empty channel either way. There is no
  "feed error" token.
- **Filters take one argument.** `trunc` takes a number and `date` takes a format
  string. There is no syntax for a second argument, and adding one would need a
  change to `Filters::apply()`.

---

## See also

| | |
|---|---|
| [`templates/README.md`](../templates/README.md) | the short reference beside the files, and the house style for a modern template |
| [Design system](DESIGN-SYSTEM.md) | the CSS components `templates/modern/` builds on |
| [Configuration](CONFIGURATION.md) | `template_set`, `default_template`, `channel_location`, `channel_one_bar`, `max_description_chars`, `allow_html_in_items` |
| [Upgrading from 1.6](UPGRADING-FROM-1.6.md) | bringing 2004 templates and settings forward |
| [Accessibility](ACCESSIBILITY.md) | what the modern set is checked against |
| [History](HISTORY.md) | where the format came from |
| [CONTRIBUTING.md](../CONTRIBUTING.md) | why the classic set is frozen |
| [VERSIONING.md](../VERSIONING.md) | the promise that tokens are never removed |

The code, if you would rather read that: `src/Render/TemplateEngine.php` (the section
split), `src/Render/Tokens.php` (the token table and the 2.0 pass),
`src/Render/Filters.php` (the three filters), `src/Render/Renderer.php` (the order
everything is emitted in), `src/Render/TemplateLocator.php` (name to file),
`src/Render/Sanitizer.php` and `src/Render/Truncator.php`.
