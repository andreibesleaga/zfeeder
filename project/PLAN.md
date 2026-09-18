# zFeeder 2.0 — modernisation plan

Status: PLAN ONLY (2026-09-15). Nothing implemented. Owner decisions in §3 gate the work.
Baseline: zFeeder 1.6 (2004), 2,172 lines of PHP 4-era code in `newsfeeds/` (zfeeder.php, admin.php, wap.php,
includes/zfuncs.php, adminfuncs.php, addnewfeed.php, subscriptions.php, importlist.php, changeconfig.php),
10 HTML templates + 5 CSS files, 11 OPML categories, flat-file cache. Screenshots of the original and of it running
today are in `~/work/other/zfeeder-modern/zfeeder-screenshots/`.

---

## 1. Goal

Rebuild zFeeder "as is" — same product, same architecture, same feature list, same workflow — on a 2026 stack, with a
modern responsive design for both the embedded output and the admin backend, and with the security holes of 2004 closed.
The result is a second portfolio piece: *zFeeder 2004 → zFeeder 2026, same architecture, modern stack*.

Non-goals: not a Feedly clone, no user accounts beyond the single admin, no database server, no SPA framework,
no mobile app. WAP/WML output is retired (kept only as history in the docs).

## 2. What is preserved (the architecture invariants of 1.6)

| 1.6 concept | keep? | 2.0 form |
|---|---|---|
| "include one line in any PHP page" | yes | `zfeeder()` function via Composer autoload + `zfeeder.php` include shim; plus a JSON/HTML endpoint for non-PHP sites |
| flat text files, no SQL | yes | `data/` directory outside the web root: OPML per category, JSON config, cache files, SQLite optional (§3 D4) |
| OPML as the subscription store (categories = OPML files) | yes | OPML 2.0, same per-outline attributes (`position`, `refreshTime`, `showedItems`, `isSubscribed`, `language`) plus `zf:` namespace for new ones |
| remote feeds cached with per-feed TTL | yes | cache + conditional GET (ETag / Last-Modified) + size/time limits |
| online vs. offline refresh with a refresh key | yes | on-request refresh (default) or `bin/zfeeder refresh` for cron; key-protected HTTP trigger kept |
| template-driven output, sections `header/channel/news/footer/between` and `{tokens}` | yes | same section markers and the same 15 tokens (`{chantitle} {link} {title} {scripturl} {chandesc} {description} {chanlink} {pubdate} {moreurl} {lastupdated} {chanlogo} {id} {hideurl} {feedurl} {category}`) so 1.x templates load unchanged; new tokens added, never removed |
| multiple categories, `zfcategory`, `zfposition`, `zftemplate`, `zfmore`, `zf_link` query parameters | yes | same names, validated |
| admin panel: add new (autodiscovery + direct URL), subscriptions, config, import OPML, updates | yes | same five screens, same field names where sensible |
| bookmarklet "ShowOnMySite" | yes | rebuilt (CSRF-safe) |
| "powered by zFeeder" link | yes | configurable, on by default |
| RSS 0.9/0.9x/1.0/2.0 | yes | plus Atom 1.0 and JSON Feed 1.1 |
| WAP (wml) output | no | retired |
| manual `addFeed()` mode without OPML | yes | kept as the programmatic API |
| server HTTP auth or PHP-session login | yes | session login only, with rate limit; HTTP auth left to the web server |

## 3. Owner decisions (answer before coding; recommendation first)

- **D1 Language/stack.** (a) PHP 8.3 + Composer, no framework, PSR-4/PSR-7/PSR-15 packages only — *recommended*: it is what "as is" means, it keeps the include-anywhere story, and it is what the shared hosts that ran 1.6 still offer. (b) PHP + Slim 4. (c) TypeScript/Node rewrite (loses the PHP include story).
- **D2 Feed parsing.** (a) `laminas/laminas-feed` — *recommended*, maintained, handles RSS/Atom, autodiscovery helpers. (b) `simplepie/simplepie`. (c) own XMLReader parser (max fidelity to 1.6, most work).
- **D3 Template engine.** (a) keep the zFeeder section/token format as a first-class engine (compat parser, ~200 lines) and add Twig only for the admin UI — *recommended*. (b) Twig everywhere with a converter for 1.x templates.
- **D4 Storage.** (a) flat files only (OPML + JSON + cache dir) — *recommended*, it is the identity of the product. (b) SQLite for the item cache to allow search/dedup (can be a later plugin).
- **D5 Admin UI technology.** (a) server-rendered Twig + htmx for partial updates + a hand-written CSS design system — *recommended*. (b) Alpine.js. (c) React/Vue (rejected for a 5-screen admin).
- **D6 HTTP client.** (a) `symfony/http-client` — *recommended*. (b) Guzzle.
- **D7 Sanitiser.** (a) `symfony/html-sanitizer` — *recommended*. (b) HTMLPurifier.
- **D8 Name, version, licence.** "zFeeder 2.0.0", GPL-2.0-or-later (1.6 is GPL v2 "or later", so this is allowed); confirm you want to keep the name and the 2004 logo.
- **D9 Repository.** New public repo `andreibesleaga/zfeeder` (history starts with a commit of the untouched 1.6 tree, so the diff tells the story). Publish to Packagist as `andreibesleaga/zfeeder`? Docker image on ghcr.io?
- **D10 Design direction.** (a) homage: keep the light-blue `#D0ECFD` / `#006699` palette and the compact tables, modern typography and spacing — *recommended* for the portfolio narrative. (b) fully new brand.
- **D11 Scope of shipped templates.** Port all 10 (bluelogos, greenlogos, aqua, ampheta, simpleblue, simplegray, titlebox, headlinebox, simplecss, infojunkie) plus RiJ/sidebar/mainframe? Recommendation: port 6 (bluelogos, simplegray, simplecss, titlebox, headlinebox, infojunkie) faithfully, add 2 new responsive ones (`cards`, `list`), keep the rest loadable through the compat engine.
- **D12 Frames demo.** Rebuild `demo_frames.php` as a CSS-grid "aggregator" page (no iframes)? Recommended yes; it is the best-looking 2004 screenshot.

