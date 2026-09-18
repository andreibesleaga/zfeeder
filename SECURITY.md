# Security policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.0.x   | Yes |
| 1.6 (2004) | No. It is kept as history at <https://github.com/andreibesleaga/old-projects> and must not be run on a public server. |

## Reporting a vulnerability

Report privately through GitHub's advisory form:
<https://github.com/andreibesleaga/zfeeder/security/advisories/new>

Please include the version, the configuration that matters (storage backend, whether the
admin panel is enabled, whether the site sits behind a proxy), a description of the impact
and the steps to reproduce. A proof of concept helps.

You will get an acknowledgement within seven days. This is a single-maintainer project, so
a fix for a confirmed high-severity issue is a matter of days rather than hours. Please do
not disclose publicly until a fix is released, and tell us if you plan to publish anyway so
the release can be scheduled.

## What is in scope

- Remote code execution, authentication bypass, session fixation or hijacking.
- Cross-site scripting through feed content, template content or the admin panel.
- Cross-site request forgery on any admin action.
- Server-side request forgery through feed URLs, autodiscovery or OPML import.
- Path traversal through category, template or set names.
- Information disclosure: configuration, the data directory, stack traces in production.
- Denial of service that a single ordinary request can cause.

## What is not in scope

- Anything in the 1.6 release, archived at <https://github.com/andreibesleaga/old-projects>.
  The 2004 code is published as a historical artefact and is documented as unsafe to deploy;
  its defects are catalogued in `docs/THREAT-MODEL.md`.
- Attacks that need an existing administrator session or filesystem access.
- Missing hardening headers on a deployment that has overridden the shipped configuration.
- Resource exhaustion from subscribing to thousands of feeds; the feed count is the
  operator's decision.
- Social engineering, physical access, or vulnerabilities in PHP, Apache or the OS.

## How this project defends itself

The full list of controls, the threat model and the test that proves each one is in
[`docs/THREAT-MODEL.md`](docs/THREAT-MODEL.md). In summary: Argon2id password hashing with
rate-limited login, a CSRF token on every state-changing request, an allow-list HTML
sanitiser applied to all feed content, an address guard that blocks requests to loopback
and private ranges on every redirect hop, name validation plus realpath containment on
every filesystem path derived from input, XML parsing with DTDs rejected outright, and
configuration stored as JSON outside the web root — never as PHP.
