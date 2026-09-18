<?php

declare(strict_types=1);

namespace Zfeeder\Fetch;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zfeeder\Config\Config;
use Zfeeder\Exception\FetchException;
use Zfeeder\Exception\SecurityException;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\CacheStoreInterface;
use Zfeeder\Subscription\Feed;

/**
 * Retrieves one feed, honouring the cache the way zFeeder 1.6 did and adding
 * the parts 2004 had no answer for: conditional requests, a size ceiling and a
 * redirect chain that is checked hop by hop.
 *
 * The one behaviour that must survive unchanged is stale-on-failure. In 1.6 a
 * site whose feed was briefly unreachable kept showing yesterday's headlines
 * rather than an empty column, and that is still the right trade for an
 * aggregator: a FAILED result therefore still carries the old cache entry.
 *
 * The clock is injected because every decision here is a comparison against
 * "now", and tests must be able to state what now is.
 */
final class FeedFetcher
{
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /** @param (callable(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly Config $config,
        private readonly CacheStoreInterface $cache,
        private readonly UrlGuard $guard,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * @param int  $refreshMinutes the subscription's own TTL (OPML `refreshTime`)
     * @param bool $force          ignore the TTL, as `bin/zfeeder refresh --force` does
     */
    public function fetch(string $url, int $refreshMinutes, bool $force = false): FetchResult
    {
        $now = ($this->clock)();
        $entry = $this->cache->get($url);

        if ($entry !== null && !$force && !$entry->isExpired($refreshMinutes, $now)) {
            return new FetchResult($url, FetchResult::NOT_EXPIRED, $entry);
        }

        try {
            $this->guard->assertAllowed($url);

            return $this->request($url, $entry, $now);
        } catch (SecurityException | FetchException $e) {
            return $this->failure($url, $entry, $e->getMessage());
        } catch (HttpExceptionInterface $e) {
            return $this->failure($url, $entry, $e->getMessage());
        }
    }

    /**
     * @param iterable<Feed> $feeds
     *
     * @return list<FetchResult>
     */
    public function fetchAll(iterable $feeds, bool $force = false): array
    {
        $results = [];
        $seen = [];
        foreach ($feeds as $feed) {
            // One URL subscribed in two categories is fetched once per run.
            if (isset($seen[$feed->xmlUrl])) {
                continue;
            }
            $seen[$feed->xmlUrl] = true;
            $results[] = $this->fetch($feed->xmlUrl, $feed->refreshMinutes, $force);
        }

        return $results;
    }

    // ---- the request itself ---------------------------------------------

    /**
     * @throws FetchException
     * @throws SecurityException
     * @throws HttpExceptionInterface
     */
    private function request(string $url, ?CacheEntry $entry, \DateTimeImmutable $now): FetchResult
    {
        [$response, $finalUrl] = $this->followRedirects($url, $entry);
        $status = $response->getStatusCode();

        if ($status === 304) {
            if ($entry === null) {
                $response->cancel();

                throw new FetchException('The server answered 304 but nothing was cached.');
            }
            // Not modified still means "checked just now", so the TTL restarts
            // and any error recorded by an earlier failure is cleared.
            $refreshed = new CacheEntry(
                $entry->url,
                $entry->body,
                $now,
                $entry->etag,
                $entry->lastModified,
                $entry->status,
            );
            $this->cache->put($refreshed);
            $response->cancel();

            return new FetchResult($url, FetchResult::NOT_MODIFIED, $refreshed);
        }

        if ($status < 200 || $status > 299) {
            $response->cancel();

            throw new FetchException(sprintf('HTTP %d from %s', $status, $finalUrl));
        }

        $headers = $response->getHeaders(false);
        $this->assertAnnouncedSizeFits($headers, $url);
        $body = $this->readBounded($response, $url);

        if (trim($body) === '') {
            throw new FetchException('The server returned an empty body.');
        }

        $stored = new CacheEntry(
            $url,
            $body,
            $now,
            $this->firstHeader($headers, 'etag'),
            $this->firstHeader($headers, 'last-modified'),
            $status,
        );
        $this->cache->put($stored);

        return new FetchResult($url, FetchResult::CACHED, $stored);
    }

    /**
     * Walks the redirect chain by hand.
     *
     * `max_redirects: 0` keeps Symfony from following anything on its own,
     * which is the only way to be certain that every hop passes through
     * UrlGuard. A client-followed redirect would be checked once, at the
     * original URL, and a 302 to http://169.254.169.254/ would sail through.
     *
     * @return array{0: ResponseInterface, 1: string}
     *
     * @throws FetchException
     * @throws SecurityException
     * @throws HttpExceptionInterface
     */
    private function followRedirects(string $url, ?CacheEntry $entry): array
    {
        $maxRedirects = max(0, $this->config->int('fetch_max_redirects'));
        $current = $url;
        $hops = 0;

        while (true) {
            $response = $this->http->request('GET', $current, [
                'max_redirects' => 0,
                'timeout' => max(1, $this->config->int('fetch_timeout')),
                'headers' => $this->requestHeaders($entry, $hops === 0),
            ]);

            $status = $response->getStatusCode();
            if ($status < 300 || $status > 399 || $status === 304) {
                return [$response, $current];
            }

            $response->cancel();

            if ($hops >= $maxRedirects) {
                throw new FetchException(sprintf('More than %d redirects starting at %s.', $maxRedirects, $url));
            }

            $location = $this->firstHeader($response->getHeaders(false), 'location');
            if ($location === null || trim($location) === '') {
                throw new FetchException(sprintf('HTTP %d from %s without a Location header.', $status, $current));
            }

            $next = self::resolveUrl(trim($location), $current);
            $this->guard->assertAllowed($next);

            $current = $next;
            ++$hops;
        }
    }

