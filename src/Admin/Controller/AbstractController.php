<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Admin\Auth\Csrf;
use Zfeeder\Admin\Http\GuardedFetch;
use Zfeeder\Admin\View\TwigFactory;
use Zfeeder\Http\Responder;
use Zfeeder\Kernel;

/**
 * What every panel screen needs: a template environment, the CSRF check, and
 * the two or three ways a form value can arrive.
 *
 * The screen name is a constant rather than something derived from the route,
 * because it drives the `aria-current` marker in the menu and that has to stay
 * right when a screen is reached by a POST or by an htmx partial.
 */
abstract class AbstractController
{
    protected const string SCREEN = '';

    public function __construct(
        protected readonly Kernel $kernel,
        protected readonly AdminContext $context,
    ) {
    }

    /**
     * @param array<string, mixed>  $vars
     * @param array<string, string> $headers
     */
    protected function render(string $template, array $vars = [], int $status = 200, array $headers = []): ResponseInterface
    {
        $twig = TwigFactory::create($this->kernel->config(), $this->context);

        $common = [
            'screen' => static::SCREEN,
            'user' => $this->context->auth->user(),
            'csrf_token' => $this->context->csrf->token(),
            'flashes' => $this->context->takeFlashes(),
            'menu' => $this->menu(),
            'title' => $vars['title'] ?? 'Administration',
        ];

        return Responder::html($twig->render($template, $vars + $common), $status, $headers);
    }

    /**
     * A fragment for htmx. Same environment, no layout, and the same variables
     * the full page would have had, so one template serves both paths.
     *
     * @param array<string, mixed> $vars
     */
    protected function partial(string $template, array $vars = [], int $status = 200): ResponseInterface
    {
        $twig = TwigFactory::create($this->kernel->config(), $this->context);

        $common = [
            'screen' => static::SCREEN,
            'csrf_token' => $this->context->csrf->token(),
            'flashes' => $this->context->takeFlashes(),
        ];

        return Responder::html($twig->render($template, $vars + $common), $status);
    }

    protected function redirect(string $path): ResponseInterface
    {
        return Responder::redirect($this->context->url($path));
    }

    /**
     * The panel's navigation, in the order the 1.6 panel listed it.
     *
     * @return list<array{key: string, href: string, label: string, description: string}>
     */
    protected function menu(): array
    {
        return [
            ['key' => 'main', 'href' => $this->context->url('/admin'), 'label' => 'main', 'description' => 'panel overview and status'],
            ['key' => 'addfeed', 'href' => $this->context->url('/admin/add-new'), 'label' => 'add new', 'description' => 'add new feeds (channels)'],
            ['key' => 'subscriptions', 'href' => $this->context->url('/admin/subscriptions'), 'label' => 'subscriptions', 'description' => 'modify or delete news feeds'],
            ['key' => 'config', 'href' => $this->context->url('/admin/config'), 'label' => 'config', 'description' => 'change the zFeeder configuration'],
            ['key' => 'import', 'href' => $this->context->url('/admin/import'), 'label' => 'import feed list', 'description' => 'import feeds from an OPML feed list'],
            ['key' => 'updates', 'href' => $this->context->url('/admin/updates'), 'label' => 'updates', 'description' => 'check for a newer zFeeder'],
        ];
    }

    // ---- request helpers -------------------------------------------------

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /** @return array<string, mixed> */
    protected function query(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    /** @param array<string, mixed> $source */
    protected function field(array $source, string $name, string $default = ''): string
    {
        $value = $source[$name] ?? null;

        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /** @param array<string, mixed> $source */
    protected function intField(array $source, string $name, int $default): int
    {
        $raw = $this->field($source, $name);

        return $raw === '' || preg_match('/^-?\d+$/', $raw) !== 1 ? $default : (int) $raw;
    }

    /** @param array<string, mixed> $source */
    protected function checked(array $source, string $name): bool
    {
        $value = $source[$name] ?? null;

        return in_array(is_string($value) ? strtolower($value) : $value, ['1', 'on', 'yes', 'true', true, 1], true);
    }

    protected function isHtmx(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('HX-Request') !== '';
    }

    // ---- cross-site request forgery -------------------------------------

    protected function csrfValid(ServerRequestInterface $request): bool
    {
        $body = $this->body($request);
        $submitted = $this->field($body, Csrf::FIELD);
        if ($submitted === '') {
            $header = $request->getHeaderLine('X-CSRF-Token');
            $submitted = trim($header);
        }

        return $this->context->csrf->validate($submitted === '' ? null : $submitted);
    }

    protected function csrfFailure(ServerRequestInterface $request): ResponseInterface
    {
        $message = 'This form could not be verified. It was probably left open for a long time. '
            . 'Please reload the page and try once more.';

        if ($this->isHtmx($request)) {
            return Responder::text($message, 403);
        }

        return $this->render('denied.twig', [
            'title' => 'Request refused',
            'heading' => 'Request refused',
            'message' => $message,
        ], 403);
    }

    // ---- services --------------------------------------------------------

    /** The guarded HTTP client for the panel's own outbound requests. */
    protected function guardedFetch(int $maxBytes = 1048576): GuardedFetch
    {
        $config = $this->kernel->config();

        return new GuardedFetch(
            $this->kernel->urlGuard(),
            $this->kernel->httpClient(),
            $config->userAgent(),
            $config->int('fetch_timeout'),
            $maxBytes,
        );
    }

    /** @return list<string> */
    protected function categoryNames(): array
    {
        return $this->kernel->subscriptions()->categories();
    }

    /** The category to work on: the requested one when it exists, else the default. */
    protected function resolveCategoryName(string $requested): string
    {
        $store = $this->kernel->subscriptions();
        if ($requested !== '' && \Zfeeder\Subscription\Category::isValidName($requested) && $store->has($requested)) {
            return $requested;
        }

        $default = $this->kernel->config()->string('default_category');
        if ($store->has($default)) {
            return $default;
        }

        $categories = $store->categories();

        return $categories[0] ?? $default;
    }
}
