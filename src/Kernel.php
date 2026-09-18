<?php

declare(strict_types=1);

namespace Zfeeder;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zfeeder\Config\Config;
use Zfeeder\Config\ConfigLoader;
use Zfeeder\Fetch\FeedFetcher;
use Zfeeder\Fetch\UrlGuard;
use Zfeeder\Log\FileLogger;
use Zfeeder\Parse\Autodiscovery;
use Zfeeder\Parse\FeedParser;
use Zfeeder\Render\Renderer;
use Zfeeder\Render\Sanitizer;
use Zfeeder\Render\TemplateEngine;
use Zfeeder\Render\TemplateLocator;
use Zfeeder\Storage\CacheStoreInterface;
use Zfeeder\Storage\StoreFactory;
use Zfeeder\Storage\SubscriptionStoreInterface;

/**
 * Wires the application together.
 *
 * A hand-written factory rather than a container: there are about fifteen
 * services with a fixed shape, so a container would add a dependency and a
 * layer of indirection to solve a problem this application does not have.
 * Every service is created once, lazily, and can be replaced in tests.
 */
final class Kernel
{
    private ?SubscriptionStoreInterface $subscriptions = null;
    private ?CacheStoreInterface $cache = null;
    private ?LoggerInterface $logger = null;
    private ?HttpClientInterface $httpClient = null;
    private ?UrlGuard $urlGuard = null;
    private ?FeedFetcher $fetcher = null;
    private ?FeedParser $parser = null;
    private ?Renderer $renderer = null;
    private ?TemplateLocator $locator = null;
    private ?Sanitizer $sanitizer = null;
    private ?\Zfeeder\Storage\StoreFactory $stores = null;
    private ?FeedService $feeds = null;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /** @param (callable(): \DateTimeImmutable)|null $clock fixed time source, for deterministic tests */
    public function __construct(
        private readonly Config $config,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** Builds a kernel from the environment and the configuration file on disk. */
    public static function boot(?string $configPath = null, ?string $projectRoot = null): self
    {
        $root = $projectRoot ?? dirname(__DIR__);
        $config = ConfigLoader::load($configPath, $root);

        return new self($config);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function clock(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    public function logger(): LoggerInterface
    {
        return $this->logger ??= new FileLogger(
            $this->config->logPath(),
            $this->config->string('log_level'),
        );
    }

    public function subscriptions(): SubscriptionStoreInterface
    {
        return $this->subscriptions ??= $this->stores()->subscriptions();
    }

    public function cache(): CacheStoreInterface
    {
        return $this->cache ??= $this->stores()->cache();
    }

    public function stores(): StoreFactory
    {
        return $this->stores ??= new StoreFactory($this->config);
    }

    public function httpClient(): HttpClientInterface
    {
        return $this->httpClient ??= HttpClient::create([
            'timeout' => (float) $this->config->int('fetch_timeout'),
            'max_duration' => (float) ($this->config->int('fetch_timeout') * 2),
            'headers' => ['User-Agent' => $this->config->userAgent()],
            // Redirects are followed by hand so every hop can be re-checked
            // against the address rules; see FeedFetcher.
            'max_redirects' => 0,
        ]);
    }

    public function urlGuard(): UrlGuard
    {
        return $this->urlGuard ??= new UrlGuard($this->config);
    }

    public function fetcher(): FeedFetcher
    {
        return $this->fetcher ??= new FeedFetcher(
            $this->config,
            $this->cache(),
            $this->urlGuard(),
            $this->httpClient(),
            $this->logger(),
            $this->clock,
        );
    }

    public function parser(): FeedParser
    {
        return $this->parser ??= new FeedParser();
    }

    public function autodiscovery(): Autodiscovery
    {
        return new Autodiscovery();
    }

    public function templateLocator(): TemplateLocator
    {
        return $this->locator ??= new TemplateLocator($this->config);
    }

    public function sanitizer(): Sanitizer
    {
        return $this->sanitizer ??= new Sanitizer();
    }

    public function renderer(): Renderer
    {
        return $this->renderer ??= new Renderer(
            $this->config,
            $this->templateLocator(),
            new TemplateEngine(),
            $this->sanitizer(),
        );
    }

    /** The use case that ties fetching, parsing and rendering together. */
    public function feeds(): FeedService
    {
        return $this->feeds ??= new FeedService(
            $this->config,
            $this->subscriptions(),
            $this->cache(),
            $this->fetcher(),
            $this->parser(),
            $this->renderer(),
            $this->logger(),
        );
    }

    // ---- test seams ----------------------------------------------------

    public function withHttpClient(HttpClientInterface $client): self
    {
        $this->httpClient = $client;
        $this->fetcher = null;
        $this->feeds = null;

        return $this;
    }

    public function withSubscriptions(SubscriptionStoreInterface $store): self
    {
        $this->subscriptions = $store;
        $this->feeds = null;

        return $this;
    }

    public function withCache(CacheStoreInterface $cache): self
    {
        $this->cache = $cache;
        $this->fetcher = null;
        $this->feeds = null;

        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }
}
