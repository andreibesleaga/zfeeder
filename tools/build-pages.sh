#!/usr/bin/env bash
# Renders docs/portfolio/ into a static site for GitHub Pages.
# No site generator: the portfolio is two pages, and a dependency that has to be
# kept current for two pages is a worse trade than forty lines of shell.
set -euo pipefail
cd "$(dirname "$0")/.."

OUT="build/pages"
rm -rf "$OUT"
mkdir -p "$OUT"

cp -r docs/portfolio/img "$OUT/img" 2>/dev/null || true

php tools/render-markdown.php docs/portfolio/index.md "zFeeder — 2004 and 2026" > "$OUT/index.html"
php tools/render-markdown.php docs/HISTORY.md "zFeeder — the history" > "$OUT/history.html"

# A .nojekyll file stops Pages from hiding directories that begin with an underscore.
touch "$OUT/.nojekyll"

echo "built $(find "$OUT" -type f | wc -l) files in $OUT"
