#!/usr/bin/env bash
# Records golden HTML output from the ORIGINAL zFeeder 1.6 running on PHP 5.6,
# using deterministic fixture feeds pre-placed in its cache so no network is touched.
# Re-run only when the golden corpus must change. Requires Docker.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
# The 1.6 container writes its cache into the mounted tree as its own user, so
# some of what is left behind is not ours to delete. Tidying up must not change
# the exit status of a recording that otherwise succeeded.
cleanup() {
    status=$?
    if [ -d "$WORK" ] && ! rm -rf "$WORK" 2>/dev/null; then
        docker run --rm -v "$WORK:/w" php:5.6-apache \
            sh -c "chown -R $(id -u):$(id -g) /w" >/dev/null 2>&1 || true
        rm -rf "$WORK" 2>/dev/null || true
    fi
    exit "$status"
}
trap cleanup EXIT
cp -r "$ROOT/legacy/zfeeder-1.6/." "$WORK/"
NF="$WORK/newsfeeds"
rm -f "$NF/categories"/*.opml
mangle() { printf '%s' "$1" | sed 's/[^[:alnum:]]/_/g'; }

# fixture feed url -> local fixture file
declare -A FEEDS=(
  ["http://fixtures.test/rss20-zfeeder.xml"]="$ROOT/tests/fixtures/feeds-2004/rss20-zfeeder.xml"
  ["http://fixtures.test/rss091-oldnews.xml"]="$ROOT/tests/fixtures/feeds-2004/rss091-oldnews.xml"
  ["http://fixtures.test/rss092-weblog.xml"]="$ROOT/tests/fixtures/feeds-2004/rss092-weblog.xml"
  ["http://fixtures.test/rdf10-devchannel.xml"]="$ROOT/tests/fixtures/feeds-2004/rdf10-devchannel.xml"
  ["http://fixtures.test/rss20-content-encoded.xml"]="$ROOT/tests/fixtures/feeds-2026/rss20-content-encoded.xml"
  ["http://fixtures.test/rss20-science.xml"]="$ROOT/tests/fixtures/feeds-2026/rss20-science.xml"
)
mkdir -p "$NF/cache"
for url in "${!FEEDS[@]}"; do
  cp "${FEEDS[$url]}" "$NF/cache/$(mangle "$url").xml"
done
# far-future mtime so timeExpired() is always false -> never fetches
find "$NF/cache" -name '*.xml' -exec touch -t 203001010000 {} +

opml() { # $1=category  rest: url|items|title
  local cat="$1"; shift; local i=1
  { echo '<?xml version="1.0"?>'
    echo '<opml version="1.0">'
    echo '  <head>'
    echo "    <title>$cat</title>"
    echo '    <dateModified>Wed, 25 Feb 2004 14:00:00 GMT</dateModified>'
    echo '  </head>'
    echo '  <body>'
    for spec in "$@"; do
      IFS='|' read -r url items title <<<"$spec"
      echo "    <outline type=\"rss\" position=\"$i\" text=\"$title\" title=\"$title\" description=\"$title description\" xmlUrl=\"$url\" htmlUrl=\"http://example.org/\" refreshTime=\"999999\" showedItems=\"$items\" isSubscribed=\"yes\" language=\"en\" />"
      i=$((i+1))
    done
    echo '  </body>'
    echo '</opml>'
  } > "$NF/categories/$cat.opml"
}
opml goldenA "http://fixtures.test/rss20-zfeeder.xml|3|zFeeder" "http://fixtures.test/rss091-oldnews.xml|2|Old News Daily"
opml goldenB "http://fixtures.test/rdf10-devchannel.xml|3|DevChannel" "http://fixtures.test/rss092-weblog.xml|2|Weblog Central"
opml goldenC "http://fixtures.test/rss20-content-encoded.xml|4|Tech Wire" "http://fixtures.test/rss20-science.xml|3|Science Desk"

cat > "$NF/config.php" <<'PHP'
<?php
define("ZF_LOGINTYPE","disabled");
define("ZF_URL","http://zf.test/newsfeeds/");
define("ZF_ADMINNAME","");
define("ZF_ADMINPASS","d41d8cd98f00b204e9800998ecf8427e");
define("ZF_REFRESHKEY","");
define("ZF_USEOPML","yes");
define("ZF_OPMLDIR","categories");
define("ZF_CATEGORY","goldenA");
define("ZF_CACHEDIR","cache");
define("ZF_OWNERNAME","Andrei Besleaga");
define("ZF_OWNEREMAIL","owner@example.org");
define("ZF_TEMPLATE","templates/bluelogos");
define("ZF_DISPLAYERROR","no");
define("ZF_CHANLOCATION","top");
define("ZF_CHANONEBAR","yes");
define("ZF_VER","1.6");
PHP

# harness page: renders ONLY the zfeeder output, nothing else
cat > "$WORK/golden.php" <<'PHP'
<?php include("newsfeeds/zfeeder.php");
PHP
chmod -R a+rwX "$WORK"

docker rm -f zfgolden >/dev/null 2>&1 || true
docker run -d --name zfgolden -p 127.0.0.1:8099:80 -v "$WORK":/var/www/html php:5.6-apache >/dev/null
docker exec zfgolden sh -c 'printf "error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_NOTICE \& ~E_STRICT\ndisplay_errors = Off\nallow_url_fopen = Off\ndate.timezone = UTC\n" > /usr/local/etc/php/conf.d/zf.ini'
docker restart zfgolden >/dev/null
sleep 4

OUT="$ROOT/tests/fixtures/goldens"
mkdir -p "$OUT"
rm -f "$OUT"/*.html
TEMPLATES="bluelogos greenlogos aqua ampheta simpleblue simplegray titlebox headlinebox simplecss infojunkie RiJ sidebar mainframe css"
CATS="goldenA goldenB goldenC"
n=0
for t in $TEMPLATES; do
  for c in $CATS; do
    curl -sS --fail "http://127.0.0.1:8099/golden.php?zftemplate=$t&zfcategory=$c" -o "$OUT/$t.$c.html"
    n=$((n+1))
  done
done
# variants that exercise the option matrix, on one template
curl -sS --fail "http://127.0.0.1:8099/golden.php?zftemplate=simplegray&zfcategory=goldenA&zf_link=off" -o "$OUT/simplegray.goldenA.nolink.html"
curl -sS --fail "http://127.0.0.1:8099/golden.php?zftemplate=simplegray&zfcategory=goldenA&zfposition=p2"  -o "$OUT/simplegray.goldenA.pos2.html"
curl -sS --fail "http://127.0.0.1:8099/golden.php?zftemplate=simplegray&zfcategory=goldenC&zfmore=0"       -o "$OUT/simplegray.goldenC.more0.html"
# channel location / one-bar matrix
for combo in "bottom yes" "bottom no" "top no" "none yes"; do
  set -- $combo
  loc="$1"; bar="$2"
  docker exec zfgolden sh -c "sed -i 's/define(\"ZF_CHANLOCATION\",\"[a-z]*\")/define(\"ZF_CHANLOCATION\",\"$loc\")/; s/define(\"ZF_CHANONEBAR\",\"[a-z]*\")/define(\"ZF_CHANONEBAR\",\"$bar\")/' /var/www/html/newsfeeds/config.php"
  curl -sS --fail "http://127.0.0.1:8099/golden.php?zftemplate=simplegray&zfcategory=goldenA" -o "$OUT/simplegray.goldenA.chan-$loc-$bar.html"
done
# copy the exact OPML inputs used, so tests replay identical data
mkdir -p "$ROOT/tests/fixtures/opml"
cp "$NF/categories"/golden*.opml "$ROOT/tests/fixtures/opml/"
docker rm -f zfgolden >/dev/null
echo "recorded $(ls "$OUT" | wc -l) golden files"
