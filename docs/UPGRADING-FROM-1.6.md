# Upgrading from zFeeder 1.6

zFeeder 2.0 is a rebuild, not a patch. There is no in-place upgrade that overwrites
files in your `newsfeeds/` directory: you install 2.0 somewhere new, import the three
things that were yours rather than the program's — your subscriptions, your settings
and any templates you wrote — and then switch the host page over.

`bin/zfeeder legacy-import` does that in one command. It never modifies the old tree,
and it never executes the old `config.php`.

One thing cannot come across: the administrator password. 1.6 stored
`md5($password)`, which is not reversible and is not an acceptable credential in
2026. You will set a new one.

**Contents**

- [Before you start](#before-you-start)
- [The upgrade, step by step](#the-upgrade-step-by-step)
- [Every 1.6 setting and where it went](#every-16-setting-and-where-it-went)
- [The password cannot be migrated](#the-password-cannot-be-migrated)
- [Your subscriptions](#your-subscriptions)
- [Your templates](#your-templates)
- [The old cache directory](#the-old-cache-directory)
- [Removed features and what to use instead](#removed-features-and-what-to-use-instead)
- [Migrating by hand](#migrating-by-hand)
- [Did it work?](#did-it-work)

---

## Before you start

**Back up the old directory.** Copy the whole `newsfeeds/` tree somewhere safe:
`categories/*.opml` is the only copy of your subscription list, and `config.php` is
the only record of your settings.

**Do not deploy the 2004 code.** The original is kept as history at
<https://github.com/andreibesleaga/old-projects> (`zfeeder-1.6.zip`). It writes its
configuration file as PHP built from `$_POST`, passes
`$_GET` straight into a filesystem path, prints feed content unescaped, and will
fetch any URL it is handed. Keep it off the internet.

**Run the old site and the new one side by side** until you are satisfied. Nothing in
the import touches the old installation, so there is no rush to delete it.

What you need: PHP 8.3 or later, and shell access if you want the one-command path.
Without shell access, see [Migrating by hand](#migrating-by-hand).

## The upgrade, step by step

### 1. Install 2.0

Install it somewhere other than the old `newsfeeds/` directory, with the web root
pointed at `public/` and the data directory outside the web root. The options are in
the [README](../README.md).

### 2. Look at what would be imported

```bash
bin/zfeeder legacy-import /path/to/old/newsfeeds --dry-run
```

`--dry-run` writes nothing. It prints three tables — subscriptions, settings,
templates — and the warning about the password. Run against the 2004 corpus in
`tests/fixtures/legacy-1.6/newsfeeds/`, it reports:

```
Subscriptions
  empty.opml         0    would import
  general.opml       4    would import
  …
  zfeeder.opml       5    would import

Settings
  ZF_URL                                   base_url = (empty)
  ZF_CATEGORY       zfeeder                default_category = zfeeder
  ZF_TEMPLATE       templates/bluelogos    default_template = bluelogos
  ZF_DISPLAYERROR   no                     display_error = false
  ZF_CHANLOCATION   top                    channel_location = top
  ZF_CHANONEBAR     yes                    channel_one_bar = true
  …
  ZF_CACHEDIR       cache                  not copied: 2.0 keeps this under the data
                                           directory, outside the web root
  ZF_ADMINPASS      (md5 hash, not shown)  CANNOT be migrated

 [OK] Would import 11 categories and 133 subscriptions from …/newsfeeds.
```

Read the third column. Anything that says "skipped" is something you will have to
deal with by hand.

### 3. Import

```bash
bin/zfeeder legacy-import /path/to/old/newsfeeds --cache
```

`--cache` also reads the 1.6 cache directory, so the first page load is warm instead
of refetching every subscription. Leave it off if the cache is stale or the old site
has not run in years.

Exit codes: `0` when the import finished, `1` when the directory is not a zFeeder
installation or a file could not be read, `2` when the path argument is missing or
not a directory.

### 4. Set a password

```bash
bin/zfeeder hash-password
```

It prompts twice without echoing, then prints the Argon2id hash and the matching
`ZF_ADMIN_PASSWORD_HASH=` line. Put the hash in your environment, or in
`admin_password_hash` in `data/config.json`. The password itself is never stored.
Until you do this, `admin_password_hash` is empty and the admin panel is disabled.

### 5. Check and fetch

```bash
bin/zfeeder check-config
bin/zfeeder list-feeds
bin/zfeeder refresh
```

`check-config` prints every option, its effective value and where that value came
from — default, file or environment. `refresh` prints one line per feed, the same
report 1.6's offline refresh printed.

### 6. Switch the host page over

The 1.6 include still works:

```php
<?php include 'zfeeder.php'; ?>
```

The function form takes its options in PHP instead of the query string:

```php
<?php echo zfeeder(['category' => 'news', 'template' => 'classic/bluelogos']); ?>
```

The 1.6 query parameters are all still read: `zfcategory`, `zftemplate`,
`zfposition`, `zfmore`, `zf_link=off`. Existing links keep working.

## Every 1.6 setting and where it went

All sixteen `define()` calls in `newsfeeds/config.php`. "Imported" means
`bin/zfeeder legacy-import` carries the value across by itself.

| 1.6 constant | 1.6 default | 2.0 config key | 2.0 environment variable | imported | notes |
|---|---|---|---|---|---|
| `ZF_LOGINTYPE` | `server` | — | — | no | Dropped. 2.0 always uses a PHP session login; HTTP Basic auth is gone. |
| `ZF_URL` | `""` | `base_url` | `ZF_URL` | yes | Same variable name. Empty now means "detect from the request"; `{scripturl}` becomes `/`. |
| `ZF_ADMINNAME` | `""` | `admin_user` | `ZF_ADMIN_USER` | yes | 2.0 default is `admin`. |
| `ZF_ADMINPASS` | md5 hash | `admin_password_hash` | `ZF_ADMIN_PASSWORD_HASH` | **no** | See [below](#the-password-cannot-be-migrated). |
| `ZF_REFRESHKEY` | `""` | `refresh_key` | `ZF_REFRESH_KEY` | yes | Still the shared secret for `/refresh?key=…`. |
| `ZF_USEOPML` | `yes` | — | — | no | Dropped. 2.0 always uses subscription files; the "manual feed configuration" path is gone. |
| `ZF_OPMLDIR` | `categories` | `categories_dir` | `ZF_CATEGORIES_DIR` | reported, not copied | The 2004 value points inside the web root. 2.0 defaults to `categories/` under the data directory. |
| `ZF_CATEGORY` | `zfeeder` | `default_category` | `ZF_DEFAULT_CATEGORY` | yes | Lower-cased; skipped if it is not a valid category name. |
| `ZF_CACHEDIR` | `cache` | `cache_dir` | `ZF_CACHE_DIR` | reported, not copied | Same reason. 2.0 defaults to `cache/` under the data directory. |
| `ZF_OWNERNAME` | `""` | `owner_name` | `ZF_OWNER_NAME` | yes | Still written into exported OPML. |
| `ZF_OWNEREMAIL` | `""` | `owner_email` | `ZF_OWNER_EMAIL` | yes | Still written into exported OPML. |
| `ZF_TEMPLATE` | `templates/bluelogos` | `default_template` | `ZF_DEFAULT_TEMPLATE` | yes | The `templates/` prefix is stripped and the name lower-cased: `templates/bluelogos` becomes `bluelogos`. Which set it comes from is `template_set`. |
| `ZF_DISPLAYERROR` | `no` | `display_error` | `ZF_DISPLAY_ERROR` | yes | `yes`/`true`/`1`/`on` become `true`. **Not implemented yet**: nothing reads this option, so it has no effect on rendering. |
| `ZF_CHANLOCATION` | `top` | `channel_location` | `ZF_CHANNEL_LOCATION` | yes | `top` and `bottom` are kept; anything else becomes `none`, which is what 1.6 did with an unrecognised value. |
| `ZF_CHANONEBAR` | `yes` | `channel_one_bar` | `ZF_CHANNEL_ONE_BAR` | yes | `yes`/`true`/`1`/`on` become `true`. |
| `ZF_VER` | `1.6` | — | — | no | Dropped. The version is `Zfeeder\Version`. |

Imported settings are written to `data/config.json`. A setting that is pinned by an
environment variable is left alone and reported as "pinned by the environment; not
changed" — a deployment that fixes a value in the environment should not be quietly
overwritten by an import.

2.0 has 44 options in total; the thirteen above are the ones with a 1.6 ancestor.
Everything else — storage backend, fetch limits, session settings, logging, the
embed and API controls — is new and documented in
[Configuration](CONFIGURATION.md).

`config.php` itself is gone. 2.0 reads JSON from `data/config.json`, outside the web
root, and environment variables beat the file. The 1.6 admin panel wrote a PHP file
built from `$_POST`; nothing in 2.0 writes executable code.

## The password cannot be migrated

1.6 stored `md5($password)` with no salt. Unsalted MD5 is reversible in practice for
any password that has ever appeared in a word list, and there is no way to turn the
stored hash into the Argon2id hash 2.0 needs without knowing the password.

So the importer does not try. It reports `ZF_ADMINPASS` as "CANNOT be migrated",
prints the hash to nobody, and ends with a warning telling you to run
`bin/zfeeder hash-password`.

Two things follow from this:

- **Choose a new password, not the old one.** If the 2004 hash is in any leaked
  database — and 2004 hashes are — the old password is already known.
- **Until you set one, the admin panel is off.** An empty `admin_password_hash`
  disables it entirely, which is the safe state for a site that is mid-upgrade.

The username does come across, from `ZF_ADMINNAME` into `admin_user`.

## Your subscriptions

`categories/*.opml` is the part of a 1.6 installation worth the most and the part
that needs the least work. 2.0 reads 1.x OPML unchanged.

The importer does not copy the files. Each one is read with the 2.0 OPML reader and
written back with the 2.0 OPML writer, so what lands on disk is validated OPML 2.0
and a malformed 2004 file is reported now rather than failing on the first page load.
The per-subscription attributes 1.6 used — `position`, `showedItems`, `refreshTime`,
`isSubscribed`, `title`, `description`, `xmlUrl`, `htmlUrl`, `language` — all survive.

Category names must match `^[a-z0-9_-]{1,40}$`. A file whose name does not is
skipped and reported; rename it and re-run. Names are lower-cased, so
`Technology.opml` becomes the category `technology`.

Existing categories with the same name are **replaced**, not merged. Import into a
fresh installation, or export first:

```bash
bin/zfeeder export --category=news --output=news-before-import.opml
```

## Your templates

Anything in the old `templates/` directory that is not one of the templates 2.0 ships
is treated as yours and copied to `templates/classic/<name>.html`. Before it is
copied it is parsed with the 2.0 engine; one that no longer parses is reported and
skipped, because a broken template in `templates/classic/` is a fatal error the next
time somebody selects it.

Files whose name starts with `wap_` are skipped without comment: 1.6's WAP templates
emitted WML, and there is no WAP output path in 2.0.

A stock 1.6 installation has no user templates at all, and the importer says so:

```
Templates
  No user templates: every template in the old installation is one of the shipped ones.
```

The 2004 template format is unchanged, so a template you wrote in 2004 runs in 2.0
as it is. Two adjustments may be needed:

- **Stylesheets.** 1.6 kept template stylesheets in `newsfeeds/templates/css/`. 2.0
  publishes the shipped ones at `/assets/classic/css/`. Update the `<link>` in your
  host page's `<head>`.
- **Icons.** Classic templates reference `{scripturl}images/more.png` and similar.
  Those files are served from the web root at `/images/`, as in 2004, so they keep
  working as long as `base_url` is right — and when `base_url` is empty,
  `{scripturl}` is `/`, which is correct for an installation at the top of a domain.

The whole format, every token and every filter: [Templates](TEMPLATES.md).

## The old cache directory

1.6 named each cached feed after its URL with every non-alphanumeric character
replaced by an underscore — `ereg_replace("[^[:alnum:]]", "_", $url) . '.xml'` — and
used the file's modification time as the freshness record. There was no metadata
sidecar.

`src/Legacy/LegacyCacheLocator.php` still knows that naming scheme, for reading only.
With `--cache`, the importer walks the subscriptions it has just imported, looks each
feed's URL up in the old cache directory, and stores whatever it finds through the
2.0 cache with the old file's mtime as the fetch time. Feeds with no cached file are
skipped silently. It reports a count:

```
Cache
  Imported 92 cached feeds from /path/to/old/newsfeeds/cache.
```

2.0 does not use the 1.6 naming for anything it writes. It stores cache entries under
`sha256(url)` in the data directory, with a metadata sidecar holding the fetch time,
the ETag and the Last-Modified value, because the 1.6 scheme is not injective —
`http://a.test/x` and `http://a.test_x` collide — and it puts the feed URL into a
filename inside the web root.

The old cache directory is never modified and never deleted. Once the new
installation is serving, delete it yourself, together with the rest of the old tree.
There is nothing in it you need.

To start cold instead, leave `--cache` off and run `bin/zfeeder refresh`.

## Removed features and what to use instead

| 1.6 | status | instead |
|---|---|---|
| WAP output — `wap.php`, `demo_wap.wml`, the four `wap_*.html` templates | removed | Nothing. WML is gone. The responsive `modern` templates work on a phone browser. |
| Frames demo — `demo_frames.php`, `framesdemo_mainframe.php`, `framesdemo_sidebar.php` | removed | `/demos/aggregator`, the same sources-and-articles layout built with CSS grid and no frames. The `sidebar` and `mainframe` templates still exist in both sets. |
| HTTP Basic auth — `ZF_LOGINTYPE=server` | removed | A session login at `/admin`: Argon2id hashing, a rate limit on failed attempts, a CSRF token on every state-changing request, an idle timeout. |
| `ZF_LOGINTYPE=session` | still the only mode | Nothing to do. |
| `config.php` as the configuration file | removed | `data/config.json`, outside the web root, plus `ZF_*` environment variables which beat the file. The admin panel writes JSON, never PHP. |
| `ZF_USEOPML=no`, the manual feed list | removed | OPML always. `bin/zfeeder import` and `bin/zfeeder add` manage it, or use the subscriptions screen. |
| Cache files named after the feed URL | removed | `sha256(url)` plus a metadata sidecar. `LegacyCacheLocator` still reads the old naming for this import. |
| The unlimited `{description}` | changed | Capped at `max_description_chars`, 600 by default, and run through an allow-list HTML sanitiser. Set it to `0` for the 1.6 behaviour — but the sanitiser stays on. |
| `ZF_DISPLAYERROR` | imported, no effect | Not implemented yet. A feed that cannot be read renders as an empty channel. |

Features that are new rather than replaced — SQLite storage, the JSON API, the
`/embed` endpoint, feed autodiscovery, the CLI — are in the
[README](../README.md).

## Migrating by hand

If you cannot run PHP on the command line — the 2004 shared-hosting situation — the
import is four manual steps. Nothing about it is magic.

1. **Subscriptions.** Copy `newsfeeds/categories/*.opml` into the `categories/`
   directory inside your 2.0 data directory. Lower-case the filenames and make sure
   each name matches `^[a-z0-9_-]{1,40}$`. The files themselves need no editing. If
   you do have a shell, `bin/zfeeder import news.opml --category=news --replace` is
   the safer route, because it validates as it reads.
2. **Settings.** Open the old `config.php` in a text editor and copy the values into
   `data/config.json` using the [table above](#every-16-setting-and-where-it-went).
   It is a flat JSON object of 2.0 keys:

   ```json
   {
     "base_url": "https://example.com/",
     "default_category": "news",
     "default_template": "bluelogos",
     "template_set": "classic",
     "channel_location": "top",
     "channel_one_bar": true,
     "owner_name": "Your Name",
     "owner_email": "you@example.com"
   }
   ```

   Drop `ZF_LOGINTYPE`, `ZF_USEOPML`, `ZF_VER` and `ZF_ADMINPASS`. Do not copy
   `ZF_OPMLDIR` or `ZF_CACHEDIR`: the 2004 values point inside the web root.
3. **Templates.** Copy any template you wrote into `templates/classic/`, lower-casing
   the filename. Skip the `wap_*` ones.
4. **Password.** You still need `bin/zfeeder hash-password` — or any PHP that can
   call `password_hash($password, PASSWORD_ARGON2ID)` — to produce the value for
   `admin_password_hash`. There is no way around this. Leave the panel disabled
   until you have it.

The old cache directory cannot be imported by hand in any useful way; just let
2.0 fetch everything once.

## Did it work?

Work down the list. Each line is one command or one thing to look at.

| # | check | how |
|---|---|---|
| 1 | every category arrived | `bin/zfeeder list-feeds` — compare the category names and counts with the old `categories/` directory |
| 2 | subscription detail survived | `bin/zfeeder list-feeds --category=news` — positions, titles and item counts match the old subscriptions screen |
| 3 | nothing was silently skipped | re-read the import output: every row of all three tables says "imported" or "copied", not "skipped" |
| 4 | the settings landed | `bin/zfeeder check-config` — `default_category`, `default_template`, `channel_location`, `channel_one_bar`, `base_url` match the old `config.php`, and the Source column says `file` |
| 5 | feeds actually fetch | `bin/zfeeder refresh` — one line per feed, and no failures you cannot explain |
| 6 | the page renders | load the host page; the feeds appear in the same order, with the same template |
| 7 | your template still works | select it explicitly: `?zftemplate=classic/<yourname>` |
| 8 | the panel lets you in | open `/admin`, sign in with `admin_user` and the new password |
| 9 | the panel refuses the old password | try it; it must fail |
| 10 | `{scripturl}` is right | view source on the rendered page: image and stylesheet URLs resolve, no `{scripturl}` left in the markup |
| 11 | the data directory is not public | request `/config.json`, `/data/config.json` and `/categories/news.opml` over HTTP; all three must fail |
| 12 | the old tree is gone | once 1–11 pass, delete the old `newsfeeds/` directory from the web root, cache and all |

If something in 1–5 is wrong, the import is cheap to repeat: fix the cause, delete
the affected category, and run `bin/zfeeder legacy-import` again. It reads the old
directory and never writes to it.

---

## See also

| | |
|---|---|
| [Configuration](CONFIGURATION.md) | all 44 options, generated from the schema |
| [Templates](TEMPLATES.md) | the format, every token, writing your own |
| [History](HISTORY.md) | what 1.6 was, and an honest account of its code |
| [README](../README.md) | installing 2.0 |
| [SECURITY.md](../SECURITY.md) | reporting a problem in 2.0 |

The code behind this page: `src/Cli/Command/LegacyImportCommand.php` (the import and
the constant mapping), `src/Legacy/LegacyCacheLocator.php` (the 1.6 cache naming),
`src/Config/Schema.php` (the 2.0 options). The 2004 original, for comparison, is
`tests/fixtures/legacy-1.6/newsfeeds/config.php`.
