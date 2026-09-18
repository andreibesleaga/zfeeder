<?php

declare(strict_types=1);

namespace Zfeeder\Parse\Model;

/** A media attachment advertised by a feed item. */
final readonly class Enclosure
{
    public function __construct(
        public string $url,
        public ?string $type = null,
        public ?int $length = null,
    ) {
    }
}
