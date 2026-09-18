# Licences

## The project

zFeeder 2.0 is **GPL-2.0-or-later**. The full text of GNU General Public License version 2 is
in [LICENSE](../LICENSE) at the root of the repository, and `composer.json` declares
`"license": "GPL-2.0-or-later"`.

Copyright © 2003–2026 Andrei N. Besleaga.

Everything written for this repository is under that licence, including:

- the PHP source in `src/`, `bin/` and `public/`;
- the modern template set in `templates/modern/` and the admin templates in
  `templates-admin/`;
- the stylesheets and SVG assets in `public/assets/modern/`, except `htmx.min.js` (below);
- the tools in `tools/` and the tests in `tests/`;
- the documentation in `docs/`.

### The 2004 material

`templates/classic/*.html`, `public/assets/classic/images/` and
`public/assets/classic/css/` are the author's own work from 2003–2004, carried into this
repository unchanged and released under the same licence. They are not third-party material
and no separate attribution is required. `legacy/zfeeder-1.6/` is the 2004 release itself,
kept as history; it ships its own copy of the same GPL v2 text at
`legacy/zfeeder-1.6/LICENSE`.

## Runtime dependencies

Twenty-seven Composer packages are installed when development dependencies are excluded, which
is what `tools/build-dist.sh` puts into the distribution archives. Versions below are the ones
pinned in `composer.lock`.

### Declared in `composer.json`

| Package | Version | Licence |
|---|---|---|
| `laminas/laminas-feed` | 2.26.2 | BSD-3-Clause |
| `nyholm/psr7` | 1.8.2 | MIT |
| `nyholm/psr7-server` | 1.1.0 | MIT |
| `psr/http-message` | 2.0 | MIT |
| `psr/http-server-handler` | 1.0.2 | MIT |
| `psr/http-server-middleware` | 1.0.2 | MIT |
| `psr/log` | 3.0.2 | MIT |
| `symfony/console` | v7.4.19 | MIT |
| `symfony/html-sanitizer` | v7.4.19 | MIT |
| `symfony/http-client` | v7.4.19 | MIT |
| `twig/twig` | v3.28.0 | BSD-3-Clause |

### Pulled in by those

| Package | Version | Licence |
|---|---|---|
| `laminas/laminas-escaper` | 2.18.0 | BSD-3-Clause |
| `laminas/laminas-stdlib` | 3.21.0 | BSD-3-Clause |
| `league/uri` | 7.8.1 | MIT |
| `league/uri-interfaces` | 7.8.1 | MIT |
| `masterminds/html5` | 2.11.0 | MIT |
| `psr/container` | 2.0.2 | MIT |
| `psr/http-factory` | 1.1.0 | MIT |
| `symfony/deprecation-contracts` | v3.7.1 | MIT |
| `symfony/http-client-contracts` | v3.7.3 | MIT |
| `symfony/polyfill-ctype` | v1.37.0 | MIT |
| `symfony/polyfill-intl-grapheme` | v1.41.0 | MIT |
| `symfony/polyfill-intl-normalizer` | v1.42.0 | MIT |
| `symfony/polyfill-mbstring` | v1.38.2 | MIT |
| `symfony/polyfill-php83` | v1.41.0 | MIT |
| `symfony/service-contracts` | v3.7.3 | MIT |
| `symfony/string` | v7.4.19 | MIT |

**Summary: 23 MIT, 4 BSD-3-Clause.** Both are permissive and compatible with distributing
this program under GPL-2.0-or-later. Nothing here is copyleft, and there is no dependency
with a licence that would restrict commercial use.

Five PHP extensions are required — `ext-dom`, `ext-json`, `ext-libxml`, `ext-mbstring` and
`ext-simplexml` — and two are suggested, `ext-pdo_sqlite` for the SQLite backend and
`ext-curl` for faster fetching. They are part of PHP and carry the PHP License; nothing is
bundled for them.

## Bundled third-party assets

| Asset | Version | Where |
|---|---|---|
| htmx | 2.0.4 | `public/assets/modern/htmx.min.js` |

htmx is the only third-party file checked into this repository. It is served from the site's
own origin, loaded by the two admin layouts only, and used for partial page updates in the
panel. The version was read from the `version:"2.0.4"` string inside the file.

htmx is published by its authors under the Zero-Clause BSD licence (0BSD).
**This repository does not currently ship that licence text beside the minified file**, and
the minified file carries no licence header of its own. Adding a short notice next to the
asset would close that gap.

## Development dependencies

These are not distributed. `tools/build-dist.sh` runs `composer install --no-dev` before
staging the archive, so nothing below reaches a user.

| Package | Version | Licence |
|---|---|---|
| `deptrac/deptrac` | 4.7.2 | MIT |
| `friendsofphp/php-cs-fixer` | v3.95.25 | MIT |
| `phpstan/phpstan` | 2.2.14 | MIT |
| `phpstan/phpstan-strict-rules` | 2.0.12 | MIT |
| `phpunit/phpunit` | 11.5.56 | BSD-3-Clause |

The browser suite adds `@playwright/test` and `@axe-core/playwright` through
`tests/e2e/package.json`. They are development tooling as well, installed by
`tools/run-e2e.sh` and by CI, and they are not part of any archive.

## Regenerating this list

```
composer licenses --no-dev
composer licenses --no-dev --format=json
```

Every release does the same thing automatically. `.github/workflows/release.yml` writes
`composer licenses --format=json` to `dist/licenses.json` after a `--no-dev` install, and runs
`php tools/sbom.php` to produce `dist/zfeeder-sbom.cdx.json`, a CycloneDX 1.5 bill of
materials built from `composer.lock` with a licence identifier for each component. Both files
are attached to the GitHub release. The container image published by the same workflow carries
an SBOM and a provenance attestation of its own.

If you add a dependency, update the table above in the same pull request. New runtime
dependencies need a stated reason — see [CONTRIBUTING.md](../CONTRIBUTING.md).
