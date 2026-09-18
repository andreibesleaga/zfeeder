# Verification report

What was checked before 2.0.0, how it was checked, what the check produced, and
what was **not** checked.

The protocol is section B12 of `project/PLAN.md`. This document is the record
required by it. Every figure here comes from a command that was run; the
commands are given so any of it can be reproduced.

## Contents

- [When, and on what](#when-and-on-what)
- [Summary](#summary)
- [1. Architecture review](#1-architecture-review)
- [2. Security review](#2-security-review)
- [3. Accessibility review](#3-accessibility-review)
- [4. Compatibility review](#4-compatibility-review)
- [5. Portability review](#5-portability-review)
- [6. Documentation review](#6-documentation-review)
- [What was not verified](#what-was-not-verified)

## When, and on what

| | |
|---|---|
| Date of this pass | 18 September 2026 |
| PHP | 8.3.6 (cli), NTS, Linux |
| Storage backends exercised | `flat` and `sqlite` |
| Browser | Chromium through Playwright, run against the PHP development server (`ZF_MODE=builtin`) |
| Live instance | <https://zfeeder.up.railway.app>, Railway, `sqlite` backend |

CI runs the same gates on a four-leg matrix — PHP 8.3 and 8.4 × `flat` and
`sqlite` — plus the container build, the image smoke test and the browser suite.
The figures below are from a local run of the same commands.

## Summary

| Review | Command | Result |
|---|---|---|
| Layering | `vendor/bin/deptrac analyse` | **0 violations**, 534 allowed edges |
| Static analysis | `vendor/bin/phpstan analyse` (level 8, strict rules) | **No errors** |
| Coding standard | `vendor/bin/php-cs-fixer fix --dry-run --diff` | **0 of 190 files** need a change |
| Tests, flat | `vendor/bin/phpunit` | **1,418 passed**, 10,643 assertions |
| Tests, sqlite | `ZF_STORAGE=sqlite vendor/bin/phpunit` | **1,418 passed**, 10,643 assertions — the same as the flat leg on this machine |
| Coverage | `php tools/coverage-gate.php coverage.xml 90` | **90.62 %** of 5,403 lines, floor 90 %, exit 0 |
| Security suite | `vendor/bin/phpunit --testsuite security` | **484 passed**, 6,478 assertions (see the note on `S18SecretsTest` below) |
| Forbidden constructs | `./tools/check-forbidden.sh` | `check-forbidden: clean`, exit 0 |
| Dependency advisories | `composer audit` | **none** |
| Browser and accessibility | `ZF_MODE=builtin tools/run-e2e.sh` | **258 passed**, 0 axe violations |
| Goldens | `vendor/bin/phpunit --testsuite golden` | **51 passed**, 263 assertions, 49 files byte-identical |
| Generated docs | `php bin/zfeeder docs:config --check` | up to date, exit 0 |
| Live instance | `curl https://zfeeder.up.railway.app/healthz` | `{"status":"ok","version":"2.0.0","storage":"sqlite"}` |

## 1. Architecture review

### Layer boundaries

`deptrac.yaml` defines seven layers. The check is
`vendor/bin/deptrac analyse --config-file=deptrac.yaml`:

```
Violations           0
Skipped violations   0
Uncovered            464
Allowed              534
Warnings             0
Errors               0
```

No infrastructure class is reachable from Domain, and `Config` is a peer of
Domain rather than a dependency of it. `src/Embed/functions.php` is excluded by
path, because it declares global functions and a class-based collector has
nothing to see; the 464 "uncovered" entries are dependencies on PHP and vendor
classes, which the ruleset does not classify.

The layer list and the reasoning are in
[ARCHITECTURE.md](ARCHITECTURE.md#components).

### Static analysis and style

```
vendor/bin/phpstan analyse --memory-limit=1G    # [OK] No errors
vendor/bin/php-cs-fixer fix --dry-run --diff    # Found 0 of 190 files that can be fixed
```

PHPStan runs at level 8 with `phpstan/phpstan-strict-rules`. 190 PHP files are
in scope for the fixer.

### 12-factor spot-checks

| Property | How it is met | Checked by |
|---|---|---|
| Config in the environment | 44 options, each with a `ZF_*` variable; environment beats file beats default | `Unit\Config\ConfigLoaderTest` (40 tests) |
| Stateless processes | Nothing is kept in memory between requests; the session is the only per-user state and lives in the store | `Integration\Admin\*` |
| Logs to a stream | `ZF_LOG_PATH=php://stdout` is what every container recipe sets | `Integration\Http\FeedServiceTest::testTheLoggerWritesWhereConfigurationSaysAndRespectsTheLevel` |
| Disposability | The data directory is the only durable state; `bin/zfeeder seed` and `migrate --ensure-schema` make a fresh volume usable | `deploy/entrypoint.sh`, not covered by a test |
| Idempotent CLI | `seed` leaves existing categories alone; `migrate --ensure-schema` exits cleanly on the flat backend; `refresh` takes an exclusive lock and exits 0 when another run holds it | `Cli\RefreshCommandTest`, `Cli\MigrateCommandTest`. **`seed` has no test** |

### No global state

`Zfeeder\Kernel` builds the object graph; there is no service locator and no
static mutable state outside `src/Embed/functions.php`, which is the 1.6
compatibility shim and holds one lazily built `Kernel`.

## 2. Security review

### The S01–S18 suite

`tests/Security/` holds one class per control of `project/PLAN.md` B6, on top of
`tests/Security/SecurityTestCase.php`:

```
vendor/bin/phpunit --testsuite security
OK (484 tests, 6478 assertions)
```

The control table in [THREAT-MODEL.md](THREAT-MODEL.md#controls-s1s18) names the
class and the methods for each control. The largest classes are
`S05SanitizerTest` (87), `S07PathTraversalTest` (86), `S06UrlGuardTest` (62) and
`S04CsrfTest` (46).

Several classes prove the control by first showing what happens without it, so a
passing assertion cannot be vacuous:
`S09XmlTest::testTheNaiveParserReallyDoesLeakALocalFile` before
`testTheFeedParserRefusesTheXxePayloadAndReadsNoLocalFile`;
`S05SanitizerTest::testWithoutTheSanitiserEveryOneOfThosePayloadsWouldReachThePage`;
`S02LoginRateLimitTest::testWithoutTheLimitTheSameAttackJustContinues`;
`S06UrlGuardTest::testWithTheGuardSwitchedOffTheSameLoopbackFetchSucceeds`;
`S18SecretsTest::testTheScannerRecognisesASecretWhenItSeesOne`.

One caveat about the assertion total. `S18SecretsTest` walks the whole working
tree and asserts three properties per readable text file, so the suite's
**assertion count moves with the number of files in the checkout** — an untracked
build artefact or a new document changes it. The test count does not. Treat 6,478
as a reading of one checkout, not a constant.

### Four defects found and closed

The review that produced the suite found four defects in 2.0 itself. All four
are fixed, and each has a test that fails without the fix. They are described in
full in
[THREAT-MODEL.md](THREAT-MODEL.md#four-defects-found-in-20-and-closed).

| # | Defect | Fix | Test |
|---|---|---|---|
| 1 | Channel strings from a feed (`{chantitle}`, `{chandesc}`, `{category}`, item `{title}` and `{pubdate}`) reached the page unescaped | Escaped exactly once, in `Renderer::present()` for the 1.6 pass and `Tokens::substitute()` for the filter pass; `Renderer::PRE_FORMED` and `Tokens::RAW_BY_DEFAULT` list what the renderer built itself and must not escape twice | `S05SanitizerTest::testHostileChannelMetadataIsEscapedBeforeItReachesThePage`, `testTheSelfBuiltTokensAreNotEscapedASecondTime` |
| 2 | Feed-supplied URLs were escaped but not scheme-checked; an escaped `javascript:` URL still runs | `Renderer::safeUrl()` on `{chanlink}`, `{feedurl}`, item `{link}` and both URLs in `{chanlogo}`: control characters and whitespace stripped, then `Renderer::SAFE_SCHEMES` (`http`, `https`, `mailto`) or a relative reference | `S05SanitizerTest::testEveryUnsafeSchemeIsRefusedInAFeedLink`, `testTheChannelLogoUrlsAreSchemeCheckedLikeEveryOtherFeedLink` |
| 3 | `Responder::json()` did not set the HEX flags, so a feed title containing `</script>` could terminate a script element in a caller that embedded the body | `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT` | `S12EmbedCorsTest::testTheJsonBodyCannotCloseAScriptElement` |
| 4 | `ErrorHandler` logged only 5xx, so probing left no trace, and a `TemplateException` was reported as a 500 | Below 500 is logged at notice level with the status and class; `TemplateException` maps to 404 | `S16ErrorHandlerTest::testARefusedRequestIsRecordedForTheOperator` |

`legacyFidelity` mode keeps the unescaped 2004 behaviour so the goldens can be
compared byte for byte. Nothing in `src/`, `public/` or `zfeeder.php` sets it;
only three test classes do.
`S05SanitizerTest::testTheLegacyFidelityModeIsTheOnlyWayBackToThe2004Behaviour`
pins that.

### Forbidden constructs

```
./tools/check-forbidden.sh
check-forbidden: clean        # exit 0
```

The script bans `eval`, `create_function`, shell execution, `unserialize`,
`extract`, dynamic includes, remote `file_get_contents`/`readfile`/`fopen`,
`md5`/`sha1` near anything named like a password, and blanket error suppression.
Two patterns that used to produce false positives — "shell execution" matching
`$pdo->exec(...)`, and an error-suppression allow-list that omitted
`file_get_contents` — were tightened, so the eleven matches an earlier pass
reported are gone. `S14ForbiddenConstructsTest` runs the script and separately
asserts that every rule still fires on the construct it bans, so a tightened
pattern cannot silently become a no-op.

### Dependencies and secrets

```
composer audit
No security vulnerability advisories found.
```

`S17DependencyAuditTest` runs `composer audit` inside the suite and checks the
CycloneDX SBOM `tools/sbom.php` produces against `composer.lock`.
`S18SecretsTest` scans the working tree and first proves the scanner recognises
a secret when it sees one. `gitleaks` with `fetch-depth: 0` and CodeQL run in
CI.

### Headers, verified against the live instance

```
curl -sI https://zfeeder.up.railway.app/          # public
curl -sI https://zfeeder.up.railway.app/admin     # panel
```

| Header | Public | Panel |
|---|---|---|
| `content-security-policy` | `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https: http:; frame-ancestors 'self'; base-uri 'none'; object-src 'none'` | the same shape with a per-request nonce on `script-src` and `style-src`, `frame-ancestors 'none'`, plus `font-src`, `connect-src` and `form-action 'self'` |
| `strict-transport-security` | `max-age=31536000; includeSubDomains` | same |
| `x-content-type-options` | `nosniff` | same |
| `referrer-policy` | `strict-origin-when-cross-origin` | same |
| `permissions-policy` | `geolocation=(), microphone=(), camera=(), payment=(), usb=()` | same |
| `cross-origin-opener-policy` | `same-origin` | same |

The nonce differs on every request; `S10SecurityHeadersTest::testTheNonceIsDifferentOnEveryRequestAndIsTheOneOnThePage`
asserts it is also the nonce on the page.

## 3. Accessibility review

```
ZF_MODE=builtin tools/run-e2e.sh
258 passed (1.5m)
```

258 tests across three specs and three Playwright projects — `desktop`
(1280×900), `mobile` (Pixel 7) and `dark` (1280×900, `colorScheme: 'dark'`):
78 from `public.spec.js`, 114 from `templates.spec.js`, 66 from
`admin.spec.js`.

**Zero axe violations.** The bar is the tags `wcag2a`, `wcag2aa`, `wcag21a`,
`wcag21aa` and `wcag22aa`, and there is no allow-list of accepted findings. axe
ran on the front page, the six demonstration pages, all sixteen modern
templates, the sign-in form and all six panel screens, in all three projects.

### The classic set is exempt, on purpose

`templates.spec.js` loads thirteen classic templates and asserts only that they
render. It also asserts that `classic/bluelogos` **still contains `<font`** —
the exemption is enforced from the other direction as well.

The classic set reproduces 2004 markup byte for byte: layout tables with no
`<th>`, no caption and no scope; `<font face="Verdana, Arial, Helvetica,
sans-serif" size="1">`; `bgcolor` attributes; fixed pixel widths. Counted by
reading the files, `bluelogos` and `greenlogos` have 2 tables and 5 `<font>`
elements each; `templates/modern/*.html` have zero of both. Making the classic
set accessible would mean changing its output, which is the one thing it exists
not to do — `tests/Golden/` asserts those bytes, and the compatibility promise
in [VERSIONING.md](../VERSIONING.md) rests on them. Every classic template has a
same-named counterpart in `templates/modern/`, and `modern` is the default.

The full assessment, including the WCAG 2.2 checklist criterion by criterion, is
in [ACCESSIBILITY.md](ACCESSIBILITY.md).

## 4. Compatibility review

### The goldens

```
vendor/bin/phpunit --testsuite golden
OK (51 tests, 263 assertions)
```

49 files in `tests/fixtures/goldens/` were recorded from the unmodified
`legacy/zfeeder-1.6/` tree running on `php:5.6-apache`, with the feed cache
timestamped far in the future so 1.6 never opened a socket. The test rebuilds
each case and compares with `assertSame` on the whole string — not
`assertEquals`, not whitespace-normalised, not a DOM comparison. CRLF line
endings and all. Two further tests guard the harness itself: the recorded cache
timestamp cannot drift from the constant, and every classic template must appear
in the corpus.

### The mutation check

The corpus is only worth something if it fails when the output changes. One
space added to one tag in one template:

```bash
cp templates/classic/simplegray.html /tmp/simplegray.bak
sed -i '0,/<td/s//<td /' templates/classic/simplegray.html
vendor/bin/phpunit --testsuite golden
# Tests: 51, Assertions: 263, Failures: 9
cp /tmp/simplegray.bak templates/classic/simplegray.html
vendor/bin/phpunit --testsuite golden
# OK (51 tests, 263 assertions)
```

Run on 18 September 2026 with exactly that result: nine golden files stopped
matching, and the file was restored byte-identically (verified by `sha256sum`).

### Everything else in the compatibility promise

| Claim | Proven by |
|---|---|
| Subscription files written by 1.x load without conversion | `Unit\Subscription\OpmlReaderTest` (36 tests), `Cli\ImportCommandTest` |
| A 2004 template loads unchanged | The golden corpus is fourteen 2004 templates, loaded by the 2.0 engine |
| `include 'zfeeder.php';` still works | `Integration\Http\EmbedEndpointsTest::testTheIncludeFunctionProducesTheSameHtmlAsTheEndpoint`, `testEmbedAcceptsBothTheModernAndThe2004ParameterNames`, `testAnEmbeddedBlockNeverBreaksThePageThatHostsIt` |
| The 1.6 `?zfrefresh=<key>` spelling still works | `PublicController::refreshKey()` reads `key` then `zfrefresh`. **No test names the 1.6 spelling**; `Integration\Http\EmbedEndpointsTest::testRefreshingWithTheKeyPrintsThe2004Report` covers the modern one only |
| A 2004 `newsfeeds/` directory can be imported | `Cli\LegacyImportCommandTest` (10 tests), against `legacy/zfeeder-1.6/` |

## 5. Portability review

| Target | Status |
|---|---|
| PHP development server | Verified in this pass: `tools/run-e2e.sh` in `ZF_MODE=builtin` started `php -S` and all 258 browser tests passed against it |
| Railway | Verified live: <https://zfeeder.up.railway.app> answers `/healthz` with `{"status":"ok","version":"2.0.0","storage":"sqlite"}`, over HTTP/2 with HSTS, and the header table above was taken from it |
| Docker, locally | **Not rebuilt in this pass.** `tools/run-e2e.sh` builds and runs the image in its default mode, and `tools/smoke-image.sh` asserts the endpoints and headers against a running container; CI runs both in the **Container image** and **Browser tests and accessibility** jobs. Neither was re-run here |
| Fly, Render, Kubernetes, a VPS | Recipes exist in `deploy/`; none was deployed |
| Shared hosting | **Never installed.** See [What was not verified](#what-was-not-verified) |

### What the platform forced, and where it is written down

The live deployment required five changes to the image, each of which applies to
Fly, Render and Cloud Run as much as to Railway. They are documented in
[DEPLOYMENT.md](DEPLOYMENT.md#docker) and
[DEPLOYMENT.md](DEPLOYMENT.md#railway):

1. Apache has to listen on `$PORT`. `deploy/entrypoint.sh` rewrites
   `/etc/apache2/ports.conf` and the default vhost at boot.
2. The Dockerfile must not declare `VOLUME`; Railway rejects the image. Storage
   is mounted at `/var/www/data` by the platform instead.
3. The entrypoint must be able to run as root, so it can take ownership of a
   freshly mounted volume before Apache drops to `www-data`. The image therefore
   has no `USER` instruction.
4. Railway selects the Dockerfile through `RAILWAY_DOCKERFILE_PATH`, not through
   a `railway.toml` builder setting. There is no `railway.toml` in the
   repository.
5. Seeding has to go through `bin/zfeeder seed` rather than copying OPML files,
   because copying only works for the flat backend — the live instance runs on
   `sqlite`, and would have started with no categories.

## 6. Documentation review

Every document in `docs/`, plus the root-level Markdown files, was read against
the code on 18 September 2026 and corrected where it disagreed.

| Check | Result |
|---|---|
| `docs/CONFIGURATION.md` matches the schema | `php bin/zfeeder docs:config --check` — up to date, exit 0. The file is generated from `src/Config/Schema.php` and is never hand-edited |
| Option count | 44, in the schema and in the generated table |
| Template counts | 14 classic, 16 modern, 30 in all — counted with `ls` |
| CLI commands | 12, from `bin/zfeeder list`; `seed` was missing from every list in the documentation and has been added |
| Internal links | Every relative Markdown link in `docs/` and the root files resolves. Two pointed at `deploy/railway.toml`, which does not exist; both corrected |
| Read-only commands | `bin/zfeeder list`, `check-config`, `docs:config --check`, `seed --dry-run`, `hash-password`, `legacy-import --help` were each run |
| Test and coverage figures | Replaced throughout with the measured 1,418 tests / 10,643 assertions / 90.62 % |

What was wrong, and is now right, in the main cases:

- `THREAT-MODEL.md` said `tests/Security/` held three classes, that most
  controls had no dedicated test, and that `tools/check-forbidden.sh` exited 1.
  All three were false.
- `TEST-PLAN.md` and `SRS.md` carried the figures of an earlier run (943 tests,
  90.76 %) and listed gaps that had since been closed.
- `ACCESSIBILITY.md` said no spec visited `/admin`, and called the classic set
  thirteen templates.
- `DEPLOYMENT.md` described an entrypoint that used a `.seeded` marker file and
  copied OPML, and a Railway service configured by `railway.toml`.
- `docs/portfolio/index.md` contained the literal placeholder `{{RAILWAY_URL}}`.

## What was not verified

Stated plainly, because a verification report that lists only successes is not
one.

- **No load or performance testing.** Nothing measures how long a refresh of a
  large subscription list takes, or how the application behaves under concurrent
  requests. Per-feed ceilings exist (`fetch_timeout`, `fetch_max_bytes`) but
  there is no global cap on a refresh, and the matching residual risk is in
  [THREAT-MODEL.md](THREAT-MODEL.md#residual-risk).
- **No penetration test.** The security review is a test suite, a threat model
  and a reading of the code by the people who wrote it. No independent tester
  has attacked a running instance.
- **No multi-architecture image test.** `.github/workflows/release.yml` builds
  for `linux/amd64` and `linux/arm64`. Only `linux/amd64` has ever been run.
- **No shared-hosting installation was actually performed.** The recipe in
  [DEPLOYMENT.md](DEPLOYMENT.md#shared-hosting-from-the-release-archive) and
  `deploy/shared-hosting.htaccess` were written from the archive layout and
  reviewed, not carried out on a real shared host over FTP.
- **No screen reader pass.** Nothing has been through NVDA, JAWS, VoiceOver or
  Orca. Every WCAG criterion in [ACCESSIBILITY.md](ACCESSIBILITY.md) that
  depends on announcement is reasoning from the markup.
- **No Lighthouse run and no 200 % zoom test**, both of which `project/PLAN.md`
  §B8 and §B9 ask for.
- **The security suite was never run against a weakened build.**
  `project/PLAN.md` §B14 asks for each control to be removed and the matching
  class shown failing. Five classes demonstrate the undefended behaviour inside
  the test instead; the rest rest on review.
- **`bin/zfeeder seed` has no test**, although the container entrypoint runs it
  on every boot.
- **The classic admin skin's axe result is not in CI.** `admin.spec.js` runs
  against the default `modern` skin. `build/check-admin.mjs` walks both skins,
  but it is not part of the pipeline, so that result is only as fresh as the
  last manual run.
- **CodeQL covers JavaScript only.** `.github/workflows/codeql.yml` sets
  `languages: javascript-typescript`; this is a PHP project.
- **The container image was not rebuilt in this pass**, and the shared-hosting
  zip was not built or unpacked. CI covers the image; nothing covers the zip
  after the build.

The gaps that are ongoing rather than one-off are tracked in
[ROADMAP.md](ROADMAP.md#verification-gaps) and
[TEST-PLAN.md](TEST-PLAN.md#known-gaps).
