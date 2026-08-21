#!/usr/bin/env python3
"""Prove sonar-gate.py still fails, before trusting it to say a build is clean.

A gate is only worth having if someone has watched it go red. This stands a
fake SonarCloud in front of it and asserts the exit code for each outcome,
including the ones that must not be mistaken for success: no token, no scanner
report, an analysis that never finished, and an API that only errors.

Runs in about a second against a loopback socket, so it can sit in front of the
real check on every run rather than being a thing someone did once.
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
GATE = os.path.join(HERE, "sonar-gate.py")

ISSUE = {
    "rule": "php:S1142",
    "severity": "MAJOR",
    "component": "beatrax-app_beatrax:Modules/Sync/Internal/Merge/RowOwnership.php",
    "line": 183,
    "message": "This method has 4 returns, which is more than the 3 allowed.",
}
GATE_OK = {"projectStatus": {"status": "OK", "conditions": []}}
GATE_ERROR = {
    "projectStatus": {
        "status": "ERROR",
        "conditions": [{
            "status": "ERROR", "metricKey": "new_coverage",
            "comparator": "LT", "errorThreshold": "80", "actualValue": "61.2",
        }],
    }
}


def task(**extra) -> dict:
    return {"task": {"id": "T1", "status": "SUCCESS", "analysisId": "AN-1", **extra}}


class Stub(BaseHTTPRequestHandler):
    scenario = "clean"
    polls = 0

    def log_message(self, *args):
        pass

    def reply(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):  # noqa: N802 - the base class names it
        scenario = Stub.scenario
        if scenario == "server_error":
            return self.reply(503, {"errors": [{"msg": "queue overloaded"}]})

        if self.path.startswith("/api/ce/task"):
            Stub.polls += 1
            if scenario == "task_stuck":
                return self.reply(200, {"task": {"id": "T1", "status": "IN_PROGRESS"}})
            if scenario == "task_failed":
                return self.reply(200, {"task": {"id": "T1", "status": "FAILED",
                                                 "errorMessage": "Java heap space"}})
            if scenario == "no_scope":
                return self.reply(200, task())
            if scenario == "pull_request":
                return self.reply(200, task(pullRequest="266"))
            return self.reply(200, task(branch="main"))

        if self.path.startswith("/api/qualitygates/project_status"):
            return self.reply(200, GATE_ERROR if scenario == "gate_failed" else GATE_OK)

        if self.path.startswith("/api/issues/search"):
            if scenario in ("clean", "gate_failed"):
                return self.reply(200, {"total": 0, "issues": []})
            return self.reply(200, {"total": 1, "issues": [ISSUE]})

        return self.reply(404, {"errors": [{"msg": "no such endpoint"}]})


CASES = [
    ("a clean analysis passes", "clean", 0, True),
    ("new issues on a branch fail", "branch_dirty", 1, True),
    ("new issues on a pull request fail", "pull_request", 1, True),
    ("a failed quality gate fails", "gate_failed", 1, True),
    ("an analysis that never finishes is not a pass", "task_stuck", 2, True),
    ("an analysis that errored is not a pass", "task_failed", 2, True),
    ("an API that only errors is not a pass", "server_error", 2, True),
    ("an analysis with no branch or PR is not a pass", "no_scope", 2, True),
    ("a missing scanner report is not a pass", "clean", 2, False),
]


def main() -> int:
    server = HTTPServer(("127.0.0.1", 0), Stub)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    base = f"http://127.0.0.1:{server.server_port}"

    failures = []
    with tempfile.TemporaryDirectory() as workspace:
        os.makedirs(os.path.join(workspace, ".scannerwork"), exist_ok=True)
        report = os.path.join(workspace, ".scannerwork", "report-task.txt")
        with open(report, "w", encoding="utf-8") as handle:
            handle.write(
                "projectKey=beatrax-app_beatrax\n"
                "serverUrl=https://sonarcloud.io\n"
                "ceTaskId=T1\n"
            )

        for name, scenario, expected, with_report in CASES:
            Stub.scenario = scenario
            Stub.polls = 0
            env = {
                **os.environ,
                "SONAR_TOKEN": "self-test-token",
                "SONAR_HOST_URL": base,
                "SONAR_GATE_TASK_WAIT_SECONDS": "2",
                "SONAR_GATE_POLL_SECONDS": "1",
                "SONAR_GATE_HTTP_ATTEMPTS": "2",
                "SONAR_GATE_REPORT_TASK": report if with_report else os.path.join(workspace, "absent.txt"),
            }
            env.pop("GITHUB_STEP_SUMMARY", None)
            result = subprocess.run(
                [sys.executable, GATE], env=env, capture_output=True, text=True, timeout=120
            )
            ok = result.returncode == expected
            print(f"  {'ok  ' if ok else 'FAIL'}  exit {result.returncode} (want {expected})  {name}")
            if not ok:
                failures.append(f"{name}: exit {result.returncode}, wanted {expected}\n{result.stdout[-500:]}")

        # The token check must not depend on anything else being reachable.
        env = {**os.environ, "SONAR_HOST_URL": base, "SONAR_GATE_REPORT_TASK": report}
        env.pop("GITHUB_STEP_SUMMARY", None)
        env["SONAR_TOKEN"] = ""
        result = subprocess.run([sys.executable, GATE], env=env, capture_output=True, text=True, timeout=60)
        ok = result.returncode == 2
        print(f"  {'ok  ' if ok else 'FAIL'}  exit {result.returncode} (want 2)  a missing token is not a pass")
        if not ok:
            failures.append(f"missing token: exit {result.returncode}, wanted 2")

    server.shutdown()

    if failures:
        print("\n::error::sonar-gate.py no longer fails where it must:")
        for failure in failures:
            print(f"  {failure}")
        return 1

    print(f"\n{len(CASES) + 1} outcomes behave as specified.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
