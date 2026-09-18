#!/usr/bin/env bash
# Builds the archives a shared-hosting user downloads: everything needed to
# upload and run, vendor/ included, tests and CI excluded.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:-2.0.0}"
STAGE="build/zfeeder-$VERSION"

rm -rf build dist
mkdir -p "$STAGE" dist

composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --classmap-authoritative

for item in bin src public templates templates-admin data-dist vendor docs zfeeder.php composer.json LICENSE README.md CHANGELOG.md SECURITY.md; do
    [ -e "$item" ] && cp -r "$item" "$STAGE/"
done
mkdir -p "$STAGE/deploy"
cp deploy/apache-vhost.conf deploy/php.ini "$STAGE/deploy/" 2>/dev/null || true
cp deploy/shared-hosting.htaccess "$STAGE/public/.htaccess" 2>/dev/null || true

find "$STAGE" -name '.DS_Store' -delete
find "$STAGE" -type d -exec chmod 0755 {} +
find "$STAGE" -type f -exec chmod 0644 {} +
chmod 0755 "$STAGE/bin/zfeeder"

( cd build && zip -qr "../dist/zfeeder-$VERSION.zip" "zfeeder-$VERSION" )
( cd build && tar -czf "../dist/zfeeder-$VERSION.tar.gz" "zfeeder-$VERSION" )

( cd dist && sha256sum ./*.zip ./*.tar.gz > SHA256SUMS )

# Restore the development dependencies for whatever runs next.
composer install --no-interaction --no-progress --quiet

ls -la dist/
echo "built zfeeder-$VERSION"
