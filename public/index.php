<?php

/**
 * zFeeder front controller.
 *
 * Serves the demonstration site, the embed endpoints, the operational
 * endpoints and the administration panel. The web server sends every request
 * that is not a real file here; see deploy/apache-vhost.conf.
 */

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Zfeeder\Admin\AdminDispatcher;
use Zfeeder\Admin\AdminRoutes;
use Zfeeder\Http\DemoController;
use Zfeeder\Http\ErrorHandler;
use Zfeeder\Http\PublicController;
use Zfeeder\Http\Responder;
use Zfeeder\Http\Router;
use Zfeeder\Http\SecurityHeaders;
use Zfeeder\Kernel;

// The PHP development server has no rewrite rules: when it is routing through
// this file it must be told to serve a real file itself. Apache and nginx never
// reach this branch because their configuration serves static files directly.
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($requested) && $requested !== '/' && !str_contains($requested, '..')) {
        $candidate = realpath(__DIR__ . $requested);
        if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, __DIR__ . DIRECTORY_SEPARATOR)) {
            return false;
        }
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

try {
    $kernel = Kernel::boot(null, dirname(__DIR__));
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "zFeeder cannot start: its configuration is not valid.\n";
    error_log('zfeeder boot failure: ' . $e->getMessage());
    exit;
}

$config = $kernel->config();
$errors = new ErrorHandler($config, $kernel->logger());
$headers = new SecurityHeaders($config);

// A switch the operator can flip to take the site down without deleting it.
// The health endpoint stays up: a paused instance is still a healthy process,
// and every hosting platform decides whether to keep the container by polling
// it. Answering 503 there would make the platform restart a container that is
// doing exactly what it was told to do.
$path = '/' . trim($request->getUri()->getPath(), '/');
if (!$config->bool('demo_enabled') && $path !== '/healthz') {
    $response = Responder::html(
        '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>zFeeder — paused</title></head><body style="font:16px/1.6 system-ui;margin:4rem auto;max-width:34rem;padding:0 1rem">'
        . '<h1>This zFeeder instance is paused</h1>'
        . '<p>The site has been switched off by its operator. The source code is at '
        . '<a href="https://github.com/andreibesleaga/zfeeder">github.com/andreibesleaga/zfeeder</a>.</p>'
        . '</body></html>',
        503,
    );
    zfeeder_emit($headers->forPublic($response, $request));
    exit;
}

$router = new Router();
$router->get('/', 'demo.index');
$router->get('/demos/template/{set}/{name}', 'demo.template');
$router->get('/demos/{slug}', 'demo.show');
$router->get('/embed', 'public.embed');
$router->add(['OPTIONS'], '/embed', 'public.preflight');
$router->get('/api/feeds', 'public.api');
$router->add(['OPTIONS'], '/api/feeds', 'public.preflight');
$router->get('/api/opml/{category}', 'public.opml');
$router->get('/refresh', 'public.refresh');
// The 1.6 trigger was a query parameter on the feed page itself; both spellings
// of the key are accepted so a 2004 cron entry keeps working after an upgrade.
$router->get('/newsfeeds/zfeeder.php', 'public.refresh');
$router->get('/healthz', 'public.health');
AdminRoutes::register($router);

$match = $router->dispatch($request);
$wantsJson = str_starts_with($request->getUri()->getPath(), '/api/');

try {
    if ($match === null) {
        $response = $errors->notFound($wantsJson);
    } elseif ($match['handler'] === '405') {
        $response = $errors->methodNotAllowed();
    } elseif (str_starts_with($match['handler'], 'admin.')) {
        $response = (new AdminDispatcher($kernel))->handle($match['handler'], $request, $match['params']);
    } else {
        $demo = new DemoController($kernel);
        $public = new PublicController($kernel);

        $response = match ($match['handler']) {
            'demo.index' => $headers->forPublic($demo->index($request), $request),
            'demo.show' => $headers->forPublic($demo->demo($request, $match['params']['slug'] ?? ''), $request),
            'demo.template' => $headers->forPublic(
                $demo->template($request, $match['params']['set'] ?? '', $match['params']['name'] ?? ''),
                $request,
            ),
            'public.embed' => $headers->forEmbed($public->embed($request), $request),
            'public.api' => $headers->forEmbed($public->api($request), $request),
            'public.preflight' => $headers->forEmbed(Responder::noContent(), $request),
            'public.opml' => $headers->forPublic($public->opml($request, $match['params']['category'] ?? ''), $request),
            'public.refresh' => $headers->forPublic($public->refresh($request), $request),
            'public.health' => $headers->forPublic($public->health(), $request),
            default => $errors->notFound($wantsJson),
        };
    }
} catch (Throwable $e) {
    $response = $headers->forPublic($errors->handle($e, $wantsJson), $request);
}

zfeeder_emit($response);

/** Writes a PSR-7 response to the SAPI. */
function zfeeder_emit(Psr\Http\Message\ResponseInterface $response): void
{
    if (!headers_sent()) {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $i => $value) {
                header($name . ': ' . $value, $i === 0);
            }
        }
    }

    $body = $response->getBody();
    if ($body->isSeekable()) {
        $body->rewind();
    }
    while (!$body->eof()) {
        echo $body->read(8192);
    }
}
