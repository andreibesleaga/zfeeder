# 0006. symfony/http-client as the HTTP transport

## Status

Accepted.

## Context

1.6 fetched feeds with `fopen()`/`file_get_contents()` on a remote URL. That gives no timeout control, no
conditional requests, no access to response headers, no size limit, and no say over redirects — the client
follows them silently, which is how an SSRF guard gets bypassed after it has already passed.

2.0 needs four things from a client: a timeout it honours, streamed reading so an oversized body can be
abandoned mid-transfer, access to `ETag` and `Last-Modified`, and the ability to *refuse* to follow redirects
so each hop can be re-checked. It also has to be mockable, because `tests/fixtures/` exists precisely so that
no test touches the network.

## Decision

`symfony/http-client` (`^7.1`), created in `src/Kernel.php::httpClient()`. The options there are the
decision: `timeout` and `max_duration` from the `fetch_timeout` key, the latter at twice the former; the
`User-Agent` from `Version::userAgent()`; and `max_redirects => 0`, with the comment recording why —
redirects are followed by hand in `src/Fetch/FeedFetcher.php` so every hop can be re-checked against the
address rules.

`FeedFetcher` walks the chain itself up to `fetch_max_redirects` hops, resolving each `Location` against the
URL it came from and re-running `src/Fetch/UrlGuard.php` before each request. It reads the body through
`$this->http->stream($response)` and stops at `fetch_max_bytes`.

The panel's non-feed requests — autodiscovery pages, OPML at a URL, the release endpoint — go through
`src/Admin/Http/GuardedFetch.php`, which repeats the guarding and counting but does not write to the feed
cache.

## Consequences

- Transport selection is Symfony's: curl when `ext-curl` is present, streams otherwise, so the extension is a
  `suggest` rather than a `require` in `composer.json`.
- Tests drive `MockHttpClient` and `MockResponse` (`tests/Unit/Fetch/FeedFetcherTest.php`), so conditional
  GET, 304 handling, redirect chains and the size cap are exercised without a network.
- Following redirects by hand is more code than setting `max_redirects`, and that code is security-relevant:
  a future change that lets the client follow a redirect itself silently disables the per-hop address check.

## Alternatives considered

- **Guzzle.** Equally capable, and it can also disable redirects and stream bodies. Symfony's client was
  preferred because it and `symfony/html-sanitizer` (ADR 0007) then come from one vendor on one release
  cadence, and `MockHttpClient` needs no handler-stack wiring in tests.
- **Streams with `stream_context_create()`.** No dependency, and it can set a timeout — but response headers,
  redirect control and incremental reading all become manual work with no test double.
