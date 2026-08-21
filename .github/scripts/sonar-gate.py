#!/usr/bin/env python3
"""Turn a SonarCloud analysis into a pass/fail verdict for the build.

The scan step uploads a report and returns; the verdict arrives asynchronously.
Without this, a scan that finds anything at all still reports success, which is
how twenty issues reached the default branch behind six green checks.

Three outcomes, and only the first one exits zero:

  0  the analysis finished, the quality gate passed, and new code carries
     no issues
  1  the analysis finished and said no: new issues, or a failed gate condition
  2  we could not find out — no token, no scanner report, the analysis never
     finished, or the API kept erroring

Exit 2 is a failure on purpose. A gate that treats "I don't know" as "fine" is
the thing this script exists to remove.
"""

from __future__ import annotations

import base64
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

# A quality gate nobody watches fail is not a gate, but one that goes red on a
# slow queue gets switched off within a week. These bound the wait for a verdict
# rather than the verdict itself: running out of patience is exit 2, never 0.
TASK_WAIT_SECONDS = int(os.environ.get("SONAR_GATE_TASK_WAIT_SECONDS", "900"))
TASK_POLL_SECONDS = int(os.environ.get("SONAR_GATE_POLL_SECONDS", "5"))
HTTP_ATTEMPTS = int(os.environ.get("SONAR_GATE_HTTP_ATTEMPTS", "5"))
HTTP_TIMEOUT_SECONDS = 30
PAGE_SIZE = 500

EXIT_CLEAN = 0
EXIT_REJECTED = 1
EXIT_UNDETERMINED = 2


class Undetermined(Exception):
    """We cannot establish the verdict, so the build must not pass."""


def log(message: str) -> None:
    print(message, flush=True)


def summary(lines: list[str]) -> None:
    path = os.environ.get("GITHUB_STEP_SUMMARY")
    if not path:
        return
    with open(path, "a", encoding="utf-8") as handle:
        handle.write("\n".join(lines) + "\n")


def api_get(server: str, path: str, params: dict[str, str], token: str) -> dict:
    """GET a SonarCloud endpoint, retrying only what is worth retrying.

    5xx and transport errors are the queue being busy; 400/401/403/404 are a
    real answer about our own request and retrying them just delays exit 2.
    """
    url = f"{server.rstrip('/')}/{path}?{urllib.parse.urlencode(params)}"
    # The token is the basic-auth username with an empty password. It travels in
    # a header rather than in the URL or argv so it cannot surface in a process
    # listing or in a logged request line.
    credential = base64.b64encode(f"{token}:".encode()).decode("ascii")

    last_error = ""
    for attempt in range(1, HTTP_ATTEMPTS + 1):
        request = urllib.request.Request(url)
        request.add_header("Authorization", f"Basic {credential}")
        request.add_header("Accept", "application/json")
        try:
            with urllib.request.urlopen(request, timeout=HTTP_TIMEOUT_SECONDS) as response:
                return json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as error:
            body = error.read().decode("utf-8", "replace")[:400]
            if error.code < 500:
                raise Undetermined(
                    f"{path} returned HTTP {error.code}, which is an answer about "
                    f"the request rather than a transient fault: {body}"
                ) from error
            last_error = f"HTTP {error.code}: {body}"
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as error:
            last_error = f"{type(error).__name__}: {error}"

        if attempt < HTTP_ATTEMPTS:
            delay = min(2**attempt, 30)
            log(f"::warning::{path} attempt {attempt} failed ({last_error}); retrying in {delay}s.")
            time.sleep(delay)

    raise Undetermined(f"{path} failed {HTTP_ATTEMPTS} times; last error was {last_error}")