## 4. Target layout

```
zfeeder/
  bin/zfeeder                 CLI: refresh, add, list, import, export, check-config
  public/                     web root of the demo site
    index.php                 demo pages (one include line), template gallery
    admin/index.php           admin front controller
    assets/                   css, js (htmx), images (logos)
  src/
    Config/                   Config (JSON file, validated, typed), ConfigWriter
    Subscription/             Category, Feed (outline), OpmlReader, OpmlWriter, SubscriptionRepository
    Fetch/                    FeedFetcher (HTTP client, SSRF guard, limits), Cache, CacheEntry
    Parse/                    FeedParser (RSS/Atom/JSON Feed → Channel + Item[]), Autodiscovery
    Render/                   Template (compat engine), TemplateLoader, Renderer, tokens
    Admin/                    Controllers (Main, AddFeed, Subscriptions, Config, Import, Updates, Auth), CSRF, Session
    Http/                     tiny PSR-15 router, responses, middleware (security headers)
    Embed/                    zfeeder() function, include shim, JSON endpoint
  templates/                  zFeeder output templates (1.x format + new ones)
  templates-admin/            Twig
  data/                       categories/*.opml, config.json, cache/  (outside web root; gitignored)
  legacy/zfeeder-1.6/         the untouched 2004 tree, for the diff and the compat tests
  tests/                      PHPUnit unit + integration, fixtures/ (recorded feeds 2004 + 2026), e2e/ (Playwright)
  docs/                       README, UPGRADING-FROM-1.6, TEMPLATES, SECURITY, ARCHITECTURE, HISTORY
  Dockerfile, docker-compose.yml, composer.json, phpstan.neon, .php-cs-fixer.php, .github/workflows/
```

Data flow: `zfeeder(['category'=>'news','template'=>'bluelogos'])` → SubscriptionRepository loads OPML → for each
outline: Cache::get or FeedFetcher::fetch (TTL, conditional GET) → FeedParser → Renderer applies template sections
and tokens → sanitised HTML string. Admin writes only through OpmlWriter and ConfigWriter (atomic rename, lock).

## 5. Security specification (the real reason 1.6 cannot be reused as is)

1. **Auth**: `password_hash` (bcrypt/argon2id), constant-time compare, login rate limit (file-based), session id regeneration on login, `SameSite=Lax; HttpOnly; Secure` cookie, logout invalidates.
2. **CSRF**: token per session on every admin POST; bookmarklet becomes a GET that lands on a confirm form.
3. **XSS**: everything from feeds passes through the sanitiser (allow: p, a[href http/https], b, i, em, strong, ul, ol, li, br, img[src https] optional, code, pre, blockquote); all tokens are escaped by default; a template opts into raw HTML per token (`{description|raw}`) only after sanitising.
4. **SSRF**: scheme allowlist http/https; resolve host and reject loopback, link-local, RFC 1918, ULA; max 5 redirects re-checked each hop; 2 MB body cap; 10 s timeout; no `file://`, no `php://`. Applies to feed fetch, autodiscovery and OPML import.
5. **Path traversal**: category and template names validated against `^[a-z0-9_-]{1,40}$` (1.6 passes `$_GET['zfcategory']` straight into a filesystem path).
6. **No PHP written by PHP**: config becomes `data/config.json`; 1.6 rewrote `config.php` from `$_POST`.
7. **Data outside the web root**; cache dir not world-writable (1.6 asked for 0777).
8. **Headers**: CSP for the admin, X-Content-Type-Options, Referrer-Policy, frame-ancestors for the embed endpoint configurable.
9. **XML**: XXE disabled (libxml entity loader off), billion-laughs guard via size cap.
10. **Update check**: signed JSON from the project's GitHub releases, opt-in, no readfile() of a remote PHP page.
11. Written up in `docs/SECURITY.md` with a threat model table mapping each 1.6 issue → fix → test.

## 6. Feed engine

- Fetch: UA `zfeeder/2.0 (+https://github.com/andreibesleaga/zfeeder)`, gzip, ETag/If-Modified-Since, 304 keeps cache and bumps mtime, failures keep the stale copy and record the error (`display error` option kept).
- Parse to one normalised model: Channel{title, link, description, language, logo, lastBuild} + Item{id, title, link, summaryHtml, contentHtml, published, author, enclosures}. Item id = guid/atom:id or hash(link+title).
- Autodiscovery: `<link rel=alternate type=application/rss+xml|atom+xml|feed+json>`, relative URL resolution, fallback to common paths (`/feed`, `/rss`, `/index.xml`); result screen unchanged from 1.6 (feed URL, site URL, language, version, copyright, title/description editable, refresh time, showed news, enabled, category, "add to subscription list", "validate at validator.w3.org/feed").
- OPML: read 1.x files unchanged; write OPML 2.0 with `dateModified`, `ownerName/ownerEmail` from config (options kept).
- Refresh: `bin/zfeeder refresh [--category=] [--force]` with a lock file; HTTP trigger `?zfrefresh=<key>` kept for hosts without cron.
- Limits per feed: `showedItems` (1.6 semantic), and new `maxDescriptionLength` (modern feeds ship whole articles; 1.6 had no cap, see the 22,000 px screenshot).

## 7. Renderer and templates

- Compat engine reproduces 1.6 exactly: split on `<!-- header -->…<!-- ENDheader -->`, `channel`, `news`, `footer`, `between`, plus the once-only "zFeeder template header"; `ZF_CHANLOCATION` top/bottom and `ZF_CHANONEBAR` kept; `{moreurl}`/`{hideurl}`/`zfmore` behaviour kept.
- Golden tests: each shipped 1.x template rendered against a recorded 2004-style fixture must equal the HTML 1.6 produces (captured from the PHP 5.6 container, whitespace-normalised).
- New responsive templates use CSS custom properties and no tables; ship a `templates/README.md` with the token reference.
- Embed options: PHP include; `GET /embed?category=news&template=cards` returning HTML fragment (CORS configurable); `GET /api/feeds?category=news` JSON for JS sites; `<iframe>` example.

