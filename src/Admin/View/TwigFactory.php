<?php

declare(strict_types=1);

namespace Zfeeder\Admin\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Config\Config;
use Zfeeder\Version;

/**
 * The panel's template engine.
 *
 * Two loader paths, in this order: the chosen skin, then `shared/`. Only
 * `layout.twig` lives in a skin directory, so `{% extends "layout.twig" %}` in
 * a shared screen picks up the classic or the modern frame while the screen
 * itself — the markup a screen reader and an axe run actually see — exists
 * exactly once. A second copy of that markup is a second place for an
 * accessibility regression to hide.
 *
 * `strict_variables` is on: a typo in a template name becomes an error here
 * rather than a silently empty table cell on the subscriptions screen.
 * Autoescaping is on and nothing in `templates-admin/` uses `|raw`, because
 * every interesting string on these screens — feed titles, descriptions,
 * error messages — came from a remote feed.
 */
final class TwigFactory
{
    public static function create(Config $config, AdminContext $context): Environment
    {
        $root = $config->adminTemplatesDir();
        $skin = self::skin($config);

        $paths = [];
        foreach ([$root . '/' . $skin, $root . '/shared'] as $path) {
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }

        $twig = new Environment(new FilesystemLoader($paths), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'debug' => false,
            'cache' => false,
        ]);

        $twig->addGlobal('skin', $skin);
        $twig->addGlobal('nonce', $context->nonce);
        $twig->addGlobal('handler', $context->handler);
        $twig->addGlobal('version', Version::NUMBER);
        $twig->addGlobal('product', Version::full());
        $twig->addGlobal('homepage', Version::HOMEPAGE);
        $twig->addGlobal('demo_mode', $config->bool('demo_mode'));
        $twig->addGlobal('csrf_field', \Zfeeder\Admin\Auth\Csrf::FIELD);

        $twig->addFunction(new TwigFunction(
            'admin_url',
            static fn (string $path): string => $context->url($path),
        ));
        $twig->addFunction(new TwigFunction(
            'asset',
            static fn (string $path): string => $context->asset($path),
        ));

        return $twig;
    }

    private static function skin(Config $config): string
    {
        $skin = $config->string('admin_skin');

        return in_array($skin, ['classic', 'modern'], true) ? $skin : 'modern';
    }
}
