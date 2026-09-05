#!/usr/bin/env python3
"""Wait for every required check on a commit, then write the commit's verdict.

No single workflow's conclusion is the commit's. The pull-request gate is
fifteen required contexts spread across three workflows plus two apps that
report from outside Actions entirely, so a notifier keyed on one workflow
finishing announces a green tick while the rest are still running -- and a
commit whose coverage and SonarCloud checks failed ten minutes later had
already been announced green twice.

The required list is read from the branch ruleset, which is the copy the merge
button itself uses, so the notifier's idea of "green" cannot drift from the
gate's the way a list copied into this file would.

A context that has not been created yet counts as pending rather than absent.
A check suite that has not been queued would otherwise read as a commit with
nothing left to wait for, which is the same false green wearing a disguise.
"""

from __future__ import annotations

import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

API = os.environ.get("GITHUB_API_URL", "https://api.github.com")

# The green and red the build log has always used, plus the reusable notifier's
# own default for the third answer -- "no verdict yet" -- which is neither of
# the other two and must not be dressed as one.
GREEN = "3066993"
RED = "15158332"
AMBER = "15844367"

# GitHub satisfies a required check with any of these, so the notifier does
# too. A stricter reading would paint every fork pull request red, because the
# spec-references job is deliberately skipped when the head repo is a fork.
SATISFYING = frozenset({"success", "skipped", "neutral"})

WAITING = "waiting"


def fetch(url: str, token: str) -> tuple[Any, str]:
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {token}",
            "User-Agent": "beatrax-build-log (github.com/beatrax-app, 1.0)",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )

    # A rate limit or a bad gateway is a condition that passes, and the last
    # completion of the run has nobody behind it to try again -- so silence
    # here is a commit whose verdict is never announced at all.
    for attempt in range(4):
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                return json.loads(response.read() or b"null"), response.headers.get("Link", "")
        except urllib.error.HTTPError as refused:
            if refused.code not in (403, 429) and refused.code < 500:
                raise
        except urllib.error.URLError:
            pass

        time.sleep(2**attempt)

    raise RuntimeError(f"{url}: no answer after four attempts")


def pages(path: str, token: str) -> list[Any]:
    collected: list[Any] = []
    url: str | None = f"{API}{path}"

    while url:
        payload, link = fetch(url, token)
        collected.append(payload)
        url = next_page(link)

    return collected


def next_page(link: str) -> str | None:
    for part in link.split(","):
        segments = part.split(";")

        if len(segments) >= 2 and 'rel="next"' in segments[1]:
            return segments[0].strip().strip("<>")

    return None


def required_contexts(repo: str, branch: str, token: str) -> list[str]:
    contexts: list[str] = []
    path = f"/repos/{repo}/rules/branches/{urllib.parse.quote(branch)}"

    for page in pages(path, token):
        for rule in page or []:
            if rule.get("type") != "required_status_checks":
                continue

            for check in (rule.get("parameters") or {}).get("required_status_checks") or []:
                if check.get("context") and check["context"] not in contexts:
                    contexts.append(check["context"])

    return contexts


def reported(repo: str, sha: str, token: str) -> dict[str, str]:
    outcomes: dict[str, str] = {}

    for page in pages(f"/repos/{repo}/commits/{sha}/check-runs?per_page=100&filter=latest", token):
        for run in (page or {}).get("check_runs") or []:
            concluded = run.get("status") == "completed"
            outcomes[run["name"]] = (run.get("conclusion") or WAITING) if concluded else WAITING

    for page in pages(f"/repos/{repo}/commits/{sha}/status?per_page=100", token):
        for status in (page or {}).get("statuses") or []:
            outcomes[status["context"]] = WAITING if status.get("state") == "pending" else status["state"]

    return outcomes


def body(headline: str, detail: list[tuple[str, str]], sha: str) -> str:
    lines = [headline]
    lines.extend(f"`{context}` — {outcome}" for context, outcome in sorted(detail))
    lines.append(sha)

    return "\n".join(lines)


def verdict(contexts: list[str], outcomes: dict[str, str], sha: str, waited: int) -> tuple[str, str]:
    named = [(context, outcomes.get(context, WAITING)) for context in contexts]
    silent = [pair for pair in named if pair[1] == WAITING]
    failed = [pair for pair in named if pair[1] not in SATISFYING and pair[1] != WAITING]
    total = len(contexts)

    if failed:
        return RED, body(f"CI red — {len(failed)} of {total} required checks did not pass", failed + silent, sha)

    if silent:
        headline = f"CI unresolved — {len(silent)} of {total} required checks had not reported after {waited // 60} minutes"

        return AMBER, body(headline, silent, sha)

    noted = [pair for pair in named if pair[1] != "success"]

    return GREEN, body(f"CI green — {total} of {total} required checks passed", noted, sha)


def emit(color: str, message: str) -> None:
    print(message)

    output = os.environ.get("GITHUB_OUTPUT")

    if not output:
        return

    with open(output, "a", encoding="utf-8") as handle:
        handle.write(f"color={color}\nbody<<VERDICT\n{message}\nVERDICT\n")


def main() -> int:
    token = os.environ["GITHUB_TOKEN"]
    repo = os.environ["GITHUB_REPOSITORY"]
    branch = os.environ["BRANCH"]
    sha = os.environ["SHA"]
    budget = int(os.environ.get("WAIT_SECONDS", "2400"))
    interval = int(os.environ.get("POLL_SECONDS", "30"))

    contexts = required_contexts(repo, branch, token)

    if not contexts:
        sys.exit(f"{repo}: {branch} declares no required status checks, so nothing here may be called green")

    print(f"{len(contexts)} required on {branch}: {', '.join(contexts)}")

    started = time.monotonic()

    while True:
        outcomes = reported(repo, sha, token)
        silent = [context for context in contexts if outcomes.get(context, WAITING) == WAITING]
        waited = int(time.monotonic() - started)

        if not silent or waited >= budget:
            emit(*verdict(contexts, outcomes, sha, waited))

            return 0

        print(f"waiting on {len(silent)}: {', '.join(silent)}")
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
