# 0008. Keep the name and the logo, number it 2.0.0, licence it GPL-2.0-or-later

## Status

Accepted.

## Context

The rebuild could have been released under a new name, which would have carried no expectations about
behaviour. Three things argued the other way: the narrative is "the same product, twenty-two years later"
(ADR 0000), existing OPML files and templates are meant to keep working, and 1.6 was distributed widely
enough to have left traces — an *INTERNET PROFESSIONELL* 08/2004 article and CD among them.

The licence had a constraint rather than a preference. 1.6 ships the GPL version 2 text, and its source
headers say "either version 2 of the License, or (at your option) any later version", which permits a
derivative under version 2 or later and forbids anything more restrictive.

## Decision

The name stays zFeeder. The version is 2.0.0, stated once in `src/Version.php`: `NUMBER`, `NAME`,
`LEGACY_COMPAT = '1.6'` and `HOMEPAGE`, with `userAgent()` and `full()` derived from them. Nothing else in
the tree hard-codes a version string.

The licence is GPL-2.0-or-later, declared in `composer.json` and present in full as `LICENSE`.
`VERSIONING.md` records the SemVer policy.

The 2004 logo is kept: `public/assets/classic/images/zflogo.png` is byte-identical to
`legacy/zfeeder-1.6/newsfeeds/images/zflogo.png`, down to its January 2004 timestamp, and
`public/assets/modern/zfeeder-logo.svg` is the modern variant.

## Consequences

- A major version licenses the breaking changes: WAP output is gone, the admin password format changed, and
  `config.php` became `data/config.json`. `bin/zfeeder legacy-import`
  (`src/Cli/Command/LegacyImportCommand.php`) makes that move for an upgrading user, mapping the 1.6
  `define()` names to 2.0 keys by table and never executing the old `config.php`.
- `Version::userAgent()` is the string every outbound request identifies itself with, so a publisher who
  rate-limits zFeeder has one stable token to match.
- GPL-2.0-or-later constrains the dependency list: every runtime package must be GPL-2-compatible. The
  release workflow records `composer licenses --format=json` as `dist/licenses.json`, so the claim is
  evidence rather than assertion.
- Keeping the name inherits its search results, including twenty-year-old pages describing the 2004 security
  model, so the documentation has to be explicit about which version it describes.

## Alternatives considered

- **A new name and a 1.0.0.** No inherited expectations, and no continuity.
- **MIT or Apache-2.0.** Not available: a derivative of GPL v2 code cannot be relicensed that way.
- **GPL-3.0-only.** Permitted by the "or later" clause, but it would exclude the result from GPL-2-only
  projects for no benefit this project needs.

**Update, 18 September 2026.** The 1.6 tree was removed from this repository and archived at
<https://github.com/andreibesleaga/old-projects> (`zfeeder-1.6.zip`); the minimal 2004 corpus the tests
need now lives in `tests/fixtures/legacy-1.6/`.
