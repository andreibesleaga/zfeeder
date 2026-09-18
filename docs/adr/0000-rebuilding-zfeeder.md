# 0000. Rebuild zFeeder rather than restore or patch it

## Status

Accepted.

## Context

zFeeder 1.6 (April 2004) is preserved unmodified in this repository at `legacy/zfeeder-1.6/`: just over
2,100 lines of PHP 4-era code across `zfeeder.php`, `admin.php`, `wap.php` and the files under `includes/`,
plus templates, CSS and OPML category files. The project is being revived as a portfolio piece and as a
working tool. Two questions had to be settled before any code was written: can the 1.6 code be run as-is or
lightly patched, and if not, what should replace it.

The 1.6 code cannot run unmodified on any currently supported PHP version:

- It calls `ereg_replace()` (`includes/zfuncs.php:305`, converting a feed URL into a cache filename) and
  the other POSIX regex functions, which were deprecated in PHP 5.3 and removed outright in PHP 7.0.
- `zfeeder.php` calls `set_magic_quotes_runtime(0)` on startup; `magic_quotes_runtime` was removed in
  PHP 5.4, so this call is now a fatal error, not a no-op.
- Feed and OPML parsing use `xml_parser_create()` with handlers registered as bare global functions
  (`rssStartElement`, `opmlStartElement`, and so on) rather than through an object, which still works
  mechanically but is difficult to isolate, test, or make thread-safe, and offers no XXE protection by
  default.

Even setting the compatibility problem aside, the 1.6 code has security properties that would not pass
review today:

- The admin panel authenticates with `md5($password)` compared with `!=`, not a constant-time comparison,
  and stores that hash in a plaintext-writable `config.php`.
- Saving configuration works by having PHP rewrite `config.php` — a file of `define()` calls — from
  `$_POST` data. This requires the web server to have write access to an executable PHP file, which is
  itself a standing risk regardless of what is written into it.
- The install instructions in `readme.html` ask for `cache/` to be `CHMOD 0777` and note this themselves as
  "considered a security risk," accepted at the time as the only way to make shared hosting work.
- Category and template names taken from `$_GET['zfcategory']` / `zftemplate` are used directly to build
  filesystem paths, with no allowlist pattern.
- Feed fetching has no SSRF guard: any URL reachable from the server, including loopback and private
  address ranges, can be requested through "add new feed."

None of these were unusual for PHP code written in 2003–2004; they were standard practice on the shared
hosting of the period. But they are not acceptable in software published and run in 2026.

## Decision

Rebuild zFeeder as version 2.0: the same product concept, architecture and feature set — flat-file/OPML
storage, template-driven output, one-line embedding, an admin panel with the same five screens — implemented
on a current PHP stack (PHP 8.3, no framework, PSR-4/7/15 packages), with authentication, configuration
storage, input validation and feed fetching redesigned to a 2026 security bar. The full scope and the
concrete decisions (feed-parsing library, template engine, storage, admin UI stack) are recorded in
`project/PLAN.md`. The unmodified 1.6 tree stays in the repository at `legacy/zfeeder-1.6/` as the reference
point for that rebuild and for the compatibility tests that check the new template engine still renders 1.x
templates correctly.

## Alternatives considered

- **Leave it archived, unmodified.** Keep `legacy/zfeeder-1.6/` and the SourceForge listing as the complete
  historical record, and do nothing further. Rejected: the project would remain unusable and undocumented
  beyond what already exists, and the portfolio value of showing "the same architecture, twenty-two years
  later, still standing up" would be lost.
- **Patch 1.6 in place for PHP 8.** Replace only the removed/deprecated calls (`ereg_replace` →
  `preg_replace`, drop `set_magic_quotes_runtime`, rewrite the global XML callbacks) and keep everything
  else, including the authentication and configuration-writing model. Rejected: this would produce code
  that runs but is still insecure by 2026 standards (MD5 password compare, PHP-writing-PHP config, no CSRF
  or SSRF protection, world-writable cache directory), and a patch-only pass would not give templates,
  admin UI or storage a genuine update either — it would look modernised without being modernised.
- **Rewrite in another language (e.g. TypeScript/Node).** Rejected for this project specifically: the
  "include one line in any PHP page" story is part of what zFeeder was, and a rewrite outside PHP would
  break that continuity and the direct diff-against-1.6 narrative that makes this a useful portfolio piece.
  A non-PHP rewrite remains a reasonable choice for a *different* project; it is not the right choice for
  *this* one.

## Consequences

- The rebuild is a genuine reimplementation against the same interfaces (OPML file format, template token
  set, admin screen names and fields, query parameters), not a fork of the 1.6 source, so most of the 1.6
  code is reference material rather than a starting point.
- Golden/compatibility tests are required to verify that 1.x templates and OPML files still work unchanged
  against the 2.0 renderer and subscription reader, since "same architecture" is a testable claim, not just
  a description.
- WAP/WML output, a genuine 1.6 feature, is retired rather than rebuilt (`project/PLAN.md` §1); it is
  documented here and in `docs/HISTORY.md` as history, not carried forward as a maintained feature.
- Security work (`project/PLAN.md` §5) is treated as the primary reason for the rebuild, not an add-on:
  authentication, CSRF, XSS sanitisation, SSRF guards, path-traversal validation, config storage, file
  permissions and XML parsing are each redesigned, not patched.

**Update, 18 September 2026.** The 1.6 tree was removed from this repository and archived at
<https://github.com/andreibesleaga/old-projects> (`zfeeder-1.6.zip`); the minimal 2004 corpus the tests
need now lives in `tests/fixtures/legacy-1.6/`.
