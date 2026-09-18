# Vendored front-end assets

Everything in `public/assets/modern/` is either written for zFeeder or listed
below. There is no build step and no package manager on the front end: a file
lands here by being downloaded once, pinned by hash, and never edited.

## htmx

| | |
|---|---|
| File | `public/assets/modern/htmx.min.js` |
| Version | **2.0.4** |
| Source URL | `https://cdnjs.cloudflare.com/ajax/libs/htmx/2.0.4/htmx.min.js` |
| Downloaded | 2026-09-18 (`curl -fsSL`) |
| Size | 50,917 bytes |
| SHA-384 (SRI) | `sha384-HGfztofotfshcF7+8n44JQL2oJmowVChPTg48S+jvZoztPfvwD79OC/LTtG6dMp+` |
| SHA-256 | `e209dda5c8235479f3166defc7750e1dbcd5a5c1808b7792fc2e6733768fb447` |
| Licence | BSD 2-Clause (htmx project) |
| Used by | the admin panel only; the feed output ships no JavaScript at all |

Served from our own origin, so the `integrity` attribute is belt-and-braces
rather than a requirement — but it is what proves this file is the published
2.0.4 build, so keep it in the tag:

```html
<script src="{scripturl}assets/modern/htmx.min.js"
        integrity="sha384-HGfztofotfshcF7+8n44JQL2oJmowVChPTg48S+jvZoztPfvwD79OC/LTtG6dMp+"
        crossorigin="anonymous" defer></script>
```

The admin CSP is `default-src 'self'` with a nonce for inline htmx
configuration (B6/S10), so the script must stay first-party; do not switch the
tag back to the CDN.

### Verifying or upgrading

```sh
curl -fsSL -o public/assets/modern/htmx.min.js \
  https://cdnjs.cloudflare.com/ajax/libs/htmx/2.0.4/htmx.min.js
echo "sha384-$(openssl dgst -sha384 -binary public/assets/modern/htmx.min.js | openssl base64 -A)"
```

The printed value must equal the one in the table (and in the `integrity`
attribute). On an upgrade, change the version, the URL, both hashes and the
`integrity` attribute in the same commit.

## Not vendored

* `zf.css`, `zf-admin.css`, `zfeeder-logo.svg`, `icons.svg` — written for
  zFeeder 2.0, no upstream.
* Fonts — none. Both stylesheets use the system font stack (the classic admin
  skin asks for Verdana first, which is the 2004 lineage, and falls back to the
  system sans). Nothing is fetched from Google Fonts or any other third party.
