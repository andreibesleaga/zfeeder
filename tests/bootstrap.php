<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('UTC');

/** Absolute path to a file inside tests/fixtures. */
function zf_fixture(string $relative): string
{
    return __DIR__ . '/fixtures/' . ltrim($relative, '/');
}

/** Contents of a file inside tests/fixtures. */
function zf_fixture_contents(string $relative): string
{
    $path = zf_fixture($relative);
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Missing fixture: ' . $path);
    }

    return $data;
}
