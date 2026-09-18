<?php

declare(strict_types=1);

namespace Zfeeder\Render;

/**
 * A zFeeder template split into the sections the 1.6 renderer emitted.
 *
 * Every chunk still carries its *opening* marker (`<!-- news -->`) because
 * `splitTemplate()` in 1.6 returned `substr($html, $start, $end - $start)`
 * where `$start` is the offset of the opening marker. Its closing marker is
 * not part of the chunk. The markers are therefore visible in the rendered
 * page, which is exactly what the 2004 output looked like and what the golden
 * corpus asserts; do not "clean" them away.
 */
final readonly class Template
{
    /** The five sections every 1.x template must define, in `splitTemplate()` call order. */
    public const array REQUIRED_SECTIONS = ['header', 'channel', 'news', 'footer', 'between'];

    /** The optional once-per-page section, emitted before the first feed. */
    public const string PAGE_HEADER_SECTION = 'zFeeder template header';

    public function __construct(
        /** Canonical "set/name" of the template, e.g. `classic/bluelogos`. */
        public string $name,
        /** The template set this came from: `classic` or `modern`. */
        public string $set,
        /** Once-per-page chunk; `null` when the template does not declare one. */
        public ?string $pageHeader,
        public string $header,
        public string $channel,
        public string $news,
        public string $footer,
        public string $between,
    ) {
    }

    /** The template's short name without its set, e.g. `bluelogos`. */
    public function shortName(): string
    {
        $slash = strrpos($this->name, '/');

        return $slash === false ? $this->name : substr($this->name, $slash + 1);
    }
}