## 8. Admin UI 2.0 (five screens, same names)

main · add new · subscriptions · config · import feed list · updates · logout.
Design: 12-column responsive grid, 1 accent colour (`#006699`), light-blue bars as a nod to 2004, dark mode via `prefers-color-scheme`, keyboard-accessible forms, htmx for: autodiscovery result loading, "save changes"/"delete selected" without full reload, category switch. No build step (plain CSS + htmx from a vendored file).
Subscriptions table keeps the columns position / subscribed / channel / refresh / showed news and adds last fetch status.

## 9. Testing (deterministic, no live network)

- PHPUnit: parser (RSS 0.91/1.0/2.0/Atom/JSON Feed fixtures, incl. the 2004 cache files from the zip and the 2026 cached feeds), OPML round-trip, template compat goldens, SSRF guard (IP tables), sanitiser cases, config validation, CSRF/auth flows with a fixed clock.
- HTTP client is mocked (symfony MockHttpClient); fixtures recorded once with a script under `tests/fixtures/record.php` (not run in CI).
- Playwright e2e against the Docker image: login, add feed by URL, subscriptions edit, config save, template gallery — reusing `~/work/other/zfeeder-modern/zfeeder-screenshots/run-kit/capture.js` so the portfolio screenshots are regenerated by CI as artefacts.
- phpstan level 8, php-cs-fixer PSR-12, composer audit.

## 10. CI/CD and distribution

GitHub Actions: lint → static analysis → unit → Docker build → e2e; release on `v2.*` tag: Packagist (if D9 yes), ghcr.io image, zip with `vendor/` for shared-hosting users (the 2004 audience). Dependabot for composer and actions. Owner runs all git writes.

## 11. Milestones and effort (single developer, focused)

| # | milestone | contents | est. |
|---|---|---|---|
| M0 | scaffold | repo with `legacy/zfeeder-1.6` committed first, composer, CI green on an empty test, ADRs for D1–D12 | 0.5 d |
| M1 | core engine + CLI | Config, OPML repo, fetcher with SSRF guard, cache, parser, `bin/zfeeder refresh/list/add/import/export` | 2 d |
| M2 | renderer | compat template engine + goldens vs 1.6, 6 ported templates, 2 new, embed function/endpoints, demo pages | 1.5 d |
| M3 | admin | auth, CSRF, five screens, htmx, design system, import, updates check | 2.5 d |
| M4 | hardening + audit | SECURITY.md threat table, tests for every item in §5, phpstan 8, dependency audit, second-pass review | 1 d |
| M5 | docs + release | README, UPGRADING-FROM-1.6 (config.php → config.json mapping, OPML as-is, template notes), Dockerfile, 2.0.0 | 1 d |
| M6 | portfolio | side-by-side page: 2004 originals vs 2026 captures, architecture diagram, "what changed and why" | 0.5 d |

Total ≈ 9 working days. Reduce to ≈ 5 by porting only bluelogos + simplegray and skipping htmx (full reloads).

## 12. Migration from 1.6

`bin/zfeeder import-legacy /path/to/newsfeeds`: copies `categories/*.opml` unchanged, maps `config.php` defines to
`config.json` (`ZF_LOGINTYPE` dropped, `ZF_ADMINPASS` md5 cannot be migrated → forces a password reset, `ZF_URL`,
`ZF_REFRESHKEY`, `ZF_USEOPML`, `ZF_OPMLDIR`, `ZF_CATEGORY`, `ZF_CACHEDIR`, `ZF_OWNERNAME/EMAIL`, `ZF_TEMPLATE`,
`ZF_DISPLAYERROR`, `ZF_CHANLOCATION`, `ZF_CHANONEBAR` mapped 1:1), copies user templates and verifies them with the compat parser.

## 13. Risks

- Feed content today is heavier than in 2004 (full articles, inline images); the description cap and sanitiser are essential or the 2004 layouts break.
- laminas-feed leniency on malformed 2004-era feeds is untested; keep the option of the own parser (D2c) for RSS 0.9x if fixtures fail.
- Shared-hosting users have no Composer; the zip-with-vendor build must be part of M5, not an afterthought.
- Scope creep toward a full reader (read/unread, search) — out of scope for 2.0; note ideas in `docs/ROADMAP.md`.

## 14. Explicitly not doing

No database server, no accounts/multi-user, no WAP, no frames, no JS framework, no auto-commits (owner runs git),
no live-network tests, no rewrite of the 2004 prose in the readme (kept verbatim in `docs/HISTORY.md`).


---
---

# PART A′ / PART B — APPROVED 2026-09-17 (appended; Part A above kept verbatim; Part B wins where more specific)

Owner 2026-09-17: all recommendations accepted; D4 = both storages; D10/D11 = classic AND modern; requirements: *working fully, complete, accurate, correct, fully secured, ready to deploy on any cloud or website, full demo on the owner's Railway account; all docs updated; all code and every other SDLC artefact; architecture and software-engineering best practices fully checked and verified; when all done save all and stop.*

**Paths (owner-fixed):** plan = `~/work/other/zfeeder-modern/PLAN.md` (this file); screenshot kit = `~/work/other/zfeeder-modern/zfeeder-screenshots/`; implementation + GitHub clone of the new `zfeeder` repo = `~/work/other/zfeeder/` (if the owner has cloned the empty repo there, build inside it; if absent, create the directory and the runbook gives `git init` + `git remote add`; never commit).

## 3. Decision record — CLOSED 2026-09-17

