# Observability

What zFeeder 2.0 tells you about itself: the health endpoint, the log, the fetch
reports, and what is worth an alert.

There is no metrics endpoint, no tracing and no built-in dashboard. The
application is one PHP process per request with one writable directory, so the
useful signals are few and cheap: one HTTP check, one log file, one command that
prints the state of every subscription.

Operating procedures are in [`docs/RUNBOOK.md`](RUNBOOK.md); the options named
here are described in [`docs/CONFIGURATION.md`](CONFIGURATION.md).

## Contents

- [The health endpoint](#the-health-endpoint)
- [The log](#the-log)
- [Every message the application logs](#every-message-the-application-logs)
- [Fetch outcomes](#fetch-outcomes)
- [Configuration as a signal](#configuration-as-a-signal)
- [Response headers](#response-headers)
- [What to alert on](#what-to-alert-on)
- [Platform log commands](#platform-log-commands)

## The health endpoint

```
GET /healthz
```

Healthy, HTTP 200:

```json
{
    "status": "ok",
    "version": "2.0.0",
    "storage": "flat",
    "checks": {
        "subscriptions": true,
        "data_writable": true
    }
}
```

Unhealthy, HTTP 503:

```json
{
    "status": "degraded",
    "version": "2.0.0",
    "storage": "flat",
    "checks": {
        "subscriptions": false,
        "data_writable": false
    }
}
```

Those are the only two shapes. The endpoint is
`PublicController::health()`.

| Field | Type | Meaning |
|---|---|---|
| `status` | `"ok"` or `"degraded"` | `ok` when `checks.data_writable` is true, `degraded` otherwise |
| `version` | string | `Version::NUMBER`, currently `2.0.0` |
| `storage` | `"flat"` or `"sqlite"` | the configured backend |
| `checks.subscriptions` | bool | true when the subscription store returned at least one category |
| `checks.data_writable` | bool | true when the data directory exists and is writable by the PHP process |

Three things about it are worth knowing before you build an alert on it.

**Only `data_writable` decides the status.** `checks.subscriptions` is reported
and then ignored: a fresh installation with no categories yet is starting up,
not broken, and failing the health check would stop it from ever being reachable
to fix.

**`subscriptions` swallows its own errors.** The call is wrapped in a
`try/catch (\Throwable)` that sets it to false, so a corrupt OPML file, an
unreadable directory and an empty installation all look the same here. Use
`bin/zfeeder list-feeds` to tell them apart.

**It checks writability, not correctness.** A writable data directory with no
feeds in it, an expired cache, or a subscription that has failed every fetch for
a week all report `"status": "ok"`. Feed freshness needs its own check — see
[Fetch outcomes](#fetch-outcomes).

### Two ways it can fail that are not "degraded"

| What you get | Cause |
|---|---|
| HTML page "This zFeeder instance is paused", 503 | `ZF_DEMO_ENABLED` is false. The front controller checks it before routing, so this answer comes back for every path, `/healthz` included. Any platform health check pointed at `/healthz` will fail while the site is paused. |
| Plain text `zFeeder cannot start: its configuration is not valid.`, 500 | The kernel did not boot. The detail went to the PHP error log as `zfeeder boot failure: …`, never to the response. |

### Using it

```bash
curl -fsS https://feeds.example.com/healthz | jq .
```

Wired up already: `HEALTHCHECK` in [`deploy/Dockerfile`](../deploy/Dockerfile)
(every 30s, 5s timeout, 10s start period, 3 retries, against `${PORT:-80}`), an
`http_service.checks` block in [`deploy/fly.toml.example`](../deploy/fly.toml.example),
`healthCheckPath` in [`deploy/render.yaml.example`](../deploy/render.yaml.example),
and startup, readiness and liveness probes in [`deploy/k8s.yaml`](../deploy/k8s.yaml).
There is no `railway.toml` in the repository; the Railway service is configured
through variables, and its health check is set in the Railway dashboard. See
[DEPLOYMENT.md](DEPLOYMENT.md#railway).

## The log

One line per record, written by `src/Log/FileLogger.php`:

```
2026-09-18T09:14:22Z WARNING Feed fetch failed {"url":"https://example.com/feed.xml","error":"HTTP 503"}
```

The format is fixed:

```
<timestamp> <LEVEL> <message><space><context>\n
```

| Part | Detail |
|---|---|
| timestamp | `gmdate('Y-m-d\TH:i:s\Z')` — UTC, second resolution, always `Z` |
| level | the PSR-3 level name in upper case |
| message | the message with every `{placeholder}` already replaced from the context |
| context | a space and the context as JSON, or nothing at all when the context is empty |

Placeholders are substituted only from scalar and `Stringable` context values,
and the context is still printed in full afterwards, so a value appears twice
when the message names it. In the context JSON a `Throwable` becomes
`"ClassName: message"`, anything that is not scalar or null becomes its type
name (`"array"`, `"object"`), and slashes and Unicode are left unescaped so the
line stays readable.

### Where it goes

`ZF_LOG_PATH`, or `zfeeder.log` in the data directory when that is empty. Any
path PHP can append to works, including `php://stdout` and `php://stderr`.
Containers should use `php://stdout`.

Each record is an `fopen(path, 'a')`, an `flock(LOCK_EX)`, one `fwrite` and a
close. Every one of those calls is error-suppressed: **if the log path cannot be
opened, the record is dropped silently**. A log that has gone quiet is therefore
not proof that nothing happened — check that the path is writable with
`bin/zfeeder check-config`.

**Nothing rotates the file.** Add a `logrotate` entry, or log to stdout and let
the platform handle it.

### Levels

`ZF_LOG_LEVEL` sets the threshold; a record below it is discarded before
anything is written. The accepted values, in order:

| Value | Rank | Used by the application |
|---|---|---|
| `debug` | 0 | no |
| `info` | 1 | yes — successful admin sign-in |
| `notice` | 2 | yes — an empty feed result, an admin password change |
| `warning` | 3 (**default**) | yes — failed fetches, unparseable feeds, failed sign-ins |
| `error` | 4 | yes — unhandled 5xx, embed failures |
| `critical` | 5 | no |
| `alert` | 6 | no |
| `emergency` | 7 | no |

`debug`, `critical`, `alert` and `emergency` are accepted by the logger and by
the configuration schema, but nothing in `src/` emits them today. Setting
`ZF_LOG_LEVEL=debug` therefore produces exactly what `info` produces.

The default of `warning` means a quiet log is the normal state. `info` is the
useful setting when you want to see administrator sign-ins.

## Every message the application logs

This is the complete list. Nothing else in `src/` writes to the logger.

| Level | Message | Context | Where | When |
|---|---|---|---|---|
| `error` | `Unhandled error: {message}` | `message`, `class`, `file` | `src/Http/ErrorHandler.php` | an exception that maps to a 5xx status |
| `error` | `Embedded render failed: {message}` | `message` | `src/Embed/Embed.php` | `zfeeder()` threw inside someone else's page |
| `error` | `Embedded channel load failed: {message}` | `message` | `src/Embed/Embed.php` | `zfeeder_feeds()` threw |
| `warning` | `Feed fetch failed` | `url`, `error` | `src/Fetch/FeedFetcher.php` | one feed could not be fetched |
| `warning` | `Cannot parse {url}: {message}` | `url`, `message` | `src/FeedService.php` | the body came back but is not a feed we can read |
| `warning` | `Admin sign-in failed` | `user`, `address` | `src/Admin/Controller/LoginController.php` | wrong user name or password |
| `notice` | `No content for {url}: {outcome}` | `url`, `outcome` | `src/FeedService.php` | a fetch produced neither a fresh body nor a cached one |
| `notice` | `Admin password changed` | — | `src/Admin/Controller/ConfigController.php` | the password was changed in the panel |
| `notice` | `Request refused ({status}): {message}` | `message`, `class`, `file`, `status` | `src/Http/ErrorHandler.php` | an exception that maps to a status below 500 — a 400 from `SecurityException`, a 404 from `TemplateException` |
| `notice` | `Discarding an unparseable cache entry for {url} and refetching` | `url` | `src/FeedService.php` | a cached body would not parse; it is deleted and fetched once more |
| `info` | `Admin signed in` | `user` | `src/Admin/Controller/LoginController.php` | a successful sign-in |

Notes on reading them:

- **`Feed fetch failed` is not a page failure.** When a cached copy exists the
  fetcher keeps it, records the reason on the entry and leaves `fetchedAt`
  alone, so the next run tries again rather than waiting out a new interval.
  The page keeps showing the last good copy.
- **`Cannot parse` is per feed.** A feed that will not parse does not take the
  page down; it renders as nothing. (`ZF_DISPLAY_ERROR` is in the schema but no
  code reads it, so there is no error block on the page — the log is where the
  reason is.) If the body came from the cache, the next line is
  `Discarding an unparseable cache entry …` and the entry is refetched once.
- **`Admin sign-in failed` carries the client address**, which is what makes it
  worth alerting on in bulk.
- **`Unhandled error` is logged at error level for 5xx only.** Anything below
  500 — a 400 from a `SecurityException`, a 404 from a `TemplateException` — is
  logged at **notice** level as `Request refused (404): …`, with the exception
  class and the file. A single refusal is routine and a thousand are not, which
  is why they are recorded but not at error level. In production the response
  itself carries no detail; the log line is
  the only place the message, class, file and line exist.
- **No secret is ever logged.** Not the password, not the hash, not the refresh
  key.

Useful greps:

```bash
grep ' ERROR '            zfeeder.log
grep 'Feed fetch failed'  zfeeder.log | tail -20
grep 'Admin sign-in failed' zfeeder.log | wc -l
```

## Fetch outcomes

Feed freshness is the signal the health endpoint does not cover. There are three
ways to see it.

### `bin/zfeeder list-feeds`

The state of every subscription, per category: rendering position, title, URL,
how many items it shows, its refresh interval, whether it is subscribed, when it
was last fetched and what the cache holds.

```
news (10 subscriptions)
 # Title                URL                              Items Refresh Subscribed Last fetch        Status
 1 ABC News: World      http://.../world_rss093.xml          1  60 min  yes        2026-09-18 09:14  200
 2 BBC News             http://.../front_page/rss091.xml     1  60 min  yes        never             -
```

The `Status` column is the cached HTTP status, `-` when there is no cache entry,
or `error: …` when the last attempt failed and the stale entry recorded why.

`--json` gives the same thing for a script. The flag exists. The shape, from a
real run:

```json
{
    "categories": [
        {
            "name": "news",
            "count": 4,
            "ownerName": "zFeeder",
            "ownerEmail": "",
            "dateModified": "2026-09-24T00:00:00+00:00",
            "feeds": [
                {
                    "position": 1,
                    "title": "BBC News - World",
                    "url": "https://feeds.bbci.co.uk/news/world/rss.xml",
                    "htmlUrl": "https://www.bbc.co.uk/news/world",
                    "items": 3,
                    "refreshMinutes": 120,
                    "subscribed": true,
                    "renderable": true,
                    "lastFetch": null,
                    "status": null,
                    "error": null
                }
            ]
        }
    ]
}
```

`lastFetch` is `null` when the feed has never been fetched, otherwise an RFC 3339
timestamp. `status` is the cached HTTP status or `null`. `error` is the recorded
failure reason or `null`. Feeds are listed in rendering order.

Two checks worth scripting:

```bash
# feeds that have never been fetched
bin/zfeeder list-feeds --json | jq -r '.categories[].feeds[] | select(.lastFetch == null) | .url'

# feeds whose last attempt recorded an error
bin/zfeeder list-feeds --json | jq -r '.categories[].feeds[] | select(.error != null) | "\(.url)\t\(.error)"'
```

### `bin/zfeeder refresh`

By default it prints the wording zFeeder 1.6 printed, one line per feed:

```
https://example.com/feed.xml - cached
https://example.com/other.xml - not expired yet
https://broken.example/feed.xml - NOT cached; check connection
```

followed by a summary: `12 feeds: 9 cached, 2 still fresh, 1 failed.` Failed
feeds are then listed again with their messages.

The exit code is what cron should act on: **0** when every feed was fetched or
was still fresh, **1** when at least one failed, **2** when `--category` names
something that does not exist. A run that finds the lock held prints
`Another refresh is already running` and exits **0**, because the other process
is doing the work.

`--json` exists too. A real run over a category with no feeds:

```json
{
    "locked": false,
    "category": "empty",
    "feeds": [],
    "summary": {
        "total": 0,
        "cached": 0,
        "notExpired": 0,
        "failed": 0
    },
    "ok": true
}
```

With feeds, each entry of `feeds` is:

```json
{
    "url": "https://example.com/feed.xml",
    "outcome": "cached",
    "success": true,
    "message": "",
    "line": "https://example.com/feed.xml - cached"
}
```

`category` is `null` when no `--category` was given. `outcome` is one of four
values from `src/Fetch/FetchResult.php`:

| `outcome` | `success` | Meaning |
|---|---|---|
| `cached` | true | fetched and written to the cache |
| `not-modified` | true | the publisher answered 304; the cached copy is still current |
| `not-expired` | true | the per-feed refresh interval has not elapsed; nothing was requested |
| `failed` | false | the fetch failed; `message` says why, and any previous body is kept |

`summary` counts `cached` and `not-modified` together under `cached`;
`not-expired` under `notExpired`; `failed` under `failed`. `ok` is
`summary.failed === 0`, and the exit code follows it.

When another run holds the lock the document is shorter:
`{"locked": true, "lock": "<path>", "feeds": [], "ok": true}`.

Monitoring a cron run:

```bash
bin/zfeeder refresh --json > /var/log/zfeeder-refresh.json || \
    logger -t zfeeder "refresh reported failures"

jq -r '.feeds[] | select(.success == false) | "\(.url)\t\(.message)"' /var/log/zfeeder-refresh.json
```

### `GET /refresh?key=…`

The HTTP trigger prints the same 1.6 report as plain text, with a date header
and a `N feeds, M failed` footer. It always answers 200 when the key matches,
whatever the feeds did, so a monitor must read the body rather than the status.
With no `ZF_REFRESH_KEY` configured it answers 403 and refreshes nothing.

## Configuration as a signal

`bin/zfeeder check-config` is the readiness check for everything the health
endpoint does not cover. It prints every option with its effective value and its
source, then runs nine checks:

```
  [OK]   Data directory exists and is writable — /var/www/zfeeder-data
  [OK]   Data directory is outside public/
  [FAIL] At least one readable category — no readable .opml file in /var/www/zfeeder-data/categories
  [OK]   Cache directory is writable — /var/www/zfeeder-data/cache
  [OK]   SQLite file is writable — not used by the flat backend
  [OK]   Administration password is set
  [OK]   base_url ends with a slash — empty: detected from the request
  [OK]   Default template exists — classic/bluelogos.html
  [OK]   PHP extensions for the flat backend — dom, json, libxml, mbstring, simplexml

 [ERROR] 1 of 9 checks failed.
```

Exit 0 when all nine pass, 1 otherwise. `--json` produces the same report as a
document with `version`, `php`, `configFile`, `projectRoot`, `options`,
`checks`, `failed` and `ok`.

`configFile` is worth reading on its own. It is always
`<projectRoot>/data/config.json` — the path does not follow `ZF_DATA_DIR` — so
on any installation where the data directory has been moved it names a
different directory from the one the subscriptions are in. If a setting is not
taking effect, this is the first line to check.

The password hash and the refresh key are never printed — they appear as `set`
or `not set` — so the output is safe to paste into a bug report, which is what
it is for. Run it in a deployment pipeline:

```bash
bin/zfeeder check-config --json > config-report.json || exit 1
```

## Response headers

`src/Http/SecurityHeaders.php` adds `X-Zfeeder-Version` alongside the security
headers, which is a quick way to see which version answered a request:

```bash
curl -sI https://feeds.example.com/ | grep -i x-zfeeder-version
```

It is on the public pages, the demo pages, `/embed`, `/api/feeds`,
`/api/opml/{category}` and the admin panel. It is **not** on `/healthz` or
`/refresh`: those two return their response directly, without the header layer.
Read `version` out of the `/healthz` body instead.

`Strict-Transport-Security` appears only on requests zFeeder believes are
HTTPS. If you expect it and it is missing behind a proxy, `ZF_TRUSTED_PROXIES`
is not set — see
[DEPLOYMENT](DEPLOYMENT.md#tls-and-reverse-proxies).

## What to alert on

| Signal | How to collect it | Threshold | Why |
|---|---|---|---|
| `/healthz` not 200 | HTTP check every 30–60s | 2 consecutive failures | the data directory has become unwritable, the process is down, or the site was paused |
| `/healthz` unreachable | the same check | 2 consecutive failures | the container or web server is down |
| `bin/zfeeder refresh` exit code | cron wrapper | non-zero twice in a row | one transient failure is normal; a repeat is a dead feed or lost egress |
| `ERROR` lines in the log | log search | any | every one is an unhandled 5xx or a broken embed; there should be none |
| `Admin sign-in failed` | log search | more than ~20 in 10 minutes from one address | a password guessing run; the rate limiter is already refusing them, but you want to know |
| Feeds never fetched | `list-feeds --json`, daily | any feed with `lastFetch == null` older than a day | cron is not running, or that subscription is permanently broken |
| Oldest `lastFetch` | `list-feeds --json`, hourly | older than three times the feed's `refreshMinutes` | the refresh is not keeping up |
| Data directory size | `du -sh` | your own ceiling | the cache and an unrotated log both grow without bound |
| Log file size | `ls -l` | your own ceiling | nothing rotates it |
| TLS certificate expiry | your platform's check | 14 days | not zFeeder's concern, but it is what takes a site down |

Deliberately **not** alert-worthy:

- a single `Feed fetch failed` — publishers have outages, and the cached copy is
  still served;
- `not expired yet` in a refresh report — that is the cache working;
- `Another refresh is already running` — the lock doing its job;
- a 404 — not logged, and not an error.

## Platform log commands

| Platform | Application log | Note |
|---|---|---|
| Docker | `docker logs -f zfeeder` | needs `ZF_LOG_PATH=php://stdout` |
| Docker Compose | `docker compose -f deploy/docker-compose.yml logs -f zfeeder` | the file already sets `php://stdout` |
| Railway | `railway logs` | set `ZF_LOG_PATH=php://stdout` |
| Fly.io | `fly logs` | set in `deploy/fly.toml.example` |
| Render | the dashboard's Logs tab | set in `deploy/render.yaml.example` |
| Kubernetes | `kubectl -n zfeeder logs -f deploy/zfeeder` | set in the ConfigMap in `deploy/k8s.yaml` |
| VPS | `tail -f /var/www/zfeeder-data/zfeeder.log` | plus the web server's own logs |
| Shared hosting | download `zfeeder.log` from the data directory | there is usually no other access |

When `ZF_LOG_PATH` is `php://stdout` the application's lines are interleaved with
Apache's access log and with anything PHP writes to `error_log`, which
[`deploy/php.ini`](../deploy/php.ini) points at `/dev/stderr`. The application's
own lines are the ones matching `^\d{4}-\d{2}-\d{2}T`.
