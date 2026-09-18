<?php

declare(strict_types=1);

namespace Zfeeder\Render;

use Zfeeder\Exception\TemplateException;

/**
 * Splits a raw template file into its sections, reproducing 1.6's
 * `splitTemplate()` byte for byte.
 *
 * The 1.6 rule, from `newsfeeds/includes/zfuncs.php`:
 *
 *     $startPos = strpos($html, '<!-- ' . $delimiter . ' -->');
 *     $endPos   = strpos($html, '<!-- END' . $delimiter . ' -->');
 *     return substr($html, $startPos, $endPos - $startPos);
 *
 * Consequences we deliberately keep, because the goldens depend on them:
 *  - the opening marker is part of the chunk and is printed;
 *  - the closing marker is not;
 *  - anything between the two markers is kept verbatim, which includes the
 *    whitespace that indents the closing marker: a chunk written as
 *    `  <!-- channel -->\n  CHAN\n  <!-- ENDchannel -->` yields
 *    `<!-- channel -->\n  CHAN\n  `, with the indentation of `<!-- ENDchannel -->`
 *    left on the end. Whitespace before an *opening* marker is outside every
 *    chunk and is simply dropped. This is why indentation in the rendered
 *    output looks misplaced; it is misplaced in exactly the way 1.6 misplaced
 *    it, and the goldens record that.
 */
final class TemplateEngine
{
    /** @var array<string, Template> parsed templates, keyed by resolved file path plus mtime */
    private array $cache = [];

    /**
     * Parse a template file. Results are memoised for the life of the engine,
     * keyed by path and mtime so a template edited during a long-running
     * process is picked up.
     *
     * @param string $name canonical "set/name" used for diagnostics and `{set}`
     */
    public function load(string $path, string $name, string $set): Template
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new TemplateException(sprintf('Template file "%s" could not be read.', $path));
        }
        $mtime = @filemtime($path);
        $key = $path . '@' . ($mtime === false ? '0' : (string) $mtime);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->parse($source, $name, $set);
    }

    /** Parse template source that is already in memory (used by tests and the admin preview). */
    public function parse(string $source, string $name = 'inline', string $set = 'classic'): Template
    {
        return new Template(
            name: $name,
            set: $set,
            pageHeader: $this->optionalSection($source, Template::PAGE_HEADER_SECTION),
            header: $this->section($source, 'header', $name),
            channel: $this->section($source, 'channel', $name),
            news: $this->section($source, 'news', $name),
            footer: $this->section($source, 'footer', $name),
            between: $this->section($source, 'between', $name),
        );
    }

    /**
     * The 1.6 `splitTemplate()`. A missing marker was a hard `exit` with an
     * HTML error message in 2004; here it is a TemplateException.
     */
    public function section(string $html, string $delimiter, string $name = 'inline'): string
    {
        $chunk = $this->find($html, $delimiter);
        if ($chunk === null) {
            throw new TemplateException(sprintf(
                'Template "%s" is missing the <!-- %s --> / <!-- END%s --> section markers.',
                $name,
                $delimiter,
                $delimiter,
            ));
        }

        return $chunk;
    }

    /** The page header section is optional in 2.0; 1.6 aborted when it was absent. */
    private function optionalSection(string $html, string $delimiter): ?string
    {
        return $this->find($html, $delimiter);
    }

    private function find(string $html, string $delimiter): ?string
    {
        $start = strpos($html, '<!-- ' . $delimiter . ' -->');
        $end = strpos($html, '<!-- END' . $delimiter . ' -->');
        // 1.6 tested `$startPos == false`, so a marker sitting at offset 0 was
        // reported as missing. None of the shipped templates start with a
        // marker, so that bug is unobservable in the goldens and is not worth
        // reproducing: we use a strict comparison instead.
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($html, $start, $end - $start);
    }
}