| # | decision |
|---|---|
| D1 | PHP 8.3, Composer, no framework; PSR-4/7/11/15 packages only (`nyholm/psr7`, tiny own router, hand-wired factory instead of a DI container). |
| D2 | `laminas/laminas-feed` for RSS/Atom + own JSON Feed 1.1 reader; own parser fallback only if a 2004 fixture fails goldens. |
| D3 | Classic zFeeder template format is a first-class engine (byte-for-byte 1.6 semantics). Twig only for admin. Modern output templates use the same section/token format. |
| **D4** | **Both storages, runtime-selectable**: `ZF_STORAGE=flat` (default) or `sqlite`; identical interfaces, `bin/zfeeder migrate --to=flat|sqlite` both ways; OPML export always. **Every option settable by env var, config.json, and admin screen** (precedence env > file > default; env-locked fields read-only in admin). |
| D5 | Server-rendered Twig + htmx (vendored) + hand-written CSS design system; no build step. |
| D6 | `symfony/http-client`. D7 `symfony/html-sanitizer`. |
| D8 | Name zFeeder, version 2.0.0, licence GPL-2.0-or-later, 2004 logo kept + modern SVG variant. |
| D9 | New public repo `andreibesleaga/zfeeder`; first commit = untouched 1.6 tree in `legacy/zfeeder-1.6/`; Packagist `andreibesleaga/zfeeder`; image `ghcr.io/andreibesleaga/zfeeder`. Owner performs every git/registry write. |
| **D10** | **Two complete visual systems**: `classic` (faithful 2004 look for templates and admin) and `modern` (ultra-modern, responsive 320 px→4K, WCAG 2.2 AA verified by axe + manual checklist). Switch by `ZF_TEMPLATE_SET`, `ZF_ADMIN_SKIN`, or per request `zftemplate=classic/bluelogos` / `modern/cards`. |
| **D11** | **All 13 originals ported** (bluelogos greenlogos aqua ampheta simpleblue simplegray titlebox headlinebox simplecss infojunkie RiJ sidebar mainframe) **+ 16 modern** (a counterpart per classic name + cards, list, ticker). |
| D12 | Frames demo rebuilt as a CSS-grid aggregator page (no iframes), classic and modern. |

---

# PART B — BUILD SPECIFICATION

## B1. Toolchain
Repo path `~/work/other/zfeeder`. Local: PHP 8.3.6 (`/usr/bin/php`), required extensions `xml dom mbstring curl openssl session pdo_sqlite sqlite3 ctype json fileinfo` (P0 verifies; missing → run tests in `php:8.3-cli` Docker); Composer (install to `~/.local/bin` from getcomposer.org with SHA-384 check if absent); Node 24 via nvm; Playwright 1.61 (`npm i -D playwright @axe-core/playwright` in repo, or `NODE_PATH=~/work/AI/kaiban-distributed/node_modules`); Docker present; images `php:5.6-apache` (goldens), `php:8.3-apache`, `php:8.3-cli`, `composer:2`. Railway CLI via `use-railway` skill (owner must `railway login` once).

## B2. Directory layout
```
zfeeder/
  bin/zfeeder                          CLI
  public/index.php                     front controller (demos, embed, api, refresh, admin)
  public/assets/classic/               ported css + images (zflogo.png more.png xml.png close.png globe.png email.png rss.png background/)
  public/assets/modern/                zf.css (tokens), zf-admin.css, htmx.min.js (vendored, SRI), icons.svg, zflogo.svg
  public/healthz                       handled by router → {"status":"ok","version":"2.0.0","storage":"flat|sqlite"}
  src/ (namespace Zfeeder\)
    Kernel.php                         boot: config → services → router
    Config/  Config, ConfigLoader (defaults←config.json←env), ConfigWriter (atomic tmp+rename+lock), Schema (typed keys, enums, ranges)
    Storage/ SubscriptionStoreInterface, CacheStoreInterface, Flat/{OpmlSubscriptionStore,FileCacheStore}, Sqlite/{SqliteSubscriptionStore,SqliteCacheStore,Migrations/*.sql}, Migrator
    Subscription/ Category, Feed, Opml/{Reader,Writer}
    Fetch/   FeedFetcher, UrlGuard (SSRF), FetchResult, ConditionalHeaders
    Parse/   FeedParser, LaminasAdapter, JsonFeedReader, Autodiscovery, Model/{Channel,Item,Enclosure}
    Render/  TemplateEngine (classic format), Template, TemplateLocator (classic/ modern/ user/), Tokens, Filters, Renderer, Sanitizer, Truncator
    Embed/   functions.php (zfeeder(), zfeeder_render()), EmbedController, ApiController
    Admin/   Auth/{SessionAuth,RateLimiter,Csrf,PasswordHasher}, Controller/{Login,Main,AddFeed,Subscriptions,ConfigScreen,Import,Updates}, View/{TwigFactory,Skins}, Middleware/{SecurityHeaders,RequireAuth,DemoMode}
    Http/    Router, Middleware pipeline, Request/Response helpers, ErrorHandler (no stack traces in production)
    Cli/     Application, Command/{Refresh,ListFeeds,AddFeed,Import,Export,Migrate,CheckConfig,HashPassword,RecordFixture,LegacyImport,Purge}
    Legacy/  ConfigPhpImporter, LegacyCacheLocator
    Log/     FileLogger (PSR-3)
  zfeeder.php                          include shim (1.6-style usage)
  templates/classic/*.html (13)  templates/modern/*.html (16)  templates/README.md
  templates-admin/classic/  templates-admin/modern/   (Twig: layout.twig + login main addfeed subscriptions config import updates + partials/)
  data/ (gitignored)  data-dist/ (config.json.dist, categories/*.opml seeds, .htaccess deny, README)
  legacy/zfeeder-1.6/                  untouched original
  tests/{Unit,Integration,Golden,Security,Cli,e2e}  tests/fixtures/{feeds-2004,feeds-2026,opml,html,goldens,payloads}
  docs/ (see B11)  docs/adr/  docs/diagrams/ (C4 in Mermaid + exported SVG)  docs/portfolio/
  deploy/ Dockerfile docker-compose.yml railway.toml railway.sh nginx.conf.example apache-vhost.conf.example k8s.yaml fly.toml.example render.yaml.example shared-hosting/.htaccess
  .github/ workflows/{ci,release,pages,codeql}.yml dependabot.yml ISSUE_TEMPLATE/ PULL_REQUEST_TEMPLATE.md
  composer.json phpunit.xml.dist phpstan.neon .php-cs-fixer.dist.php .editorconfig .gitignore .gitattributes
  LICENSE (GPL-2.0-or-later) README.md CHANGELOG.md CONTRIBUTING.md CODE_OF_CONDUCT.md SECURITY.md SUPPORT.md VERSIONING.md
```

