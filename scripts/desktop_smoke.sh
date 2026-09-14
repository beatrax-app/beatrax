#!/usr/bin/env bash
# Release smoke test for a built desktop bundle. Launches what the build just
# produced and asks it for /health, which is the only thing that proves the
# bundle RUNS rather than merely being shaped right (REPO-R29, OPS-R5).
#
# A signature check, a contents scan and a permission scan all pass for a
# bundle that dies on launch: a missing runtime, an entitlement that blocks
# execution, an interpreter that cannot map under a sandbox. Only fetching a
# route catches those, which is the same reason deploy/server/smoke.sh exists
# for the self-host shape.
#
# Usage: desktop_smoke.sh <expected-app-version> <launch-command...>
# Leaves nothing behind: the bundle is stopped on exit, however this ends.
set -euo pipefail

EXPECTED_VERSION="${1:?expected app version (the tag without its leading v)}"
shift
[ "$#" -gt 0 ] || { echo "SMOKE FAIL: no launch command given" >&2; exit 1; }

# NativePHP's PHP server takes the first free port in this range (get-port,
# portNumbers(8100, 9000) in the vendored electron plugin), so the port is
# discovered rather than assumed — a runner holding 8100 shifts it by one.
PORT_FROM="${SMOKE_PORT_FROM:-8100}"
PORT_TO="${SMOKE_PORT_TO:-8130}"
DEADLINE=$((SECONDS + 180))

fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

APP_PID=""
cleanup() {
    if [ -n "$APP_PID" ] && kill -0 "$APP_PID" 2>/dev/null; then
        kill "$APP_PID" 2>/dev/null || true
        wait "$APP_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT

# A port in the range that already answers makes this test unable to tell its
# own bundle from whatever else is listening: it would report PASS for a bundle
# that died on launch. Proven by inverting it -- with a dead launch command and
# a desktop app already on 8100, the check below is the only thing that fails.
for port in $(seq "$PORT_FROM" "$PORT_TO"); do
    if curl -fsS --max-time 2 "http://127.0.0.1:${port}/health" >/dev/null 2>&1; then
        fail "something already answers /health on port ${port}; this test could not tell that from the bundle it launches"
    fi
done

echo "==> launching: $*"
"$@" >/tmp/desktop-smoke-app.log 2>&1 &
APP_PID=$!

echo "==> asking ports ${PORT_FROM}-${PORT_TO} for /health"
BODY=""
while [ "$SECONDS" -lt "$DEADLINE" ]; do
    if ! kill -0 "$APP_PID" 2>/dev/null; then
        echo "--- the bundle's own output ---" >&2
        tail -40 /tmp/desktop-smoke-app.log >&2 || true
        fail "the bundle exited before it answered /health"
    fi

    for port in $(seq "$PORT_FROM" "$PORT_TO"); do
        if BODY=$(curl -fsS --max-time 3 "http://127.0.0.1:${port}/health" 2>/dev/null); then
            echo "==> /health answered on port ${port}"
            break 2
        fi
    done

    BODY=""
    sleep 3
done

if [ -z "$BODY" ]; then
    echo "--- the bundle's own output ---" >&2
    tail -40 /tmp/desktop-smoke-app.log >&2 || true
    fail "no port in ${PORT_FROM}-${PORT_TO} answered /health within 180s"
fi

echo "    $BODY"

BODY="$BODY" EXPECTED_VERSION="$EXPECTED_VERSION" python3 <<'PY'
import json, os, sys

body = json.loads(os.environ["BODY"])
expected_keys = {"status", "app_version", "php_version", "sqlite_version", "network_boundary"}

if set(body) != expected_keys:
    sys.exit(f"SMOKE FAIL: /health returned keys {sorted(body)}, expected {sorted(expected_keys)}")

if body["status"] != "ok":
    sys.exit(f"SMOKE FAIL: /health reports status {body['status']!r}, not 'ok'")

# The bundle reads NATIVEPHP_APP_VERSION, which the workflow sets from the tag.
# A bundle that answers with any other version is not the one the tag asked for.
want = os.environ["EXPECTED_VERSION"]
if body["app_version"] != want:
    sys.exit(f"SMOKE FAIL: the bundle reports app_version {body['app_version']!r}, tag asked for {want!r}")

# A shipped bundle must serve loopback only; anything else is reachable from
# the network the machine sits on.
if body["network_boundary"] != "loopback":
    sys.exit(f"SMOKE FAIL: network_boundary is {body['network_boundary']!r}, not 'loopback'")

print(f"    status=ok app_version={body['app_version']} php={body['php_version']} "
      f"sqlite={body['sqlite_version']} boundary={body['network_boundary']}")
PY

echo "==> SMOKE PASS: the bundle launched and answered /health as ${EXPECTED_VERSION}"
