#!/usr/bin/env bash
# Renders docs/diagrams/*.mmd to SVG.
#
# Mermaid is not a project dependency: GitHub renders ```mermaid blocks in
# Markdown by itself, and the .mmd sources are the thing under version control.
# The SVGs exist so the diagrams are also readable outside GitHub — in an
# editor, in the release archive, on the portfolio page. Run this after editing
# a diagram; it installs mermaid into a scratch directory on first use.
set -euo pipefail
cd "$(dirname "$0")/.."

DIR="docs/diagrams"
WORK="${ZF_MERMAID_DIR:-${TMPDIR:-/tmp}/zfeeder-mermaid}"
NODE_MODULES="$WORK/node_modules"

if [ ! -f "$NODE_MODULES/mermaid/dist/mermaid.min.js" ]; then
    echo "installing mermaid into $WORK (first run only)"
    mkdir -p "$WORK"
    ( cd "$WORK" && npm install --silent --no-audit --no-fund mermaid >/dev/null )
fi

: "${NODE_PATH:=}"
export NODE_PATH="${NODE_PATH:+$NODE_PATH:}$NODE_MODULES:${ZF_PLAYWRIGHT_MODULES:-$HOME/work/AI/kaiban-distributed/node_modules}"

# Resolve playwright's entry point: ESM cannot find it through NODE_PATH.
PLAYWRIGHT_DIR="${ZF_PLAYWRIGHT_MODULES:-$HOME/work/AI/kaiban-distributed/node_modules}/playwright"
if [ ! -d "$PLAYWRIGHT_DIR" ]; then
    PLAYWRIGHT_DIR="tests/e2e/node_modules/playwright"
fi
if [ ! -d "$PLAYWRIGHT_DIR" ]; then
    echo "playwright not found; set ZF_PLAYWRIGHT_MODULES to a node_modules containing it" >&2
    exit 1
fi

case "$PLAYWRIGHT_DIR" in
    /*) PLAYWRIGHT_ENTRY="$PLAYWRIGHT_DIR/index.mjs" ;;
    *)  PLAYWRIGHT_ENTRY="$PWD/$PLAYWRIGHT_DIR/index.mjs" ;;
esac

node tools/render-diagrams.mjs "$DIR" "$NODE_MODULES/mermaid/dist/mermaid.min.js" "$PLAYWRIGHT_ENTRY"
