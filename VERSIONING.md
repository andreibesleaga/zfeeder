# Versioning

zFeeder 2.x follows [Semantic Versioning 2.0.0](https://semver.org/).

## What the numbers cover

The public interface, for versioning purposes, is:

- the `zfeeder()`, `zfeeder_echo()` and `zfeeder_feeds()` functions and their options;
- the `zfeeder.php` include shim;
- the HTTP endpoints `/embed`, `/api/feeds`, `/api/opml/{category}`, `/refresh`, `/healthz`;
- the template format: section markers, token names and filter syntax;
- the OPML dialect written by the exporter;
- the configuration keys and environment variable names in `docs/CONFIGURATION.md`;
- the `bin/zfeeder` commands, their arguments and their exit codes;
- every class and method not marked `@internal`.

## What the numbers mean

- **Major** — a change that can break an existing installation: a removed token, a renamed
  configuration key, a changed default that alters output, a dropped endpoint.
- **Minor** — new templates, new tokens, new commands, new configuration keys with defaults
  that preserve current behaviour.
- **Patch** — fixes and security updates with no interface change.

## Compatibility promises

- **Template tokens are never removed within 2.x.** A 2004 template must keep working.
- **The OPML reader accepts every dialect it has ever accepted.** Subscriptions written by
  zFeeder 1.x in 2004 load in 2.x without conversion, and will in every 2.x release.
- **Storage is forward-compatible.** `bin/zfeeder migrate` moves between the flat and SQLite
  backends in both directions, and a flat data directory can always be read by the next
  minor release.
- **The minimum PHP version can rise in a minor release** only to a version that is still
  receiving security support at the time.

## Release cadence

There is no schedule. Security fixes are released when they are ready; everything else when
there is enough to be worth the upgrade. See `docs/RELEASE-PROCESS.md`.
