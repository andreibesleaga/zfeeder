<div align="center">

# zFeeder 2.0

**The PHP feed aggregator from 2004, rebuilt for 2026.**

[![CI](https://github.com/andreibesleaga/zfeeder/actions/workflows/ci.yml/badge.svg)](https://github.com/andreibesleaga/zfeeder/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![PHP 8.3+](https://img.shields.io/badge/php-8.3%2B-777bb4.svg)](https://www.php.net/)

</div>

---

zFeeder reads RSS, Atom and JSON feeds and puts them on your page. It keeps its
subscriptions in OPML files, caches what it fetches, renders through templates you
can edit, and drops into an existing page in one line of PHP. It needs no database
server and no build step.

It was first released in 2003–2004 on SourceForge and, by its author's own count,
ended up running on more than 20,000 websites. Development stopped at version 1.6 in
2004. This is version 2.0: the same product and the same architecture, on a current
stack, with the security problems of 2004 fixed.

The original is archived, unmodified, as `zfeeder-1.6.zip` in
[andreibesleaga/old-projects](https://github.com/andreibesleaga/old-projects) — and the
classic templates in this version are tested byte for byte against output captured from
that code running on PHP 5.6. See [docs/HISTORY.md](docs/HISTORY.md). 
More info at [zFeeder GitHub Pages](https://andreibesleaga.github.io/zfeeder/).
A public instance runs at **<https://zfeeder.up.railway.app>**, with the panel in
read-only demonstration mode.


## What it does

- Reads **RSS 0.9, 0.91, 0.92, 1.0 and 2.0, Atom 1.0 and JSON Feed 1.1**.
- Keeps subscriptions in **OPML**, one file per category. Files written in 2004 still load.
- **Two storage backends**, chosen at runtime: flat files, or a single SQLite file.
  `bin/zfeeder migrate` moves between them in either direction.
- **Caches** every feed with a per-subscription refresh interval, using ETag and
  If-Modified-Since, and keeps serving the last good copy when a publisher is down.
- **Two complete template sets**: `classic` reproduces the 2004 output exactly (14
  templates), and `modern` is a responsive, accessible redesign (16). 30 in total.
- An **administration panel** with the same five screens as 2004 — add feed,
  subscriptions, config, import, updates — in either a modern or a classic skin.
- **Feed autodiscovery** from a site URL, and OPML import and export.
- Embeds as a **PHP include**, an **HTML fragment** over HTTP, or a **JSON API**.
- A **command line tool** for everything the panel does, so cron and scripted installs work.

## Install

### Docker, the quickest way

```bash
docker run -d --name zfeeder -p 8080:80 \
  -e ZF_ADMIN_PASSWORD_HASH="$(docker run --rm ghcr.io/andreibesleaga/zfeeder bin/zfeeder hash-password 'your password' | head -1)" \
  -v zfeeder-data:/var/www/data \
  ghcr.io/andreibesleaga/zfeeder:2.0.0
```

Open <http://localhost:8080>. The panel is at `/admin`.

### From source

```bash
git clone https://github.com/andreibesleaga/zfeeder.git
cd zfeeder
composer install --no-dev
bin/zfeeder seed                     # load the shipped example subscriptions
bin/zfeeder hash-password            # prompts, prints the hash
php -S 127.0.0.1:8080 -t public      # development server
```

### Shared hosting, the way 1.6 was installed

Download `zfeeder-2.0.0.zip` from the [releases](https://github.com/andreibesleaga/zfeeder/releases),
unpack it, upload it, point the document root at `public/`, and put the `data/`
directory outside the web root. Dependencies are already in the archive; there is
nothing to compile and no Composer needed on the server.
Full instructions: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Use it

One line, exactly as in 2004:

```php
<?php echo zfeeder(['category' => 'news', 'template' => 'modern/cards']); ?>
```

The 1.6 include still works too:

```php
<?php include 'zfeeder.php'; ?>
```

Not a PHP site:

```
GET /embed?category=news&template=modern/list     an HTML fragment
GET /api/feeds?category=news                       JSON
GET /api/opml/news                                 the subscription list as OPML
```

From the command line:

```bash
bin/zfeeder seed                           # load the shipped example subscriptions
bin/zfeeder add https://lwn.net/headlines/rss --category=opensource
bin/zfeeder refresh --category=news        # what cron runs
bin/zfeeder list-feeds
bin/zfeeder export --category=news > news.opml
bin/zfeeder check-config
```

`bin/zfeeder list` prints all twelve commands; each takes `--help`.

Refresh from cron every fifteen minutes:

```cron
*/15 * * * * /path/to/zfeeder/bin/zfeeder refresh --quiet
```

## Configure it

Every option can be set three ways — an environment variable, `data/config.json`, or the
admin panel — and the environment always wins. `bin/zfeeder check-config` prints the
effective value of each option and where it came from.

```bash
ZF_STORAGE=sqlite           # flat (default) or sqlite
ZF_TEMPLATE_SET=modern      # classic or modern
ZF_ADMIN_SKIN=modern
ZF_MAX_DESCRIPTION_CHARS=600
ZF_DEMO_MODE=true           # read-only panel, for a public demo
```

All 44 options: [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Security

The 2004 code stored passwords as unsalted MD5, wrote its configuration file as PHP
built from `$_POST`, passed `$_GET` straight into a filesystem path, and would fetch
any URL it was given. None of that survives. Version 2.0 uses Argon2id with a
rate-limited login, a CSRF token on every state-changing request, an allow-list HTML
sanitiser on all feed content, an address guard that blocks loopback and private
ranges on every redirect hop, name validation plus realpath containment on every path
derived from input, XML parsing with document type declarations refused, and JSON
configuration stored outside the web root.

Each of the eighteen controls has a class in `tests/Security/`, `S01…` to `S18…`; the
suite is 484 tests. See [docs/THREAT-MODEL.md](docs/THREAT-MODEL.md), which also records
the four defects found in 2.0 itself and closed, and [SECURITY.md](SECURITY.md).

**Do not deploy the 2004 release.** It is kept as history in
[andreibesleaga/old-projects](https://github.com/andreibesleaga/old-projects), and it is not safe.

## Documentation

| | |
|---|---|
| [Configuration](docs/CONFIGURATION.md) | every option, generated from the schema |
| [Deployment](docs/DEPLOYMENT.md) | Docker, Railway, Fly, Render, Kubernetes, a VPS, shared hosting |
| [Templates](docs/TEMPLATES.md) | the format, every token, writing your own |
| [Architecture](docs/ARCHITECTURE.md) | how it is put together and why |
| [Upgrading from 1.6](docs/UPGRADING-FROM-1.6.md) | bringing a 2004 installation forward |
| [Runbook](docs/RUNBOOK.md) | operating it: backup, restore, rotate, upgrade |
| [Threat model](docs/THREAT-MODEL.md) | what it defends against and how that is proven |
| [Accessibility](docs/ACCESSIBILITY.md) | the WCAG 2.2 AA commitment and how it is checked |
| [Test plan](docs/TEST-PLAN.md) | the suites, the golden method, and what is not tested |
| [Verification report](docs/VERIFICATION-REPORT.md) | what each review actually produced, and what was not verified |
| [History](docs/HISTORY.md) | 2003 to 2026, with the original screenshots |
| [Roadmap](docs/ROADMAP.md) | what 2.x will and will not do |

## Contributing

[CONTRIBUTING.md](CONTRIBUTING.md). In short: the classic templates are frozen and
proven by golden tests, tokens are never removed, new dependencies need a reason, and
a security fix needs a test that fails without it.

## Licence

GPL-2.0-or-later, the same licence the 2004 release carried. See [LICENSE](LICENSE).

zFeeder is © 2003–2026 Andrei N. Besleaga. The original project is archived at
[sourceforge.net/projects/zvonnews](https://sourceforge.net/projects/zvonnews/).
