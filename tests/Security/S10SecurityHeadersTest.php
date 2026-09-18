<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Config\Config;
use Zfeeder\Http\SecurityHeaders;

/**
 * S10 — every response carries the hardening headers: a Content Security Policy
 * with `default-src 'self'`, a fresh nonce per admin request,
 * `frame-ancestors 'none'` for the panel and a configurable value for the
 * embed, plus `X-Content-Type-Options`, `Referrer-Policy` and
 * `Permissions-Policy`. HSTS appears only over HTTPS, and a forwarded protocol
 * is believed only when the deployment says a proxy is in front.
 *
 * Closes 1.6 defect L14: nothing in `newsfeeds/` called `header()` at all
 * except for the 401 challenge at `admin.php:27`.
 *
 * The control lives in `src/Http/SecurityHeaders.php` (the three policies and
 * `isHttps()`/`trustsProxy()`) and
 * `src/Admin/Middleware/SecurityHeadersMiddleware.php`, which mints the nonce
 * before the controller runs so the templates can print it, and applies the
 * policy afterwards so error responses are covered too.
 */
final class S10SecurityHeadersTest extends SecurityTestCase
{
    /** @param array<string, string|int|bool> $overrides */
    private function headers(array $overrides = []): SecurityHeaders
    {
        return new SecurityHeaders(Config::forTesting($overrides, $this->tempDir()));
    }

    private static function serverRequest(string $uri, string $address = self::ADDRESS): ServerRequestInterface
    {
        return new ServerRequest('GET', $uri, [], null, '1.1', ['REMOTE_ADDR' => $address]);
    }

    /** @return array<string, string> the policy split into directive => value */
    private static function directives(string $policy): array
    {
        $out = [];
        foreach (explode(';', $policy) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $space = strpos($part, ' ');
            $out[$space === false ? $part : substr($part, 0, $space)] = $space === false ? '' : trim(substr($part, $space));
        }

        return $out;
    }

    /** @return iterable<string, array{string}> every screen of the panel */
    public static function adminPaths(): iterable
    {
        yield 'main' => ['/admin'];
        yield 'login' => ['/admin/login'];
        yield 'add new' => ['/admin/add-new'];
        yield 'subscriptions' => ['/admin/subscriptions'];
        yield 'config' => ['/admin/config'];
        yield 'import' => ['/admin/import'];
        yield 'updates' => ['/admin/updates'];
    }

