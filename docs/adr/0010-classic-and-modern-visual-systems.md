# 0010. Two complete visual systems, classic and modern

## Status

Accepted.

## Context

The plan offered a choice: reproduce the 2004 look as homage, or design a new one. Either answer loses
something. The 2004 look is the evidence — light blue `#D0ECFD` bars, `#006699` headings, compact tables —
and a screenshot of it beside a 2004 original is the clearest statement the portfolio can make. But that
layout is fixed-width, table-driven and untestable against WCAG 2.2. The owner's decision of 2026-09-17 took
both: two complete systems, not one with a theme switch.

## Decision

Everything that renders has a `classic` and a `modern` form, selected at runtime.

For feed output the unit is the template *set*. `src/Render/TemplateLocator.php` declares
`SETS = ['classic', 'modern']` and resolves a request of the form `set/name`; the default comes from the
`template_set` key (`ZF_TEMPLATE_SET`), and a request may say `zftemplate=modern/cards` instead. Classic
templates keep their 2004 markup and their original stylesheets and images under `public/assets/classic/`.
Modern templates share one stylesheet, `public/assets/modern/zf.css`.

`zf.css` is scoped entirely under a `.zf` root class, because feed output is injected into somebody else's
page: their CSS must not reach in and zFeeder's must not leak out. Layout is driven by **container** queries,
since the output lives in a column of unknown width. Colours exist as `-l`/`-d` token pairs and the theme
blocks only re-point the live token. The file opens with a measured contrast table for every pair.

For the panel the unit is the *skin*: `admin_skin` (`ZF_ADMIN_SKIN`, default modern) chooses
`templates-admin/classic/layout.twig` or `templates-admin/modern/layout.twig`, and
`public/assets/modern/zf-admin.css` styles both through a skin class on `<body>`. Its header carries the same
measured figures, including the classic skin's `#006699` on `#d0ecfd`.

## Consequences

- Every output change is made twice, and `tests/e2e/specs/templates.spec.js` catches the forgotten half.
- The classic set cannot be made responsive without ceasing to be the classic set: its tables are reproduced,
  not repaired.
- Accessibility claims are measured ratios in the stylesheets, so a colour edit that breaks one shows in the
  diff.

## Alternatives considered

- **Homage only.** The original recommendation and the cheapest; it leaves the product unusable on a phone.
- **A new brand only.** It discards the comparison the rebuild exists to make.
- **One markup set with two stylesheets.** Rejected for output templates — the classic markup is the
  artefact, and restyling it would defeat the goldens (ADR 0011). It *is* how the panel works.
