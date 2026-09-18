# 0001. PHP 8.3 and Composer, with no framework

## Status

Accepted.

## Context

ADR 0000 settled that zFeeder would be reimplemented rather than patched, leaving the question of how much of
the application to buy in. The 1.6 tree has no dependencies at all, and the "include one line in any PHP
page" story only survives if the result is still ordinary PHP that a shared host can run without a service or
a build step. A full-stack framework would have supplied routing, dependency injection, sessions and
templating, at the price of a dependency tree larger than the application. Writing everything by hand means
writing a feed parser, an HTTP client and an HTML sanitiser — precisely where the 2004 code's security
defects were.

## Decision

PHP 8.3 as the floor (`composer.json`: `"php": ">=8.3"`), Composer autoloading under the PSR-4 prefix
`Zfeeder\`, and no framework. Libraries are taken only where the problem is hard or security-sensitive:
`laminas/laminas-feed`, `symfony/http-client`, `symfony/html-sanitizer`, `symfony/console`, `twig/twig`,
`nyholm/psr7` with `nyholm/psr7-server`, and four PSR interface packages — the entire runtime requirement
list.

The two things a framework would otherwise have provided are written here. `src/Kernel.php` is a
hand-written factory — its docblock records the reason: about fifteen services of fixed shape, where a
container adds a dependency and a layer of indirection for a problem this application does not have.
`src/Http/Router.php` matches routes in declaration order, for the twenty or so registered in
`public/index.php` and `src/Admin/AdminRoutes.php`.

The 8.3 floor is used, not merely declared: typed class constants (`src/Version.php`) and readonly classes
(`src/Parse/Model/Item.php`, `src/Http/Route.php`) appear throughout.

## Consequences

- Layering has to be enforced rather than assumed, because no framework imposes it. `deptrac.yaml` defines
  Shared, Domain, Config, Storage, Application, Infrastructure and Composition layers and is run in CI.
- Every service is a lazily memoised method on `Kernel`, and each one that tests replace has an explicit seam
  (`withHttpClient()`, `withSubscriptions()`, `withCache()`, `withLogger()`).
- Composer is a build-time tool the 2004 audience does not have, so a distribution archive with `vendor/`
  included becomes mandatory; see ADR 0009.
- Hosts on PHP 8.1 or 8.2 cannot run 2.0. CI tests 8.3 and 8.4 (`.github/workflows/ci.yml`).

## Alternatives considered

- **PHP with Slim 4.** Would have supplied the PSR-15 pipeline and routing that `src/Http/` now contains, but
  adds a container and a framework boot to every embedded include.
- **A TypeScript/Node rewrite.** Rejected in ADR 0000: it ends the include-anywhere story.