    #[DataProvider('adminPaths')]
    public function testEveryPanelScreenCarriesTheFullSetOfHeaders(string $path): void
    {
        $this->signIn();
        $response = $this->send('GET', $path);

        $policy = self::directives($response->getHeaderLine('Content-Security-Policy'));

        self::assertSame("'self'", $policy['default-src'] ?? null, $path . ' has no default-src');
        self::assertSame("'none'", $policy['frame-ancestors'] ?? null, $path . ' can be framed');
        self::assertSame("'none'", $policy['base-uri'] ?? null);
        self::assertSame("'none'", $policy['object-src'] ?? null);
        self::assertSame("'self'", $policy['form-action'] ?? null);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), $path);
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'), $path);
        self::assertStringContainsString('geolocation=()', $response->getHeaderLine('Permissions-Policy'), $path);
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'), $path);
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), $path);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), $path . ' is cacheable');
    }

    public function testTheNonceIsDifferentOnEveryRequestAndIsTheOneOnThePage(): void
    {
        $this->signIn();

        $first = $this->send('GET', '/admin');
        $second = $this->send('GET', '/admin');

        $firstNonce = self::nonceOf($first->getHeaderLine('Content-Security-Policy'));
        $secondNonce = self::nonceOf($second->getHeaderLine('Content-Security-Policy'));

        self::assertNotSame('', $firstNonce, 'the admin policy carries no nonce');
        self::assertNotSame($firstNonce, $secondNonce, 'the same nonce was reused for a second request');
        self::assertGreaterThanOrEqual(16, strlen($firstNonce), 'the nonce is too short to be unguessable');

        // A nonce that is not on the page would break the panel; a nonce the
        // page carries but the policy does not would disable the protection.
        self::assertStringContainsString('nonce="' . $firstNonce . '"', self::bodyOf($first));
        self::assertStringNotContainsString('nonce="' . $secondNonce . '"', self::bodyOf($first));
    }

    public function testTheAdminPolicyDoesNotAllowUnsafeInlineScript(): void
    {
        $this->signIn();
        $policy = self::directives($this->send('GET', '/admin')->getHeaderLine('Content-Security-Policy'));

        $scriptSrc = $policy['script-src'] ?? '';
        self::assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        self::assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
        self::assertStringContainsString("'self'", $scriptSrc);
        self::assertStringContainsString("'nonce-", $scriptSrc);
    }

    public function testAnErrorResponseFromThePanelIsProtectedToo(): void
    {
        // The middleware applies the policy to whatever the controller returns,
        // including a refusal, so a 403 page is not an unprotected island.
        $this->signIn();
        $response = $this->send('POST', '/admin/config', ['action' => 'save']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testThePublicSideHasItsOwnPolicy(): void
    {
        $response = $this->headers()->forPublic(new Response(200), self::serverRequest('http://zfeeder.test/'));
        $policy = self::directives($response->getHeaderLine('Content-Security-Policy'));

        self::assertSame("'self'", $policy['default-src'] ?? null);
        self::assertSame("'self'", $policy['script-src'] ?? null, 'the public side allows script from elsewhere');
        self::assertSame("'none'", $policy['object-src'] ?? null);
        self::assertSame("'none'", $policy['base-uri'] ?? null);
        self::assertSame("'self'", $policy['frame-ancestors'] ?? null, 'the default frame-ancestors is not self');
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('camera=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testTheEmbedPolicyHonoursTheConfiguredFrameAncestors(): void
    {
        $configured = $this->headers(['embed_frame_ancestors' => 'https://host.example https://other.example'])
            ->forEmbed(new Response(200), self::serverRequest('http://zfeeder.test/embed'));

        $policy = self::directives($configured->getHeaderLine('Content-Security-Policy'));
        self::assertSame('https://host.example https://other.example', $policy['frame-ancestors'] ?? null);

        // An empty value must fall back to the safe default rather than to no
        // directive at all.
        $empty = $this->headers(['embed_frame_ancestors' => ''])
            ->forEmbed(new Response(200), self::serverRequest('http://zfeeder.test/embed'));
        self::assertSame("'self'", self::directives($empty->getHeaderLine('Content-Security-Policy'))['frame-ancestors'] ?? null);
    }

    public function testStrictTransportSecurityAppearsOnlyOverHttps(): void
    {
        $plain = $this->headers()->forPublic(new Response(200), self::serverRequest('http://zfeeder.test/'));
        self::assertFalse($plain->hasHeader('Strict-Transport-Security'), 'HSTS was sent over plain HTTP');

        $secure = $this->headers()->forPublic(new Response(200), self::serverRequest('https://zfeeder.test/'));
        self::assertStringContainsString('max-age=31536000', $secure->getHeaderLine('Strict-Transport-Security'));
        self::assertStringContainsString('includeSubDomains', $secure->getHeaderLine('Strict-Transport-Security'));
    }

    public function testAForwardedProtocolIsBelievedOnlyWhenAProxyIsDeclared(): void
    {
        $claim = self::serverRequest('http://zfeeder.test/')->withHeader('X-Forwarded-Proto', 'https');

        // No trusted proxies: any client could claim HTTPS, so none are believed.
        $untrusted = $this->headers()->forPublic(new Response(200), $claim);
        self::assertFalse($untrusted->hasHeader('Strict-Transport-Security'), 'a client was believed about HTTPS');

        // A proxy that is not this peer: still not believed.
        $wrongPeer = $this->headers(['trusted_proxies' => '198.51.100.1'])->forPublic(new Response(200), $claim);
        self::assertFalse($wrongPeer->hasHeader('Strict-Transport-Security'), 'an untrusted peer was believed');

        // The declared peer, and the wildcard used behind a platform balancer.
        $named = $this->headers(['trusted_proxies' => self::ADDRESS])->forPublic(new Response(200), $claim);
        self::assertTrue($named->hasHeader('Strict-Transport-Security'), 'the declared proxy was not believed');

        $wildcard = $this->headers(['trusted_proxies' => '*'])->forPublic(new Response(200), $claim);
        self::assertTrue($wildcard->hasHeader('Strict-Transport-Security'));
    }

    public function testAForwardedProtocolOfHttpNeverUpgradesTheConnection(): void
    {
        $claim = self::serverRequest('http://zfeeder.test/')->withHeader('X-Forwarded-Proto', 'http');
        $response = $this->headers(['trusted_proxies' => '*'])->forPublic(new Response(200), $claim);

        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testTheFrontControllerAppliesAPolicyToEveryPublicRoute(): void
    {
        // The policies above are only worth anything if the front controller
        // uses them. This asserts the wiring rather than re-implementing it.
        $index = self::readFile(self::projectRoot() . '/public/index.php');

        foreach (['demo.index', 'demo.show', 'demo.template', 'public.opml'] as $handler) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($handler, '/') . '\'\s*=>\s*\$headers->forPublic\(/',
                $index,
                $handler . ' is served without the public policy',
            );
        }

        foreach (['public.embed', 'public.api', 'public.preflight'] as $handler) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($handler, '/') . '\'\s*=>\s*\$headers->forEmbed\(/',
                $index,
                $handler . ' is served without the embed policy',
            );
        }

        self::assertStringContainsString('$headers->forPublic($errors->handle($e', $index, 'error responses carry no policy');
    }

    private static function nonceOf(string $policy): string
    {
        $matches = [];
        if (preg_match("/'nonce-([^']+)'/", $policy, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }
}
