# Build log — zFeeder 2.0

What was built, in what order, and what proved it. Written as the work happened, so it
records the defects found along the way as well as the result.

Finished 2026-09-18. Every gate below was run on the finished tree; the output is real.

## Result

| | |
|---|---|
| Version | 2.0.0 |
| Live demo | <https://zfeeder.up.railway.app> (Railway, SQLite backend, read-only admin) |
| Tests | 1,426 PHP, 258 browser, all passing |
| Coverage | 91.6 % of lines in `src/` |
| Static analysis | PHPStan level 8 with strict rules, no errors |
| Architecture | deptrac, 0 boundary violations |
| Dependencies | `composer audit`, no advisories |
| Accessibility | axe-core, 0 violations on the modern set and both admin skins |
| Compatibility | 49 golden files byte-identical to output captured from the 2004 original |

## Gate output

```
vendor/bin/phpunit                    OK (1426 tests, ~9.7k assertions)
                                      Lines: 91.58% (4948/5403)
php tools/coverage-gate.php           coverage: 91.58% of 5403 statements (floor 90%)
vendor/bin/phpstan analyse            [OK] No errors
vendor/bin/deptrac analyse            Violations 0
./tools/check-forbidden.sh            check-forbidden: clean
vendor/bin/php-cs-fixer fix --dry-run Found 0 of 191 files that can be fixed
composer audit                        No security vulnerability advisories found
bin/zfeeder docs:config --check       [OK] docs/CONFIGURATION.md is up to date
./tools/smoke-image.sh zfeeder:local  smoke test passed
./tools/run-e2e.sh                    258 passed
```

Per suite: unit 624, integration 179, golden 51, security 484, cli 88.

## Phases

| Phase | What was built | What proved it |
|---|---|---|
| P0 | Fixtures and goldens: deterministic 2004-era and 2026-era feeds, then 49 HTML files captured from the original 1.6 running in a `php:5.6-apache` container with those feeds pre-placed in its cache | `tools/record-goldens.sh` recorded 49 files; a deliberate one-character change to a template later made them fail, so the corpus is load-bearing |
| P1 | Scaffold: Composer, PSR-4, PHPUnit, PHPStan level 8, php-cs-fixer, deptrac, CI workflows, Dockerfile | `composer validate`, smoke test, `docker build` |
| P2 | Config and schema, both storage backends, migrator, OPML reader and writer, address guard, fetcher, parser, CLI | One abstract contract test case run against both backends; 232 tests |
| P3 | Template engine reproducing 1.6 semantics, sanitiser, truncator, renderer, embed surface, 14 classic templates | All 49 goldens byte-identical |
| P4 | Modern design system, 16 responsive templates, demo pages | Playwright at 320/768/1280/1920, light and dark, no overflow |
| P5 | Admin panel: auth, CSRF, rate limiting, 7 screens in two skins, htmx | 170 tests, axe clean on 24 screen/skin/scheme combinations |
| P6 | Security suite S01–S18, one class per requirement | 484 tests; four real defects found (below) |
| P7 | Browser suite, accessibility gates, coverage floor | 258 browser tests across desktop, mobile and dark |
| P8 | Documentation, nine architecture diagrams, container image, deploy recipes, Railway deployment | Live and verified |
| P9 | Verification and adversarial review | `docs/VERIFICATION-REPORT.md` |

## Defects found and fixed during the build

The point of the verification phases was to find these. Each has a test that fails
without the fix.

| # | Found by | Defect | Fix |
|---|---|---|---|
| 1 | Template filter check | Filters on 1.6 tokens (`{description\|trunc:200}`) were never expanded; modern templates printed the token text | `Renderer::filterableLegacyValues()` resolves them for the filter pass |
| 2 | Same | Values escaped twice, producing `&amp;#039;` | Each value escaped exactly once, at substitution |
| 3 | Hostile-feed test | A bare `{chantitle}` rendered a feed's markup unescaped — the 2004 hole, still open | Feed strings escaped outside legacy-fidelity mode |
| 4 | Hostile-feed test | `javascript:` URLs survived escaping and stayed clickable | `Renderer::safeUrl()` scheme allow-list |
| 5 | Security suite | The channel logo's own URLs skipped that check | `chanLogo()` now uses `safeUrl()` |
| 6 | Security suite | `Responder::json()` emitted a literal `</script>` | `JSON_HEX_TAG` and friends |
| 7 | Security suite | Refused requests were never logged | `ErrorHandler` logs 4xx at notice |
| 8 | Security suite | A password hash was committed in a tool script | Generated at run time |
| 9 | Documentation review | `ConfigLoader` ignored `ZF_DATA_DIR` when locating `config.json`, so admin saves were lost on every container redeploy | Config file resolves inside the data directory |
| 10 | Documentation review | `ZF_DEMO_ENABLED=false` also took `/healthz` down, so a paused instance failed its platform health check | Health endpoint stays up |
| 11 | Browser suite | Making the channel logo decorative left its link with no accessible name | `aria-label` on the link |
| 12 | Browser suite | Item title links were distinguished by colour alone | Underlined by default |
| 13 | Browser suite | A logo link nested inside a `<summary>` | Moved out of the disclosure |
| 14 | Browser suite | Scrollable regions were not keyboard-reachable | `tabindex="0"` and a focus ring |
| 15 | **Live deployment** | Setting `Accept-Encoding` by hand stopped the HTTP client decompressing, so every feed arrived gzipped and none parsed | Header left to the client; regression test added |
| 16 | Live deployment | A damaged cache entry left a feed blank until its interval expired | Discarded and refetched once |
| 17 | Live deployment | The container seeded OPML files, which the SQLite backend cannot read | `bin/zfeeder seed` writes through the store interface |

| 18 | Documentation review | `base_url` help and `check-config` both said an empty value is "detected from the request"; the renderer returns the site root | Wording corrected in `Config\Schema`, documentation regenerated |
| 19 | Documentation review | `deploy/README.md` pointed at a `railway.toml` that no longer exists | Replaced with how the service is really configured |
| 20 | Documentation review | `S18SecretsTest` walked the working tree, so its assertion count changed with whatever files were lying around — a breach of the project's own determinism rule | Reads git's tracked list, with a filesystem fallback for a checkout without git |
| 21 | Documentation review | `bin/zfeeder seed` was the only command with no test, and the container runs it on every boot | `tests/Cli/SeedCommandTest.php`, including both backends and repeat runs |
| 22 | v3 research | `SecurityHeaders::forEmbed()` set `Vary: Origin` only when an origin matched, so a shared cache could serve the no-CORS variant to a permitted site | Varies on every embed and API response |

Numbers 15 to 17 were only reachable by actually deploying. That is the argument for
the live demo being part of the build rather than a follow-up.

## Platform lessons, now in the Dockerfile and documented

- The image must listen on `$PORT`; the entrypoint rewrites Apache's `ports.conf`.
- Railway rejects a Docker `VOLUME` instruction and wants its own volume mounted.
- The entrypoint needs root to take ownership of a freshly mounted volume, then Apache
  drops to `www-data` for every worker.
- Railway selects a Dockerfile through `RAILWAY_DOCKERFILE_PATH`; its `DOCKERFILE`
  builder enum no longer exists.
- `a2dismod -f` can leave two Apache MPMs enabled, which Apache refuses to start with.

## Not done

No load testing, no penetration test, no multi-architecture image test, and no
shared-hosting installation actually performed — the archive is built and its layout
checked, but it has not been uploaded to a real shared host. See
`docs/VERIFICATION-REPORT.md`.
