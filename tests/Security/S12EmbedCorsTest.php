<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Config\Config;
use Zfeeder\Http\PublicController;
use Zfeeder\Http\SecurityHeaders;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Feed;

/**
 * S12 — the embed endpoints answer cross-origin only for an origin the operator
 * configured, exactly and in full, and the JSON endpoint carries feed content
 * and nothing else.
 *
 * This is a 2.0 surface: 1.6 had no JSON API and no CORS, so there is no 2004
 * defect to close — the requirement exists because an embeddable endpoint with
 * a permissive `Access-Control-Allow-Origin` would hand any site the ability to
 * read this installation's responses with the visitor's credentials.
 *
 * The control lives in `src/Http/SecurityHeaders::forEmbed()` (exact membership
 * of `embed_cors_origins`, plus `Vary: Origin`) and
 * `src/Http/Responder::json()` (`JSON_INVALID_UTF8_SUBSTITUTE`, so hostile
 * bytes cannot break the encoding).
 */
final class S12EmbedCorsTest extends SecurityTestCase
{
    private const string ALLOWED = 'https://host.example';

    /** A feed title designed to break out of a JSON string, a script block and an HTML attribute at once. */
    private const string HOSTILE = '</script><script>alert(1)</script>", "injected_key": "owned';

    /** @param array<string, string|int|bool> $overrides */
    private function headers(array $overrides = []): SecurityHeaders
    {
        return new SecurityHeaders(Config::forTesting($overrides, $this->tempDir()));
    }

    private static function embedRequest(string $origin = ''): ServerRequestInterface
    {
        $request = new ServerRequest('GET', 'http://zfeeder.test/embed', [], null, '1.1', ['REMOTE_ADDR' => self::ADDRESS]);

        return $origin === '' ? $request : $request->withHeader('Origin', $origin);
    }

    /** @return iterable<string, array{string}> origins that are not the configured one */
    public static function nearMissOrigins(): iterable
    {
        yield 'suffix' => ['https://host.example.evil.test'];
        yield 'prefix' => ['https://evil-host.example'];
        yield 'subdomain' => ['https://sub.host.example'];
        yield 'different scheme' => ['http://host.example'];
        yield 'different port' => ['https://host.example:8443'];
        yield 'trailing slash' => ['https://host.example/'];
        yield 'trailing dot' => ['https://host.example.'];
        yield 'uppercase' => ['https://HOST.EXAMPLE'];
        yield 'with a path' => ['https://host.example/embed'];
        yield 'null origin' => ['null'];
        yield 'wildcard' => ['*'];
    }

    public function testAnUnconfiguredOriginGetsNoCorsHeaderAtAll(): void
    {
        // The default is an empty list, which must mean "nobody", not "anybody".
        $response = $this->headers()->forEmbed(new Response(200), self::embedRequest(self::ALLOWED));

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
    }