## B3. Configuration — every option: env var · json key · admin field · CLI flag
| env | json key | default | notes |
|---|---|---|---|
| ZF_ENV | env | production | production/development |
| ZF_DATA_DIR | data_dir | ../data | never under public/ (checked at boot) |
| ZF_STORAGE | storage | flat | flat / sqlite |
| ZF_SQLITE_PATH | sqlite_path | {data}/zfeeder.sqlite | |
| ZF_CATEGORIES_DIR | categories_dir | {data}/categories | flat only |
| ZF_CACHE_DIR | cache_dir | {data}/cache | flat only |
| ZF_DEFAULT_CATEGORY | default_category | zfeeder | 1.6 ZF_CATEGORY |
| ZF_TEMPLATE_SET | template_set | classic | classic / modern |
| ZF_DEFAULT_TEMPLATE | default_template | bluelogos | 1.6 ZF_TEMPLATE |
| ZF_ADMIN_SKIN | admin_skin | modern | classic / modern |
| ZF_URL | base_url | auto | 1.6 ZF_URL, trailing / |
| ZF_ADMIN_ENABLED | admin_enabled | true | 1.6 "no panel" |
| ZF_ADMIN_USER | admin_user | admin | |
| ZF_ADMIN_PASSWORD_HASH | admin_password_hash | unset → admin disabled | `bin/zfeeder hash-password` |
| ZF_LOGIN_MAX_ATTEMPTS / ZF_LOGIN_WINDOW | login_max_attempts / login_window_seconds | 5 / 900 | |
| ZF_SESSION_NAME / ZF_SESSION_IDLE / ZF_SESSION_SECURE | session_name / session_idle_seconds / session_secure | zfsid / 1800 / auto | |
| ZF_REFRESH_MODE / ZF_REFRESH_KEY | refresh_mode / refresh_key | online / "" | offline needs key |
| ZF_DISPLAY_ERROR | display_error | false | 1.6 ZF_DISPLAYERROR |
| ZF_CHANNEL_LOCATION / ZF_CHANNEL_ONE_BAR | channel_location / channel_one_bar | top / true | top bottom none |
| ZF_POWERED_BY | powered_by | true | |
| ZF_MAX_DESCRIPTION_CHARS | max_description_chars | 600 | 0 = unlimited |
| ZF_ALLOW_HTML_IN_ITEMS | allow_html_in_items | true | sanitised HTML vs text |
| ZF_FETCH_TIMEOUT / ZF_FETCH_MAX_BYTES / ZF_FETCH_MAX_REDIRECTS / ZF_FETCH_USER_AGENT | fetch_* | 10 / 2097152 / 5 / zfeeder/2.0 (+repo) | |
| ZF_ALLOW_PRIVATE_HOSTS | allow_private_hosts | false | dev/tests only |
| ZF_OWNER_NAME / ZF_OWNER_EMAIL | owner_* | "" | OPML head |
| ZF_EMBED_CORS_ORIGINS / ZF_EMBED_FRAME_ANCESTORS | embed_cors_origins / embed_frame_ancestors | "" / 'self' | |
| ZF_API_ENABLED / ZF_OPML_EXPORT_PUBLIC | api_enabled / opml_export_public | true / false | |
| ZF_UPDATE_CHECK | update_check | false | GitHub releases JSON, opt-in |
| ZF_TRUSTED_PROXIES | trusted_proxies | "" | Railway/Cloudflare: set `*` or CIDRs |
| ZF_LOG_LEVEL / ZF_LOG_PATH | log_level / log_path | warning / {data}/zfeeder.log | |
| ZF_DEMO_MODE / ZF_DEMO_ENABLED | demo_mode / demo_enabled | false / true | read-only admin; paused page |
`Schema.php` validates types/enums/ranges, rejects unknown keys; `bin/zfeeder check-config` prints effective config with the source of each value; `docs/CONFIGURATION.md` is generated from Schema so it cannot drift.

## B4. Storage contract (D4)
```php
interface SubscriptionStoreInterface { categories(): array; category(string $name): Category; saveCategory(Category $c): void;
  createCategory(string $name): void; deleteCategory(string $name): void; exportOpml(string $name): string; importOpml(string $name, string $opml, bool $replace): int; }
interface CacheStoreInterface { get(string $url): ?CacheEntry; put(string $url, CacheEntry $e): void; delete(string $url): void; purge(int $olderThan): int; }
```
Flat: `^[a-z0-9_-]{1,40}$.opml`; cache key sha256(url) + sidecar meta json; `LegacyCacheLocator` still finds 1.6 mangled names. SQLite: tables `categories feeds cache schema_version`, WAL mode, PDO prepared statements only, migrations versioned. Migrator converts both ways and verifies counts; one abstract PHPUnit case runs against both stores.

## B5. Pinned 1.6 behaviours (each becomes a test)
1 category resolution & fallback; 2 ordering/filters/`zfposition`; 3 cache freshness → fetch → stale-on-failure; 4 section rendering order incl. top/bottom/none & one-bar; 5 tokens + new tokens `{author} {summary} {content} {enclosure} {itemdate_iso} {itemdate_rel} {feedid} {position} {set}` and filters `|raw |trunc:N |date:"fmt"` (no expressions); 6 powered-by; 7 offline refresh report text identical to 1.6.

