# Contributing

Thanks for looking. This is a small project with a clear shape; the notes below are mostly
about keeping that shape.

## Ground rules

- **The classic templates are frozen.** `templates/classic/*.html` reproduce the 2004 output
  byte for byte, and `tests/Golden/` proves it against output captured from the original
  script running on PHP 5.6. If a golden test fails, the change is wrong, not the test.
  New ideas go into `templates/modern/`.
- **Template tokens are never removed.** Add, never take away. See [VERSIONING.md](VERSIONING.md).
- **No new runtime dependencies** without a good reason stated in the pull request. The
  dependency list is short on purpose, and every addition has to be carried by whoever
  deploys this on shared hosting.
- **Security changes need a test that fails without the fix.** See `tests/Security/`.

## Getting set up

```
git clone https://github.com/andreibesleaga/zfeeder.git
cd zfeeder
composer install
php -S 127.0.0.1:8080 -t public          # http://127.0.0.1:8080
bin/zfeeder hash-password                 # prompts twice, no echo; or pass the
                                          # password as an argument, which warns
                                          # that it lands in your shell history
```

Tests need no network and no services:

```
composer test          # everything
composer stan          # static analysis at level 8
composer cs            # coding standard, dry run
composer check         # all of the above, the same gates CI runs
```

Run the browser tests when you touch templates, CSS or the admin panel:

```
tools/run-e2e.sh
```

## Pull requests

- One topic per pull request.
- [Conventional Commits](https://www.conventionalcommits.org/) for commit subjects:
  `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `build:`, `ci:`, `chore:`.
  A `!` or a `BREAKING CHANGE:` footer marks anything that breaks the interface.
- Sign off your commits (`git commit -s`): this project uses the
  [Developer Certificate of Origin](https://developercertificate.org/).
- CI must be green: coding standard, static analysis, architecture boundaries, the forbidden
  constructs scan, the full test suite on PHP 8.3 and 8.4 against both storage backends, the
  container build, the browser tests and the accessibility checks.
- Update the documentation in the same pull request. `docs/CONFIGURATION.md` is generated —
  run `bin/zfeeder docs:config` rather than editing it.

## Code style

PSR-12, enforced. `declare(strict_types=1)` in every file. Constructor property promotion,
readonly and final by default. Type every parameter and return, including array shapes in
docblocks — static analysis runs at level 8 with the strict rules and will not let you skip it.

Comments explain **why**, never what. The most valuable comments in this codebase are the
ones marking a deliberate reproduction of a 2004 quirk; if you touch one of those, keep the
explanation with it.

## Architecture

Layers are enforced by `deptrac.yaml`, so the rule is checkable rather than aspirational:

```
Shared          Version, Exception\*                      depends on nothing
Domain          Subscription\*, Parse\Model\*             Shared
Config          Config\*                                  Shared
Storage         Storage\*                                 Shared, Domain, Config
Application     Render\*, Fetch\*, Parse\* (not Model)     + Storage
Infrastructure  Http\*, Admin\*, Cli\*, Embed\*, Legacy\*,  everything, incl. Composition
                Log\*
Composition     Kernel, FeedService                       everything
```

Seven layers, not four. `docs/ARCHITECTURE.md` explains why `Config` is its own
layer and why `Parse\Model` sits in Domain while the rest of `Parse` does not.

If you need a new layer edge, change `deptrac.yaml` in the same pull request and say why.

## What is out of scope for 2.x

Read and unread state, full-text search, multiple user accounts, a database server, a
JavaScript framework. These are listed in [docs/ROADMAP.md](docs/ROADMAP.md) with the
reasoning. A pull request that adds one of them will be declined, however good it is.