    public function testTheConfiguredOriginIsEchoedWithVaryOrigin(): void
    {
        $response = $this->headers(['embed_cors_origins' => self::ALLOWED])
            ->forEmbed(new Response(200), self::embedRequest(self::ALLOWED));

        self::assertSame(self::ALLOWED, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'), 'a shared cache could serve this to another origin');
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'), 'credentials are allowed cross-origin');
    }

    public function testOneOfSeveralConfiguredOriginsIsEchoed(): void
    {
        $headers = $this->headers(['embed_cors_origins' => 'https://a.example, https://b.example ,https://c.example']);

        foreach (['https://a.example', 'https://b.example', 'https://c.example'] as $origin) {
            $response = $headers->forEmbed(new Response(200), self::embedRequest($origin));
            self::assertSame($origin, $response->getHeaderLine('Access-Control-Allow-Origin'), $origin);
        }
    }

    #[DataProvider('nearMissOrigins')]
    public function testANearMissOriginIsRefused(string $origin): void
    {
        $response = $this->headers(['embed_cors_origins' => self::ALLOWED])
            ->forEmbed(new Response(200), self::embedRequest($origin));

        self::assertFalse(
            $response->hasHeader('Access-Control-Allow-Origin'),
            $origin . ' was answered "' . $response->getHeaderLine('Access-Control-Allow-Origin') . '"',
        );
    }

    public function testNoRequestWithoutAnOriginIsAnsweredWithACorsHeader(): void
    {
        $response = $this->headers(['embed_cors_origins' => self::ALLOWED])
            ->forEmbed(new Response(200), self::embedRequest());

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testTheWildcardIsNeverEchoedEvenWhenItIsConfigured(): void
    {
        // `*` in the list would be an operator error; it must not become an
        // origin that matches everything. The value is compared as a string, so
        // only a request whose Origin is literally `*` could match it — which
        // no browser sends.
        $response = $this->headers(['embed_cors_origins' => '*'])
            ->forEmbed(new Response(200), self::embedRequest('https://evil.example'));

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    // ---- the JSON endpoint --------------------------------------------------

    public function testHostileFeedTextCannotBreakOutOfTheJsonStructure(): void
    {
        $body = $this->apiBody();

        /** @var array{category: string, generator: string, channels: list<array{title: string, items: list<array{title: string}>}>} $decoded */
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

        // The document has exactly the keys the endpoint builds: a feed title
        // full of quotes and braces stayed inside its own string.
        self::assertSame(['category', 'generator', 'channels'], array_keys($decoded));
        self::assertCount(1, $decoded['channels']);
        self::assertSame('Title ' . self::HOSTILE, $decoded['channels'][0]['title']);
        self::assertSame('Item ' . self::HOSTILE, $decoded['channels'][0]['items'][0]['title']);

        // And the response is labelled as data and told not to be sniffed, so a
        // browser never treats it as a document.
        $response = $this->headers()->forEmbed(
            (new PublicController($this->kernel))->api(self::embedRequest()),
            self::embedRequest(),
        );
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testInvalidUtf8FromAFeedCannotBreakTheEncoding(): void
    {
        // `JSON_INVALID_UTF8_SUBSTITUTE` is what keeps a feed with broken bytes
        // from turning the whole response into `{"error":"encoding failed"}`.
        $response = \Zfeeder\Http\Responder::json(['title' => "bad \xB1\x31 bytes", 'ok' => true]);
        $body = self::bodyOf($response);

        self::assertJson($body);
        /** @var array{ok: bool} $decoded */
        $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['ok']);
    }

    /**
     * OPEN GAP (low severity, defence in depth) — `Responder::json()` does not
     * pass `JSON_HEX_TAG`, so `</script>` from a feed title appears literally
     * in the body.
     *
     * Nothing exploitable ships today: the response is `application/json` with
     * `nosniff`, and no template or demo page inlines `/api/feeds` into a
     * `<script>` block. It becomes an XSS the moment somebody does, which is
     * what the flag exists to prevent.
     */
    public function testTheJsonBodyCannotCloseAScriptElement(): void
    {
        $body = $this->apiBody();

        // The response is served as application/json with nosniff, so this
        // cannot be exploited today. Encoding the tag characters means it
        // still cannot be if the JSON is ever inlined into a page.
        self::assertStringNotContainsString('</script>', $body);
        self::assertStringNotContainsString('<', $body);
        self::assertStringNotContainsString('>', $body);
        self::assertStringContainsString('\u003C', $body, 'the characters are escaped, not deleted');

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'and the response is still valid JSON');
    }

    public function testTheJsonEndpointLeaksNoConfigurationPathOrHash(): void
    {
        $body = $this->apiBody();

        self::assertStringNotContainsString($this->config->dataDir(), $body, 'the data directory is in the response');
        self::assertStringNotContainsString('$argon2', $body, 'the password hash is in the response');
        self::assertStringNotContainsString(self::passwordHash(), $body);
        self::assertStringNotContainsString('admin_password_hash', $body);
        self::assertStringNotContainsString('refresh_key', $body);
        self::assertStringNotContainsString('super-secret-refresh-key', $body);
        self::assertStringNotContainsString(self::projectRoot(), $body);
        self::assertStringNotContainsString('.opml', $body);
    }

    public function testTheJsonEndpointIsClosedWhenTheApiIsSwitchedOff(): void
    {
        $this->bootKernel(['api_enabled' => false]);
        $this->seedCategory();

        $response = (new PublicController($this->kernel))->api(self::embedRequest());

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('disabled', self::bodyOf($response));
    }

    public function testThePublicOpmlExportIsClosedUnlessTheOperatorOpenedIt(): void
    {
        $controller = new PublicController($this->kernel);

        $closed = $controller->opml(self::embedRequest(), self::CATEGORY);
        self::assertSame(404, $closed->getStatusCode());
        self::assertStringNotContainsString('<opml', self::bodyOf($closed));

        $this->bootKernel(['opml_export_public' => true]);
        $this->seedCategory();
        $open = (new PublicController($this->kernel))->opml(self::embedRequest(), self::CATEGORY);
        self::assertSame(200, $open->getStatusCode());
        self::assertStringContainsString('<opml', self::bodyOf($open));
    }

    /** The `/api/feeds` body for a category holding one hostile feed. */
    private function apiBody(): string
    {
        $this->bootKernel([
            'api_enabled' => true,
            'refresh_mode' => 'offline',
            'refresh_key' => 'super-secret-refresh-key',
        ]);
        $this->seedCategory();

        $url = 'https://feed.example/rss.xml';
        $this->withFeeds([new Feed($url, 'Example', '', '', 1, 60, 3, true)]);

        $hostile = self::HOSTILE;
        $this->kernel->cache()->put(new CacheEntry(
            $url,
            '<?xml version="1.0"?><rss version="2.0"><channel>'
            . '<title>Title ' . htmlspecialchars($hostile, ENT_QUOTES | ENT_XML1) . '</title>'
            . '<link>https://feed.example/</link><description>d</description>'
            . '<item><title>Item ' . htmlspecialchars($hostile, ENT_QUOTES | ENT_XML1) . '</title>'
            . '<link>https://feed.example/1</link></item></channel></rss>',
            new \DateTimeImmutable(self::NOW),
        ));

        $response = (new PublicController($this->kernel))->api(self::embedRequest());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        return self::bodyOf($response);
    }
}
