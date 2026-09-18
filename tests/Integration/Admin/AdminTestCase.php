<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zfeeder\Admin\AdminDispatcher;
use Zfeeder\Admin\AdminRoutes;
use Zfeeder\Admin\Auth\ArraySession;
use Zfeeder\Admin\Auth\Csrf;
use Zfeeder\Admin\Auth\PasswordHasher;
use Zfeeder\Admin\Auth\SessionAuth;
use Zfeeder\Config\Config;
use Zfeeder\Http\Router;
use Zfeeder\Kernel;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;
use Zfeeder\Tests\Support\StorageTempDirectory;

/**
 * The panel end to end: a real router, a real dispatcher, real templates, and
 * nothing that touches the clock, the network or a PHP session.
 *
 * Requests are built by hand rather than through a client, because what is
 * under test is the chain from route name to response, and a test that also
 * exercised a web server would only add ways to be flaky.
 */
abstract class AdminTestCase extends TestCase
{
    use StorageTempDirectory;

    protected const string NOW = '2026-09-18T12:00:00+00:00';
    protected const string USER = 'admin';
    protected const string PASSWORD = 'correct-horse-battery-staple';
    protected const string CATEGORY = 'zfeeder';
    protected const string ADDRESS = '203.0.113.9';

    /** Argon2id is slow on purpose; one hash serves the whole suite. */
    private static ?string $passwordHash = null;

    protected ArraySession $session;

    protected Config $config;

    protected Kernel $kernel;

    protected Router $router;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->router = AdminRoutes::register(new Router());
        $this->bootKernel();
        $this->seedCategory();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** @param array<string, string|int|bool> $overrides */
    protected function bootKernel(array $overrides = []): void
    {
        // The overrides come first: with PHP's `+` the left-hand keys win.
        $this->config = Config::forTesting($overrides + [
            'admin_user' => self::USER,
            'admin_password_hash' => self::passwordHash(),
            // Address checks are exercised on purpose in their own tests; the
            // rest of the suite must never make a DNS query to run.
            'allow_private_hosts' => true,
            'default_category' => self::CATEGORY,
        ], $this->tempDir());

        $this->kernel = new Kernel($this->config, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW));
    }

    protected static function passwordHash(): string
    {
        return self::$passwordHash ??= (new PasswordHasher())->hash(self::PASSWORD);
    }

    protected function seedCategory(): void
    {
        $store = $this->kernel->subscriptions();
        if (!$store->has(self::CATEGORY)) {
            $store->createCategory(self::CATEGORY);
        }
    }

    /** @param list<Feed> $feeds */
    protected function withFeeds(array $feeds): void
    {
        $store = $this->kernel->subscriptions();
        $store->saveCategory(new Category(self::CATEGORY, $feeds));
    }

    // ---- requests --------------------------------------------------------

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function send(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        array $uploads = [],
    ): ResponseInterface {
        $request = $this->request($method, $path, $headers);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        if ($uploads !== []) {
            $request = $request->withUploadedFiles($uploads);
        }

        return $this->dispatch($request);
    }

    /** @param array<string, string> $headers */
    protected function request(string $method, string $path, array $headers = []): ServerRequestInterface
    {
        return new ServerRequest(
            $method,
            'http://zfeeder.test' . $path,
            $headers,
            null,
            '1.1',
            ['REMOTE_ADDR' => self::ADDRESS],
        );
    }

    protected function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $route = $this->router->dispatch($request);
        self::assertIsArray($route, 'No route matched ' . $request->getUri()->getPath());
        self::assertNotSame('405', $route['handler'], 'Wrong method for ' . $request->getUri()->getPath());

        return (new AdminDispatcher($this->kernel, $this->session))
            ->handle($route['handler'], $request, $route['params']);
    }

    // ---- state -----------------------------------------------------------

    protected function signIn(): void
    {
        $this->session->set(SessionAuth::USER_KEY, self::USER);
        $this->session->set(SessionAuth::SEEN_KEY, (new \DateTimeImmutable(self::NOW))->getTimestamp());
    }

    protected function token(): string
    {
        return (new Csrf($this->session))->token();
    }

    /** @param array<string, mixed> $body */
    protected function withToken(array $body): array
    {
        return [Csrf::FIELD => $this->token()] + $body;
    }

    /** @param list<MockResponse> $responses */
    protected function mockHttp(array $responses): void
    {
        $this->kernel->withHttpClient(new MockHttpClient($responses));
    }

    protected static function bodyOf(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return $response->getBody()->getContents();
    }
}
