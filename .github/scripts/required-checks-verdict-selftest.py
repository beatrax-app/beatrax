#!/usr/bin/env python3
"""Prove required-checks-verdict.py still refuses to say green, before it says it.

The bug this script exists to prevent was a notifier that announced a passing
build twice while the checks that would fail it had not been queued yet. So the
cases that matter are the ones a careless edit turns green: a context that has
not been created, a context still running, a context that failed, and a branch
whose ruleset came back empty.

This stands a fake GitHub in front of the real script and asserts the colour it
writes for each. A few seconds against a loopback socket, most of it the backoff
being ridden out, so it can sit in front of the real evaluation on every run.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))
VERDICT = os.path.join(HERE, "required-checks-verdict.py")

GREEN = "3066993"
RED = "15158332"
AMBER = "15844367"

REQUIRED = ["quality (PHP 8.5)", "coverage", "SonarCloud Code Analysis"]


def done(name: str, conclusion: str) -> dict[str, object]:
    return {"name": name, "status": "completed", "conclusion": conclusion}


SCENARIOS: dict[str, dict[str, object]] = {
    "green": {"check_runs": [done(name, "success") for name in REQUIRED]},
    "red": {"check_runs": [done(REQUIRED[0], "success"), done(REQUIRED[1], "failure"), done(REQUIRED[2], "success")]},
    "skipped": {"check_runs": [done(REQUIRED[0], "success"), done(REQUIRED[1], "skipped"), done(REQUIRED[2], "success")]},
    "unqueued": {"check_runs": [done(REQUIRED[0], "success"), done(REQUIRED[1], "success")]},
    "running": {
        "check_runs": [
            done(REQUIRED[0], "success"),
            done(REQUIRED[1], "success"),
            {"name": REQUIRED[2], "status": "in_progress", "conclusion": None},
        ],
    },
    "viastatus": {
        "check_runs": [done(REQUIRED[0], "success"), done(REQUIRED[1], "success")],
        "statuses": [{"context": REQUIRED[2], "state": "success"}],
    },
    "cancelled": {"check_runs": [done(name, "cancelled") for name in REQUIRED]},
}

CASES = [
    ("green", "every required check passed", GREEN),
    ("red", "a required check failed", RED),
    ("skipped", "a skipped required check is one GitHub counts as satisfied", GREEN),
    ("unqueued", "a required check that was never queued is not a pass", AMBER),
    ("running", "a required check still running is not a pass", AMBER),
    ("viastatus", "a required context reported as a commit status, not a check run", GREEN),
    ("cancelled", "a cancelled required check is not a pass", RED),
]


class Stub(BaseHTTPRequestHandler):
    refusals = 0

    def log_message(self, *args: object) -> None:
        pass

    def reply(self, payload: object) -> None:
        body = json.dumps(payload).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802 — the name BaseHTTPRequestHandler dispatches to
        path = self.path.split("?")[0]

        if path.endswith("/rules/branches/flaky") and Stub.refusals < 2:
            Stub.refusals += 1
            self.send_error(502)
        elif path.endswith("/rules/branches/bare"):
            self.reply([])
        elif "/rules/branches/" in path:
            rule = {"type": "required_status_checks", "parameters": {
                "required_status_checks": [{"context": name} for name in REQUIRED]}}
            self.reply([{"type": "pull_request"}, rule])
        else:
            scenario = SCENARIOS[path.split("/commits/")[1].split("/")[0]]
            key = "statuses" if path.endswith("/status") else "check_runs"
            self.reply({key: scenario.get(key, [])})


def run(base: str, sha: str, branch: str = "main") -> tuple[int, str, str]:
    with tempfile.NamedTemporaryFile("w+", suffix=".txt", delete=False) as output:
        path = output.name

    environment = {
        **os.environ,
        "BRANCH": branch,
        "GITHUB_API_URL": base,
        "GITHUB_OUTPUT": path,
        "GITHUB_REPOSITORY": "beatrax-app/beatrax",
        "GITHUB_TOKEN": "stub",
        "POLL_SECONDS": "1",
        "SHA": sha,
        "WAIT_SECONDS": "0",
    }

    result = subprocess.run([sys.executable, VERDICT], env=environment, capture_output=True, text=True, timeout=60)

    with open(path, encoding="utf-8") as handle:
        written = handle.read()

    os.unlink(path)
    colour = next((line.split("=", 1)[1] for line in written.splitlines() if line.startswith("color=")), "")

    return result.returncode, colour, result.stdout


def main() -> int:
    server = HTTPServer(("127.0.0.1", 0), Stub)
    base = f"http://127.0.0.1:{server.server_port}"
    threading.Thread(target=server.serve_forever, daemon=True).start()

    failures: list[str] = []

    for sha, description, expected in CASES:
        code, colour, _ = run(base, sha)
        ok = code == 0 and colour == expected
        print(f"  {'ok  ' if ok else 'FAIL'}  {colour or 'no colour'} (want {expected})  {description}")

        if not ok:
            failures.append(f"{sha}: exit {code}, colour {colour or 'none'}, wanted {expected}")

    # A ruleset that answers with no required checks must stop the notifier,
    # not licence it to call an unmeasured commit green.
    code, colour, _ = run(base, "green", branch="bare")
    ok = code != 0 and colour == ""
    print(f"  {'ok  ' if ok else 'FAIL'}  exit {code} (want non-zero)  a branch with no required checks yields no verdict")

    if not ok:
        failures.append(f"empty ruleset: exit {code}, colour {colour or 'none'}")

    # The last workflow to finish has nobody behind it to try again, so a
    # gateway error on that read is a commit nobody ever hears about.
    code, colour, _ = run(base, "green", branch="flaky")
    ok = code == 0 and colour == GREEN
    print(f"  {'ok  ' if ok else 'FAIL'}  {colour or 'no colour'} (want {GREEN})  two bad gateways are ridden out, not surrendered to")

    if not ok:
        failures.append(f"transient failure: exit {code}, colour {colour or 'none'}, wanted {GREEN}")

    server.shutdown()

    if failures:
        print("\n::error::required-checks-verdict.py no longer refuses where it must:")

        for failure in failures:
            print(f"  {failure}")

        return 1

    print(f"\n{len(CASES) + 2} outcomes behave as specified.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
