<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Http\Responder;

/**
 * Everything but the login form needs a signed-in administrator.
 *
 * An anonymous request is redirected rather than refused, because the usual
 * cause is an expired session on a bookmarked page. htmx requests get the
 * redirect as a header instead: a 303 inside an `hx-post` would be followed
 * by the browser and the login page swapped into a table cell.
 */
final class RequireAuth implements MiddlewareInterface
{
    public function __construct(private readonly AdminContext $context)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->context->auth->isAuthenticated()) {
            return $handler->handle($request);
        }

        $login = $this->context->url('/admin/login');

        if ($request->getHeaderLine('HX-Request') !== '') {
            return Responder::text('Your session has ended. Please sign in again.', 401, [
                'HX-Redirect' => $login,
            ]);
        }

        return Responder::redirect($login);
    }
}
