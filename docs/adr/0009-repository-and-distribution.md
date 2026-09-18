# 0009. One public repository, three distribution channels

## Status

Accepted.

## Context

The rebuild has two audiences that install software in incompatible ways. The 2004 audience uploads files
over FTP, has no shell and no Composer, and expects an archive that runs where it lands. The 2026 audience
expects `composer require`, a container image, or a clone plus `composer install`. There was also the
question of where the 1.6 code should live: keeping it as a separate archive would have been tidier and would
have hidden the comparison that justifies the exercise.

## Decision

A single public repository, `andreibesleaga/zfeeder`, with the untouched 1.6 tree committed first at
`legacy/zfeeder-1.6/`, so the history itself shows the 2004 code and then the rebuild landing on top of it.
The Composer package carries the same name.

Three channels come out of `.github/workflows/release.yml` on a `v2.*` tag:

1. **Archives.** `tools/build-dist.sh` runs `composer install --no-dev --classmap-authoritative`, stages
   `bin src public templates templates-admin data-dist vendor docs zfeeder.php composer.json LICENSE
   README.md CHANGELOG.md SECURITY.md`, drops `deploy/shared-hosting.htaccess` in as `public/.htaccess`,
   normalises permissions to 0755/0644 and produces a `.zip`, a `.tar.gz` and `SHA256SUMS`. `vendor/` is
   inside, so nothing has to be built on the host.
2. **A container image** built from `deploy/Dockerfile` for `linux/amd64` and `linux/arm64`, pushed to
   `ghcr.io/andreibesleaga/zfeeder` with provenance and an SBOM.
3. **A GitHub release** carrying everything in `dist/`, with notes extracted from `CHANGELOG.md` by
   `tools/changelog-section.php`, plus `composer licenses` output and `tools/sbom.php`'s CycloneDX document.

Packagist is not automated: it watches the repository once the owner has submitted the package, which is an
owner-side account action, as is every git write in this project.

## Consequences

- `legacy/zfeeder-1.6/` ships in the repository, about 2,100 lines of PHP 4 that nothing loads. The goldens
  and `bin/zfeeder legacy-import` both read from it, so it is a fixture as much as an exhibit.
- `deploy/` carries the recipes the image does not cover: `docker-compose.yml`, `k8s.yaml`,
  `fly.toml.example`, `render.yaml.example`, `nginx.conf.example`, `apache-vhost.conf`,
  `php.ini`, `entrypoint.sh` and `shared-hosting.htaccess`. There is no `railway.toml`:
  Railway selects the Dockerfile through the `RAILWAY_DOCKERFILE_PATH` variable, and the
  rest of the service is variables and one volume, so a file would have added nothing that
  could be checked in. `.railwayignore` at the repository root does the one thing a file is
  needed for, trimming the build context.
- Not done yet: the repository has no commits. The first commit of the 1.6 tree, the GitHub repository, the
  Packagist submission and the ghcr login are all owner actions still outstanding.

## Alternatives considered

- **Two repositories**, one archival and one for 2.0. The side-by-side diff is the portfolio piece, and a
  submodule is not the same thing.
- **Composer-only distribution.** It drops `tools/build-dist.sh` and the archive job, and with them the users
  this rebuild is aimed at.

**Update, 18 September 2026.** The 1.6 tree was removed from this repository and archived at
<https://github.com/andreibesleaga/old-projects> (`zfeeder-1.6.zip`); the minimal 2004 corpus the tests
need now lives in `tests/fixtures/legacy-1.6/`.
