<?php

/**
 * zFeeder 2.0 — the one-line include, exactly as it worked in 2004.
 *
 *     <?php include 'zfeeder.php'; ?>
 *
 * Optional query parameters, unchanged from 1.6: zfcategory, zftemplate,
 * zfposition, zfmore, zf_link=off. To pass options from PHP instead, use the
 * function directly:
 *
 *     <?php echo zfeeder(['category' => 'news', 'template' => 'modern/cards']); ?>
 *
 * This file prints its output, like the original did. Nothing else here is
 * part of the public interface.
 */

declare(strict_types=1);

(static function (): void {
    $autoload = null;
    foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../autoload.php'] as $candidate) {
        if (is_file($candidate)) {
            $autoload = $candidate;
            break;
        }
    }

    if ($autoload === null) {
        echo '<!-- zFeeder: dependencies are not installed; run composer install -->';

        return;
    }

    require_once $autoload;

    $get = static function (string $key): ?string {
        $value = $_GET[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    };

    echo zfeeder(array_filter([
        'category' => $get('zfcategory'),
        'template' => $get('zftemplate'),
        'position' => $get('zfposition'),
        'more' => $get('zfmore'),
        'link' => $get('zf_link') !== 'off',
    ], static fn (mixed $v): bool => $v !== null));
})();
