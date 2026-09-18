# 0013. The security architecture

## Status

Accepted.

## Context

ADR 0000 names security as the reason 1.6 cannot be reused: MD5 passwords compared with `!=`, PHP rewriting
its own `config.php` from `$_POST`, filesystem paths built from `$_GET`, feed markup printed unescaped, no
restriction on which URL the server may fetch. Fixing those one at a time produces patches, not an
architecture. `project/PLAN.md` §5 and B6 (S1–S18, against ASVS 4.0 L2) ask instead that each defence have
one owner in the code.

## Decision

Each requirement is implemented in exactly one class.

`src/Fetch/UrlGuard.php` alone decides whether a URL may be opened: scheme allow-list, then every resolved
A/AAAA record tested against RFC 6890 tables held as data, plus names ending in `.local` or `.internal`.
`FeedFetcher` re-runs it on every redirect hop (ADR 0006); `src/Admin/Http/GuardedFetch.php` applies it to
autodiscovery, remote OPML and the update check.

`src/Render/Sanitizer.php` owns the XSS policy (ADR 0007). `FeedParser::assertSafeXml()` and
`Subscription\Opml\Reader` reject document type declarations, deny external entities and never pass
`LIBXML_NOENT`; an import is capped at 500 outlines and 1 MiB. `src/Storage/PathGuard.php` backs the
`^[a-z0-9_-]{1,40}$` name pattern with a realpath containment check, symlinks resolved first.

In `src/Admin/`, `Auth/PasswordHasher` uses Argon2id and `password_verify()`; `Auth/SessionAuth` regenerates
the session id on sign-in, sets HttpOnly/SameSite=Lax/Secure and enforces an idle timeout on an injected
clock; `Auth/Csrf` holds one constant-time token per session and treats a missing token as a wrong one;
`Auth/RateLimiter` limits sign-in attempts; `Middleware/DemoMode` refuses every unsafe method.
`src/Http/SecurityHeaders.php` holds a nonce-based CSP for the panel and a configurable `frame-ancestors`
for the embed endpoint; `ErrorHandler` leaks no paths or traces in production.

## Consequences

- Configuration is data, not code: `data/config.json`, written through `ConfigWriter` and validated by
  `src/Config/Schema.php`. Nothing in the tree writes PHP.
- `tools/check-forbidden.sh` greps for what a type checker cannot express: `eval`, shell execution,
  `unserialize`, dynamic includes, remote file reads, MD5/SHA-1 near a password. `deptrac.yaml` enforces
  layering; CI adds `composer audit`, gitleaks and CodeQL.
- The goldens' `legacyFidelity` path skips sanitising (ADR 0007), and must never be reachable from the
  network.
- **Proven: `tests/Security/` holds one class per control**, `S01PasswordHashingTest` through
  `S18SecretsTest`, 484 tests. The control table in [../THREAT-MODEL.md](../THREAT-MODEL.md) names the
  class and the methods for each control. What the suite does *not* do is run against a build with the
  controls removed, so a vacuous test is caught by review rather than by construction; several classes
  compensate by asserting the undefended behaviour first, and the gap is recorded in
  [../TEST-PLAN.md](../TEST-PLAN.md#known-gaps).
- **The review that wrote those classes found four defects in 2.0 itself** — unescaped channel strings,
  unchecked URL schemes, JSON without the HEX flags, and an error handler that logged nothing below 500.
  All four are closed and described in [../THREAT-MODEL.md](../THREAT-MODEL.md#four-defects-found-in-20-and-closed).

## Alternatives considered

- **Patch the 1.6 defects individually.** Rejected in ADR 0000: code that runs and is still insecure, with
  nowhere for the next feature to inherit a defence.
- **A web application firewall.** It protects one deployment; zFeeder is installed by its users, so the
  defences must travel with the code.
