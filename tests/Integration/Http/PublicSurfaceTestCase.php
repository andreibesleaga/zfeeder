<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Config\Config;
use Zfeeder\Http\DemoController;
use Zfeeder\Http\ErrorHandler;
use Zfeeder\Http\PublicController;
use Zfeeder\Http\Router;
use Zfeeder\Http\SecurityHeaders;
use Zfeeder\Kernel;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Tests\Support\StorageTempDirectory;

/**
 * Shared harness for the public side: the demonstration pages, the embedding
 * endpoints and the operational endpoints.
 *
 * The cache is seeded from the recorded fixtures and the refresh mode is
 * offline, so a page render is a pure function of the files on disk. No socket
 * is opened and no clock is read.
 */
abstract class PublicSurfaceTestCase extends TestCase
{
    use StorageTempDirectory;

    protected const string NOW = '2026-09-18T12:00:00+00:00';
    protected const string CATEGORY = 'demo';

    /** Fixture feeds, keyed by the URL the subscriptions point at. */
    protected const array FEEDS = [
        'https://techwire.example.com/feed.xml' => 'feeds-2026/rss20-content-encoded.xml',
        'https://science.example.com/feed.xml' => 'feeds-2026/rss20-science.xml',
    ];

    protected Config $config;
    protected Kernel $kernel;
    protected Router $router;

    protected function setUp(): void
    {
        $this->bootKernel();
        $this->seed();
        $this->router = $this->buildRouter();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** @param array<string, string|int|bool> $overrides */
    protected function bootKernel(array $overrides = []): void
    {
        $this->config = Config::forTesting($overrides + [
            'default_category' => self::CATEGORY,
            'template_set' => 'modern',
            'default_template' => 'list',
            // Nothing in this suite may reach the network: offline mode renders
            // whatever the cache holds and never fetches.
            'refresh_mode' => 'offline',
            'admin_enabled' => false,
            // The address rules have their own tests; here they would only add
            // a DNS lookup for example.com hosts that do not resolve.
            'allow_private_hosts' => true,
        ], $this->tempDir());

        $this->kernel = new Kernel(
            $this->config,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW),
        );
    }

    protected function seed(): void
    {
        $store = $this->kernel->subscriptions();
        $cache = $this->kernel->cache();
        $fetchedAt = new \DateTimeImmutable(self::NOW);

        $feeds = [];
        $position = 1;
        foreach (self::FEEDS as $url => $fixture) {
            $cache->put(new CacheEntry($url, zf_fixture_contents($fixture), $fetchedAt));
            $feeds[] = new Feed(
                xmlUrl: $url,
                title: 'Fixture ' . $position,
                description: 'A recorded feed',
                htmlUrl: 'https://example.com/',
                position: $position,
                refreshMinutes: 10_000,
                showedItems: 3,
            );
            $position++;
        }

        // seed() runs again after a test re-boots the kernel with different
        // options, and the data directory survives, so creating is conditional.
        foreach ([self::CATEGORY, 'other'] as $name) {
            if (!$store->has($name)) {
                $store->createCategory($name);
            }
        }
        $store->saveCategory(new Category(self::CATEGORY, $feeds));
    }

    protected function buildRouter(): Router
    {
        $router = new Router();
        $router->get('/', 'demo.index');
        $router->get('/demos/template/{set}/{name}', 'demo.template');
        $router->get('/demos/{slug}', 'demo.show');
        $router->get('/embed', 'public.embed');
        $router->get('/api/feeds', 'public.api');
        $router->get('/api/opml/{category}', 'public.opml');
        $router->get('/refresh', 'public.refresh');
        $router->get('/healthz', 'public.health');

        return $router;
    }

    /** @param array<string, string> $headers */
    protected function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->dispatch($this->request('GET', $path, $headers));
    }

    /** @param array<string, string> $headers */
    protected function request(string $method, string $path, array $headers = []): ServerRequestInterface
    {
        $uri = 'http://zfeeder.test' . $path;
        $request = new ServerRequest($method, $uri, $headers, null, '1.1', ['REMOTE_ADDR' => '203.0.113.9']);
        $query = parse_url($uri, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            $request = $request->withQueryParams($params);
        }

        return $request;
    }

    /**
     * The same dispatch table the front controller uses. Kept here rather than
     * including public/index.php so a test failure points at a controller
     * instead of at the emitter.
     */
    protected function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->router->dispatch($request);
        $errors = new ErrorHandler($this->config, $this->kernel->logger());
        $headers = new SecurityHeaders($this->config);
        $wantsJson = str_starts_with($request->getUri()->getPath(), '/api/');

        if ($match === null) {
            return $errors->notFound($wantsJson);
        }
        if ($match['handler'] === '405') {
            return $errors->methodNotAllowed();
        }

        $demo = new DemoController($this->kernel);
        $public = new PublicController($this->kernel);
        $params = $match['params'];

        try {
            return match ($match['handler']) {
                'demo.index' => $headers->forPublic($demo->index($request), $request),
                'demo.show' => $headers->forPublic($demo->demo($request, $params['slug'] ?? ''), $request),
                'demo.template' => $headers->forPublic(
                    $demo->template($request, $params['set'] ?? '', $params['name'] ?? ''),
                    $request,
                ),
                'public.embed' => $headers->forEmbed($public->embed($request), $request),
                'public.api' => $headers->forEmbed($public->api($request), $request),
                'public.opml' => $headers->forPublic($public->opml($request, $params['category'] ?? ''), $request),
                'public.refresh' => $public->refresh($request),
                'public.health' => $public->health(),
                default => $errors->notFound($wantsJson),
            };
        } catch (\Throwable $e) {
            return $headers->forPublic($errors->handle($e, $wantsJson), $request);
        }
    }

    protected static function bodyOf(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $body->rewind();

        return $body->getContents();
    }

    /** @return array<string, mixed> */
    protected static function jsonOf(ResponseInterface $response): array
    {
        $decoded = json_decode(self::bodyOf($response), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
