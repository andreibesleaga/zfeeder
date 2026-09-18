# 0004. Two storage backends, flat files and SQLite, selectable at runtime

## Status

Accepted.

## Context

"No database" is part of what zFeeder is. 1.6 kept subscriptions in OPML files, one per category, and cached
feed bodies as files, which is why it ran on any shared host and why an administrator could back the whole
installation up with an FTP client. The counter-argument is equally real: one file per cached feed does not
survive a few hundred subscriptions gracefully, and search or de-duplication wants a query engine.

The plan originally recommended flat files only. The owner's decision of 2026-09-17 changed it to both,
selectable at runtime, with migration in both directions.

## Decision

Two ports, two adapters each. `src/Storage/SubscriptionStoreInterface.php` covers categories, feeds and OPML
import/export; `src/Storage/CacheStoreInterface.php` covers cached bodies. The flat adapters are
`src/Storage/Flat/OpmlSubscriptionStore.php` and `FileCacheStore.php`; the SQLite adapters are
`src/Storage/Sqlite/SqliteSubscriptionStore.php` and `SqliteCacheStore.php`, sharing one connection from
`Database.php`.

The backend is the `storage` option (`ZF_STORAGE`, enum `flat|sqlite` in `src/Config/Schema.php`), and
`src/Storage/StoreFactory.php` is the only place in the codebase that names a concrete store. Flat is the
default.

`Database` applies the connection settings itself so no store can forget one — exceptions instead of silent
`false`, `foreign_keys=ON`, and WAL so a page render is never blocked by a cron job writing the cache — and
runs numbered migrations from `src/Storage/Sqlite/Migrations/` (currently `001_initial.sql`), recording
applied versions in `schema_version`.

`src/Storage/Migrator.php` copies everything either way, then re-reads the destination and compares what
landed, because "the writes did not throw" is not the same as "the data is there". It is exposed as
`bin/zfeeder migrate`.

## Consequences

- The backends must be indistinguishable from outside, so the contract is written once and run twice:
  `tests/Support/SubscriptionStoreContractTestCase.php` and `CacheStoreContractTestCase.php` are each
  extended by a flat and a SQLite case in `tests/Unit/Storage/`.
- CI runs the whole suite under both: the matrix in `.github/workflows/ci.yml` crosses PHP 8.3/8.4 with
  `ZF_STORAGE=flat` and `sqlite`.
- `ext-pdo_sqlite` stays a `suggest`, not a `require`, so a flat installation needs nothing extra.

## Alternatives considered

- **Flat files only**, as originally recommended: the product's identity, and one less adapter pair to keep
  in step. Rejected by the owner in favour of covering the larger installation too.
- **SQLite for the item cache only**, keeping OPML as the subscription store. It leaves cache and
  subscriptions in different worlds and gives migration no clean meaning.
