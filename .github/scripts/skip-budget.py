#!/usr/bin/env python3
"""Hold each job's skipped tests against the pinned budget in test-skip-budget.json.

A test that skips in every job runs nowhere, and a suite reports it exactly the
way it reports one that passed: a count, in a colour nobody reads. The pipeline
already guarded one rule against that shape -- the docs-symbol rule, whose step
fails if the word "skipped" appears at all, because that rule skipped in 100% of
runs while reporting green. This generalises the same reasoning to every skip in
the tree.

The budget is the decision, not a tally. Each entry names the job that RUNS the
test, and there is no value meaning "nowhere": a capability gate can only be
pinned by pointing at the job that supplies the capability. This script then
verifies the claim in that job -- the file has to be collected there, and it has
to skip nothing there. A pin that says "the mobile-app root runs it" and is
wrong is a red build in the mobile-app job, not a sentence in a JSON file.

Three ways to fail, and the middle one is the reason for the whole exercise:

  * a file skipped tests here and the budget does not mention it -- somebody
    added a skip and nothing says where it still runs;
  * a file the budget says RUNS here skipped something, or was not collected at
    all -- the escape hatch the other jobs were pointed at is not real;
  * a file's count moved in either direction -- a skip appearing is a guarantee
    lost, and a skip disappearing means the pin is describing a run that no
    longer happens, which is how a pin rots into a rubber stamp.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import xml.etree.ElementTree as ET
from collections import defaultdict

# Both are repo-relative roots that a test path must start with, and the
# absolute path a runner writes into the report has to be cut back to one of
# them: the mobile-app job runs from a second Composer root whose Modules/ is a
# symlink, so the same test file is reported under two prefixes.
PATH_ANCHORS = ("/Modules/", "/tests/")


def repo_relative(path: str) -> str:
    normalised = path.replace("\\", "/")

    for anchor in PATH_ANCHORS:
        index = normalised.find(anchor)
        if index != -1:
            return normalised[index + 1 :]

    return normalised.lstrip("/")


def observed(reports: list[pathlib.Path]) -> tuple[dict[str, int], dict[str, int], dict[str, list[str]]]:
    collected: dict[str, int] = defaultdict(int)
    skipped: dict[str, int] = defaultdict(int)
    names: dict[str, list[str]] = defaultdict(list)

    for report in reports:
        for case in ET.parse(report).getroot().iter("testcase"):
            path = repo_relative(case.get("file", ""))
            collected[path] += 1

            if case.find("skipped") is not None:
                skipped[path] += 1
                names[path].append(case.get("name", "?"))

    return dict(collected), dict(skipped), dict(names)


def report(collected: dict[str, int], skipped: dict[str, int], names: dict[str, list[str]]) -> None:
    print(f"collected {sum(collected.values())} tests across {len(collected)} files")
    print(f"skipped   {sum(skipped.values())} tests across {len(skipped)} files")

    for path in sorted(skipped):
        print(f"\n  {path}  ({skipped[path]} of {collected.get(path, 0)})")
        for name in sorted(names[path]):
            print(f"      {name}")


def failures(budget: dict, job: str, collected: dict[str, int], skipped: dict[str, int], names: dict[str, list[str]]) -> list[str]:
    files = budget["files"]
    problems = []

    for path, count in sorted(skipped.items()):
        pinned = files.get(path)

        if pinned is None:
            problems.append(
                f"{path}: {count} test(s) skipped here and the budget does not mention the file. "
                f"A skip nothing accounts for is a test that may run in no job at all. Add it to "
                f".github/test-skip-budget.json with the job that does run it, or make it run here. "
                f"Skipped: {', '.join(sorted(names[path]))}"
            )
            continue

        if pinned["runs_in"] == job:
            problems.append(
                f"{path}: the budget says this job RUNS these tests, and {count} of them skipped in it. "
                f"That is the shape the guard exists to catch — the file now runs in no job at all. "
                f"Skipped: {', '.join(sorted(names[path]))}"
            )

    for path, pinned in sorted(files.items()):
        expected = pinned["skipped"].get(job)

        if expected is None:
            continue

        if path not in collected:
            problems.append(
                f"{path}: the budget has a count for this job and the job collected the file not at all. "
                f"Either the testsuite stopped reaching it or the budget names a job that never sees it."
            )
            continue

        actual = skipped.get(path, 0)

        if actual != expected:
            problems.append(
                f"{path}: budget expects {expected} skipped in this job, the run reported {actual}. "
                f"A count that moved down is as much a stale pin as one that moved up."
            )

    return problems


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--job", required=True, help="budget key for the job being checked")
    parser.add_argument("--junit", type=pathlib.Path, nargs="+", required=True)
    parser.add_argument("--budget", type=pathlib.Path, default=pathlib.Path(".github/test-skip-budget.json"))
    parser.add_argument("--report-only", action="store_true", help="print the inventory without holding it to the budget")
    args = parser.parse_args()

    present = [path for path in args.junit if path.is_file()]

    if not present:
        # An absent report reads as "nothing skipped" to every check below, which
        # is the same false green in a different costume.
        sys.exit(f"::error::no JUnit report was written, so the skip budget checked nothing: {args.junit}")

    collected, skipped, names = observed(present)
    report(collected, skipped, names)

    if args.report_only:
        return

    budget = json.loads(args.budget.read_text())

    if args.job not in budget["jobs"]:
        sys.exit(f"::error::{args.job} is not a job the budget knows: {', '.join(sorted(budget['jobs']))}")

    problems = failures(budget, args.job, collected, skipped, names)

    if problems:
        print()
        for problem in problems:
            print(f"::error::{problem}")
        sys.exit(1)

    print("\nthe skips this job reported are the ones the budget pins, and each names a job that runs them.")


if __name__ == "__main__":
    main()
