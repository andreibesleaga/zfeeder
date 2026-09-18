<?php

declare(strict_types=1);

namespace Zfeeder\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Zfeeder\Config\Config;

/**
 * Turns a thrown exception into a response.
 *
 * In production the client is told only the status: a stack trace or a file
 * path in an error page is an information leak, and the details belong in the
 * log where the operator can see them.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(\Throwable $error, bool $wantsJson = false): ResponseInterface
    {
        $status = $this->statusFor($error);

        // A refused request is the interesting one for an operator watching for
        // probing, so it is recorded too - at a lower level, because a single
        // refusal is routine and a thousand are not.
        $context = [
            'message' => $error->getMessage(),
            'class' => $error::class,
            'file' => $error->getFile() . ':' . $error->getLine(),
            'status' => $status,
        ];
        if ($status >= 500) {
            $this->logger->error('Unhandled error: {message}', $context);
        } else {
            $this->logger->notice('Request refused ({status}): {message}', $context);
        }

        $public = $this->publicMessage($status);
        $detail = $this->config->isProduction() ? null : $error->getMessage();

        if ($wantsJson) {
            return Responder::json(array_filter([
                'error' => $public,
                'status' => $status,
                'detail' => $detail,
            ], static fn (mixed $v): bool => $v !== null), $status);
        }

        return Responder::html($this->page($status, $public, $detail), $status);
    }

    public function notFound(bool $wantsJson = false): ResponseInterface
    {
        return $wantsJson
            ? Responder::json(['error' => 'Not found', 'status' => 404], 404)
            : Responder::html($this->page(404, 'Not found', null), 404);
    }

    public function methodNotAllowed(): ResponseInterface
    {
        return Responder::html($this->page(405, 'Method not allowed', null), 405);
    }

    private function statusFor(\Throwable $error): int
    {
        return match (true) {
            $error instanceof \Zfeeder\Exception\SecurityException => 400,
            // A template named in the URL that does not exist, or whose name is
            // a traversal attempt, is a bad request rather than a broken server.
            $error instanceof \Zfeeder\Exception\TemplateException => 404,
            $error instanceof \Zfeeder\Exception\ConfigException => 500,
            default => 500,
        };
    }

    private function publicMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            429 => 'Too many requests',
            default => 'Something went wrong',
        };
    }

    private function page(int $status, string $title, ?string $detail): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDetail = $detail === null
            ? ''
            : '<pre>' . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';

        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$status} — {$safeTitle}</title>
            <style>
              :root { color-scheme: light dark; }
              body { font: 16px/1.6 system-ui, sans-serif; margin: 0; display: grid; place-items: center;
                     min-height: 100vh; background: Canvas; color: CanvasText; padding: 24px; }
              main { max-width: 34rem; text-align: center; }
              h1 { font-size: 4rem; margin: 0; letter-spacing: -0.03em; }
              p { margin: 0.5rem 0 1.5rem; }
              a { color: #006699; }
              pre { text-align: left; overflow-x: auto; padding: 1rem; background: rgba(127,127,127,.12);
                    border-radius: 8px; font-size: 0.85rem; }
            </style></head>
            <body><main>
              <h1>{$status}</h1>
              <p>{$safeTitle}</p>
              {$safeDetail}
              <p><a href="/">Back to the front page</a></p>
            </main></body></html>
            HTML;
    }
}
