<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signing out.
 *
 * It is a POST with a token, not the `?zfaction=logout` link 1.6 used: a link
 * can be triggered by any image tag on any page the administrator happens to
 * be reading, and being logged out by a stranger is a real, if small, denial
 * of service. Demo mode lets this one POST through, because it writes nothing.
 */
final class LogoutController extends AbstractController
{
    protected const string SCREEN = 'logout';

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfFailure($request);
        }

        $this->context->auth->logout();

        return $this->redirect('/admin/login');
    }
}
