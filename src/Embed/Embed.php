<?php

declare(strict_types=1);

namespace Zfeeder\Embed;

use Zfeeder\Kernel;
use Zfeeder\Render\RenderRequest;

/**
 * Backs the `zfeeder()` family of functions.
 *
 * The 2004 script was included straight into a page and printed as a side
 * effect. The same convenience is kept, but the kernel is built once and
 * remembered, so a page that embeds four categories does not build four of
 * everything.
 */
final class Embed
{
    private static ?Kernel $kernel = null;

    /** Replace the kernel, for tests and for hosts that have already built one. */
    public static function setKernel(?Kernel $kernel): void
    {
        self::$kernel = $kernel;
    }

    public static function kernel(?string $configPath = null): Kernel
    {
        return self::$kernel ??= Kernel::boot($configPath, dirname(__DIR__, 2));
    }

    /**
     * @param array{
     *     category?: string, template?: string, position?: string,
     *     more?: string|int, link?: bool, config?: string, self_url?: string
     * } $options
     */
    public static function render(array $options = []): string
    {
        $kernel = self::kernel(isset($options['config']) ? (string) $options['config'] : null);

        $request = new RenderRequest(
            category: isset($options['category']) ? (string) $options['category'] : null,
            template: isset($options['template']) ? (string) $options['template'] : null,
            positions: isset($options['position']) ? (string) $options['position'] : null,
            moreFeed: isset($options['more']) ? (int) $options['more'] : null,
            showPoweredBy: ($options['link'] ?? true) !== false,
            selfUrl: isset($options['self_url']) ? (string) $options['self_url'] : self::currentUrl(),
        );

        try {
            return $kernel->feeds()->render($request);
        } catch (\Throwable $e) {
            $kernel->logger()->error('Embedded render failed: {message}', ['message' => $e->getMessage()]);

            // An embedded widget must never break the page that hosts it.
            return $kernel->config()->isProduction()
                ? '<!-- zFeeder: this block could not be rendered -->'
                : '<pre>zFeeder: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        }
    }

    /**
     * @param array{category?: string, config?: string} $options
     *
     * @return list<\Zfeeder\Parse\Model\Channel>
     */
    public static function channels(array $options = []): array
    {
        $kernel = self::kernel(isset($options['config']) ? (string) $options['config'] : null);

        try {
            return $kernel->feeds()->channels(isset($options['category']) ? (string) $options['category'] : null);
        } catch (\Throwable $e) {
            $kernel->logger()->error('Embedded channel load failed: {message}', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * The path the host page was requested with, used to build the "more" and
     * "hide" links so they return to the page the widget is embedded in.
     */
    private static function currentUrl(): string
    {
        $self = $_SERVER['PHP_SELF'] ?? '';

        return is_string($self) ? $self : '';
    }
}
