# Getting help

- **A question about using zFeeder** — open a [discussion](https://github.com/andreibesleaga/zfeeder/discussions).
- **Something is broken** — open an [issue](https://github.com/andreibesleaga/zfeeder/issues)
  with your PHP version, the storage backend, the relevant part of `bin/zfeeder check-config`
  (it redacts secrets) and what you expected to happen.
- **A security problem** — do not open an issue. Follow [SECURITY.md](SECURITY.md).

## Before you open an issue

Run the built-in check; it catches most configuration problems on its own:

```
bin/zfeeder check-config
```

If a feed is not appearing, `bin/zfeeder refresh --category=<name>` prints what happened to
each subscription, one line each, and is usually enough to see the cause.

## Documentation

| If you want to | Read |
|---|---|
| install it | [README.md](README.md) |
| deploy it somewhere | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| configure it | [docs/CONFIGURATION.md](docs/CONFIGURATION.md) |
| write a template | [docs/TEMPLATES.md](docs/TEMPLATES.md) |
| move from the 2004 version | [docs/UPGRADING-FROM-1.6.md](docs/UPGRADING-FROM-1.6.md) |
| operate it | [docs/RUNBOOK.md](docs/RUNBOOK.md) |
| understand how it is built | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| know where it came from | [docs/HISTORY.md](docs/HISTORY.md) |

This is a single-maintainer project. Answers are best-effort and unpaid.
