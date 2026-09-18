# Runbook

Operating a running zFeeder 2.0 installation: first-run setup, backup and
restore, changing the password, switching storage, managing subscriptions from
the command line, clearing the cache, reading the log and upgrading.

Getting it running in the first place is [`docs/DEPLOYMENT.md`](DEPLOYMENT.md).
What the log and `/healthz` mean in detail is
[`docs/OBSERVABILITY.md`](OBSERVABILITY.md). Every configuration option is in
[`docs/CONFIGURATION.md`](CONFIGURATION.md).

Every command below is run from the installation directory, as the user the web
server runs as. On a container, prefix it:

```bash
docker exec -it zfeeder bin/zfeeder <command>
kubectl -n zfeeder exec deploy/zfeeder -- bin/zfeeder <command>
```

## Contents

- [The data directory](#the-data-directory)
- [First-run setup](#first-run-setup)
- [Everyday commands](#everyday-commands)
- [Backup](#backup)
- [Restore](#restore)
- [Rotating the administrator password](#rotating-the-administrator-password)
- [Switching storage backend](#switching-storage-backend)
- [Adding and removing subscriptions](#adding-and-removing-subscriptions)
- [Clearing the cache](#clearing-the-cache)
- [Reading the log](#reading-the-log)
- [When `/healthz` is degraded](#when-healthz-is-degraded)
- [Upgrading](#upgrading)
- [Two self-repairing behaviours in the fetch path](#two-self-repairing-behaviours-in-the-fetch-path)
- [Symptom, cause, command](#symptom-cause-command)

## The data directory

Almost everything that cannot be recreated from the repository lives in one
directory. It is `ZF_DATA_DIR`, or `data/` beside the code when that is unset,
and it must be outside `public/`.

The exception is the configuration file. `ConfigLoader` always reads
`<project root>/data/config.json`, a path built from the project root rather
than from `ZF_DATA_DIR`. With the default layout the two are the same directory
and the listing below is complete. If you have moved `ZF_DATA_DIR` elsewhere —
every container recipe does — then `config.json` is **not** in it: it is at
`<project root>/data/config.json`, which inside a container image means the
container's own filesystem, and a panel save there does not survive a redeploy.
`bin/zfeeder check-config` prints both paths, on its `Config file` and
`Data directory` lines. Configure those installations through environment
variables.

With `ZF_STORAGE=flat` (the default):

```
data/
    categories/
        news.opml              one file per category, OPML 2.0
        technology.opml
    cache/
        <sha256 of the url>.xml     the fetched body
        <sha256 of the url>.json    etag, last-modified, status, fetched-at
    ratelimit/                 one small JSON file per failed-login key
    config.json                what the admin panel writes; holds the password hash.
                               Always at <project root>/data/config.json, even
                               when ZF_DATA_DIR points somewhere else.
    zfeeder.log                unless ZF_LOG_PATH points elsewhere
    refresh.lock               held while bin/zfeeder refresh runs
```

With `ZF_STORAGE=sqlite`, `categories/` and `cache/` are replaced by one file:

```
data/
    zfeeder.sqlite             tables: categories, feeds, cache, schema_version
    zfeeder.sqlite-wal         write-ahead log; part of the database
    zfeeder.sqlite-shm         shared memory index; part of the database
    ratelimit/
    config.json
    zfeeder.log
```

`config.json` is a JSON file in both cases. There is no `config.php` and nothing
generates PHP at runtime.

Files are written at mode 0640 and directories at 0750, through
`src/Storage/AtomicFile.php`, which writes to a temporary file in the same
directory and renames it over the target. A reader never sees a half-written
file, and nothing needs to be world-writable.

## First-run setup

1. **Create the data directory and seed it.** A container does this for you; on
   a VPS or shared host, in the installation directory:

   ```bash
   mkdir -p data/categories data/cache
   cp data-dist/config.json.dist  data/config.json
   chown -R www-data:www-data data
   chmod 0750 data
   bin/zfeeder seed                 # load the shipped example subscriptions
   ```

   `seed` reads every OPML file in `data-dist/categories/` and creates one
   category per file, through the same store interface the admin panel uses, so
   it works with `ZF_STORAGE=sqlite` as well as with the flat backend. Copying
   the OPML files by hand only works for `flat`. It leaves a category that
   already exists alone unless `--force` is given, which is why the container
   entrypoint can run it on every boot. `--dry-run` reports what it would do and
   changes nothing.

2. **Set the administrator password.**

   ```bash
   bin/zfeeder hash-password
   ```

   It prompts twice without echo and prints three lines: the bare hash, a
   `ZF_ADMIN_PASSWORD_HASH=…` assignment and an `"admin_password_hash": "…"`
   JSON fragment. Put one of them where your deployment keeps configuration.
   The minimum is 12 characters. Until it is set, `/admin` answers 503.

3. **Check the configuration.**

   ```bash
   bin/zfeeder check-config
   ```

   Nine checks run, each printed as `[OK]` or `[FAIL]`: the data directory
   exists and is writable; it is outside `public/`; at least one category is
   readable; the cache directory is writable; the SQLite file is writable (`not
   used by the flat backend` when it is not); the administrator password is set;
   `base_url` ends with a slash; the default template exists; and the PHP
   extensions for the chosen backend are loaded. Exit code 0 means all nine
   passed; anything else exits 1 and names the failures.

4. **Fill the cache once**, so the first visitor does not wait for it:

   ```bash
   bin/zfeeder refresh --force
   ```

5. **Install the cron entry.** See
   [Keeping the feeds fresh](DEPLOYMENT.md#keeping-the-feeds-fresh).

6. **Sign in** at `/admin` and confirm the panel works.

## Everyday commands

| Command | What it does |
|---|---|
| `bin/zfeeder refresh` | fetch every subscription whose cache has expired |
| `bin/zfeeder refresh --force` | fetch everything, ignoring the per-feed interval |
| `bin/zfeeder list-feeds` | every category and subscription, with the last fetch and its status |
| `bin/zfeeder check-config` | effective configuration, where each value came from, and nine checks |
| `bin/zfeeder purge --older-than=30d` | drop old cached bodies |
| `bin/zfeeder export --output=FILE` | write the whole subscription list as OPML |
| `bin/zfeeder add URL --category=NAME` | subscribe to a feed |
| `bin/zfeeder import FILE --category=NAME` | import an OPML list |
| `bin/zfeeder migrate --to=sqlite` | move storage backends |
| `bin/zfeeder hash-password` | make a password hash |
| `bin/zfeeder seed` | load the shipped example subscriptions into the configured backend |
| `bin/zfeeder legacy-import DIR` | bring a 2004 `newsfeeds/` directory forward |
| `bin/zfeeder docs:config` | regenerate `docs/CONFIGURATION.md` from the schema |

`bin/zfeeder list` prints the current list; `bin/zfeeder <command> --help` prints
a worked example and the exit codes for that command.

Exit codes are consistent across the tool: 0 success, 1 the operation failed, 2
the arguments were wrong (Symfony Console's `INVALID`), 78 a configuration
problem (`EX_CONFIG` from `sysexits.h`). `bin/zfeeder` itself exits 78 when the
configuration cannot be loaded at all, printing `Configuration error: …`.

## Backup

The subscriptions are the part you cannot get back. The cache is disposable: the
next refresh rebuilds it.

### Everything, both backends

Stop writes if you can — the web server and the cron entry — then archive the
directory:

```bash
tar -czf /var/backups/zfeeder-$(date +%F).tar.gz -C /var/www/zfeeder data
```

From a Docker volume, without stopping the container:

```bash
docker run --rm -v zfeeder-data:/data -v "$PWD":/backup alpine \
  tar -czf /backup/zfeeder-$(date +%F).tar.gz -C /data .
```

That volume holds the subscriptions, the cache and, when `ZF_LOG_PATH` is a
file, the log. It does **not** hold `config.json`, which the container reads
from `/var/www/html/data/config.json` on its own filesystem. On a container,
the configuration you need to be able to restore is the set of `ZF_*` variables
you gave the platform; keep those wherever you keep deployment configuration.

### Subscriptions only, portable, any backend

```bash
bin/zfeeder export --output=/var/backups/zfeeder-$(date +%F).opml
```

One OPML 2.0 document with every category merged, renumbered from 1 and titled
`all`. It opens in any other aggregator, and in the 2004 script. Add
`--category=news` for one category, which keeps the category name.

This is the backup to run nightly. It is small, it is text, and it survives a
change of storage backend.

### SQLite

Copying `zfeeder.sqlite` while the site is running is not safe: the recent
writes are in `zfeeder.sqlite-wal`. Use SQLite's own backup instead.

```bash
sqlite3 /var/www/zfeeder/data/zfeeder.sqlite \
  ".backup '/var/backups/zfeeder-$(date +%F).sqlite'"
```

If the `sqlite3` binary is not installed, PHP can do the same thing through the
extension zFeeder already requires for this backend:

```bash
php -r '$p = new PDO("sqlite:/var/www/zfeeder/data/zfeeder.sqlite");
        $p->exec("VACUUM INTO \"/var/backups/zfeeder.sqlite\"");'
```

Either way you get a single consistent file with no `-wal` beside it.

### What to keep

| File | Keep it | Why |
|---|---|---|
| `categories/*.opml` or `zfeeder.sqlite` | yes | the subscriptions; irreplaceable |
| `config.json` | yes | settings and the password hash — back it up from `<project root>/data/`, which is not the mounted directory on a container |
| `cache/` or the `cache` table | no | rebuilt by the next refresh |
| `zfeeder.log` | your choice | diagnosis only |
| `ratelimit/` | no | failed-login counters; they expire anyway |
| `refresh.lock` | no | recreated automatically |

## Restore

1. Stop the web server, or the container.
2. Put the files back:

   ```bash
   tar -xzf /var/backups/zfeeder-2026-09-18.tar.gz -C /var/www/zfeeder
   chown -R www-data:www-data /var/www/zfeeder/data
   ```

   For a SQLite backup, copy the single file into place as
   `zfeeder.sqlite` and delete any stale `zfeeder.sqlite-wal` and
   `zfeeder.sqlite-shm` beside it.
3. Start it again and check:

   ```bash
   bin/zfeeder check-config
   bin/zfeeder list-feeds
   curl -fsS http://localhost/healthz
   ```
4. Warm the cache: `bin/zfeeder refresh --force`.

### Restoring from an OPML export only

```bash
bin/zfeeder import /var/backups/zfeeder-2026-09-18.opml --category=news --replace
```

`--replace` throws away whatever is in the category first. Without it the feeds
are appended and the existing numbering continues, so an import can never
reorder what is already there. A whole-installation export is one merged
document, so restoring it into separate categories means either importing it
into one category and moving feeds in the panel, or keeping per-category
exports:

```bash
for c in $(bin/zfeeder list-feeds --json | jq -r '.categories[].name'); do
    bin/zfeeder export --category="$c" --output="/var/backups/$c.opml"
done
```

## Rotating the administrator password

Which route to use depends on where the current hash comes from. `bin/zfeeder
check-config` prints the source in the `admin_password_hash` row: `env`, `file`
or `default`.

### The hash comes from the environment (`env`)

The admin panel refuses to change it — it says so on the config screen — because
a deployment that pins a setting must not be quietly overridden from inside the
application. Make a new hash and update the environment:

```bash
bin/zfeeder hash-password | head -1
```

Then set `ZF_ADMIN_PASSWORD_HASH` in the platform (`railway variables --set`,
`fly secrets set`, the Kubernetes secret, the vhost, the `.htaccess`) and
restart or redeploy. The old password stops working when the new value is live.

### The hash comes from `config.json` (`file`)

Either use the panel — `/admin/config` has a password change form that takes the
new password twice, hashes it with Argon2id and rewrites `config.json` — or do
it from the command line:

```bash
bin/zfeeder hash-password | head -1
```

and paste the result into `<project root>/data/config.json` as
`"admin_password_hash"`. Write the file atomically or copy over it; there is no
CLI command that edits `config.json` in place.

`bin/zfeeder check-config` prints the file it reads on its `Config file` line.
Edit that file and no other — on a container it is inside the image's own
filesystem, not on the mounted data volume, which is why a container's password
belongs in `ZF_ADMIN_PASSWORD_HASH` rather than here.

### Afterwards

- Existing sessions are not invalidated by a password change. Sign out of any
  browser you care about, or wait for `ZF_SESSION_IDLE` (1800 seconds by
  default) to expire them.
- A successful change is logged at `notice` level as `Admin password changed`.
- If you are locked out by the login rate limiter (`Too many sign-in attempts`,
  HTTP 429), it clears by itself after `ZF_LOGIN_WINDOW` seconds, 900 by
  default. It is a JSON file per key under `ratelimit/` in the data directory;
  a successful sign-in clears that key. Deleting the file has the same effect if
  you cannot wait.

## Switching storage backend

`bin/zfeeder migrate` copies every category, every subscription and every cache
entry from the backend that is currently configured into the other one, then
re-reads the destination and compares the counts. The source is never modified,
so it is your backup.

```bash
# look first
bin/zfeeder migrate --to=sqlite --dry-run

# do it, and print the per-category counts read back from the destination
bin/zfeeder migrate --to=sqlite --verify
```

Output ends with the step the command cannot do for you:

```
Now set ZF_STORAGE=sqlite (or "storage": "sqlite" in config.json) and restart.
The flat data is left in place as your backup.
```

Set it, restart, and confirm:

```bash
bin/zfeeder check-config      # storage row should read sqlite
bin/zfeeder list-feeds        # same categories, same counts
```

The flags are `--to=flat|sqlite`, `--verify`, `--dry-run` and `--ensure-schema`.
Points worth knowing:

- **It works in both directions.** `--to=flat` writes the OPML files back out.
  Switching storage is never a one-way door.
- **`--to` must differ from the current backend.** Migrating to the backend you
  are already on prints a warning and exits 0 without doing anything.
- **`--ensure-schema` is a different job**: it creates or upgrades the SQLite
  schema and exits, does nothing when the backend is `flat`, and is safe on every
  boot. The container entrypoint runs it.
- **`pdo_sqlite` must be loaded** for anything involving `sqlite`; without it the
  command exits 78.
- **If the verification fails**, nothing was removed from the source. Fix the
  problem and run it again.
- The migration copies the cache too, so the site is warm immediately after the
  switch.

## Adding and removing subscriptions

### Adding

```bash
bin/zfeeder add https://www.theregister.com/headlines.atom \
    --category=technology --items=5 --refresh=30
```

The feed is fetched first, and the stored title and description come from the
feed rather than from what you typed. The new subscription takes the next free
position. Options: `--category`, `--items` (default 3), `--refresh` (minutes,
default 120), `--title`, `--no-verify`.

`--no-verify` skips the fetch, for an air-gapped install or a feed that is down
right now. The address is still checked against the URL guard, because what is
stored now is what cron will fetch later.

The command refuses, before writing anything, a URL already subscribed in the
same category, and a URL that resolves to a private, loopback or cloud-metadata
address unless `ZF_ENV=development`.

Importing a list:

```bash
bin/zfeeder import ~/Downloads/subscriptions.opml --category=news
bin/zfeeder import https://example.com/feeds.opml --category=news --replace
```

### Removing

**There is no `bin/zfeeder remove` command.** Deletion is a panel action:
`/admin/subscriptions`, tick the feeds, choose delete.

From the command line, do it through OPML:

```bash
bin/zfeeder export --category=news --output=/tmp/news.opml
$EDITOR /tmp/news.opml                       # delete the <outline> elements you want gone
bin/zfeeder import /tmp/news.opml --category=news --replace
```

`--replace` is what makes this a removal rather than a merge. Export first and
keep the original file: if you get it wrong, importing the original back with
`--replace` undoes it.

With `ZF_STORAGE=flat` you can edit `data/categories/news.opml` directly instead;
it is the same document. Do not edit it while a refresh is running.

A third option, if the feed should stop being rendered but stay on the list: set
its "subscribed" flag to no, or its item count to 0, in the panel. A feed with
`showedItems` 0 or `isSubscribed` no is stored but never rendered and never
fetched — the way zFeeder 1.6 let you park a feed without deleting it.
`bin/zfeeder list-feeds` shows both columns.

## Clearing the cache

There is a purge command.

```bash
bin/zfeeder purge --older-than=12h --dry-run    # look
bin/zfeeder purge --older-than=30d              # housekeeping
bin/zfeeder purge --all                         # everything
```

`--older-than` takes a number and a unit: `s`, `m`, `h` or `d`. The default is
`7d`. The report says how many entries went and how much disk came back:

```
Removed 41 cache entries, freeing 3.7 MiB. 12 kept.
```

Purging is always safe — the next refresh fetches whatever was removed — and it
works the same on both backends: files under `cache/` for `flat`, rows in the
`cache` table for `sqlite`.

Use it when a feed is being served from something stale, or as a weekly job:

```cron
0 4 * * 0 /var/www/zfeeder/bin/zfeeder purge --older-than=30d --quiet
```

After a full purge, run `bin/zfeeder refresh --force` rather than leaving the
first visitor to refill it.

## Reading the log

One line per record, written by `src/Log/FileLogger.php`:

```
2026-09-18T09:14:22Z WARNING Feed fetch failed {"url":"https://example.com/feed.xml","error":"HTTP 503"}
```

UTC timestamp, the level in upper case, the message with `{placeholders}`
already substituted, and the context as JSON when there is any.

Where it is: `ZF_LOG_PATH`, or `zfeeder.log` in the data directory. Containers
should set `php://stdout`, which puts it in `docker logs`, `railway logs`,
`fly logs` or `kubectl logs`.

What is recorded: `ZF_LOG_LEVEL` and everything above it. The default is
`warning`, which in practice means failed fetches, unparseable feeds, failed
sign-ins and unhandled 5xx errors. Set `info` to also see successful sign-ins,
`debug` for the lowest level the logger accepts.

```bash
tail -f /var/www/zfeeder/data/zfeeder.log
grep ' ERROR ' /var/www/zfeeder/data/zfeeder.log
grep 'Feed fetch failed' /var/www/zfeeder/data/zfeeder.log | tail -20
```

**The log does not rotate itself.** Add a `logrotate` entry, or point
`ZF_LOG_PATH` at something that does.

zFeeder never writes a password, a hash or the refresh key to the log.
[`docs/OBSERVABILITY.md`](OBSERVABILITY.md) lists every message the application
emits and the level it uses.

## When `/healthz` is degraded

`GET /healthz` returns:

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

Status 200 with `"status": "ok"`, or **status 503 with `"status": "degraded"`**.

Only one thing decides it: `data_writable`. `PublicController::health()` sets
`status` from that check alone. `subscriptions` is reported but does not affect
it, because a brand-new installation with no categories is starting up, not
broken.

| What you see | What it means | What to do |
|---|---|---|
| 503, `data_writable: false` | the data directory does not exist, or the web server user cannot write it | `ls -ld` the directory; `chown` it; `bin/zfeeder check-config` names the user it tested as |
| 200, `subscriptions: false` | no category could be read: an empty installation, or `categories/` is missing | `bin/zfeeder list-feeds`; import or add a feed |
| the "This zFeeder instance is paused" HTML page, 503 | `ZF_DEMO_ENABLED` is false | the check runs before routing, so it affects every path including this one; set it back to `true` |
| no answer at all | the process is down, or the web server is not reaching PHP | the platform's own logs, not zFeeder's |

## Upgrading

1. Read [`CHANGELOG.md`](../CHANGELOG.md) for the target version, and
   [`VERSIONING.md`](../VERSIONING.md) for what each kind of release may change.
   Within 2.x, template tokens are never removed, the OPML reader keeps accepting
   every dialect it ever accepted, and `bin/zfeeder migrate` keeps working in
   both directions.
2. Back up: `bin/zfeeder export --output=…` plus an archive of the data
   directory.
3. Replace the code. Per-target commands are in
   [`docs/DEPLOYMENT.md`](DEPLOYMENT.md#upgrading).
4. On SQLite, let the schema catch up:

   ```bash
   bin/zfeeder migrate --ensure-schema
   ```

   Migrations are numbered `.sql` files applied inside a transaction and recorded
   in `schema_version`; a file is never rewritten once released. The container
   entrypoint runs this on every boot.
5. `bin/zfeeder check-config` — expect all nine checks to pass.
6. `curl -fsS http://localhost/healthz` — expect `"status": "ok"` and the new
   `version`.
7. Sign in to `/admin` once, and load the public page.
8. On a VPS, reload PHP-FPM or Apache. `opcache.validate_timestamps = 0` in
   `deploy/php.ini` means PHP does not notice changed files by itself.

Rolling back is putting the old code back and restoring the data directory from
the backup taken at step 2.

## Two self-repairing behaviours in the fetch path

Both of these exist because of a failure that actually happened, and both are
worth recognising in a log rather than investigating from scratch.

### Compression is left to the HTTP client

`FeedFetcher::requestHeaders()` sets `User-Agent` and `Accept`, and deliberately
**does not** set `Accept-Encoding`. Setting it by hand tells the transport that
the caller will handle the encoding, so curl stops decompressing transparently;
the body then arrives still gzipped and every feed fails to parse with
`Start tag expected`. Leaving the header off lets the client negotiate
compression and decode it, which is what this code wants — publishers still
serve gzip, and the bytes that reach the parser are the feed. If you are
patching the fetcher, this is the line not to add.

### An unparseable cached body is discarded and refetched once

A cached body that will not parse would otherwise leave a feed blank until its
refresh interval expires, which can be hours. It usually means the entry is
damaged rather than the publisher being broken — a truncated write, or a body
stored by an older version that encoded it differently.

`FeedService::loadOne()` therefore deletes the entry and fetches once more, and
logs `Discarding an unparseable cache entry for {url} and refetching` at notice
level. The retry is bounded: it happens only when the entry came from the cache
(`FetchResult::NOT_EXPIRED`) and only when the caller did not already ask for a
forced refresh, so a feed that is genuinely malformed costs one extra request
per interval, not one per page view.
`Integration\Http\FeedServiceTest::testAnUnparseableCacheEntryIsDiscardedAndRefetchedOnce`
and `testAFeedThatIsGenuinelyBrokenIsNotRefetchedRepeatedly` pin both halves.

## Symptom, cause, command

| Symptom | Likely cause | Command |
|---|---|---|
| `/healthz` is 503, `data_writable: false` | the data directory is missing or not writable by the web server user | `bin/zfeeder check-config` |
| The whole site is a "paused" page | `ZF_DEMO_ENABLED=false` | `bin/zfeeder check-config` and look at the `demo_enabled` row |
| `/admin` answers 503 with a message about no password | no `admin_password_hash` anywhere | `bin/zfeeder hash-password` |
| `/admin` answers 404 | `ZF_ADMIN_ENABLED=false` | `bin/zfeeder check-config` |
| The panel refuses every save with a 403 page | `ZF_DEMO_MODE=true` | `bin/zfeeder check-config` |
| Sign-in loops back to the form over HTTPS | the proxy is not trusted, so the session cookie is not Secure | set `ZF_TRUSTED_PROXIES`; [DEPLOYMENT](DEPLOYMENT.md#tls-and-reverse-proxies) |
| `Too many sign-in attempts`, 429 | login rate limiter, 5 attempts per 900 seconds per address | wait, or raise `ZF_LOGIN_MAX_ATTEMPTS` / `ZF_LOGIN_WINDOW` |
| One feed is blank on the page | its fetch failed and nothing is cached | `bin/zfeeder list-feeds --category=NAME` |
| Every feed is stale | no cron, or `ZF_REFRESH_MODE=offline` with nothing calling it | `bin/zfeeder refresh --force` |
| Feeds stale even though cron runs | the per-feed refresh interval has not elapsed | `bin/zfeeder refresh --force`, then check the `Refresh` column of `list-feeds` |
| `Another refresh is already running` | a previous run still holds `refresh.lock` | normal; if it never clears, check for a stuck process and remove the stale lock file |
| `Cannot open the refresh lock file` | cron runs as a user that cannot write the data directory | run cron as the web server user |
| A feed shows old content after the publisher fixed it | a stale cache entry kept because the refresh failed | `bin/zfeeder purge --all && bin/zfeeder refresh --force` |
| Disk filling up | the cache, or an unrotated log | `bin/zfeeder purge --older-than=7d`; add logrotate for `ZF_LOG_PATH` |
| `Configuration error: …`, exit 78 | an unknown key, a bad type or an out-of-range value in `config.json`, or a data directory inside `public/` | fix the key the message names, then `bin/zfeeder check-config` |
| `could not find driver` | `ZF_STORAGE=sqlite` without `pdo_sqlite` | `bin/zfeeder check-config` — the extension check names it |
| A setting changed in the panel has no effect | it is pinned by an environment variable | `bin/zfeeder check-config` — the `Source` column reads `env` |
| A `config.json` you edited has no effect | it is not the file the loader reads: the path is `<project root>/data/config.json`, not `$ZF_DATA_DIR/config.json` | `bin/zfeeder check-config` — the `Config file` line names the file it used |
| Panel settings reset after a container redeploy | they were written to `<project root>/data/config.json` inside the container, not to the mounted volume | move those settings to `ZF_*` environment variables |
| `docs/CONFIGURATION.md` disagrees with the program | it was edited by hand | `bin/zfeeder docs:config` |
| Site returns 500 with no detail | production hides the detail on purpose | the log has it: `grep ' ERROR ' zfeeder.log` |
| Site returns 404 for a template name you expect to work | a `TemplateException` is reported as 404, not 500, because a template that does not exist is a bad request | `grep ' NOTICE ' zfeeder.log` — refusals below 500 are logged at notice level with the status and the class |
| `Discarding an unparseable cache entry for … and refetching` in the log | a cached body would not parse, so it was thrown away and fetched once more | nothing; this is the self-repair described below. If it repeats for the same URL every interval, the publisher is serving something that is not a feed |
| Every feed fails with `Start tag expected` right after a change to the fetcher | a hand-set `Accept-Encoding` header stopped the transport decompressing, so gzip bytes reached the XML parser | leave `Accept-Encoding` unset; see below |
