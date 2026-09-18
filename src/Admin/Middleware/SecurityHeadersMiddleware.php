<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Http\SecurityHeaders;

/**
 * Mints one nonce per request, publishes it to the templates and applies the
 * admin policy to whatever comes back.
 *
 * The nonce is generated before the controller runs because the templates have
 * to print it on the one script tag the panel loads; it is applied to the
 * response afterwards so that error responses are protected too.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SecurityHeaders $headers,
        private readonly AdminContext $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->context->nonce === '') {
            $this->context->nonce = SecurityHeaders::nonce();
        }

        return $this->headers->forAdmin($handler->handle($request), $request, $this->context->nonce);
    }
}
