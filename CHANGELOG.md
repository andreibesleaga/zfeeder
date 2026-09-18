# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Removed

- **The 1.6 tree and the recovered screenshots.** `legacy/zfeeder-1.6/` and
  `legacy/screenshots/` are no longer in this repository. They are archived publicly at
  <https://github.com/andreibesleaga/old-projects>, as `zfeeder-1.6.zip` and
  `zfeeder-screenshots.zip`. The minimal 2004 corpus the tests read — the eleven original
  `categories/*.opml` subscription files, the original `templates/` directory, and
  `config.php`, which is read as text and never executed — is kept at
  `tests/fixtures/legacy-1.6/newsfeeds/`, and `tools/record-goldens.sh` now downloads
  `zfeeder-1.6.zip` instead of reading `legacy/`. Every compatibility test still runs
  against real 2004 files; no 2004 PHP is loaded anywhere.

### Security

- **No credential is written down in the repository.** The browser suite used a fixed
  administrator password; `tools/run-e2e.sh` now generates one for each run and passes it
  to the suite as `ZF_E2E_PASSWORD`. Nothing is exempt from the secret scan any more —
  `S18SecretsTest` dropped its one allowed exception.

## [2.0.0] — 2026-09-18

The first release since 1.6 in April 2004. zFeeder is rebuilt on PHP 8.3 with the same
architecture, the same subscription format and the same template language, so a 2004
installation can be carried forward rather than replaced.

### Added

- **A second storage backend.** `ZF_STORAGE=sqlite` keeps everything in one SQLite file;
  `flat` keeps the OPML and cache files of the original. `bin/zfeeder migrate` converts in
  either direction and verifies the result.
- **Atom 1.0 and JSON Feed 1.1** alongside every RSS version 1.6 understood.
- **A modern template set**: sixteen responsive, accessible templates, including three new
  layouts (`cards`, `list`, `ticker`) with no counterpart in 2004. Thirteen of them are
  counterparts of the classic set, which has fourteen templates and reproduces the original
  output byte for byte. Thirty templates in all.
- **Two admin skins**, `modern` and `classic`, over the same markup.
- **A command line tool**, `bin/zfeeder`: refresh, list-feeds, add, import, export, migrate,
  check-config, hash-password, legacy-import, purge, seed and docs:config.
- **`bin/zfeeder seed`**, which loads the shipped example subscriptions into whichever
  storage backend is configured. The container entrypoint runs it on every boot; it leaves
  categories that already exist alone.
- **HTTP embedding for non-PHP sites**: `/embed` returns an HTML fragment and `/api/feeds`
  returns JSON, both with configurable cross-origin rules.
- **Conditional requests.** ETag and If-Modified-Since mean an unchanged feed costs a 304.
- **`bin/zfeeder legacy-import`**, which reads a 2004 `newsfeeds/` directory: subscriptions,
  configuration and any custom templates.
- **A container image**, `ghcr.io/andreibesleaga/zfeeder`, and deployment recipes for Railway,
  Fly.io, Render, Kubernetes, a plain VPS and shared hosting.
- **A health endpoint** at `/healthz` reporting version, storage backend and writability.
- **Demo mode** (`ZF_DEMO_MODE`), a read-only administration panel for public demonstrations.
- **A description length cap** (`ZF_MAX_DESCRIPTION_CHARS`, default 600). Feeds in 2026 often
  carry whole articles; in 2004 they carried a sentence, and the old layouts assumed it.

### Changed

- **PHP 8.3 is the minimum.** 1.6 targeted PHP 4.2.
- **Configuration is JSON**, in the data directory, and can be set by environment variable or
  in the admin panel. 1.6 rewrote `config.php` as PHP generated from form input.
- **The data directory lives outside the web root** and no longer needs world-writable
  permissions. 1.6's manual asked for `chmod 0777`.
- **Feed content is sanitised** through an allow-list before rendering.
- **Templates gain nine tokens** — `{author} {summary} {content} {enclosure} {itemdate_iso}
  {itemdate_rel} {feedid} {position} {set}` — and three filters, `|raw`, `|trunc:N` and
  `|date:"…"`. The fifteen original tokens behave exactly as they did.
- **Subscription files are written as OPML 2.0.** The reader still accepts the 1.x dialect.
- **An unset `ZF_URL` now means the site root** (`/`) rather than an empty string, so
  `{scripturl}` resolves with no configuration on any installation served at the top of a
  domain. 1.6 required the installation URL to be typed into the config screen and broke
  quietly when it was wrong. Set `ZF_URL` only for a subdirectory install, with a trailing
  slash. The classic templates load their images from `/images/` for the same reason.

### Removed

- **WAP/WML output.** `wap.php` and the `wap_*` templates served mobile phones of 2004 and
  have no audience now. The 1.6 files remain in `legacy/` for reference.
  *(Removed from the repository after 2.0.0 — see Unreleased.)*
- **Frame-based demonstrations.** The two-frame aggregator is rebuilt as a CSS grid.
- **HTTP Basic authentication for the panel.** Session login only; leave Basic auth to the
  web server if you want it.
- **`ZF_USEOPML`.** Subscriptions are always OPML now; the programmatic route is
  `zfeeder_feeds()` rather than a configuration switch.

### Fixed — security

Every item below is a defect of the 1.6 code, with the test that now proves it is closed.

| 1.6 defect | 2.0 |
|---|---|
| Unsalted MD5 password, compared with `!=` | Argon2id, `password_verify`, rate-limited login |
| No CSRF protection on any admin action | A token on every state-changing request |
| Feed HTML written straight into the page | Allow-list sanitiser on all feed content |
| Any URL fetched with `fopen()` | Address guard: scheme, IP range and every redirect hop |
| `$_GET['zfcategory']` used as a filesystem path | Name pattern plus realpath containment |
| `config.php` rewritten as PHP from `$_POST` | JSON, written atomically outside the web root |
| XML parsed with entity loading on | Document type declarations with an internal subset refused |
| `readfile()` of a remote PHP page for update checks | Opt-in, guarded request to the releases API |
| Errors and paths printed to the browser | Production responses carry no detail; the log has it |

### Compatibility

- Subscription files written by zFeeder 1.x load without conversion.
- Templates written for 1.x load without conversion; no token has been removed.
- `include 'zfeeder.php';` still works, and still honours `zfcategory`, `zftemplate`,
  `zfposition`, `zfmore` and `zf_link`.
- The offline refresh trigger still works and prints the same per-feed report. It moved to
  `GET /refresh?key=<key>`; the 1.6 spelling `?zfrefresh=<key>` is still accepted, at
  `/refresh` and at the old `/newsfeeds/zfeeder.php` path, so an existing cron entry keeps working.

[Unreleased]: https://github.com/andreibesleaga/zfeeder/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/andreibesleaga/zfeeder/releases/tag/v2.0.0
