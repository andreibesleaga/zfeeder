<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zfeeder\Http\Responder;

/**
 * The public demonstration panel, which anyone may open and nobody may change.
 *
 * The rule is stated as "no unsafe method gets through", not as a list of the
 * screens that write, so a new screen is refused by default rather than
 * forgotten. Signing in and out are the two exceptions: they change no data,
 * and without them the demonstration could not be reached at all.
 */
final class DemoMode implements MiddlewareInterface
{
    /** Methods that RFC 9110 defines as safe; everything else can write. */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @param list<string> $exemptHandlers */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $handlerName,
        private readonly array $exemptHandlers = [],
        private readonly string $panelUrl = '/admin',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }
        if (in_array($this->handlerName, $this->exemptHandlers, true)) {
            return $handler->handle($request);
        }
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $message = 'This is the public zFeeder demonstration: the panel is read-only, '
            . 'so nothing can be added, changed or deleted here. '
            . 'Install zFeeder yourself to use these screens for real.';

        if ($request->getHeaderLine('HX-Request') !== '') {
            return Responder::text($message, 403);
        }

        return Responder::html($this->page($message), 403);
    }

    private function page(string $message): string
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $back = htmlspecialchars($this->panelUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Demonstration mode — zFeeder</title></head>
            <body>
            <main>
            <h1>Demonstration mode</h1>
            <p>{$escaped}</p>
            <p><a href="{$back}">Back to the panel</a></p>
            </main>
            </body>
            </html>
            HTML;
    }
}
