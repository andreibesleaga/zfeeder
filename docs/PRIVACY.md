# Privacy

zFeeder is software you run on your own server. This document describes what the program
does with data, so that you can write a privacy notice for your own site. It is not a privacy
notice, and the project operates no service and receives nothing from your installation.

Everything below was read out of the source. File and class names are given so you can check
each claim.

## What is stored on your server

All of it lives under your data directory (`ZF_DATA_DIR`), which is meant to sit outside the
web root.

| What | Where | Contents |
|---|---|---|
| Subscriptions | `categories/*.opml`, or the `categories` and `feeds` tables in `zfeeder.sqlite` | Feed URL, title, site link, description, language, refresh interval, item count. Chosen by you. |
| Cached feed bodies | `cache/<sha256 of the URL>.xml` plus a `.json` sidecar, or the `cache` table | The XML or JSON the publisher served, unchanged, with `fetched_at`, `etag`, `last_modified`, the HTTP status and the last error. |
| Configuration | `config.json` | Every key in `src/Config/Schema.php`, including `admin_user` and `admin_password_hash` (an Argon2id hash, never the password) and `refresh_key`. |
| Failed sign-in counters | `ratelimit/<sha256>.json` | A JSON array of UNIX timestamps, nothing else. The file name is `sha256(client address + "\|" + submitted user name)`, so neither the address nor the name appears on disk. Entries older than `ZF_LOGIN_WINDOW` (default 900 s) are dropped on every read, and the file is deleted on a successful sign-in. |
| The log | `zfeeder.log` by default, or wherever `ZF_LOG_PATH` points | One line per record: UTC timestamp, level, message, and a JSON context object. See below. |

The administration session is the one thing that is **not** in the data directory. PHP stores
it wherever that PHP installation keeps sessions. zFeeder puts three values in it
(`src/Admin/Auth/SessionAuth.php`, `src/Admin/Auth/Csrf.php`):

- `_zf_admin_user` — the administrator's user name;
- `_zf_last_seen` — a UNIX timestamp, used for the idle timeout;
- `_zf_csrf` — a random token.

No password and no personal data are kept in the session. 1.6 kept the password there; 2.0
does not.

### What the log contains

The default level is `warning` (`ZF_LOG_LEVEL`), so in normal operation the log holds failed
feed fetches — the URL and the error. One record contains personal data: a **failed** admin
sign-in is logged at `warning` with the submitted user name and the client address
(`src/Admin/Controller/LoginController.php`). A successful sign-in logs the user name only.
The client address is `REMOTE_ADDR` unless you have listed a proxy in `ZF_TRUSTED_PROXIES`,
in which case the first entry of `X-Forwarded-For` is used instead.

zFeeder never rotates or truncates the log. Rotation is your operating system's job.

### Retention

| Data | Removed |
|---|---|
| Cached bodies | When you run `bin/zfeeder purge`, or when a newer fetch overwrites them |
| Rate-limit counters | Automatically, once the window has passed; immediately on a successful sign-in |
| Session | On sign-out, or after `ZF_SESSION_IDLE` seconds of inactivity (default 1800) |
| Log records | Never, by zFeeder |

## Cookies

zFeeder sets **one** cookie, and only for the administration panel. The session is started in
`src/Admin/AdminDispatcher.php`, which `public/index.php` reaches only for routes whose
handler name begins with `admin.`, so a visitor who
only reads your pages, `/embed` or `/api/feeds` receives no cookie from zFeeder.

| Property | Value |
|---|---|
| Name | `ZF_SESSION_NAME`, default `zfsid` |
| Contents | A PHP session identifier. Nothing else; the data stays on the server. |
| Lifetime | The browser session (`lifetime => 0`) |
| Flags | `HttpOnly`, `SameSite=Lax`, and `Secure` when the request arrives over HTTPS (`ZF_SESSION_SECURE`, default `auto`) |
| Path | The path part of `base_url`, so a second application on the same host never sees it |