## B6. Security requirements (OWASP ASVS 4.0 L2 target) → tests in tests/Security
S1 argon2id hash + constant-time verify · S2 login rate limit 429/Retry-After · S3 session regenerate/SameSite=Lax/HttpOnly/Secure/idle timeout · S4 CSRF on every mutating request, GET never mutates · S5 sanitiser allow-list, `javascript:`/`data:` stripped · S6 UrlGuard (scheme allow-list; resolved IPs not loopback/private/link-local/ULA/0.0.0.0; re-check per redirect; max redirects/bytes/timeout) · S7 name regex + realpath containment for categories/templates/sets · S8 config only JSON in data dir · S9 XXE off, DTD rejected, size cap · S10 headers (CSP admin `default-src 'self'` + nonce for htmx, embed frame-ancestors configurable, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, HSTS when https) · S11 OPML import guard (UrlGuard, ≤500 outlines) · S12 CORS only configured origins, JSON escaping · S13 demo mode 403 on writes · S14 static rules (no eval/dynamic include/remote readfile; phpstan + grep in CI) · S15 upload limits for OPML files (1 MB, mime check) · S16 error handler never leaks paths/traces in production · S17 dependency audit + SBOM (CycloneDX via `composer sbom` plugin or cyclonedx-php-composer) · S18 secrets never in repo (gitleaks in CI).

## B7. Pages, endpoints, CLI
Public: `/` (set switcher + demo index), `/demos/{one-line|css|multiple|positions|categories|aggregator}`, `/demos/template/{set}/{name}`, `/embed`, `/api/feeds`, `/api/opml/{category}`, `/refresh?key=`, `/healthz`.
Admin `/admin/*`: login, main, add-new (site URL → autodiscovery; feed URL → preview form; subscribe), subscriptions (category select, save, delete selected, move), config (all non-env-locked keys + password change), import (URL or upload), updates, logout; htmx partials `/admin/_/…`.
Include shim `zfeeder.php`; CLI `bin/zfeeder`: refresh, list, add, import, export, migrate, check-config, hash-password, record-fixture, legacy-import, purge.

## B8. Templates and skins
Classic: 13 originals verbatim (assets via `{scripturl}`), goldens prove 1.6-identical output. Modern: 16 templates, semantic HTML, `zf.css` tokens (`--zf-bg --zf-fg --zf-accent --zf-muted --zf-radius --zf-gap --zf-font`), container queries, dark mode (`prefers-color-scheme` + `[data-zf-theme]`), reduced motion, visible focus, ≥4.5:1 contrast, lazy images. Admin skins: classic (2004 look with modern semantics) and modern (8-pt grid, cards, sticky action bar, aria-live toasts, mobile nav, 44 px targets, dark mode). A11y gate: axe 0 violations on every page/skin/set, scripted keyboard walkthrough, skip link, heading order, `aria-describedby` errors, 200 % zoom no horizontal scroll, `docs/ACCESSIBILITY.md` checklist signed off.

## B9. Testing (deterministic, no live network)
Fixtures: feeds-2004 (samples matching the 2004 subscriptions; RSS 0.91/0.92/1.0/2.0), feeds-2026 (BBC, NYT, LWN, Phoronix, Wired, Ars, HN, SourceForge — recorded once in P0), opml, html autodiscovery pages, payload corpus. Goldens: recorded in P0 from the 1.6 code running in `php:5.6-apache` with fixture feeds pre-placed in its cache (future mtime), every classic template × 3 categories, whitespace-normalised. Suites: Unit (config, opml, urlguard table, fetcher with MockHttpClient, parser snapshots, sanitizer corpus, engine, truncator, both stores via abstract case, migrator, cli, legacy importer), Integration (kernel render both storages; admin flows with fixed clock in temp data dir), Golden, Security (B6), Cli, e2e (Playwright vs built image + fixture server in compose: all pages × sets × skins, axe, keyboard, screenshots as artefacts), Lighthouse a11y ≥ 95. Gates: phpunit green, coverage ≥ 90 % src/, phpstan level 8 (+strict rules), php-cs-fixer PSR-12, composer audit clean, gitleaks clean, axe 0.

## B10. CI/CD, packaging, deployment anywhere
`ci.yml` lint → phpstan → phpunit matrix (PHP 8.3/8.4 × flat/sqlite) → docker build → e2e+axe+Lighthouse → artefacts; `codeql.yml`; `release.yml` on `v2.*`: multi-arch image to ghcr + `zfeeder-2.0.0.zip` (with vendor/, for shared hosting) + tar.gz + SBOM + GitHub Release; `pages.yml` publishes `docs/portfolio/`.
Dockerfile multi-stage (composer:2 → php:8.3-apache, rewrite+headers, DocumentRoot public/, non-root, opcache, `data/` volume, HEALTHCHECK /healthz, 12-factor env config). Deploy recipes in `deploy/` + `docs/DEPLOYMENT.md`: Docker/compose, **Railway** (railway.toml + railway.sh; volume at /var/www/data; env per B3; `ZF_DEMO_MODE=true`; owner-switchable via `ZF_DEMO_ENABLED`), Fly.io, Render, Kubernetes manifest, generic VPS (nginx/apache examples), shared hosting zip (+ .htaccess, data dir outside webroot instructions), cron line for offline refresh.
**Railway live demo is mandatory**: P8 deploys to the owner's account (owner runs `railway login` once; if not logged in, everything is prepared and the exact blocking command recorded), verifies `/healthz`, demo pages and admin login page over HTTPS, records the URL in PROGRESS.md and docs.
Owner-only steps (never done by me): git init/commit/push/tag, GitHub repo creation, Packagist submit, ghcr login, `railway login`. All written in `OWNER-RUNBOOK.md`.

