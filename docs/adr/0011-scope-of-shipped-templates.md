# 0011. Ship every 1.6 template verbatim, and a modern counterpart for each

## Status

Accepted.

## Context

1.6 shipped fourteen output templates plus four WAP ones. The original plan recommended porting six
faithfully, adding two responsive designs, and leaving the rest merely loadable. That reasoning was about
effort, and it had a cost: a template that is loadable but neither shipped nor tested is a claim nobody
checks, and the compatibility argument for the rebuild (ADR 0000) is only as strong as the number of 2004
files proven to render identically. The owner closed the decision the other way on 2026-09-17.

## Decision

`templates/classic/` holds all fourteen non-WAP templates from `legacy/zfeeder-1.6/newsfeeds/templates/`,
**byte-identical** to the originals: `ampheta`, `aqua`, `bluelogos`, `css`, `greenlogos`, `headlinebox`,
`infojunkie`, `mainframe`, `rij`, `sidebar`, `simpleblue`, `simplecss`, `simplegray`, `titlebox`. Only one
file name changed — `RiJ.html` is stored as `rij.html`, and `TemplateLocator` matches case-insensitively so
the 2004 spelling still resolves. Their comment headers, including RiJ's "do NOT delete
or modify comments in the template files", are part of the file and are preserved.

`templates/modern/` holds sixteen: one counterpart per classic name, plus `cards`, `list` and `ticker`, which
descend from nothing in 1.6. Each modern file opens with a comment naming the classic template it descends
from, so the pairing is documented in the artefact, not in a table that drifts.

The WAP templates are not ported; that output was retired in ADR 0000.

## Consequences

- The compatibility claim is tested. `tests/fixtures/goldens/` holds 49 files recorded by
  `tools/record-goldens.sh` from 1.6 running under `php:5.6-apache` against fixed fixture feeds, and
  `tests/Golden/ClassicTemplateGoldenTest.php` asserts `assertSame` on the whole string — never a
  whitespace-normalised comparison. Beyond three feed fixtures per template, seven `simplegray` goldens pin
  behaviour rather than appearance: four channel-location combinations, `.nolink`, `.pos2` and `.more0`.
- Determinism came from the recorder, not the test: feed bodies were pre-placed in 1.6's cache with a fixed
  far-future mtime, so nothing was fetched and `{lastupdated}` is a constant. The goldens are CRLF, as the
  templates are, and `.gitattributes` pins both with `-text`.
- The fourteen cannot be reformatted, re-indented or tidied; a whitespace change is a test failure by design.

## Alternatives considered

- **Port six, leave eight loadable.** Cheaper, and it leaves the most interesting compatibility cases —
  `mainframe`, `sidebar`, the CSS-only `rij` — untested.
- **Modernise the classic markup in place.** It would have produced fourteen responsive templates and
  destroyed the byte-for-byte evidence in the same move.

**Update, 18 September 2026.** The 1.6 tree was removed from this repository and archived at
<https://github.com/andreibesleaga/old-projects> (`zfeeder-1.6.zip`); the minimal 2004 corpus the tests
need now lives in `tests/fixtures/legacy-1.6/`.
