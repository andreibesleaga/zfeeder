<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Admin\Auth\RateLimiter;

/**
 * The sign-in screen.
 *
 * Three things here are deliberate and easy to get wrong.
 *
 * The error message never says which half was wrong. "Unknown user" and "wrong
 * password" together are a free account enumeration oracle, and the panel has
 * exactly one account to enumerate.
 *
 * The limiter is keyed on the address *and* the submitted name, so one
 * attacker cannot lock the real administrator out by hammering the form from
 * elsewhere, and a shared office address does not lock out a colleague.
 *
 * A throttled attempt answers 429 with `Retry-After` and never touches the
 * password hash: Argon2id is expensive by design, and an unthrottled login
 * form is a denial of service amplifier pointed at your own CPU.
 */
final class LoginController extends AbstractController
{
    protected const string SCREEN = 'login';

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->context->auth->isAuthenticated()) {
            return $this->redirect('/admin');
        }

        return $this->form($request, '', '');
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfFailure($request);
        }

        $body = $this->body($request);
        $user = $this->field($body, 'admin_user');
        $password = $this->field($body, 'admin_pass');

        $limiter = $this->limiter();
        $key = $this->limiterKey($request, $user);

        if (!$limiter->check($key)) {
            $seconds = $limiter->retryAfter($key);

            return $this->form(
                $request,
                $user,
                sprintf('Too many sign-in attempts. Please wait %d seconds and try again.', $seconds),
                429,
                ['Retry-After' => (string) $seconds],
            );
        }

        if (!$this->context->auth->attempt($user, $password)) {
            $limiter->record($key);
            $this->kernel->logger()->warning('Admin sign-in failed', [
                'user' => $user,
                'address' => $this->clientAddress($request),
            ]);

            return $this->form($request, $user, 'That user name and password do not match.', 401);
        }

        $limiter->clear($key);
        $this->context->csrf->rotate();
        $this->context->addFlash(AdminContext::LEVEL_SUCCESS, 'Signed in.');
        $this->kernel->logger()->info('Admin signed in', ['user' => $user]);

        return $this->redirect('/admin');
    }

    /** @param array<string, string> $headers */
    private function form(
        ServerRequestInterface $request,
        string $user,
        string $error,
        int $status = 200,
        array $headers = [],
    ): ResponseInterface {
        return $this->render('login.twig', [
            'title' => 'Sign in',
            'admin_user' => $user,
            'error' => $error,
        ], $status, $headers);
    }

    private function limiter(): RateLimiter
    {
        $config = $this->kernel->config();

        return new RateLimiter(
            $config->dataDir() . '/ratelimit',
            $config->int('login_max_attempts'),
            $config->int('login_window_seconds'),
            fn (): \DateTimeImmutable => $this->kernel->clock(),
        );
    }

    private function limiterKey(ServerRequestInterface $request, string $user): string
    {
        return $this->clientAddress($request) . '|' . strtolower($user);
    }

    /**
     * The address to hold responsible. A forwarded header is believed only
     * when the deployment declared a proxy, for the same reason the security
     * headers do: otherwise every client can pick its own rate limit bucket.
     */
    private function clientAddress(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : 'unknown';

        $trusted = $this->kernel->config()->list('trusted_proxies');
        if ($trusted === []) {
            return $remote;
        }
        if (!in_array('*', $trusted, true) && !in_array($remote, $trusted, true)) {
            return $remote;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if (trim($forwarded) === '') {
            return $remote;
        }

        $first = trim(explode(',', $forwarded)[0]);

        return $first === '' ? $remote : $first;
    }
}
