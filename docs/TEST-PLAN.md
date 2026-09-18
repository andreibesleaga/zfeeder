# Test plan

What is tested, how it is tested, why the golden method is the one that matters,
and what is not tested.

Requirement identifiers referenced here are defined in [SRS.md](SRS.md).

## Contents

- [The suites](#the-suites)
- [Golden tests](#golden-tests)
- [Determinism rules](#determinism-rules)
- [Quality gates](#quality-gates)
- [Known gaps](#known-gaps)

## The suites

`phpunit.xml.dist` registers five PHPUnit suites. The browser suite is separate
and runs under Playwright.

| Suite | Directory | Covers | Run it with |
|---|---|---|---|
| unit | `tests/Unit/` | Configuration loading and writing, OPML reading and writing, the address guard's table, the fetcher against a mock transport, the parsers, the sanitiser corpus, the template engine, the token and filter passes, the truncator, both storage backends through one contract case, the migrator, the store factory, the 1.6 cache locator | `vendor/bin/phpunit --testsuite unit` |
| integration | `tests/Integration/` | Two groups. `Integration/Admin/`: the panel from route name to response, with the real router, the real dispatcher, real Twig templates, an array session, a frozen clock and a scratch data directory — sign-in, screen access, CSRF, demo mode, add feed, subscriptions, config, import. `Integration/Http/`: the demonstration site, the template gallery, `/embed`, `/api/feeds`, `/api/opml`, `/refresh` and `/healthz` | `vendor/bin/phpunit --testsuite integration` |
| golden | `tests/Golden/` | Byte equality of the classic template set against output recorded from zFeeder 1.6 | `vendor/bin/phpunit --testsuite golden` |
| security | `tests/Security/` | One class per control S1–S18 of `project/PLAN.md` B6 — `S01PasswordHashingTest` through `S18SecretsTest` — on top of `tests/Security/SecurityTestCase.php`. Several classes prove the control by first showing the undefended behaviour: `S09XmlTest::testTheNaiveParserReallyDoesLeakALocalFile` before `testTheFeedParserRefusesTheXxePayloadAndReadsNoLocalFile` | `vendor/bin/phpunit --testsuite security` |
| cli | `tests/Cli/` | Eleven of the twelve `bin/zfeeder` commands through `CommandTester`, with the application assembled the way the binary assembles it. `seed` has no class here — see [Known gaps](#known-gaps) | `vendor/bin/phpunit --testsuite cli` |
| e2e | `tests/e2e/specs/` | `public.spec.js`, `templates.spec.js` and `admin.spec.js`: the demonstration site, the template gallery and the administration panel in a real browser, with axe, a skip-link check, keyboard walkthroughs and horizontal-overflow checks | `./tools/run-e2e.sh` |

Everything at once:

```bash
vendor/bin/phpunit
composer check          # cs + stan + deptrac + phpunit
```

### Counts

Counts change whenever a test is added. Get the current figures with:

```bash
vendor/bin/phpunit --list-tests | wc -l
for s in unit integration golden security cli; do
  printf '%s: ' "$s"
  vendor/bin/phpunit --testsuite "$s" --list-tests | grep -c ' - '
done
```

The figures below are from a full local run on 18 September 2026, PHP 8.3, flat
backend. `vendor/bin/phpunit` reported **1,418 tests, 10,643 assertions, all
passing**, with **90.62 % line coverage of `src/` (4,896 of 5,403 lines)**;
the same run reported 72.80 % of methods (447 of 614) and 28.89 % of classes
(26 of 90), the class figure being low because it counts only classes every
line of which is executed.

| Suite | Tests | Assertions |
|---|---|---|
| unit | 624 | 1,966 |
| integration | 179 | 1,576 |
| golden | 51 | 263 |
| security | 484 | 6,478 |
| cli | 80 | 360 |
| **total** | **1,418** | **10,643** |

The largest classes are `Security\S05SanitizerTest` (87),
`Security\S07PathTraversalTest` (86), `Unit\Parse\FeedParserTest` (64),
`Unit\Fetch\UrlGuardTest` (62), `Security\S06UrlGuardTest` (62),
`Golden\ClassicTemplateGoldenTest` (51) and `Security\S04CsrfTest` (46). Many of
their tests are data-provider cases: `UrlGuardTest::testVerdict` alone is 55
address and scheme cases, one per row of the guard's tables, which is why the
class reports 62 tests.

The security suite carries about a third of the tests and well over half the
assertions. That is deliberate: most of its classes assert the whole shape of a
response — every header, every field of a JSON body — rather than one property.

**The assertion total is not a constant.** `Security\S18SecretsTest` walks the
working tree and asserts three properties per readable text file, so an untracked
build artefact, a coverage report or a new document changes the number. The test
count is stable; the assertion count is a reading of one checkout.

### Fixtures

| Directory | Contents |
|---|---|
| `tests/fixtures/feeds-2004/` | `rss091-oldnews.xml`, `rss092-weblog.xml`, `rss20-zfeeder.xml`, `rdf10-devchannel.xml` |
| `tests/fixtures/feeds-2026/` | `atom10-modern.xml`, `rss20-content-encoded.xml`, `rss20-science.xml`, `jsonfeed11-notes.json` |
| `tests/fixtures/opml/` | `goldenA.opml`, `goldenB.opml`, `goldenC.opml` — the exact files the golden recorder gave to 1.6 |
| `tests/fixtures/html/` | `two-feeds.html`, `base-href.html`, `scheme-relative.html`, `no-feeds.html` — autodiscovery cases |
| `tests/fixtures/payloads/` | `xxe.xml`, `billion-laughs.xml`, `xss-corpus.json` |
| `tests/fixtures/goldens/` | 49 recorded HTML files |
| `tests/fixtures/legacy-1.6/` | The minimal 2004 corpus: the eleven original `newsfeeds/categories/*.opml` files, 1.6's `templates/` directory, and its `config.php`, read as text and never executed |

`tests/bootstrap.php` sets the timezone to UTC and provides `zf_fixture()` and
`zf_fixture_contents()`, so a fixture is always addressed by its relative path.

### The browser suite

`tools/run-e2e.sh` starts a server and runs Playwright against it. By default it
builds `deploy/Dockerfile` and runs the container; `ZF_MODE=builtin` uses
`php -S` instead, which is faster locally; `ZF_BASE_URL` plus
`ZF_BASE_URL_EXTERNAL=1` points the suite at an already deployed instance. Either
way the script writes a scratch data directory, invents a random administrator
password and hashes it with Argon2id, pre-seeds the cache through
`tools/seed-e2e-cache.php`, and waits for `/healthz` before starting. The
password reaches the suite as `ZF_E2E_PASSWORD`, so no credential is written
down in the repository and the tests must be started through this script rather
than by calling Playwright directly.

`tests/e2e/playwright.config.js` defines three projects, and every test runs in
all three: `desktop` (Desktop Chrome, 1280×900), `mobile` (Pixel 7) and `dark`
(Desktop Chrome, 1280×900, `colorScheme: 'dark'`).

| Spec | What it asserts |
|---|---|
| `public.spec.js` | The front page has an `h1` naming zFeeder and links to the embed and admin pages, and passes axe. The first Tab focuses "Skip to content" and Enter reveals `#main`. Each of the six demonstrations (`one-line`, `css`, `multiple`, `positions`, `categories`, `aggregator`) answers 200, shows a fixture headline, and passes axe in the `modern` set; the same six are loaded in the `classic` set and checked for rendering only. An unknown demonstration is a 404 page, not a crash. Two traversal probes — `/demos/template/classic/..%2F..%2Fconfig` and `/api/opml/..%2F..%2Fetc%2Fpasswd` — answer 4xx and never contain `root:`. `/embed` returns a fragment with no `<!doctype`, carries no `Access-Control-Allow-Origin` for an origin that is not configured, and `/api/feeds` returns JSON with `category` and a `channels` array. `/healthz` reports `status: ok`, a `2.` version and a known backend; `/refresh` without the key is 403. The front page carries `default-src 'self'`, `nosniff`, a referrer policy and a permissions policy, and does not scroll sideways at 320, 768, 1280 or 1920 px. |
| `templates.spec.js` | All sixteen modern templates answer 200, render a non-empty `.output`, and pass axe. `modern/cards` has no horizontal overflow at 320, 768, 1280 and 1920 px. The modern output contains an `<article>` and zero `<font>` elements, and `modern/list` emits `time[datetime]` that parses as a date. All thirteen classic templates in its `CLASSIC` list render; `classic/bluelogos` is asserted to still contain `<font`, and every `/images/` request it makes is asserted to return below 400. In forced dark mode `modern/cards` has a non-white body and passes axe. |
| `admin.spec.js` | The sign-in form is labelled and passes axe. An anonymous request to `/admin/subscriptions` redirects to `/admin/login`. A wrong password is refused without distinguishing an unknown user from a bad password. Each of the six screens — main, add new, subscriptions, config, import, updates — answers 200, has an `h1`, passes axe and has no horizontal overflow. `[aria-current="page"]` appears exactly once. Every non-hidden form control on the config screen has a label, `aria-label` or `aria-labelledby`. At least one config field is shown locked, because the e2e server sets it in the environment. The panel carries zero inline `<script>` elements, which is what the nonce policy requires. Signing out ends the session. A keyboard-only walk of `/admin` reaches more than three controls and hits the skip link first. |

The accessibility bar is zero axe violations with the tags `wcag2a`, `wcag2aa`,
`wcag21a`, `wcag21aa` and `wcag22aa`, and there is no allow-list of accepted
findings. The classic set is deliberately outside that bar;
[ACCESSIBILITY.md](ACCESSIBILITY.md) says why.

## Golden tests

### What was recorded, and how

`tools/record-goldens.sh` is the recorder. It does not run in CI and is not
needed to run the tests; it exists so the corpus can be regenerated and so the
method is checkable. What it does:

1. Downloads `zfeeder-1.6.zip` — the untouched 2004 tree — from
   <https://github.com/andreibesleaga/old-projects> and unpacks it into a temporary
   directory.
2. Deletes the shipped OPML categories and writes three of its own —
   `goldenA`, `goldenB`, `goldenC` — each with two subscriptions whose
   `refreshTime` is 999999.
3. Copies six fixture feeds into 1.6's cache directory under the exact file
   names `url2file()` would have produced, and runs
   `touch -t 203001010000` on them. With a far-future modification time
   `timeExpired()` is always false, so **1.6 never opens a socket**: the recorded
   output depends only on files in this repository.
4. Writes a 1.6 `config.php` with `ZF_URL=http://zf.test/newsfeeds/`, so
   `{scripturl}` is a constant.
5. Starts `php:5.6-apache` with `display_errors = Off` and `allow_url_fopen =
   Off`, serving a one-line harness page, `golden.php`, that does nothing but
   `include("newsfeeds/zfeeder.php")`. `$_SERVER['PHP_SELF']` is therefore
   `/golden.php`, which is what `{moreurl}` and `{hideurl}` are built from.
6. Requests every one of the fourteen classic templates against each of the three
   categories, plus seven option variants on `simplegray`: `zf_link=off`,
   `zfposition=p2`, `zfmore=0`, and the four `ZF_CHANLOCATION` /
   `ZF_CHANONEBAR` combinations.
7. Copies the three OPML files it used into `tests/fixtures/opml/`, so the test
   replays identical input.

The result is 49 files in `tests/fixtures/goldens/`, named
`<template>.<category>[.<variant>].html`.

### What the test asserts

`tests/Golden/ClassicTemplateGoldenTest.php` rebuilds each case from its file
name, renders it through `Render\Renderer` with `legacyFidelity: true`, and
asserts:

```php
self::assertSame($expected, $actual, ...);
```

`assertSame` on the entire string. Not `assertEquals`, not a
whitespace-normalised comparison, not a DOM comparison. The recorded files carry
CRLF line endings, exactly as the 2004 templates do, and `.gitattributes` pins
both with `-text` so git cannot rewrite them.

Two further tests guard the harness itself:

- `testEveryGoldenUsesTheRecordedCacheTimestamp` re-derives the cache timestamp
  from the corpus and compares it with `FixtureSubscriptions::CACHE_FETCHED_AT`,
  so the hard-coded constant cannot drift away from the recorded files.
- `testEveryClassicTemplateIsCovered` asserts that `TemplateLocator::available()`
  lists fourteen classic templates and names each one, so adding a template
  without recording a golden fails.

### Why this is stronger than a hand-written expectation

A hand-written expectation encodes what the author believed the old program did.
A recording encodes what it actually did. The difference is not academic — the
classic renderer reproduces several 1.6 behaviours that nobody would write down
on purpose:

- The opening section marker is printed and the closing one is not, because
  `splitTemplate()` returned `substr($html, $start, $end - $start)`.
- Whitespace that indents a closing marker stays at the end of that chunk, while
  whitespace between a closing marker and the next opening marker is dropped
  altogether, so a template's indentation does not survive into the output as
  written. (The name and the comment of
  `Unit\Render\TemplateEngineTest::testWhitespaceBeforeTheNextMarkerBelongsToThePreviousChunk`
  say the opposite of what its own assertions check; the assertions are right.)
- In one-bar mode the channel chunk is substituted with the *feed* index, so
  `{title}` in a channel bar silently shows whichever item happens to sit at that
  offset — or nothing, when the feed is shorter.
- With the per-item channel bar, `{moreurl}` is never substituted and is printed
  literally, and `{hideurl}` is built from `"p-1,"`, which matches nothing.
- `showedItems` stops after N items because the break test runs *after* the item
  is emitted (`$i + 2 > $showedItems`).
- PHP 5.6's `htmlspecialchars()` defaulted to `ENT_COMPAT`, so single quotes in a
  link survive; PHP 8 defaults to `ENT_QUOTES`, and legacy mode pins the 2004
  flags.

Every one of those is in the corpus. None of them would have been in a
hand-written fixture, and each is exactly the kind of detail somebody's 2004
template depends on.

### The mutation check

The corpus is only worth something if it fails when the renderer changes. Change
one character in a classic template and the suite fails:

```bash
cd /path/to/zfeeder
cp templates/classic/simplegray.html /tmp/simplegray.bak
sed -i '0,/<td/s//<td /' templates/classic/simplegray.html    # one extra space
vendor/bin/phpunit --testsuite golden
# Tests: 51, Assertions: 263, Failures: 9 - each naming the golden file that no longer matches
cp /tmp/simplegray.bak templates/classic/simplegray.html
vendor/bin/phpunit --testsuite golden                          # green again
```

The same holds for the renderer: removing the `ENT_COMPAT` branch in
`Renderer::renderChunk()`, or "tidying" the section markers out of
`TemplateEngine::find()`, turns the golden suite red.

## Determinism rules

No test may depend on the network, on the wall clock, on the machine's timezone,
or on state left by another test. The rules and the mechanisms:

| Rule | Mechanism |
|---|---|
| No network | `Symfony\Component\HttpClient\MockHttpClient` in `Unit\Fetch\FeedFetcherTest`, `tests/Cli/CliTestCase.php` and `tests/Integration/Admin/AdminTestCase.php`. `UrlGuard` takes its resolver as a constructor parameter, so DNS answers are described in the test rather than looked up |
| No wall clock | `Kernel`, `FeedFetcher`, `SessionAuth`, `RateLimiter` and `Renderer` all take a clock or a fixed "now". `CliTestCase::NOW` and `AdminTestCase::NOW` are both `2026-09-18T12:00:00+00:00` |
| No shared filesystem state | `tests/Support/StorageTempDirectory.php` creates a fresh directory under the system temporary directory per test and removes it afterwards. No test touches the repository's `data/` directory |
| No PHP session | `Admin\Auth\ArraySession` implements `SessionInterface` over an array, so session regeneration can be asserted without a subprocess |
| Fixed timezone | `tests/bootstrap.php` calls `date_default_timezone_set('UTC')`; `phpunit.xml.dist` sets `date.timezone` to UTC as well |
| Fixed inputs | Every feed, OPML file, HTML page and payload is a file under `tests/fixtures/`. The golden corpus was recorded once from a container and is committed |
| Fixed environment | `phpunit.xml.dist` sets `ZF_ENV=testing`; `Config::forTesting()` builds a configuration without reading the real environment |
| Deterministic browser content | `tools/run-e2e.sh` runs `php tools/seed-e2e-cache.php` to pre-seed the cache from the fixtures and starts the server with `ZF_REFRESH_MODE=offline`, so the browser suite never depends on a live feed or on today's headlines |

PHPUnit is configured with `failOnRisky="true"` and `failOnWarning="true"`, and
`beStrictAboutOutputDuringTests="true"`, so a test that prints, that asserts
nothing, or that triggers a warning fails rather than passing quietly.

There is one deliberate exception to "no shared filesystem state".
`Security\S18SecretsTest` and `Security\S14ForbiddenConstructsTest` read the
repository itself, because that is what they are for: the question is whether a
secret or a banned construct is committed. They are deterministic in outcome —
neither passes or fails because of another test — but `S18` reports one assertion
per file it reads, so the suite's assertion total follows the contents of the
checkout.

## Quality gates

These are the jobs in `.github/workflows/ci.yml`, in the order the file declares
them. Each command below is the one the workflow runs.

### Job: Lint and static analysis

| Gate | Command | Threshold |
|---|---|---|
| Manifest | `composer validate --strict --no-check-publish` | clean |
| Coding standard | `vendor/bin/php-cs-fixer fix --dry-run --diff --show-progress=none` | no diff |
| Static analysis | `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | level 8 with `phpstan-strict-rules`, no errors |
| Architecture boundaries | `vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress` | no violation of the seven-layer ruleset |
| Forbidden constructs | `./tools/check-forbidden.sh` | no match for `eval`, `create_function`, shell execution, `unserialize`, `extract`, dynamic `include`, remote `file_get_contents`/`readfile`/`fopen`, `md5`/`sha1` near a password, string `assert()`, or error suppression outside the allowed filesystem calls |

### Job: PHP × storage matrix

Runs on PHP 8.3 and 8.4, with `ZF_STORAGE` set to `flat` and to `sqlite` — four
legs.

| Gate | Command | Threshold |
|---|---|---|
| Tests | `vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml` | all green, on every leg |
| Coverage | `php tools/coverage-gate.php coverage.xml 90` | at least **90 %** line coverage of `src/`. Enforced on the PHP 8.3 / flat leg only, because the other three produce the same figure |

### Job: Dependency and secret scan

| Gate | Command | Threshold |
|---|---|---|
| Dependencies | `composer audit --no-interaction` | no known advisory |
| Secrets | `gitleaks/gitleaks-action@v2`, with `fetch-depth: 0` | nothing found |

### Job: Container image

| Gate | Command | Threshold |
|---|---|---|
| Build | `docker/build-push-action@v6` on `deploy/Dockerfile` | builds |
| Smoke test | `./tools/smoke-image.sh zfeeder:ci` | passes |

### Job: Browser tests and accessibility

Depends on the image job.

| Gate | Command | Threshold |
|---|---|---|
| Browser suite | `./tools/run-e2e.sh` | all Playwright projects green: `desktop` (1280×900), `mobile` (Pixel 7) and `dark` (1280×900, `colorScheme: dark`) |
| Accessibility | `expectNoAccessibilityViolations()` in `tests/e2e/helpers.js` | **zero** axe violations, with tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa` |
| Layout | `expectNoHorizontalOverflow()` | no horizontal document overflow at 320, 768, 1280 or 1920 pixels |

Screenshots and `test-results/` are uploaded as artefacts on every run, pass or
fail.

`.github/workflows/codeql.yml` runs CodeQL over the tree separately.

## Known gaps

Stated plainly, because a test plan that only lists what is covered is a
marketing document.

1. **`bin/zfeeder seed` has no test.** `tests/Cli/` has one class per command
   for the other eleven; `SeedCommand` is exercised only by running the
   container, where `deploy/entrypoint.sh` calls it on every boot. Its
   `--dry-run`, `--force` and `--from` branches and its "leave an existing
   category alone" rule are unproven by the suite.

2. **The security tests were not written against a weakened build.**
   `project/PLAN.md` B14 asks for each S-class to be shown failing with its
   control removed, so that a test cannot pass vacuously. Several classes do
   this within the test itself — `S09XmlTest::testTheNaiveParserReallyDoesLeakALocalFile`,
   `S05SanitizerTest::testWithoutTheSanitiserEveryOneOfThosePayloadsWouldReachThePage`,
   `S02LoginRateLimitTest::testWithoutTheLimitTheSameAttackJustContinues`,
   `S06UrlGuardTest::testWithTheGuardSwitchedOffTheSameLoopbackFetchSucceeds`,
   `S18SecretsTest::testTheScannerRecognisesASecretWhenItSeesOne` — but there was
   no separate build with the controls compiled out, so the remaining classes
   rest on review rather than on demonstration.

3. **Coverage clears the gate by about thirty lines.** The run of 18 September
   2026 reported 90.62 % of lines in `src/` (4,896 of 5,403) against a floor of
   90 %, which needs 4,863 covered lines: 33 lines of slack. Adding a few dozen
   uncovered lines fails the build. Get the current figure with
   `vendor/bin/phpunit --coverage-text` and
   `php tools/coverage-gate.php coverage.xml 90`.

4. **Three of the four CI legs are not coverage-gated.** The gate runs on the
   PHP 8.3 / flat leg only, on the assumption that the other three produce the
   same figure. That assumption is not checked, and a local run with
   `ZF_STORAGE=sqlite` and the flat default both report 1,418 tests and 10,643
   assertions, so on this machine they agree — but nothing asserts that they will.

5. **`templates/classic/css.html` has no browser test.** The `CLASSIC` list in
   `tests/e2e/specs/templates.spec.js` names thirteen templates and the directory
   holds fourteen. It is covered by the golden suite, which is what matters for
   that set, but no browser ever loads it in CI.

6. **The golden corpus is one generation of one program.** It proves that 2.0
   reproduces zFeeder 1.6 on `php:5.6-apache` with six specific feeds and three
   specific subscription lists. It does not prove anything about 1.5 or earlier,
   about a template nobody shipped, or about a feed shape that is not in
   `tests/fixtures/`.

7. **The modern template set has no golden equivalent**, by design: there is
   nothing to be byte-compatible with. Its correctness rests on
   `Unit\Render\RendererTest`, `Unit\Render\FiltersTest` and the browser suite.

8. **Concurrency is not tested.** `Storage\AtomicFile` and the refresh lock are
   written for concurrent access and are exercised only sequentially. There is no
   test that runs two writers at once.

9. **No performance test.** The size and timeout ceilings are tested; how long a
   refresh of a large subscription list takes is not measured anywhere. See the
   residual risk of the same name in [THREAT-MODEL.md](THREAT-MODEL.md).

10. **No Lighthouse run and no 200 % zoom test.** `project/PLAN.md` B8 and B9
    ask for both. Nothing in `tools/` or in the workflows runs Lighthouse, and
    zoom is approximated rather than tested: the overflow check at 640 CSS pixels
    is the same measurement as 200 % zoom at 1280, but no test sets a zoom level.

11. **CodeQL runs over JavaScript only.** `.github/workflows/codeql.yml` sets
    `languages: javascript-typescript`, and this is a PHP project. PHPStan at level 8 with
    strict rules, deptrac and `tools/check-forbidden.sh` are what actually scan
    the PHP.
