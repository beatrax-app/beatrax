#!/usr/bin/env bash
#
# Release smoke test for the self-hosted server shape. Brings the shipped stack
# up the way .docs/deployment.md tells an operator to, then asks the running
# application for real routes and checks what comes back.
#
# It exists because this recipe once answered 404 to every request while all
# four containers were up and `migrate` had reported success: FrankenPHP
# publishes no SERVER_ADDR, so the address gate fell through to its
# no-bind-address branch and refused the lot. Nothing that watched processes,
# ports or exit codes could see it. Only fetching a route can.
#
# Usage:  deploy/server/smoke.sh
# Leaves nothing behind: the stack and its volumes are torn down on exit.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="$ROOT/deploy/server/docker-compose.yml"
ENV_FILE="$ROOT/deploy/server/.env"
PORT="${HTTP_PORT:-8000}"

compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        echo "--- container state ---" >&2
        compose ps >&2 || true
        echo "--- container logs (last 200 lines) ---" >&2
        compose logs --no-color --tail=200 >&2 || true
    fi
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT

# The operator's step 1, with the one value they are told to generate. A key is
# 32 random bytes base64-encoded, which is what `key:generate` writes — minted
# here so the stack does not have to be started once just to produce it.
cp "$ROOT/deploy/server/.env.example" "$ENV_FILE"
{
    grep -Ev '^(APP_KEY|HTTP_PORT)=' "$ENV_FILE"
    printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
    printf 'HTTP_PORT=%s\n' "$PORT"
} > "$ENV_FILE.smoke"
mv "$ENV_FILE.smoke" "$ENV_FILE"

echo "==> building and starting the stack"
compose up -d --build

echo "==> waiting for the app container to report healthy"
container="$(compose ps -q app)"
[ -n "$container" ] || fail "no app container was created"

state=unknown
for _ in $(seq 1 60); do
    state="$(docker inspect -f '{{.State.Health.Status}}' "$container" 2>/dev/null || echo unknown)"
    case "$state" in
        healthy) break ;;
        unhealthy) fail "the app container reported unhealthy — it is serving something other than /health" ;;
    esac
    sleep 5
done
[ "$state" = healthy ] || fail "the app container never became healthy (last state: $state)"

# Inside the container, because the boundary is closed by default and Docker's
# NAT makes even a request from this machine arrive on a non-loopback peer
# address. This is the same path the healthcheck takes, asserted in full.
probe() { compose exec -T app curl -fsS --max-time 10 "http://127.0.0.1$1"; }
status_of() { compose exec -T app curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1$1"; }

echo "==> /health answers the contract"
body="$(probe /health)" || fail "/health did not answer"
echo "    $body"

BODY="$body" python3 <<'PY'
import json, os, sys

body = json.loads(os.environ["BODY"])
expected = {"status", "app_version", "php_version", "sqlite_version", "network_boundary"}

if set(body) != expected:
    sys.exit(f"SMOKE FAIL: /health returned keys {sorted(body)}, expected {sorted(expected)}")
if body["status"] != "ok":
    sys.exit(f"SMOKE FAIL: status was {body['status']!r}, expected 'ok'")
if body["network_boundary"] != "loopback":
    sys.exit(f"SMOKE FAIL: an install nobody widened reported boundary {body['network_boundary']!r}")
if not body["php_version"].startswith("8.5"):
    sys.exit(f"SMOKE FAIL: the image runs PHP {body['php_version']}, not 8.5")
if not body["sqlite_version"]:
    sys.exit("SMOKE FAIL: sqlite_version was empty, so no database connection was opened")
PY

echo "==> /health is byte-identical when asked twice"
second="$(probe /health)" || fail "/health did not answer a second time"
[ "$body" = "$second" ] || fail "/health is not deterministic; a probe cannot equality-check it
  first:  $body
  second: $second"

echo "==> a real application route is served, not 404"
root_status="$(status_of /)"
case "$root_status" in
    404) fail "/ answered 404 — the gate is refusing the whole deployment shape again" ;;
    000|5*) fail "/ answered '$root_status'" ;;
esac
echo "    / answered $root_status"

echo "==> the framework health page is not served"
up_status="$(status_of /up)"
[ "$up_status" = 404 ] || fail "/up answered $up_status; this app serves one health endpoint and it is /health"

# Deliberately not asserting 200 or 404 here. Which one an unwidened install
# gives back depends on whether the published port preserves the peer address,
# and that is a property of the container runtime rather than of this app: with
# a loopback peer the gate admits the request, with a NATed one it refuses by
# design. Both are correct answers. What must not happen is no answer at all,
# which is what a container that is up but serving nothing looks like from here.
echo "==> the published port reaches the application"
host_status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:${PORT}/health")"
case "$host_status" in
    200|404) echo "    published port answered $host_status (boundary: $([ "$host_status" = 200 ] && echo open to this host || echo closed until widened))" ;;
    *) fail "the published port answered '$host_status' — nothing is listening on it, or the app never got the request" ;;
esac

echo "SMOKE PASS"
