<?php

declare(strict_types=1);

namespace Zfeeder;

use Psr\Log\LoggerInterface;
use Zfeeder\Config\Config;
use Zfeeder\Exception\StorageException;
use Zfeeder\Fetch\FeedFetcher;
use Zfeeder\Fetch\FetchResult;
use Zfeeder\Parse\FeedParser;
use Zfeeder\Parse\Model\Channel;
use Zfeeder\Render\RenderedFeed;
use Zfeeder\Render\Renderer;
use Zfeeder\Render\RenderRequest;
use Zfeeder\Storage\CacheStoreInterface;
use Zfeeder\Storage\SubscriptionStoreInterface;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * The one use case zFeeder exists for: turn a category of subscriptions into
 * rendered output, fetching and parsing on the way.
 *
 * Everything that has to happen in order — resolve the category, decide whether
 * each feed needs a network request, parse what came back, hand the result to a
 * template — happens here, so the HTTP layer, the CLI and the embed function all
 * behave identically.
 */
final class FeedService
{
    public function __construct(
        private readonly Config $config,
        private readonly SubscriptionStoreInterface $subscriptions,
        private readonly CacheStoreInterface $cache,
        private readonly FeedFetcher $fetcher,
        private readonly FeedParser $parser,
        private readonly Renderer $renderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve a requested category name to one that exists.
     *
     * 1.6 silently fell back to the default when the name was unknown, and
     * embedded output should never turn into an error page, so that behaviour
     * is kept. An unknown default is a real misconfiguration and does throw.
     */
    public function resolveCategory(?string $requested): Category
    {
        $default = $this->config->string('default_category');

        if ($requested !== null && $requested !== '' && Category::isValidName($requested) && $this->subscriptions->has($requested)) {
            return $this->subscriptions->category($requested);
        }

        if ($this->subscriptions->has($default)) {
            return $this->subscriptions->category($default);
        }

        $available = $this->subscriptions->categories();
        if ($available === []) {
            throw new StorageException('There are no subscription categories yet. Create one in the admin panel or with bin/zfeeder.');
        }

        return $this->subscriptions->category($available[0]);
    }

    /**
     * Fetch and parse every renderable feed of a category.
     *
     * @return list<RenderedFeed>
     */
    public function load(Category $category, bool $forceRefresh = false): array
    {
        $offline = $this->config->string('refresh_mode') === 'offline' && !$forceRefresh;
        $loaded = [];

        foreach ($category->renderableFeeds() as $feed) {
            $loaded[] = $this->loadOne($feed, $offline, $forceRefresh);
        }

        return $loaded;
    }

    private function loadOne(Feed $feed, bool $offline, bool $forceRefresh): RenderedFeed
    {
        if ($offline) {
            // Offline mode never opens a socket while rendering: whatever is in
            // the cache is what the page shows, and cron does the refreshing.
            $entry = $this->cache->get($feed->xmlUrl);

            return new RenderedFeed($feed, $entry === null ? null : $this->parseQuietly($entry->body, $feed->xmlUrl), $entry);
        }

        $result = $this->fetcher->fetch($feed->xmlUrl, $feed->refreshMinutes, $forceRefresh);
        $entry = $result->entry;

        if ($entry === null) {
            $this->logger->notice('No content for {url}: {outcome}', [
                'url' => $feed->xmlUrl,
                'outcome' => $result->outcome,
            ]);

            return new RenderedFeed($feed, null, null);
        }

        $channel = $this->parseQuietly($entry->body, $feed->xmlUrl);

        // A cached body that will not parse leaves the feed blank until its
        // interval expires, which can be hours. It usually means the entry is
        // damaged rather than the publisher being broken - a truncated write,
        // or a body stored by an older version that encoded it differently.
        // Discarding it and fetching once more repairs that within one request.
        // The retry is bounded to one attempt and only happens when the entry
        // was served from cache, so a genuinely malformed feed costs one extra
        // request per interval, not one per page view.
        if ($channel === null && $result->outcome === FetchResult::NOT_EXPIRED && !$forceRefresh) {
            $this->logger->notice('Discarding an unparseable cache entry for {url} and refetching', [
                'url' => $feed->xmlUrl,
            ]);
            $this->cache->delete($feed->xmlUrl);

            $retry = $this->fetcher->fetch($feed->xmlUrl, $feed->refreshMinutes, true);
            if ($retry->entry !== null) {
                return new RenderedFeed($feed, $this->parseQuietly($retry->entry->body, $feed->xmlUrl), $retry->entry);
            }
        }

        return new RenderedFeed($feed, $channel, $entry);
    }

    /**
     * A feed that will not parse must not take the whole page down with it —
     * 1.6 rendered an error block in its place and carried on, and so do we.
     */
    private function parseQuietly(string $body, string $url): ?Channel
    {
        try {
            return $this->parser->parse($body, $url);
        } catch (\Throwable $e) {
            $this->logger->warning('Cannot parse {url}: {message}', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** Fetch, parse and render in one call. */
    public function render(RenderRequest $request, bool $forceRefresh = false): string
    {
        $category = $this->resolveCategory($request->category);
        $feeds = $this->load($category, $forceRefresh);

        return $this->renderer->render($category, $feeds, $request);
    }

    /**
     * Refresh a category without rendering anything, for cron and the CLI.
     *
     * @return list<FetchResult>
     */
    public function refresh(?string $categoryName = null, bool $force = false): array
    {
        $names = $categoryName !== null && $categoryName !== ''
            ? [$categoryName]
            : $this->subscriptions->categories();

        $results = [];
        $seen = [];
        foreach ($names as $name) {
            if (!$this->subscriptions->has($name)) {
                continue;
            }
            foreach ($this->subscriptions->category($name)->renderableFeeds() as $feed) {
                // A feed subscribed in two categories is only fetched once.
                if (isset($seen[$feed->xmlUrl])) {
                    continue;
                }
                $seen[$feed->xmlUrl] = true;
                $results[] = $this->fetcher->fetch($feed->xmlUrl, $feed->refreshMinutes, $force);
            }
        }

        return $results;
    }

    /**
     * The parsed channels of a category, for callers that want the data rather
     * than the markup: the JSON endpoint and `zfeeder_feeds()`.
     *
     * @return list<Channel>
     */
    public function channels(?string $categoryName = null): array
    {
        $category = $this->resolveCategory($categoryName);
        $channels = [];
        foreach ($this->load($category) as $rendered) {
            if ($rendered->channel !== null) {
                $channels[] = $rendered->channel;
            }
        }

        return $channels;
    }

    public function store(): SubscriptionStoreInterface
    {
        return $this->subscriptions;
    }
}
