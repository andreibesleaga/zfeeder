<?php

declare(strict_types=1);

namespace Zfeeder\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Config\Config;

/**
 * Response hardening headers.
 *
 * Two policies, because the two audiences differ: the admin panel is a
 * first-party application and gets a strict same-origin policy with a nonce
 * for its inline bootstrap; the embed endpoint is meant to be framed by other
 * sites, so its frame-ancestors value is configuration rather than a constant.
 */
final class SecurityHeaders
{
    public function __construct(private readonly Config $config)
    {
    }

    public static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public function forAdmin(ResponseInterface $response, ServerRequestInterface $request, string $nonce): ResponseInterface
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $nonce . "'",
            "style-src 'self' 'nonce-" . $nonce . "'",
            "img-src 'self' data: https:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "object-src 'none'",
        ]);

        return $this->common($response->withHeader('Content-Security-Policy', $csp), $request)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function forPublic(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https: http:",
            'frame-ancestors ' . $this->frameAncestors(),
            "base-uri 'none'",
            "object-src 'none'",
        ]);

        return $this->common($response->withHeader('Content-Security-Policy', $csp), $request);
    }

    public function forEmbed(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->forPublic($response, $request);
        $origin = $request->getHeaderLine('Origin');
        $allowed = $this->config->list('embed_cors_origins');

        // `Vary: Origin` goes on every embed response, matched or not, because
        // the response body is the same but its headers are not. A shared cache
        // that did not vary would store whichever variant it saw first: the
        // no-CORS response for an origin that is not on the list would then be
        // served to one that is, silently breaking every other site's embed,
        // and the reverse would hand one site's allowance to another.
        $response = $response->withHeader('Vary', 'Origin');

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
                ->withHeader('Access-Control-Max-Age', '600');
        }

        return $response;
    }

    private function common(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(), usb=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('X-Zfeeder-Version', \Zfeeder\Version::NUMBER);

        if ($this->isHttps($request)) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function frameAncestors(): string
    {
        $value = trim($this->config->string('embed_frame_ancestors'));

        return $value !== '' ? $value : "'self'";
    }

    public function isHttps(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }
        if ($this->trustsProxy($request)) {
            $forwarded = strtolower($request->getHeaderLine('X-Forwarded-Proto'));
            if ($forwarded !== '') {
                return str_starts_with($forwarded, 'https');
            }
        }

        return false;
    }

    /**
     * Forwarded headers are only believed when the deployment says a proxy is
     * in front. Trusting them unconditionally would let any client claim HTTPS
     * and, with it, a Secure cookie on a plaintext connection.
     */
    private function trustsProxy(ServerRequestInterface $request): bool
    {
        $trusted = $this->config->list('trusted_proxies');
        if ($trusted === []) {
            return false;
        }
        if (in_array('*', $trusted, true)) {
            return true;
        }
        $server = $request->getServerParams();
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';

        return $remote !== '' && in_array($remote, $trusted, true);
    }
}