def read_report_task(path: str) -> dict[str, str]:
    """Parse the scanner's receipt, which names the analysis to wait for.

    Going through this file rather than "the newest analysis of this project"
    is what ties the verdict to the commit that is being built: a concurrent
    scan of another branch would otherwise be able to answer for us.
    """
    if not os.path.isfile(path):
        raise Undetermined(
            f"the scanner left no task report at {path}, so there is no analysis "
            "to wait for. The scan step did not run or did not complete."
        )

    fields: dict[str, str] = {}
    with open(path, encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line or "=" not in line:
                continue
            key, value = line.split("=", 1)
            fields[key.strip()] = value.strip()

    for required in ("ceTaskId", "serverUrl", "projectKey"):
        if not fields.get(required):
            raise Undetermined(f"{path} has no {required}; the scanner report is unusable.")
    return fields


def await_analysis(server: str, task_id: str, token: str) -> dict:
    """Poll the compute-engine task until it stops being in progress.

    PENDING and IN_PROGRESS are the only states worth waiting on. Everything
    else is terminal, including the ones that mean the analysis is never
    arriving.
    """
    deadline = time.monotonic() + TASK_WAIT_SECONDS
    reported_queue = False

    while True:
        payload = api_get(server, "api/ce/task", {"id": task_id}, token)
        task = payload.get("task") or {}
        status = task.get("status", "")

        if status == "SUCCESS":
            return task
        if status in ("FAILED", "CANCELED"):
            raise Undetermined(
                f"the analysis finished as {status} "
                f"({task.get('errorMessage') or 'no error message'}), so it produced no verdict."
            )
        if status not in ("PENDING", "IN_PROGRESS"):
            raise Undetermined(f"the analysis reported an unrecognised status {status!r}.")

        if not reported_queue:
            log(f"analysis {task_id} is {status}; waiting up to {TASK_WAIT_SECONDS}s for it to finish.")
            reported_queue = True

        if time.monotonic() >= deadline:
            raise Undetermined(
                f"the analysis was still {status} after {TASK_WAIT_SECONDS}s. "
                "That is SonarCloud being slow rather than the code being clean, "
                "so this is not a pass. Re-run the job."
            )
        time.sleep(TASK_POLL_SECONDS)


def scope_of(task: dict) -> tuple[dict[str, str], str]:
    """Work out which slice of the project the verdict covers.

    Sonar's own record of what it analysed decides this, not the CI context:
    if the two ever disagree, the analysis is the one that produced the issues.
    """
    pull_request = task.get("pullRequest")
    if pull_request:
        return {"pullRequest": str(pull_request)}, f"pull request {pull_request}"

    branch = task.get("branch")
    if branch:
        return (
            {"branch": str(branch), "inNewCodePeriod": "true"},
            f"new code on branch {branch}",
        )

    raise Undetermined(
        "the analysis names neither a branch nor a pull request, so its issues "
        "cannot be scoped to what this build changed."
    )


def fetch_new_issues(server: str, project: str, scope: dict[str, str], token: str) -> list[dict]:
    issues: list[dict] = []
    page = 1
    while True:
        params = {
            "componentKeys": project,
            "resolved": "false",
            "ps": str(PAGE_SIZE),
            "p": str(page),
            **scope,
        }
        payload = api_get(server, "api/issues/search", params, token)
        batch = payload.get("issues", [])
        issues.extend(batch)

        total = int(payload.get("total", len(issues)))
        if len(issues) >= total or not batch:
            return issues
        page += 1
        if page > 20:
            raise Undetermined("the issue list did not terminate; refusing to guess at the count.")


def gate_status(server: str, analysis_id: str, token: str) -> tuple[str, list[dict]]:
    payload = api_get(server, "api/qualitygates/project_status", {"analysisId": analysis_id}, token)
    status = payload.get("projectStatus") or {}
    if not status.get("status"):
        raise Undetermined("the quality gate response carried no status.")
    return status["status"], status.get("conditions", [])


def describe(issue: dict) -> str:
    component = issue.get("component", "?")
    path = component.split(":", 1)[1] if ":" in component else component
    line = issue.get("line", "-")
    return (
        f"  {issue.get('rule', '?'):<24} {str(issue.get('severity', '?')):<8} "
        f"{path}:{line}\n      {issue.get('message', '')}"
    )


def main() -> int:
    token = os.environ.get("SONAR_TOKEN", "").strip()
    if not token:
        log(
            "::error::SONAR_TOKEN is not set, so the analysis cannot be read. "
            "This is a failure, not a skip: an unreadable gate is an unenforced gate. "
            "A pull request from a fork does not receive secrets by design, so it "
            "cannot be merged on a fork run alone."
        )
        return EXIT_UNDETERMINED

    report_path = os.environ.get("SONAR_GATE_REPORT_TASK", ".scannerwork/report-task.txt")

    try:
        report = read_report_task(report_path)
        server = os.environ.get("SONAR_HOST_URL") or report["serverUrl"]
        project = report["projectKey"]

        task = await_analysis(server, report["ceTaskId"], token)
        analysis_id = task.get("analysisId")
        if not analysis_id:
            raise Undetermined("the finished analysis carried no analysisId.")

        scope, scope_label = scope_of(task)
        status, conditions = gate_status(server, str(analysis_id), token)
        issues = fetch_new_issues(server, project, scope, token)
    except Undetermined as error:
        log(f"::error::Cannot determine the SonarCloud verdict: {error}")
        summary(["## SonarCloud gate: undetermined", "", f"{error}", "", "Treated as a failure."])
        return EXIT_UNDETERMINED

    dashboard = report.get("dashboardUrl", "")
    log(f"analysis {analysis_id} covers {scope_label}")
    log(f"quality gate: {status}")
    log(f"issues on {scope_label}: {len(issues)}")

    failed_conditions = [c for c in conditions if c.get("status") not in ("OK", "NO_VALUE")]
    rejected = bool(issues) or status != "OK"

    if failed_conditions:
        log("\nfailing quality gate conditions:")
        for condition in failed_conditions:
            log(
                f"  {condition.get('metricKey')}: actual {condition.get('actualValue')} "
                f"vs threshold {condition.get('comparator')} {condition.get('errorThreshold')}"
            )

    if issues:
        log(f"\n{len(issues)} issue(s) on {scope_label} — every one of these must be fixed or the build stays red:")
        for issue in sorted(issues, key=lambda i: (i.get("component", ""), i.get("line") or 0)):
            log(describe(issue))

    lines = [
        "## SonarCloud gate",
        "",
        f"- scope: **{scope_label}**",
        f"- quality gate: **{status}**",
        f"- issues on this scope: **{len(issues)}**",
    ]
    if dashboard:
        lines.append(f"- [dashboard]({dashboard})")
    if issues:
        lines += ["", "| rule | severity | location | message |", "|---|---|---|---|"]
        for issue in issues:
            component = issue.get("component", "?")
            path = component.split(":", 1)[1] if ":" in component else component
            message = str(issue.get("message", "")).replace("|", "\\|")
            lines.append(
                f"| `{issue.get('rule')}` | {issue.get('severity')} | "
                f"`{path}:{issue.get('line', '-')}` | {message} |"
            )
    summary(lines)

    if rejected:
        log(
            "\n::error::SonarCloud rejected this build. "
            "Fix the issues in the code; excluding a rule or lowering a threshold "
            "to clear this check defeats the point of it."
        )
        return EXIT_REJECTED

    log("\nno new issues and the quality gate passed.")
    return EXIT_CLEAN


if __name__ == "__main__":
    sys.exit(main())
