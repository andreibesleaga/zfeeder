<?php

declare(strict_types=1);

namespace Zfeeder;

/**
 * Single source of truth for the product version and identity strings.
 */
final class Version
{
    public const string NUMBER = '2.0.1';
    public const string NAME = 'zFeeder';

    /** The version of the original release this one is compatible with. */
    public const string LEGACY_COMPAT = '1.6';

    public const string HOMEPAGE = 'https://github.com/andreibesleaga/zfeeder';

    public static function userAgent(): string
    {
        return 'zfeeder/' . self::NUMBER . ' (+' . self::HOMEPAGE . ')';
    }

    public static function full(): string
    {
        return self::NAME . ' ' . self::NUMBER;
    }
}
