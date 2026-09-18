# zFeeder 1.6 — screenshots for the old-projects portfolio

zFeeder (2003–2004, GPL) — "simple, customizable PHP feedreader (aggregator)", flat-file, OPML subscriptions,
template driven, WAP output. SourceForge project `zvonnews`, homepage `http://zvonnews.sourceforge.net`
(the sf.net project web space is gone; sourceforge.net/projects/zvonnews still exists, last touched 2017).
Source: https://github.com/andreibesleaga/old-projects/blob/main/zfeeder-1.6.zip

## 01-original-2004-wayback  (authentic, February 2004)
The original screenshots page of the project site (`shots.php`) survived in the Internet Archive along with all
nine images. Downloaded 2026-09-15 from `web.archive.org/web/2016*/http://zvonnews.sourceforge.net/images/zfeederN.PNG`.

| file | what it shows |
|---|---|
| 01-admin-add-new-feed-autodiscovery.png | admin panel › add new: RSS autodiscovery by site URL, or direct feed URL, bookmarklet |
| 02-admin-subscriptions-list.png | admin panel › subscriptions: category selector, position / subscribed / refresh / showed-news per channel |
| 03-admin-config-panel.png | admin panel › config: general, feeds and display options |
| 04-demo-categories-infojunkie-template.png | demo_categories.php with the *infojunkie* template (category menu, fold/unfold channels) |
| 05-demo-three-column-templates.png | demo_multiple.php: three categories, three templates on one page |
| 06-demo-page-bluelogos-and-positions.png | demo page with *bluelogos* + positioned layers |
| 07-demo-rij-css-template.png | *RiJ* pure-CSS template (contributed) |
| 08-demo-simpleblue-template.png | *simpleblue* template |
| 09-demo-frames-aggregator-osdn.png | demo_frames.php: sidebar + main frame "the aggregator" (linked from the 1.4 news item) |

## 02-running-now-php56  (captured 2026-09-15)
The unmodified 1.6 code running on PHP 5.6 / Apache (Docker `php:5.6-apache`), with the dead 2004 subscriptions
replaced by live feeds (BBC, NYT, LWN, Phoronix, Wired, Ars Technica, Hacker News …). Retina (2×) PNGs, 1280 px viewport.

* `usage-*` — integration demos shipped in the zip: default page (`demo.php`, one `include` line), CSS template,
  three columns, absolutely-positioned layers, categories/infojunkie, frames aggregator (default + technology category),
  RiJ template, and the WML/WAP output (`wap.php`: menu, category, single feed).
* `template-*` — gallery of the bundled templates rendered on the same category (bluelogos, greenlogos, aqua, ampheta,
  simpleblue, simplegray, titlebox, headlinebox, simplecss, infojunkie).
* `admin-01…11` — backend walkthrough: login (PHP-session mode), main menu, add new (form), autodiscovery result for
  phoronix.com, direct feed preview/edit form for a BBC feed, "added to subscription list", subscriptions for the default
  and the *news* category, config, import OPML, updates check (fails: the update server no longer exists).
* `docs-readme.png` — bundled readme.html.
* `site-2004-*` — the project website as archived (see 03).

## 03-project-site-2004-archive
Wayback copies (2016/2017 captures of the 2004 site): index, shots, references, support pages + images. Open `index.html` locally.

## run-kit
`RUN.sh` rebuilds the demo in one command (needs Docker); `capture.js` re-takes every screenshot with Playwright.
Only `config.php` (session login, admin/demo2004, ZF_URL) and the category OPML files differ from the zip; the two extra
PHP files are helper pages for the template gallery and the WML viewer, not part of zFeeder.
