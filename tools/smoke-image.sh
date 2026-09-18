#!/usr/bin/env bash
# Starts the built image and checks that the real endpoints answer.
# Usage: tools/smoke-image.sh [image-tag]
set -euo pipefail
IMAGE="${1:-zfeeder:ci}"
NAME="zfeeder-smoke-$$"
PORT="${PORT:-8199}"
# Generated per run: a password hash in the repository is a hash someone will
# eventually deploy, however loudly the comment says not to.
HASH="$(php -r 'echo password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);')"

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

docker run -d --name "$NAME" -p "127.0.0.1:$PORT:80" \
    -e ZF_ENV=production \
    -e ZF_REFRESH_MODE=offline \
    -e ZF_ADMIN_PASSWORD_HASH="$HASH" \
    -e ZF_LOG_PATH=php://stdout \
    "$IMAGE" >/dev/null

echo "waiting for the container to become healthy"
for i in $(seq 1 40); do
    if curl -fsS "http://127.0.0.1:$PORT/healthz" >/dev/null 2>&1; then break; fi
    if [ "$i" = 40 ]; then
        echo "container never answered; logs follow:"; docker logs "$NAME" 2>&1 | tail -40; exit 1
    fi
    sleep 1
done

fail=0
expect() { # path expected-status [must-contain]
    local path="$1" want="$2" needle="${3:-}"
    local body status
    body=$(curl -sS -o /tmp/smoke-body.$$ -w '%{http_code}' "http://127.0.0.1:$PORT$path" || echo 000)
    status="$body"
    if [ "$status" != "$want" ]; then
        echo "FAIL $path -> $status (expected $want)"; fail=1
    elif [ -n "$needle" ] && ! grep -q "$needle" /tmp/smoke-body.$$; then
        echo "FAIL $path -> missing '$needle'"; fail=1
    else
        echo "ok   $path -> $status"
    fi
    rm -f /tmp/smoke-body.$$
}

expect /healthz 200 '"status"'
expect / 200 'zFeeder'
expect /admin 303 ''                 # anonymous visitors are sent to the sign-in form
expect /admin/login 200 'csrf_token'  # and the form carries a CSRF token
expect /demos/one-line 200 ''
expect /nope 404 ''
expect /../../etc/passwd 404 ''

echo "checking security headers"
headers=$(curl -sSI "http://127.0.0.1:$PORT/admin")
for h in "content-security-policy" "x-content-type-options" "referrer-policy"; do
    if ! printf '%s' "$headers" | tr 'A-Z' 'a-z' | grep -q "$h"; then
        echo "FAIL missing header: $h"; fail=1
    else
        echo "ok   header $h"
    fi
done

echo "checking the data directory is not served"
expect /../data/config.json 404 ''

if [ $fail -ne 0 ]; then
    echo "--- container logs ---"; docker logs "$NAME" 2>&1 | tail -30
    exit 1
fi
echo "smoke test passed"
