<?php

declare(strict_types=1);

namespace Zfeeder\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Kernel;
use Zfeeder\Render\RenderRequest;
use Zfeeder\Version;

/**
 * Everything the public side serves: the demo site, the embed endpoints and
 * the operational endpoints. The admin panel is dispatched separately.
 */
final class PublicController
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function health(): ResponseInterface
    {
        $config = $this->kernel->config();
        $subscriptionsReadable = false;
        try {
            $subscriptionsReadable = $this->kernel->subscriptions()->categories() !== [];
        } catch (\Throwable) {
            $subscriptionsReadable = false;
        }

        $checks = [
            'subscriptions' => $subscriptionsReadable,
            'data_writable' => is_dir($config->dataDir()) && is_writable($config->dataDir()),
        ];

        $healthy = $checks['data_writable'];

        return Responder::json([
            'status' => $healthy ? 'ok' : 'degraded',
            'version' => Version::NUMBER,
            'storage' => $config->string('storage'),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** The embeddable HTML fragment: the same output a PHP include would produce. */
    public function embed(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $html = $this->kernel->feeds()->render($this->requestFrom($query, '/embed'));

        return Responder::html($html);
    }

    /** The parsed feed data, for pages that would rather render it themselves. */
    public function api(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->kernel->config()->bool('api_enabled')) {
            return Responder::json(['error' => 'The JSON API is disabled on this installation.'], 404);
        }

        $query = $request->getQueryParams();
        $category = isset($query['category']) && is_string($query['category']) ? $query['category'] : null;
        $service = $this->kernel->feeds();
        $resolved = $service->resolveCategory($category);

        $channels = [];
        foreach ($service->load($resolved) as $feed) {
            $channel = $feed->channel;
            if ($channel === null) {
                continue;
            }
            $channels[] = [
                'title' => $channel->title,
                'link' => $channel->link,
                'description' => $channel->description,
                'language' => $channel->language,
                'format' => $channel->format,
                'feedUrl' => $feed->feed->xmlUrl,
                'lastFetched' => $feed->cache?->fetchedAt->format(DATE_ATOM),
                'items' => array_map(static fn ($item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'link' => $item->link,
                    'summary' => $item->summaryHtml,
                    'published' => $item->published?->format(DATE_ATOM),
                    'author' => $item->author,
                ], array_slice($channel->items, 0, max(1, $feed->feed->showedItems))),
            ];
        }

        return Responder::json([
            'category' => $resolved->name,
            'generator' => Version::full(),
            'channels' => $channels,
        ]);
    }

    public function opml(ServerRequestInterface $request, string $category): ResponseInterface
    {
        if (!$this->kernel->config()->bool('opml_export_public')) {
            return Responder::json(['error' => 'OPML export is not public on this installation.'], 404);
        }

        $store = $this->kernel->subscriptions();
        if (!\Zfeeder\Subscription\Category::isValidName($category) || !$store->has($category)) {
            return Responder::json(['error' => 'No such category.'], 404);
        }

        return Responder::xml($store->exportOpml($category), 200, [
            'Content-Disposition' => 'attachment; filename="' . $category . '.opml"',
        ]);
    }

    /**
     * The cron trigger kept from 1.6: refresh every feed without rendering.
     * Useless without a configured key, so an unset key means the door is shut.
     */
    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $configured = trim($this->kernel->config()->string('refresh_key'));
        $query = $request->getQueryParams();
        // `zfrefresh` is the 1.6 spelling; a 2004 cron line keeps working.
        $given = '';
        foreach (['key', 'zfrefresh'] as $name) {
            if (isset($query[$name]) && is_string($query[$name]) && $query[$name] !== '') {
                $given = $query[$name];
                break;
            }
        }

        if ($configured === '' || !hash_equals($configured, $given)) {
            return Responder::text("Refreshing over HTTP needs a refresh key.\n", 403);
        }

        $category = isset($query['category']) && is_string($query['category']) ? $query['category'] : null;
        $results = $this->kernel->feeds()->refresh($category, isset($query['force']));

        $lines = array_map(static fn ($r): string => $r->legacyLine(), $results);
        $failed = count(array_filter($results, static fn ($r): bool => !$r->isSuccess()));

        return Responder::text(
            gmdate('r') . " - zFeeder feed refreshing\n\n"
            . implode("\n", $lines)
            . sprintf("\n\n%d feeds, %d failed\n", count($results), $failed),
        );
    }

    /** @param array<string, mixed> $query */
    private function requestFrom(array $query, string $selfUrl): RenderRequest
    {
        $get = static function (array $q, string $key): ?string {
            $value = $q[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        return new RenderRequest(
            category: $get($query, 'category') ?? $get($query, 'zfcategory'),
            template: $get($query, 'template') ?? $get($query, 'zftemplate'),
            positions: $get($query, 'position') ?? $get($query, 'zfposition'),
            moreFeed: ($more = $get($query, 'more') ?? $get($query, 'zfmore')) !== null ? (int) $more : null,
            showPoweredBy: ($get($query, 'link') ?? $get($query, 'zf_link')) !== 'off',
            selfUrl: $selfUrl,
        );
    }
}