There is no analytics cookie, no advertising cookie and no consent banner, because there is
nothing to consent to.

## What leaves your server

### To feed publishers

Every refresh is an HTTP request from **your** server to the publisher's, so the publisher
sees your server's address, not your readers'. It carries
(`src/Fetch/FeedFetcher.php::requestHeaders()`):

- `User-Agent: zfeeder/<version> (+https://github.com/andreibesleaga/zfeeder)`, unless you
  set `ZF_FETCH_USER_AGENT` to something else;
- an `Accept` and an `Accept-Encoding` header;
- `If-None-Match` and `If-Modified-Since` from the cache, so an unchanged feed costs a 304.

No visitor address, no referrer and no cookie is forwarded to a publisher, and nothing about
who read what is sent anywhere, because zFeeder never knows it.

### To GitHub, if you ask for it

The updates screen can check whether a newer zFeeder exists. It is **off by default**
(`ZF_UPDATE_CHECK`, default `false`). When you turn it on, opening that screen makes one
request to `https://api.github.com/repos/andreibesleaga/zfeeder/releases/latest`, which
discloses to GitHub that a zFeeder installation at your server's address looked. The comment
in `src/Admin/Controller/UpdatesController.php` says why it defaults to off. Nothing else in
the program contacts the project.

### To the visitor's browser

Nothing is loaded from a third party. Searching `src/`, `public/`, `templates/` and
`templates-admin/` for a third-party host finds none: no analytics, no tag manager, no CDN,
no hosted fonts, no error reporter. The one third-party asset, htmx, is served from your own
origin as `public/assets/modern/htmx.min.js`, and only the panel loads it; the public output
loads no JavaScript at all. The Content-Security-Policy on public pages is `default-src
'self'`, asserted by `tests/e2e/specs/public.spec.js`.

zFeeder adds an `X-Zfeeder-Version` response header (`src/Http/SecurityHeaders.php`), so
visitors and scanners can see which version you run.

Feed content is a separate matter, and it is the one thing you cannot switch off. A feed item
may contain `<img>` tags pointing at the publisher's server, and a reader's browser fetches
those images directly, which tells the publisher that someone visited your page. The
sanitiser (`src/Render/Sanitizer.php`) drops scripts, iframes, objects, embeds, styles, event
handlers and `data:` URLs, and forces `rel="nofollow noopener"` on links, but an image from
an allowed `http` or `https` origin is left in place: removing it would empty most feeds.

## What zFeeder does not do

- It has no per-reader state at all: no accounts for readers, no read/unread marks, no
  history, no preferences. Everyone gets the same page from the same cache. This is a design
  decision, not an omission — see [ROADMAP.md](ROADMAP.md).
- It does not track, profile or fingerprint anyone.
- It sets no cookie for public pages, `/embed` or `/api/feeds`.
- It sends no e-mail, so there is no mailing list and no address book.
- It phones home only when you switch the update check on.
- It stores no password anywhere, only an Argon2id hash.

Two things sit outside zFeeder's reach: your web server's access log, which records visitor
addresses whatever the application does, and your hosting provider's own logging.

## If you set an owner e-mail

`ZF_OWNER_NAME` and `ZF_OWNER_EMAIL` are written into exported OPML files. They are empty by
default. If you fill them in **and** set `ZF_OPML_EXPORT_PUBLIC=true`, anyone can download
`/api/opml/<category>` and read that address. The export is not public by default.

## What to tell your visitors

If you run zFeeder on a public site, a short paragraph covers it:

> This site aggregates headlines from other publishers' feeds. The feeds are fetched by this
> server, not by your browser, and no cookie is set when you read these pages. Images inside a
> headline are loaded from the publisher that supplied it, so that publisher can see that your
> browser requested them.

Adjust it if you have changed the defaults — in particular if you made the OPML export public
or turned the update check on. Whether you also need to mention your web server's access log
depends on your jurisdiction and is a question for you, not for this program.