## B11. SDLC artefacts (all delivered, all kept current)
Requirements: `docs/SRS.md` (functional FR-xx + non-functional NFR-xx, traceability matrix FR→tests). Architecture: `docs/ARCHITECTURE.md` (C4 context/container/component in Mermaid, hexagonal layering: Domain=Subscription/Parse model, Application=Render/Fetch/Admin use cases, Infrastructure=Storage/Http/Cli), `docs/adr/0001…0012` (one ADR per D1–D12 + ADR for security choices). Design: `docs/TEMPLATES.md`, `docs/DESIGN-SYSTEM.md` (tokens, components, a11y rules), `docs/CONFIGURATION.md` (generated). Security: `SECURITY.md` (disclosure policy) + `docs/THREAT-MODEL.md` (STRIDE table, B6 mapping) + SBOM. Quality: `docs/TEST-PLAN.md`, coverage report, `docs/ACCESSIBILITY.md`. Ops: `docs/DEPLOYMENT.md`, `docs/RUNBOOK.md` (operate/backup/restore/rotate password/upgrade), `docs/OBSERVABILITY.md` (healthz, logs). Release: `CHANGELOG.md` (Keep a Changelog), `VERSIONING.md` (SemVer), `docs/RELEASE-PROCESS.md`, `CONTRIBUTING.md` (Conventional Commits, DCO), `CODE_OF_CONDUCT.md`, `SUPPORT.md`, issue/PR templates, `docs/UPGRADING-FROM-1.6.md`, `docs/HISTORY.md` (2004 readme verbatim + original screenshots), `docs/ROADMAP.md`, `docs/PRIVACY.md` (what the app stores: admin session + feed cache only), `docs/LICENSES.md` (third-party licence list).

## B12. Verification protocol (architecture and engineering best practices — checked, not assumed)
Before P9 closes, run and record in `docs/VERIFICATION-REPORT.md`:
1. **Architecture review** against a checklist: hexagonal boundaries (no infrastructure import in Domain; enforced by phpstan-deptrac or `qossmic/deptrac`), SOLID spot-checks, 12-factor (config/env, stateless process, logs to stdout option, disposability), no global state except the shim, error handling policy, idempotent CLI.
2. **Security review**: ASVS L2 checklist walk (auth, session, access, validation, crypto, errors/logging, data protection, communication, config), OWASP Top-10 mapping, headers verified with curl, `composer audit`, gitleaks, CodeQL clean.
3. **Accessibility review**: axe + Lighthouse + manual WCAG 2.2 AA checklist per page.
4. **Compatibility review**: goldens byte-equal; `legacy-import` on the 1.6 tree; 1.x user template loads unchanged.
5. **Portability review**: image runs on Docker locally, Railway live, `php -S` dev server, shared-hosting zip verified in `php:8.3-apache` with the zip layout.
6. **Docs review**: every command in docs executed once; config table regenerated; links checked (lychee).
7. **Independent adversarial review**: two subagents (security lens, maintainability lens) review the finished tree with the plan and report findings; all findings fixed or explicitly deferred to ROADMAP with reason.
8. **Definition of done** (all true): every B9 gate green with output pasted; both storages pass the same suite; both sets and both skins axe-clean; Docker image runs; Railway URL live over HTTPS (or the single owner-side blocker named); OWNER-RUNBOOK complete; no git writes by me; PROGRESS.md and memory updated.

## B13. Execution protocol for "continue"
Rules: work in `~/work/other/zfeeder`; batch and parallelise (subagents for template ports, tests, docs; keep the channel open); every gate command's real output goes into `PROGRESS.md`; verify before and after each phase; never commit; if context nears its limit: finish the current gate, update PROGRESS.md + memory, stop. Load `use-railway` before P8.

| phase | work | gate |
|---|---|---|
| P0 preflight | read PLAN + PROGRESS; toolchain (B1); pull images; **regenerate the screenshot kit if `~/work/other/zfeeder-modern/zfeeder-screenshots` is absent (Appendix C)**; record feeds-2026 fixtures once (curl, then never again) and copy feeds-2004 samples; record goldens from 1.6 in php:5.6 (B9); create PROGRESS.md | fixtures + goldens present; `php -m` saved; P0 ✅ |
| P1 scaffold | composer.json + deps (laminas-feed, symfony/http-client, symfony/html-sanitizer, nyholm/psr7, twig, symfony/console, psr/log; dev: phpunit ^11, phpstan ^2 + strict, php-cs-fixer, deptrac), PSR-4, legacy tree, LICENSE, configs, CI workflows, Dockerfile, compose, bin skeleton, community files | `composer validate`; smoke test; phpstan clean; `docker build` ok |
| P2 core | Config+Schema+Writer, both Storages+Migrator, Opml, UrlGuard, Fetcher, Parser (+JSON Feed), CLI, Legacy importer, Logger | unit green; coverage ≥ 90 % on these; `bin/zfeeder refresh` works vs fixture server in both storages |
| P3 renderer + classic | Engine, tokens/filters, Sanitizer, Truncator, Renderer, Embed fn/shim/endpoints, 13 classic templates + assets, classic demos | **goldens pass for all 13**; embed/api tests green |
| P4 modern | zf.css tokens, 16 modern templates, modern demos, aggregator page ×2 | Playwright at 320/768/1280/1920, axe 0, dark/reduced-motion snapshots |
| P5 admin | Auth/CSRF/rate-limit, 7 screens × 2 skins, htmx partials, config screen (env-locked read-only), import, updates, demo mode | integration + e2e both skins; axe 0; keyboard walkthrough |
| P6 hardening | S1–S18 tests + fixes; headers; static rules; SBOM; threat model | Security suite green; phpstan 8; audit + gitleaks clean |
| P7 quality + CI | full e2e matrix, Lighthouse, coverage, run every CI job's commands locally; export screenshots to docs/portfolio/img | all B9 gates met with output in PROGRESS.md |
| P8 docs + release + deploy | all B11 docs, CHANGELOG 2.0.0, portfolio page (2004 originals beside 2026 captures + architecture SVG), zip build, deploy/ recipes, **Railway deployment on the owner's account**, OWNER-RUNBOOK.md | image serves demo+admin; Railway URL verified (or blocker named); runbook complete; docs commands executed |
| P9 verification + stop | B12 protocol incl. two adversarial subagent reviews; fix; re-run all gates; VERIFICATION-REPORT; PROGRESS.md; memory; **stop** | final outputs pasted; memory updated; stopped |

