# Release process

How a zFeeder 2.x release is cut. Version numbers and the compatibility promises behind them
are in [VERSIONING.md](../VERSIONING.md); what goes in the changelog is in
[CHANGELOG.md](../CHANGELOG.md).

**The repository owner performs every git write.** Nothing in this document commits, pushes
or tags on anyone's behalf. Where a step is a git command, it is written out for the owner to
run.

## 1. Check the gates on `main`

The release workflow **runs no tests**. It builds and publishes whatever the tag points at,
so the gates have to be green before the tag exists. These are the same commands
`.github/workflows/ci.yml` runs.

| Gate | Command | CI job |
|---|---|---|
| Manifest is valid | `composer validate --strict --no-check-publish` | Lint and static analysis |
| Coding standard | `vendor/bin/php-cs-fixer fix --dry-run --diff` (or `composer cs`) | Lint and static analysis |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=1G` (or `composer stan`) | Lint and static analysis |
| Architecture boundaries | `vendor/bin/deptrac analyse --config-file=deptrac.yaml` (or `composer deptrac`) | Lint and static analysis |
| Forbidden constructs | `./tools/check-forbidden.sh` | Lint and static analysis |
| Tests | `vendor/bin/phpunit` (or `composer test`) | PHP 8.3 / 8.4 × flat / sqlite |
| Coverage floor | `php tools/coverage-gate.php coverage.xml 90` | PHP 8.3 / flat only |
| Known vulnerabilities | `composer audit --no-interaction` | Dependency and secret scan |
| Secret scan | `gitleaks` | Dependency and secret scan |
| Image builds and answers | `./tools/smoke-image.sh zfeeder:ci` | Container image |
| Browser and accessibility suite | `./tools/run-e2e.sh` | Browser tests and accessibility |

`composer check` runs the coding standard, static analysis, deptrac and the tests in one go.
It does not cover the forbidden-constructs scan, `composer audit`, the image or the browser
suite; run those separately, or read them off a green CI run of the commit you are about to
tag.

The test matrix matters. `ZF_STORAGE=flat` and `ZF_STORAGE=sqlite` are separate runs of the
same suite, on PHP 8.3 and 8.4. A release goes out only when all four are green.

CodeQL (`.github/workflows/codeql.yml`) runs on every push to `main` and weekly. Note that it
is configured with `languages: javascript-typescript`, so it analyses the vendored htmx file and the
Playwright suite, not the PHP.

## 2. Prepare the commit that will be tagged

1. **Set the version.** `src/Version.php` holds `Version::NUMBER`, which is the single source
   of truth for `/healthz`, the demonstration site, the panel's updates screen, the
   `X-Zfeeder-Version` response header and the outgoing `User-Agent`
   (`zfeeder/<NUMBER> (+https://github.com/andreibesleaga/zfeeder)`). Update it.

   Two other files carry a literal version and are worth a look at the same time:
   `tools/sbom.php` writes a hard-coded `'version' => '2.0.0'` for the application component
   of the bill of materials, so a later release will describe itself as 2.0.0 until that is
   fixed; `tools/build-dist.sh` defaults to `2.0.0` only when it is called with no argument,
   which the workflow never does.

2. **Regenerate the configuration reference if `src/Config/Schema.php` changed.**
   `docs/CONFIGURATION.md` is generated:

   ```
   bin/zfeeder docs:config            # write it
   bin/zfeeder docs:config --check    # fail if it is out of date
   ```

3. **Write the changelog section.** Move the entries under `## [Unreleased]` into a new
   heading and leave `## [Unreleased]` in place, empty. The heading must start with the bare
   version number, because `tools/changelog-section.php` matches `^##\s+\[?v?([0-9]…)` and
   compares the captured text with the tag minus its leading `v`:

   ```
   ## [Unreleased]

   ## [2.0.1] — 2026-10-04
   ```

   A mismatch is not fatal — the release notes fall back to the single line
   `Release <version>` — but it means the release page says nothing.

4. **Commit.** Conventional Commits and a DCO sign-off, per
   [CONTRIBUTING.md](../CONTRIBUTING.md):

   ```
   git commit -s -m "chore(release): 2.0.1"
   git push origin main
   ```

5. **Wait for CI on that commit.** Tag nothing until it is green.

## 3. Tag

```
git tag -a v2.0.1 -m "zFeeder 2.0.1"
git push origin v2.0.1
```

`.github/workflows/release.yml` triggers on pushed tags matching `v2.*`. The tag name with
its `v` stripped becomes the archive version, so `v2.0.1` produces `zfeeder-2.0.1.zip`.

The workflow also declares `workflow_dispatch` with a `tag` input, but **no step reads that
input**: every job uses `GITHUB_REF_NAME`. Dispatching it from a branch would build archives
named after the branch and publish a container image tagged `latest` with no version tags.
Push a tag; do not dispatch it manually.

## 4. What the workflow does

Three jobs, all on `ubuntu-latest`.

### `package` — build the distribution archives

1. `./tools/build-dist.sh "${GITHUB_REF_NAME#v}"`, which:
   - runs `composer install --no-dev … --classmap-authoritative`;
   - stages `bin src public templates templates-admin data-dist vendor docs zfeeder.php
     composer.json LICENSE README.md CHANGELOG.md SECURITY.md`, plus `deploy/apache-vhost.conf`
     and `deploy/php.ini`, and `deploy/shared-hosting.htaccess` as `public/.htaccess`;
   - normalises permissions to 0755 for directories and 0644 for files, and 0755 for
     `bin/zfeeder`;
   - writes `dist/zfeeder-<version>.zip`, `dist/zfeeder-<version>.tar.gz` and
     `dist/SHA256SUMS`;
   - reinstalls the development dependencies afterwards.
2. `composer install --no-dev`, then `composer licenses --format=json > dist/licenses.json`.
3. `php tools/sbom.php > dist/zfeeder-sbom.cdx.json` — a CycloneDX 1.5 bill of materials
   built from `composer.lock`.
4. Uploads `dist/` as the artefact named `dist`.

The archives carry `vendor/`, so a shared-hosting user uploads and runs them without Composer.

### `image` — publish the container image

Builds `deploy/Dockerfile` for `linux/amd64` and `linux/arm64` and pushes to
`ghcr.io/<owner>/<repo>` using the workflow's own `GITHUB_TOKEN`; no registry credentials
need to be configured. `docker/metadata-action` applies three tags: the full version, the
`major.minor` prefix and `latest`. Provenance attestation and an image SBOM are both enabled.

### `publish` — create the GitHub release

Needs both jobs above. Downloads the `dist` artefact, runs
`php tools/changelog-section.php "$GITHUB_REF_NAME" > release-notes.md`, and creates the
release with `softprops/action-gh-release@v2`, attaching every file in `dist/` and using the
extracted changelog section as the body. `generate_release_notes` is off, so the body is
exactly what the changelog says.

## 5. After the workflow finishes

These are manual, and the maintainer does them.

1. **Check the release page.** Five files should be attached: the `.zip`, the `.tar.gz`,
   `SHA256SUMS`, `licenses.json` and `zfeeder-sbom.cdx.json`. Confirm the body is the
   changelog section and not the `Release <version>` fallback.
2. **Verify one archive.** Download it, check it against `SHA256SUMS`, unpack it and serve
   `public/` — the shared-hosting path is the one with no CI coverage after the build.
3. **Pull the image and check it.** `docker pull ghcr.io/andreibesleaga/zfeeder:2.0.1`, then
   `tools/smoke-image.sh ghcr.io/andreibesleaga/zfeeder:2.0.1`, which starts the container and
   waits on `/healthz`. `/healthz` reports the version, so it also confirms the version bump
   in step 2.1 landed.
4. **Packagist.** The package is `andreibesleaga/zfeeder`. Packagist updates itself from the
   repository's GitHub hook once the package is submitted; if the hook is not configured, use
   the "Update" button on the package page. A first release has to be submitted by hand at
   <https://packagist.org/packages/submit>.
5. **Announce it**, if there is anything to announce. A security release should say so
   plainly and name the versions affected, per [SECURITY.md](../SECURITY.md).
6. **Expect the Pages workflow to fail, for now.** `.github/workflows/pages.yml` publishes the
   portfolio page on every push to `main` that touches `docs/`. It calls
   `./tools/build-pages.sh`, **which does not exist in this repository**, so the job fails and
   the published page does not refresh. It has nothing to do with the release archives, but it
   will be red next to them.

## Security releases

Same process, with two differences. Fix first on a private branch or through a GitHub
security advisory, and do not push the tag until the advisory is ready to publish — the tag
makes the fix public the moment the release page appears. Name the affected versions in both
the changelog section and the advisory.

## A checklist to copy

```
[ ] CI green on the commit to be tagged (all four matrix legs)
[ ] composer audit clean
[ ] tools/run-e2e.sh green
[ ] src/Version.php bumped
[ ] bin/zfeeder docs:config --check passes
[ ] CHANGELOG.md: new heading, [Unreleased] left empty
[ ] commit signed off, pushed, CI green again
[ ] git tag -a v2.x.y && git push origin v2.x.y
[ ] release page: five files, changelog body
[ ] archive unpacked and served once
[ ] image pulled and smoke tested
[ ] Packagist shows the new version
```
