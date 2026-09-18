# 0005. Server-rendered Twig with htmx for the admin panel

## Status

Accepted.

## Context

The panel is seven screens: login, main, add new, subscriptions, config, import feed list, updates. Three
benefit from updating part of a page — the autodiscovery result, saving and deleting subscriptions,
switching category — and none is an application in its own right. The audience includes people on shared
hosting, where a Node toolchain is unavailable and often not permitted. The panel also has to exist in two
skins (ADR 0010), which doubles whatever markup decision is taken here.

## Decision

Server-rendered Twig, progressive enhancement with htmx, hand-written CSS. No build step, no JavaScript
framework.

`src/Admin/View/TwigFactory.php` configures the environment: autoescaping on, `strict_variables` on, `|raw`
used nowhere in `templates-admin/`, because every interesting string on these screens came from a remote
feed. It registers two loader paths in order — the chosen skin directory, then `templates-admin/shared/`.
Only `layout.twig` exists per skin; the screens live once in `shared/`, so `{% extends "layout.twig" %}`
picks up the right frame while the markup a screen reader and an axe run actually see exists in a single
place. A second copy would be a second place for an accessibility regression to hide.

htmx is vendored as `public/assets/modern/htmx.min.js` (about 50 KB) and loaded from the layout with the
per-request CSP nonce. Fragment routes live under `/admin/_/` and are named in `src/Admin/AdminRoutes.php`.
Each has a full-page equivalent, and forms post to that route when scripting is off.

## Consequences

- The panel works without JavaScript. htmx improves three interactions and is required by none.
- `src/Admin/Middleware/RequireAuth.php` has to tell htmx from a browser navigation: an expired session on an
  `hx-post` gets a redirect *header*, because a 303 inside a fragment request would swap the login page into
  a table cell.
- The skin directories hold one file each. All seven screens, `denied.twig` and the partials (`flash`,
  `footer`, `header`, `menu`, `discovery`, `subscription_row`, `subscriptions_table`) live in
  `templates-admin/shared/`, and `tests/Integration/Admin/` tests them once rather than once per skin.

## Alternatives considered

- **Alpine.js.** No build step either, but its attributes describe client state where htmx's describe a
  request to a route that has to exist anyway.
- **React or Vue.** A build step, a bundle and a second rendering model, for screens of forms.
- **Full page reloads only.** Still the fallback path, but the subscriptions screen is worse for it.
