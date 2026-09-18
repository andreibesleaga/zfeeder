<?php

declare(strict_types=1);

namespace Zfeeder\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Admin\Auth\Csrf;
use Zfeeder\Admin\Auth\PasswordHasher;
use Zfeeder\Admin\Auth\PhpSession;
use Zfeeder\Admin\Auth\SessionAuth;
use Zfeeder\Admin\Auth\SessionInterface;
use Zfeeder\Admin\Controller\AddFeedController;
use Zfeeder\Admin\Controller\ConfigController;
use Zfeeder\Admin\Controller\ImportController;
use Zfeeder\Admin\Controller\LoginController;
use Zfeeder\Admin\Controller\LogoutController;
use Zfeeder\Admin\Controller\MainController;
use Zfeeder\Admin\Controller\SubscriptionsController;
use Zfeeder\Admin\Controller\UpdatesController;
use Zfeeder\Admin\Middleware\DemoMode;
use Zfeeder\Admin\Middleware\Pipeline;
use Zfeeder\Admin\Middleware\RequireAuth;
use Zfeeder\Admin\Middleware\SecurityHeadersMiddleware;
use Zfeeder\Http\Responder;
use Zfeeder\Http\SecurityHeaders;
use Zfeeder\Kernel;

/**
 * Turns a route handler name into a response: opens the session, builds the
 * request context, runs the middleware chain and calls the controller.
 *
 * The front controller only ever needs two lines of this file
 * (`AdminRoutes::register($router)` and `handle($handler, $request, $params)`),
 * which keeps the panel a self-contained unit that can be left out of a build
 * entirely — `admin_enabled` off, and nothing here is ever constructed.
 *
 * The session backend is a constructor parameter with a production default so
 * that the integration tests can run the whole chain, including sign-in and
 * session regeneration, against an array.
 */
final class AdminDispatcher
{
    private readonly AdminContext $context;

    public function __construct(
        private readonly Kernel $kernel,
        ?SessionInterface $session = null,
    ) {
        $sessionBackend = $session ?? new PhpSession();
        $auth = new SessionAuth(
            $kernel->config(),
            $sessionBackend,
            new PasswordHasher(),
            fn (): \DateTimeImmutable => $this->kernel->clock(),
        );

        $this->context = new AdminContext(
            $kernel->config(),
            $sessionBackend,
            $auth,
            new Csrf($sessionBackend),
        );
    }

    /** Exposed so a front controller can reuse the context (flash messages, nonce). */
    public function context(): AdminContext
    {
        return $this->context;
    }

    /** @param array<string, string> $params route parameters, as captured by the Router */
    public function handle(string $handlerName, ServerRequestInterface $request, array $params = []): ResponseInterface
    {
        $config = $this->kernel->config();

        if (!$config->bool('admin_enabled')) {
            return Responder::text('The administration panel is switched off.', 404);
        }
        if (trim($config->string('admin_password_hash')) === '') {
            // No password has ever been set: there is nothing to sign in to,
            // and serving the form would invite a guess at an empty secret.
            return Responder::text(
                'The administration panel has no password yet. '
                . 'Run "bin/zfeeder hash-password" and put the result in ZF_ADMIN_PASSWORD_HASH '
                . 'or in the configuration file.',
                503,
            );
        }

        $controller = $this->controllerFor($handlerName, $params);
        if ($controller === null) {
            return Responder::text('Not found.', 404);
        }

        $this->context->handler = $handlerName;
        $this->context->auth->start($request);

        $middleware = [
            new SecurityHeadersMiddleware(new SecurityHeaders($config), $this->context),
            new DemoMode(
                $config->bool('demo_mode'),
                $handlerName,
                AdminRoutes::DEMO_EXEMPT_HANDLERS,
                $this->context->url('/admin'),
            ),
        ];

        if (!in_array($handlerName, AdminRoutes::PUBLIC_HANDLERS, true)) {
            $middleware[] = new RequireAuth($this->context);
        }

        return (new Pipeline($middleware, $controller))->handle($request);
    }

    /**
     * @return (callable(ServerRequestInterface): ResponseInterface)|null
     *
     * @param array<string, string> $params
     */
    private function controllerFor(string $handlerName, array $params = []): ?callable
    {
        $kernel = $this->kernel;
        $context = $this->context;

        return match ($handlerName) {
            AdminRoutes::MAIN => static fn (ServerRequestInterface $r): ResponseInterface => (new MainController($kernel, $context))->show($r),
            AdminRoutes::LOGIN => static fn (ServerRequestInterface $r): ResponseInterface => (new LoginController($kernel, $context))->show($r),
            AdminRoutes::LOGIN_SUBMIT => static fn (ServerRequestInterface $r): ResponseInterface => (new LoginController($kernel, $context))->submit($r),
            AdminRoutes::LOGOUT => static fn (ServerRequestInterface $r): ResponseInterface => (new LogoutController($kernel, $context))->submit($r),
            AdminRoutes::ADD_FEED => static fn (ServerRequestInterface $r): ResponseInterface => (new AddFeedController($kernel, $context))->show($r),
            AdminRoutes::ADD_FEED_SUBMIT => static fn (ServerRequestInterface $r): ResponseInterface => (new AddFeedController($kernel, $context))->submit($r),
            AdminRoutes::PARTIAL_DISCOVER => static fn (ServerRequestInterface $r): ResponseInterface => (new AddFeedController($kernel, $context))->discoverPartial($r),
            AdminRoutes::SUBSCRIPTIONS => static fn (ServerRequestInterface $r): ResponseInterface => (new SubscriptionsController($kernel, $context))->show($r),
            AdminRoutes::SUBSCRIPTIONS_SUBMIT, AdminRoutes::PARTIAL_SUBSCRIPTIONS_SUBMIT => static fn (ServerRequestInterface $r): ResponseInterface => (new SubscriptionsController($kernel, $context))->submit($r),
            AdminRoutes::PARTIAL_SUBSCRIPTIONS => static fn (ServerRequestInterface $r): ResponseInterface => (new SubscriptionsController($kernel, $context))->tablePartial($r),
            AdminRoutes::CONFIG => static fn (ServerRequestInterface $r): ResponseInterface => (new ConfigController($kernel, $context))->show($r),
            AdminRoutes::CONFIG_SUBMIT => static fn (ServerRequestInterface $r): ResponseInterface => (new ConfigController($kernel, $context))->submit($r),
            AdminRoutes::IMPORT => static fn (ServerRequestInterface $r): ResponseInterface => (new ImportController($kernel, $context))->show($r),
            AdminRoutes::IMPORT_SUBMIT => static fn (ServerRequestInterface $r): ResponseInterface => (new ImportController($kernel, $context))->submit($r),
            AdminRoutes::EXPORT => static fn (ServerRequestInterface $r): ResponseInterface => (new ImportController($kernel, $context))->export($r, $params['category'] ?? ''),
            AdminRoutes::UPDATES => static fn (ServerRequestInterface $r): ResponseInterface => (new UpdatesController($kernel, $context))->show($r),
            default => null,
        };
    }
}
