# Architecture decision records

One file per decision, numbered in the order the decisions were taken. Each record states the situation, what
was decided, what follows from it and what was rejected. A record is not edited once accepted; a later record
supersedes it and says so.

ADR 0000 covers the rebuild itself. ADRs 0001–0012 correspond one-to-one with decisions D1–D12 in
`project/PLAN.md` §3, as closed on 2026-09-17. ADR 0013 covers the security requirements S1–S18 in §5 and B6.

| # | Decision | Status |
|---|---|---|
| [0000](0000-rebuilding-zfeeder.md) | Rebuild zFeeder rather than restore or patch it | Accepted |
| [0001](0001-php-83-no-framework.md) | PHP 8.3 and Composer, with no framework | Accepted |
| [0002](0002-laminas-feed-for-parsing.md) | laminas-feed for RSS and Atom, an own reader for JSON Feed | Accepted |
| [0003](0003-classic-template-engine-twig-for-admin.md) | The 1.6 template format is the engine; Twig is only for the admin panel | Accepted |
| [0004](0004-two-storage-backends.md) | Two storage backends, flat files and SQLite, selectable at runtime | Accepted |
| [0005](0005-twig-and-htmx-admin.md) | Server-rendered Twig with htmx for the admin panel | Accepted |
| [0006](0006-symfony-http-client.md) | symfony/http-client as the HTTP transport | Accepted |
| [0007](0007-symfony-html-sanitizer.md) | symfony/html-sanitizer with an explicit allow-list | Accepted |
| [0008](0008-name-version-licence.md) | Keep the name and the logo, number it 2.0.0, licence it GPL-2.0-or-later | Accepted |
| [0009](0009-repository-and-distribution.md) | One public repository, three distribution channels | Accepted |
| [0010](0010-classic-and-modern-visual-systems.md) | Two complete visual systems, classic and modern | Accepted |
| [0011](0011-scope-of-shipped-templates.md) | Ship every 1.6 template verbatim, and a modern counterpart for each | Accepted |
| [0012](0012-aggregator-demo-as-css-grid.md) | Rebuild the frames demo as a CSS grid | Accepted |
| [0013](0013-security-architecture.md) | The security architecture | Accepted |

## Adding a record

Number the file after the highest existing record and name it `NNNN-short-kebab-case-slug.md`. Use the same
five headings the other records use — Status, Context, Decision, Consequences, Alternatives considered — and
cite the files that implement the decision, so a reader can check the record against the code. Add a row to
the table above.
