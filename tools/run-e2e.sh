#!/usr/bin/env bash
# Runs the browser and accessibility suite against a real server.
#
#   tools/run-e2e.sh                 build and use the container image
#   ZF_MODE=builtin tools/run-e2e.sh use the PHP development server (faster locally)
#   ZF_BASE_URL=https://...          test an already deployed instance
set -euo pipefail
cd "$(dirname "$0")/.."

PORT="${ZF_PORT:-8181}"
MODE="${ZF_MODE:-container}"
E2E_PASSWORD='e2e-password-2026'
# Generated with: bin/zfeeder hash-password 'e2e-password-2026'
HASH="${ZF_E2E_HASH:-}"
DATA_DIR="$(mktemp -d)"
CONTAINER="zfeeder-e2e-$$"
SERVER_PID=""

# Teardown must never decide the outcome: by the time this runs the suite has
# already passed or failed, and a tidying-up problem is not a test result. The
# container owns the data directory it was given — it writes the cache and the
# config as root — so those files are not ours to delete. Hand the removal back
# to a container before falling back to leaving the directory behind.
cleanup() {
    status=$?
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    if [ -d "$DATA_DIR" ] && ! rm -rf "$DATA_DIR" 2>/dev/null; then
        docker run --rm -v "$DATA_DIR:/data" --entrypoint /bin/sh zfeeder:e2e \
            -c "chown -R $(id -u):$(id -g) /data" >/dev/null 2>&1 || true
        rm -rf "$DATA_DIR" 2>/dev/null || true
    fi
    exit "$status"
}
trap cleanup EXIT

if [ -z "$HASH" ]; then
    HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$E2E_PASSWORD")
fi

mkdir -p "$DATA_DIR/categories" "$DATA_DIR/cache"
cp data-dist/categories/*.opml "$DATA_DIR/categories/"

# Deterministic content: pre-seed the cache from the test fixtures so the suite
# never depends on a live feed being up or on what today's headlines say.
php tools/seed-e2e-cache.php "$DATA_DIR"

export ZF_BASE_URL="${ZF_BASE_URL:-http://127.0.0.1:$PORT}"

if [ -n "${ZF_BASE_URL_EXTERNAL:-}" ]; then
    echo "testing an external instance at $ZF_BASE_URL"
elif [ "$MODE" = "builtin" ]; then
    echo "starting the PHP development server on port $PORT"
    ZF_ENV=development \
    ZF_DATA_DIR="$DATA_DIR" \
    ZF_ADMIN_USER=admin \
    ZF_ADMIN_PASSWORD_HASH="$HASH" \
    ZF_REFRESH_MODE=offline \
    ZF_ALLOW_PRIVATE_HOSTS=true \
    php -S "127.0.0.1:$PORT" -t public public/index.php >/tmp/zfeeder-e2e-server.log 2>&1 &
    SERVER_PID=$!
else
    echo "building the container image"
    docker build -f deploy/Dockerfile -t zfeeder:e2e . >/dev/null
    docker run -d --name "$CONTAINER" -p "127.0.0.1:$PORT:80" \
        -e ZF_ENV=production \
        -e ZF_ADMIN_USER=admin \
        -e ZF_ADMIN_PASSWORD_HASH="$HASH" \
        -e ZF_REFRESH_MODE=offline \
        -e ZF_LOG_PATH=php://stdout \
        -v "$DATA_DIR:/var/www/data" \
        zfeeder:e2e >/dev/null
fi

echo "waiting for $ZF_BASE_URL/healthz"
for i in $(seq 1 45); do
    if curl -fsS "$ZF_BASE_URL/healthz" >/dev/null 2>&1; then break; fi
    if [ "$i" = 45 ]; then
        echo "server never became healthy"
        [ "$MODE" = "builtin" ] && tail -40 /tmp/zfeeder-e2e-server.log
        [ "$MODE" = "container" ] && docker logs "$CONTAINER" 2>&1 | tail -40
        exit 1
    fi
    sleep 1
done

cd tests/e2e
[ -d node_modules ] || npm install --silent
npx playwright test "$@"
