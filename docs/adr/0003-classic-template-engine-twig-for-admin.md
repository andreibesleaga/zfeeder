# 0003. The 1.6 template format is the engine; Twig is only for the admin panel

## Status

Accepted.

## Context

A zFeeder template is an HTML file cut into sections by comment markers — `<!-- header -->` …
`<!-- ENDheader -->`, and the same for `channel`, `news`, `footer` and `between` — with `{token}`
placeholders inside. Fourteen shipped in 1.6 and an unknown number were written by users; the format is the
closest thing the project has to a public interface. It could be a first-class engine, or converted once to
a modern template language. The admin panel has the opposite shape: seven server-rendered screens with forms
and loops, and no compatibility obligation at all.

## Decision

Two engines, chosen per audience.

Feed output uses the classic format, implemented in `src/Render/TemplateEngine.php`, which reproduces 1.6's
`splitTemplate()` byte for byte — including the consequences that look like bugs: the opening marker is part
of the chunk and is printed, the closing marker is not, and whitespace between a closing marker and the next
opening marker belongs to the *previous* chunk.

`src/Render/Tokens.php` holds two generations. The fifteen 1.6 tokens keep 2004 escaping exactly and are
substituted in 1.6's replacement order, because the order is observable: a value substituted early could
itself contain a later token. The nine added in 2.0 — `author`, `summary`, `content`, `enclosure`,
`itemdate_iso`, `itemdate_rel`, `feedid`, `position`, `set` — are HTML-escaped by default.

`src/Render/Filters.php` adds `{token|filter}` with a closed list of three: `raw`, `trunc`, `date`. It is
deliberately not an expression language, because templates are files an administrator supplies.

The panel uses Twig, wired in `src/Admin/View/TwigFactory.php` with autoescaping and `strict_variables` on,
so a mistyped variable is an error, not an empty table cell.

## Consequences

- 1.x templates load unchanged: the fourteen files in `templates/classic/` are byte-identical to their
  originals in `legacy/zfeeder-1.6/newsfeeds/templates/` (ADR 0011).
- Modern templates use the same format rather than a second syntax, so one engine serves both sets.
- Tokens may be added but never removed: removing one blanks part of somebody's page.

## Alternatives considered

- **Twig everywhere, with a converter for 1.x templates.** One language, and Twig's escaping for free.
  Rejected: a converted template is no longer the user's template, and byte-identical golden output — the
  compatibility claim that justifies the rebuild — becomes unreachable.
- **The classic engine for the panel too.** It has no loops, conditionals or inheritance, which the
  subscriptions screen needs.

**Update, 18 September 2026.** The 1.6 tree was removed from this repository and archived at
<https://github.com/andreibesleaga/old-projects> (`zfeeder-1.6.zip`); the minimal 2004 corpus the tests
need now lives in `tests/fixtures/legacy-1.6/`.