    /**
     * Reads the body in chunks so that an oversized response is abandoned as
     * soon as the ceiling is crossed. Buffering first and measuring afterwards
     * would hand a hostile server the memory exhaustion it was aiming for.
     *
     * @throws FetchException
     * @throws HttpExceptionInterface
     */
    private function readBounded(ResponseInterface $response, string $url): string
    {
        $limit = max(1024, $this->config->int('fetch_max_bytes'));
        $body = '';
        $size = 0;

        foreach ($this->http->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if ($content === '') {
                continue;
            }
            $size += strlen($content);
            if ($size > $limit) {
                $response->cancel();

                throw new FetchException(sprintf('%s is larger than the %d byte limit.', $url, $limit));
            }
            $body .= $content;
        }

        return $body;
    }

    /**
     * A truthful Content-Length lets us refuse before reading a single byte.
     * A lying one changes nothing: readBounded() counts what actually arrives.
     *
     * @param array<string, list<string>> $headers
     *
     * @throws FetchException
     */
    private function assertAnnouncedSizeFits(array $headers, string $url): void
    {
        $announced = $this->firstHeader($headers, 'content-length');
        if ($announced === null || !ctype_digit(trim($announced))) {
            return;
        }

        $limit = max(1024, $this->config->int('fetch_max_bytes'));
        if ((int) trim($announced) > $limit) {
            throw new FetchException(sprintf('%s announces %s bytes, over the %d byte limit.', $url, trim($announced), $limit));
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(?CacheEntry $entry, bool $conditional): array
    {
        // Accept-Encoding is deliberately NOT set here. Setting it by hand tells
        // the transport that the caller will handle the encoding, so curl stops
        // decompressing transparently and the body arrives still gzipped - which
        // then fails to parse as XML with "Start tag expected". Leaving the
        // header off lets the client negotiate compression and decode it, which
        // is what this code wants: publishers still serve gzip, and the bytes
        // that reach the parser are the feed.
        $headers = [
            'User-Agent' => $this->config->userAgent(),
            'Accept' => 'application/atom+xml, application/rss+xml, application/feed+json, application/xml;q=0.9, text/xml;q=0.9, application/json;q=0.8, */*;q=0.1',
        ];

        // Validators belong to the original URL only; replaying them after a
        // redirect would ask a different resource about a different entity tag.
        if (!$conditional || $entry === null || $entry->body === '') {
            return $headers;
        }

        if ($entry->etag !== null && trim($entry->etag) !== '') {
            $headers['If-None-Match'] = $entry->etag;
        }
        if ($entry->lastModified !== null && trim($entry->lastModified) !== '') {
            $headers['If-Modified-Since'] = $entry->lastModified;
        }

        return $headers;
    }

    private function failure(string $url, ?CacheEntry $entry, string $message): FetchResult
    {
        $this->logger->warning('Feed fetch failed', ['url' => $url, 'error' => $message]);

        if ($entry === null) {
            return new FetchResult($url, FetchResult::FAILED, null, $message);
        }

        // Keep the body, remember why the refresh failed, and leave fetchedAt
        // alone so the next run tries again instead of waiting out a new TTL.
        $stale = $entry->withError($message);
        $this->cache->put($stale);

        return new FetchResult($url, FetchResult::FAILED, $stale, $message);
    }

    /** @param array<string, list<string>> $headers */
    private function firstHeader(array $headers, string $name): ?string
    {
        $values = $headers[strtolower($name)] ?? [];

        return $values[0] ?? null;
    }

    /**
     * Resolves a Location value against the URL it came from: absolute,
     * scheme-relative, root-relative and path-relative forms all occur in the
     * wild, and getting this wrong would mean guarding the wrong address.
     */
    public static function resolveUrl(string $reference, string $base): string
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $reference) === 1) {
            return $reference;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['host'])) {
            return $reference;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? $parts['scheme'] : 'http';
        $authority = $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        if (str_starts_with($reference, '//')) {
            return $scheme . ':' . $reference;
        }

        if (str_starts_with($reference, '#')) {
            return $base;
        }

        if (str_starts_with($reference, '/')) {
            return $scheme . '://' . $authority . $reference;
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
        if ($directory === '') {
            $directory = '/';
        }

        return $scheme . '://' . $authority . self::normalisePath($directory . $reference);
    }

    /** Collapses `.` and `..` so that a relative hop cannot smuggle a path. */
    private static function normalisePath(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }

        $normalised = '/' . implode('/', $out);
        if (str_ends_with($path, '/') && !str_ends_with($normalised, '/')) {
            $normalised .= '/';
        }

        return $normalised;
    }
}
