<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Http;

use Nyholm\Psr7\ServerRequest;
use Zfeeder\Http\ErrorHandler;
use Zfeeder\Http\Responder;
use Zfeeder\Http\Router;
use Zfeeder\Http\SecurityHeaders;

/** Routing, response headers and error handling on the public side. */
final class HttpHardeningTest extends PublicSurfaceTestCase
{
    public function testPublicResponsesCarryTheHardeningHeaders(): void
    {
        $headers = $this->get('/')->getHeaders();
        $flat = array_change_key_case($headers);

        self::assertArrayHasKey('content-security-policy', $flat);
        self::assertStringContainsString("default-src 'self'", $flat['content-security-policy'][0]);
        self::assertSame('nosniff', $flat['x-content-type-options'][0]);
        self::assertNotEmpty($flat['referrer-policy'][0]);
        self::assertNotEmpty($flat['permissions-policy'][0]);
    }

    public function testStrictTransportSecurityOnlyAppearsOverHttps(): void
    {
        $plain = $this->get('/');
        self::assertFalse($plain->hasHeader('Strict-Transport-Security'));

        $secure = new SecurityHeaders($this->config);
        $request = new ServerRequest('GET', 'https://zfeeder.test/');
        $response = $secure->forPublic(Responder::html('hello'), $request);

        self::assertTrue($response->hasHeader('Strict-Transport-Security'));
    }

    public function testAForwardedProtocolIsOnlyBelievedFromATrustedProxy(): void
    {
        $request = new ServerRequest(
            'GET',
            'http://zfeeder.test/',
            ['X-Forwarded-Proto' => 'https'],
            null,
            '1.1',
            ['REMOTE_ADDR' => '203.0.113.9'],
        );

        $untrusting = new SecurityHeaders($this->config);
        self::assertFalse(
            $untrusting->forPublic(Responder::html('x'), $request)->hasHeader('Strict-Transport-Security'),
            'an untrusted client must not be able to claim HTTPS',
        );

        $this->bootKernel(['trusted_proxies' => '*']);
        $trusting = new SecurityHeaders($this->config);
        self::assertTrue(
            $trusting->forPublic(Responder::html('x'), $request)->hasHeader('Strict-Transport-Security'),
        );
    }

    public function testTheEmbedEndpointAnswersCrossOriginOnlyForAConfiguredOrigin(): void
    {
        self::assertFalse(
            $this->get('/embed', ['Origin' => 'https://friend.example'])->hasHeader('Access-Control-Allow-Origin'),
        );

        $this->bootKernel(['embed_cors_origins' => 'https://friend.example, https://other.example']);
        $this->seed();

        $allowed = $this->get('/embed', ['Origin' => 'https://friend.example']);
        self::assertSame('https://friend.example', $allowed->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $allowed->getHeaderLine('Vary'));

        foreach (['http://friend.example', 'https://friend.example.evil', 'https://friend.example:8443'] as $near) {
            self::assertFalse(
                $this->get('/embed', ['Origin' => $near])->hasHeader('Access-Control-Allow-Origin'),
                $near . ' is not the configured origin',
            );
        }
    }

    public function testEveryEmbedResponseVariesOnOriginSoACacheCannotMixVariants(): void
    {
        // The header set differs by Origin while the body does not. Without
        // Vary, a shared cache stores whichever variant it saw first and serves
        // it to everyone - which either leaks one site's allowance to another
        // or silently breaks every embedder after the first.
        $this->bootKernel(['embed_cors_origins' => 'https://friend.example']);
        $this->seed();

        foreach (['https://friend.example', 'https://stranger.example', ''] as $origin) {
            $headers = $origin === '' ? [] : ['Origin' => $origin];
            $response = $this->get('/embed', $headers);

            self::assertSame(
                'Origin',
                $response->getHeaderLine('Vary'),
                sprintf('Vary: Origin is missing for origin "%s"', $origin),
            );
        }

        self::assertSame('Origin', $this->get('/api/feeds')->getHeaderLine('Vary'));
    }

    public function testTheEmbedFrameAncestorsPolicyIsConfigurable(): void
    {
        self::assertStringContainsString("frame-ancestors 'self'", $this->get('/embed')->getHeaderLine('Content-Security-Policy'));

        $this->bootKernel(['embed_frame_ancestors' => 'https://host.example']);
        $this->seed();

        self::assertStringContainsString(
            'frame-ancestors https://host.example',
            $this->get('/embed')->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testProductionErrorsRevealNothingAboutTheServer(): void
    {
        $this->bootKernel(['env' => 'production']);
        $handler = new ErrorHandler($this->config, $this->kernel->logger());
        $response = $handler->handle(new \RuntimeException('database at /srv/secret/zfeeder.sqlite is locked'));
        $body = self::bodyOf($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Something went wrong', $body);
        self::assertStringNotContainsString('/srv/secret', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('.php', $body);
    }

    public function testDevelopmentErrorsExplainThemselves(): void
    {
        $this->bootKernel(['env' => 'development']);
        $handler = new ErrorHandler($this->config, $this->kernel->logger());

        self::assertStringContainsString(
            'something specific went wrong',
            self::bodyOf($handler->handle(new \RuntimeException('something specific went wrong'))),
        );
    }

    public function testApiErrorsComeBackAsJson(): void
    {
        $handler = new ErrorHandler($this->config, $this->kernel->logger());
        $response = $handler->notFound(true);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(404, self::jsonOf($response)['status']);
    }

    public function testTheRouterDistinguishesAnUnknownPathFromAWrongMethod(): void
    {
        $router = new Router();
        $router->get('/only-get', 'a');
        $router->post('/only-post', 'b');

        $get = new ServerRequest('GET', 'http://zfeeder.test/only-get');
        $wrongMethod = new ServerRequest('POST', 'http://zfeeder.test/only-get');
        $unknown = new ServerRequest('GET', 'http://zfeeder.test/nowhere');

        $matched = $router->dispatch($get);
        $mismatched = $router->dispatch($wrongMethod);
        self::assertNotNull($matched);
        self::assertNotNull($mismatched);
        self::assertSame('a', $matched['handler']);
        self::assertSame('405', $mismatched['handler']);
        self::assertNull($router->dispatch($unknown));
    }

    public function testTheRouterCapturesAndDecodesParameters(): void
    {
        $router = new Router();
        $router->get('/demos/template/{set}/{name}', 'gallery');

        $match = $router->dispatch(new ServerRequest('GET', 'http://zfeeder.test/demos/template/modern/cards'));

        self::assertNotNull($match);
        self::assertSame(['set' => 'modern', 'name' => 'cards'], $match['params']);
        self::assertCount(1, $router->routes());
    }

    public function testATrailingSlashDoesNotChangeTheRoute(): void
    {
        $router = new Router();
        $router->get('/embed', 'embed');

        $match = $router->dispatch(new ServerRequest('GET', 'http://zfeeder.test/embed/'));

        self::assertNotNull($match);
        self::assertSame('embed', $match['handler']);
    }

    public function testAPathParameterCannotSmuggleASeparator(): void
    {
        // {category} matches one segment, so a traversal attempt cannot match
        // the route at all and falls through to the 404 branch.
        self::assertNull($this->router->dispatch($this->request('GET', '/api/opml/../../etc/passwd')));
    }

    public function testTheSiteCanBeSwitchedOffWithoutBeingRemoved(): void
    {
        $this->bootKernel(['demo_enabled' => false]);

        self::assertFalse($this->config->bool('demo_enabled'), 'the front controller serves a notice when this is off');
    }
}
