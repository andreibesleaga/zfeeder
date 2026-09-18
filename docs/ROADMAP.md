# Roadmap

zFeeder 2.0 is a rebuild of a 2004 program, not a new product. The goal in
`project/PLAN.md` §1 is "same product, same architecture, same feature list, same workflow"
on a 2026 stack, with the 2004 security holes closed. The roadmap follows from that: the
2.x line stays the size it is.

There is no release schedule. See [VERSIONING.md](../VERSIONING.md) for what the numbers
mean and [RELEASE-PROCESS.md](RELEASE-PROCESS.md) for how one is cut.

## What 2.x will not do

Each of these is a decision, not a backlog item. A pull request that adds one will be
declined, however well it is written. The same list is in
[CONTRIBUTING.md](../CONTRIBUTING.md) and in `project/PLAN.md` §14.

| Not doing | Why |
|---|---|
| **Read and unread state** | It needs per-reader state. zFeeder renders the same page for everyone, from a cache keyed only by feed URL — see the `cache` table in `src/Storage/Sqlite/Migrations/001_initial.sql`, which has no reader column and is not going to grow one. Per-reader state means either a cookie and a session for every visitor (the opposite of what [PRIVACY.md](PRIVACY.md) promises) or an account system. Both change what the program is. |
| **Full-text search** | Search over cached bodies means an index, an index means either a search engine or SQLite FTS. The flat-file backend could not implement it, so the two backends would stop being interchangeable, and `bin/zfeeder migrate` in both directions is a promise in [VERSIONING.md](../VERSIONING.md). A feature that only half the installations can have is worse than one nobody has. |
| **Multiple user accounts** | There is one administrator: `admin_user` and `admin_password_hash`, two configuration keys in `src/Config/Schema.php`. Accounts mean a user store, roles, per-role authorisation on every action, password reset, and an e-mail path for the reset. That is a larger program than zFeeder, and every one of those parts is a way to get authentication wrong. |
| **A database server** | zFeeder has to keep running on the shared hosting it ran on in 2004. `flat` needs nothing but a writable directory; `sqlite` needs one PHP extension. MySQL or PostgreSQL would add a service to install, back up, patch and connect to, for data that is a few thousand rows of cached XML. |
| **A JavaScript framework** | The panel uses htmx 2.0.4, one 50 KB file served from the site's own origin, for partial updates. The public output uses no JavaScript at all, which is why `/embed` can be dropped into any page. A framework would add a build step, a dependency tree and a bundle to every embedded page. |
| **A mobile application** | The output is responsive and the panel works at 320 px. An application would be a second codebase with a release process of its own and nothing to do that the site does not already do. |
| **WAP and WML output** | 1.6 emitted WML for mobile phones of the time. No browser has read it for over a decade. It is retired, and kept as history in [HISTORY.md](HISTORY.md). |
| **Frames** | 1.6 had a frames-based layout. `templates/modern/mainframe.html` keeps the name and the look without the frameset. |
| **Becoming a feed reader service** | No accounts, no subscriptions per reader, no synchronisation, no recommendations. zFeeder aggregates a site owner's chosen feeds into that owner's pages. |

Two of these have an accessibility consequence, recorded honestly in
[ACCESSIBILITY.md](ACCESSIBILITY.md): with no search and no site map, navigation is the only
route through the site, which is why WCAG 2.2 criterion 2.4.5 is marked "partly met".

## Open, and not promised

Everything below is a known gap in what is already here, traceable to a file in this
repository. None of it is scheduled, and none of it is a commitment.

### Verification gaps

- **The classic admin skin has never been through axe.** `tests/e2e/specs/admin.spec.js`
  runs axe over all six screens, but neither it nor `tools/run-e2e.sh` sets
  `ZF_ADMIN_SKIN`, so only the default `modern` layout has been checked. The screens
  themselves are shared (`templates-admin/shared/`); it is the classic layout's masthead,
  menu and footer that are unverified.
- **The keyboard walkthrough covers one screen.** `admin.spec.js` walks `/admin` with the
  Tab key; `project/PLAN.md` §B8 asks for one on every screen. `tests/e2e/helpers.js`
  exports `expectKeyboardReachable()`, which nothing calls.
- **`bin/zfeeder seed` has no test.** Every other command has a class in `tests/Cli/`.
- **Lighthouse and the 200 % zoom check.** Both are in `project/PLAN.md` §B8 and §B9. Neither
  exists in `.github/workflows/ci.yml`.
- **`templates/classic/css.html`.** The `CLASSIC` list in `tests/e2e/specs/templates.spec.js`
  names thirteen templates and the directory holds fourteen files, so this one has no render
  test.
- **CodeQL runs over JavaScript only.** `.github/workflows/codeql.yml` sets
  `languages: javascript-typescript`, and the project is PHP.
- **The security suite was never run against a weakened build.** `project/PLAN.md` §B14
  asks for each S-class to be shown failing with its control removed. Several classes
  demonstrate the undefended behaviour inside the test instead; the rest rest on review.

### Small, self-contained improvements

- **`lang` on rendered feed content.** The `feeds` table has a `language` column and the OPML
  dialect carries it, but no template emits a `lang` attribute, which is why WCAG 2.2
  criterion 3.1.2 is marked not met in [ACCESSIBILITY.md](ACCESSIBILITY.md). It would need a
  new token, which is a minor release under [VERSIONING.md](../VERSIONING.md).
- **A meaningful alternative for `{chanlogo}`.** `Renderer::chanLogo()` emits `alt="[logo]"`
  because 1.6 did. Legacy fidelity mode must keep that exact string for the golden tests; the
  modern path could use the channel title instead.
- **`scroll-padding` for the panel's sticky bars.** `public/assets/modern/zf-admin.css` makes
  the masthead and the action bar sticky and sets no scroll padding, so a control reached
  with the Tab key can be scrolled under one of them.

## Beyond 2.x

A 3.0 is under consideration. Nothing has been designed or agreed, and no work has
started; when it does, the entries in the first table are the questions it would reopen —
that is what a major version is for. Until then, the compatibility promises in
[VERSIONING.md](../VERSIONING.md) hold: template tokens are never removed within 2.x, and
a 2004 template keeps working.

## Proposing something

1. **Check the first table.** If it is there, the answer is no, and the reason is in the
   table. If you think the reason is wrong, argue with the reason rather than restating the
   feature.
2. **Open an issue before writing code**, at
   <https://github.com/andreibesleaga/zfeeder/issues>. Describe the problem you have, not the
   solution you want. Say which storage backend and which template set you use: a change that
   only works on one of the two is usually the wrong shape.
3. **Read [CONTRIBUTING.md](../CONTRIBUTING.md) first if you intend to send a pull request.**
   The constraints that catch most proposals: the classic templates are frozen and proven by
   `tests/Golden/`, template tokens are added but never removed, no new runtime dependency
   goes in without a stated reason, and a security change needs a test that fails without the
   fix.
4. **Say what it costs the person deploying this.** Every runtime dependency, every new
   configuration key and every new PHP extension is carried by someone running zFeeder on
   shared hosting. That is the audience the program is for.

Security problems are not roadmap items. Report them privately, following
[SECURITY.md](../SECURITY.md).
