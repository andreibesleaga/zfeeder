<?php

declare(strict_types=1);

namespace Zfeeder\Render;

/**
 * Everything the renderer needs to know about *this* request.
 *
 * The four query parameters of 1.6 (`zfcategory`, `zftemplate`, `zfposition`,
 * `zfmore`, plus `zf_link`) are modelled as nullable fields because 1.6's
 * output depended not only on their values but on whether they were *present*:
 * `{moreurl}` gains `&zftemplate=…` only when a template was actually asked
 * for. Keeping "absent" distinct from "empty" is what makes the goldens match.
 */
final readonly class RenderRequest
{
    /**
     * @param string|null $category      the `zfcategory` value as requested, or null when the default was used
     * @param string|null $template      the `zftemplate` value as requested, or null for the configured default
     * @param string|null $positions     the raw `zfposition` value, e.g. `p1,p3`; null means "no filter"
     * @param int|null    $moreFeed      the `zfmore` value: the feed index whose item limit is lifted
     * @param string      $selfUrl       what 1.6 took from `$_SERVER['PHP_SELF']`, used to build more/hide links
     * @param bool        $legacyFidelity when true, feed text is passed through verbatim exactly as 1.6 did
     *                                   (no sanitiser, no truncation). Only the golden tests and an explicit
     *                                   compatibility mode should set this: it re-opens the 2004 XSS hole.
     */
    public function __construct(
        public ?string $category = null,
        public ?string $template = null,
        public ?string $positions = null,
        public ?int $moreFeed = null,
        public bool $showPoweredBy = true,
        public string $selfUrl = '',
        public bool $legacyFidelity = false,
    ) {
    }

    /**
     * The positions to render, parsed from `zfposition`.
     *
     * 1.6 did `split(',', str_replace('p', '', $_GET['zfposition']))`, so
     * `p1,p3,` yields 1, 3 and an empty trailing entry that never matched a
     * feed. We drop the empty entries instead of carrying the bug forward,
     * because an empty entry can only ever select nothing.
     *
     * @return list<int>|null null when no filter was requested
     */
    public function positionFilter(): ?array
    {
        if ($this->positions === null || trim($this->positions) === '') {
            return null;
        }
        $out = [];
        foreach (explode(',', str_replace('p', '', $this->positions)) as $part) {
            $part = trim($part);
            if ($part !== '' && preg_match('/^\d{1,6}$/', $part) === 1) {
                $out[] = (int) $part;
            }
        }

        return $out;
    }

    /** True when `zfmore` names this feed, which lifts its `showedItems` limit. */
    public function wantsMore(int $feedIndex): bool
    {
        return $this->moreFeed !== null && $this->moreFeed === $feedIndex;
    }

    public function withLegacyFidelity(bool $legacy = true): self
    {
        return new self(
            $this->category,
            $this->template,
            $this->positions,
            $this->moreFeed,
            $this->showPoweredBy,
            $this->selfUrl,
            $legacy,
        );
    }
}
