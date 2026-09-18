<?php

declare(strict_types=1);

namespace Zfeeder\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

/** Small helpers for the response shapes this application actually returns. */
final class Responder
{
    /** @param array<string, string> $headers */
    public static function html(string $html, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'] + $headers,
            Stream::create($html),
        );
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        // The HEX flags cost a little readability and remove a whole class of
        // problem: a feed title containing `</script>` cannot terminate a
        // script element if this response is ever embedded in one, whatever
        // the content type says.
        $body = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            Stream::create($body === false ? '{"error":"encoding failed"}' : $body),
        );
    }

    /** @param array<string, string> $headers */
    public static function text(string $text, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'text/plain; charset=utf-8'] + $headers,
            Stream::create($text),
        );
    }

    /** @param array<string, string> $headers */
    public static function xml(string $xml, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'text/x-opml+xml; charset=utf-8'] + $headers,
            Stream::create($xml),
        );
    }

    public static function redirect(string $location, int $status = 303): ResponseInterface
    {
        return new Response($status, ['Location' => $location]);
    }

    public static function noContent(): ResponseInterface
    {
        return new Response(204);
    }
}
