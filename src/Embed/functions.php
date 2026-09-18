<?php

declare(strict_types=1);

/**
 * The one-line include surface, kept from zFeeder 1.6.
 *
 * 2004:  <?php include("newsfeeds/zfeeder.php"); ?>
 * 2026:  <?php echo zfeeder(['category' => 'news', 'template' => 'modern/cards']); ?>
 *
 * These are plain functions on purpose: a page that wants one line of PHP
 * should not have to know about namespaces, containers or autoloading order.
 */

use Zfeeder\Embed\Embed;

if (!function_exists('zfeeder')) {
    /**
     * Render subscriptions as HTML and return it.
     *
     * @param array{
     *     category?: string,
     *     template?: string,
     *     position?: string,
     *     more?: string,
     *     link?: bool,
     *     config?: string
     * } $options
     */
    function zfeeder(array $options = []): string
    {
        return Embed::render($options);
    }
}

if (!function_exists('zfeeder_echo')) {
    /**
     * Render subscriptions as HTML and print it.
     *
     * @param array{category?: string, template?: string, position?: string, more?: string|int, link?: bool, config?: string, self_url?: string} $options
     */
    function zfeeder_echo(array $options = []): void
    {
        echo Embed::render($options);
    }
}

if (!function_exists('zfeeder_feeds')) {
    /**
     * The parsed channels behind a category, for pages that want to lay out
     * the data themselves instead of using a template.
     *
     * @param array{category?: string, config?: string} $options
     *
     * @return list<\Zfeeder\Parse\Model\Channel>
     */
    function zfeeder_feeds(array $options = []): array
    {
        return Embed::channels($options);
    }
}