## B14. Paste-ready prompts
Master ("continue"): *Read ~/work/other/zfeeder-modern/PLAN.md fully and ~/work/other/zfeeder/PROGRESS.md if present. Resume at the first phase whose gate is not ✅. Execute B13 phase by phase, running every gate command and pasting real output into PROGRESS.md. Never commit or push. Load use-railway before P8. When P9's gate passes, update memory and stop.*
P2-store: *Implement Storage per B4 for flat and sqlite with one abstract PHPUnit case run against both; Migrator both ways with count verification.*
P3-templates: *Port templates/classic/{name}.html from legacy/zfeeder-1.6/newsfeeds/templates/{name}.html verbatim, assets via {scripturl}; make tests/Golden/{name}Test pass against tests/fixtures/goldens/{name}-*.html.*
P4-modern: *Design templates/modern/{name}.html as the modern counterpart of classic/{name}: same information architecture, semantic HTML, zf.css tokens, container queries, dark mode, WCAG 2.2 AA; Playwright+axe at 320/768/1280/1920 must pass.*
P5-admin: *Build the {screen} admin screen for skins classic and modern per B7/B8 with CSRF, validation, htmx partial, integration test and e2e+axe test.*
P6-sec: *Write tests/Security/{Test} for requirement {Sn} in B6; make it fail against a deliberately weakened build, then pass.*
P8-deploy: *Using use-railway, deploy the Dockerfile as service zfeeder-demo with a volume at /var/www/data and the env in B10; verify /healthz, /demos and /admin over HTTPS; record the URL in PROGRESS.md and docs/DEPLOYMENT.md.*
P9-review-security / P9-review-maintainability: *Review ~/work/other/zfeeder against PLAN.md B6/B12 (resp. B11/B12) adversarially; list concrete findings with file:line and a fix; report only findings.*

## B15. Non-blocking owner questions (defaults apply)
1 Demo domain: Railway default URL (default) or `zfeeder.andreibesleaga.com`? 2 Portfolio page: repo GitHub Pages (default) and/or a block for andreibesleaga.com (prepared, owner adds)? 3 Public demo admin: read-only demo mode with the password shown (default) or hidden? 4 Packagist + ghcr on 2.0.0 (default yes, owner performs)? 5 Keep `legacy/zfeeder-1.6/` in the repo (default yes)?

---

# APPENDIX C — Screenshot kit and evidence (regenerate if `~/work/other/zfeeder-modern/zfeeder-screenshots` is missing)

C1 Originals (Feb 2004), download with curl (WebFetch is blocked on web.archive.org; the CDX API may say "offline" but `web/<ts>id_/` works; sleep 4 s between requests to avoid 429):
`http://web.archive.org/web/2016id_/http://zvonnews.sourceforge.net/images/zfeeder{1..8}.PNG` and `…/web/2017id_/http://zvonnews.sourceforge.net/images/zfeeder9.PNG`; site pages `…/web/2017id_/http://zvonnews.sourceforge.net/{index.php,shots.php,references.php,support.php}` + images `images/opml.gif images/valid-rss.png images/zflogo.png news/images/rss.png pixel.gif`.
Captions: 1 admin add-new (autodiscovery), 2 admin subscriptions, 3 admin config, 4 demo_categories infojunkie, 5 three-column demo, 6 demo page bluelogos+positions, 7 RiJ CSS template, 8 simpleblue, 9 frames aggregator (osdn).
C2 Running 1.6 today: `docker run -d --name zfeeder56 -p 127.0.0.1:8090:80 -v $PWD/zfrun:/var/www/html php:5.6-apache`; php.ini in container `error_reporting = E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT`, `display_errors=Off`; `config.php`: `ZF_LOGINTYPE=session`, `ZF_ADMINNAME=admin`, `ZF_ADMINPASS=md5('demo2004')`, `ZF_URL=http://127.0.0.1:8090/newsfeeds/`; `chmod -R a+rwX`; replace categories with live short-summary feeds (zfeeder: BBC Technology, NYT Technology, LWN, Phoronix; news: BBC World, BBC Technology, NYT Technology, BBC Science; technology: Wired, Ars Technica, Hacker News (hnrss), NYT Technology, Phoronix; general: BBC World, BBC Science, LWN; rss: HN, LWN; osdn: SourceForge docs feed, Linux.com, Phoronix; software: Phoronix, LWN, Ars; php: LWN, Phoronix; mobile: Wired, BBC Technology; delete lockergnome.opml). Avoid Slashdot (long text + blank ad block), NPR (description contains `'/>` which breaks templates), GitHub/Mozilla/NASA/TechCrunch/Register (whole articles). Helper pages `demo_template.php?zftemplate=X&zfcategory=Y` and `wap_source.php?q=…`; Playwright 1.61 at 1280×860, deviceScaleFactor 2, viewport shots for demos/templates, full-page for admin; admin flow: login form (`admin_user admin_pass submit_login`), `?zfaction=addnew` (siteurl → phoronix.com works, lwn.net fails), feedurl preview (BBC science), subscribe (`zfcategory`, `showednews`, `subscribe`), subscriptions (`zfcategory` + `changecateg`), config, importlist, updates.
C3 Facts: project registered 2003-06-27 by "andreutz"; 1.6 released 2004-04-25; INTERNET PROFESSIONELL 08/2004 article + CD; modules existed for WordPress (Bakshi), PHP-Nuke (NukeDiva), XHP, MovableType how-to (Elise).
