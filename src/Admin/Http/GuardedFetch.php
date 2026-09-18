<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Http;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zfeeder\Exception\FetchException;
use Zfeeder\Exception\SecurityException;
use Zfeeder\Fetch\FeedFetcher;
use Zfeeder\Fetch\UrlGuard;

/**
 * The one way the admin panel is allowed to read something from the network
 * that is not a feed: a web page for autodiscovery, an OPML list at a URL, the
 * project's release endpoint.
 *
 * `FeedFetcher` cannot serve these, because it caches what it reads as a feed
 * body, and an HTML page in the feed cache would be rendered as a broken
 * subscription later. Everything else it does is repeated here: the address is
 * guarded before the connection and again on every redirect, the body is
 * counted as it arrives rather than measured afterwards, and the whole thing
 * gives up quickly.
 *
 * What this class must never become is `file_get_contents($url)`, which is how
 * 1.6 did all three of these.
 */
final class GuardedFetch
{
    private const int MAX_REDIRECTS = 3;

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly HttpClientInterface $http,
        private readonly string $userAgent,
        private readonly int $timeoutSeconds,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws SecurityException     when the URL must not be contacted
     * @throws FetchException        on a transport failure, a bad status or an oversized body
     * @throws HttpExceptionInterface
     */
    public function get(string $url, array $headers = []): string
    {
        $this->guard->assertAllowed($url);

        $current = $url;
        $hops = 0;

        while (true) {
            $response = $this->http->request('GET', $current, [
                'max_redirects' => 0,
                'timeout' => max(1, $this->timeoutSeconds),
                'headers' => ['User-Agent' => $this->userAgent] + $headers,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 300 && $status <= 399) {
                $response->cancel();
                if ($hops >= self::MAX_REDIRECTS) {
                    throw new FetchException(sprintf('More than %d redirects starting at %s.', self::MAX_REDIRECTS, $url));
                }
                $location = $response->getHeaders(false)['location'][0] ?? '';
                if (trim($location) === '') {
                    throw new FetchException(sprintf('HTTP %d from %s without a Location header.', $status, $current));
                }
                $current = FeedFetcher::resolveUrl(trim($location), $current);
                $this->guard->assertAllowed($current);
                ++$hops;

                continue;
            }

            if ($status < 200 || $status > 299) {
                $response->cancel();

                throw new FetchException(sprintf('HTTP %d from %s', $status, $current));
            }

            return $this->readBounded($response, $current);
        }
    }

    /**
     * @throws FetchException
     * @throws HttpExceptionInterface
     */
    private function readBounded(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $url): string
    {
        $limit = max(1024, $this->maxBytes);
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
}
