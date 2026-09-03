#!/usr/bin/env python3
"""Print the phpunit testsuites belonging to one shard, comma-separated.

The suite list comes from phpunit.xml rather than from a checked-in manifest,
so a suite added tomorrow is assigned to a shard tomorrow. A manifest would
have to be updated by hand, and the failure mode when someone forgets is that
the new tests quietly never run -- which is the failure this repository has
already had twice, from an empty testsuite and from an orphaned test file.

Balance comes from .github/test-shard-weights.json, which is only a hint: an
unlisted suite is assigned a default weight and still runs. Stale weights cost
a slower shard, never a missing test.

These weights look badly wrong and are not worth re-deriving. They track
something close to test count, and runtime does not follow it -- so the split
they produce is lopsided, around 245s / 124s / 255s across four runs on main.
Replacing them with measured per-suite seconds was tried and reverted: it
evened the predicted load to within 1% and made the real thing slightly worse,
259s / 107s / 172s over two runs, because the local measurement it was built
from over-reads AuthFeature. That suite's Argon2id work is memory-hard and a
contended developer machine is the wrong instrument for it.

What the experiment established is that shard balance is not the lever here at
all. The longest shard cannot go below Feature, a single ~245s testsuite no
choice of weights can divide, and the lopsided split already lands within about
ten seconds of that floor. Evening the load only moves work onto the shard that
is already the longest. Shortening this workflow means splitting the Feature
testsuite in phpunit.xml, and nothing short of that will show up on the clock.

The shard totals do not sum to the full-suite total, and that is expected.
tests/Helpers belongs to the Unit testsuite, but paratest collects it whatever
`--testsuite` asks for, so its six tests run in every shard rather than only in
the one holding Unit. Comparing the JUnit test ids of a serial and a parallel
run of one suite showed the difference is entirely those six repeating: nothing
appears in the serial run that is missing from the parallel one. They are safe
to repeat -- the fixture hands out a unique path per call, by design.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import xml.etree.ElementTree as ET

# Big enough that an unmeasured suite is not assumed free, small enough that it
# does not distort the split. Roughly a mid-sized module suite.
DEFAULT_WEIGHT = 60


def suites(phpunit_xml: pathlib.Path) -> list[str]:
    testsuites = ET.parse(phpunit_xml).getroot().find("testsuites")
    if testsuites is None:
        sys.exit(f"{phpunit_xml}: no <testsuites> element")

    names = [ts.get("name") for ts in testsuites if ts.get("name")]
    if not names:
        sys.exit(f"{phpunit_xml}: no named testsuites")

    return names


def assign(names: list[str], weights: dict[str, int], total: int) -> list[list[str]]:
    # Longest-processing-time first: the heaviest suite is placed while every
    # shard is still empty, which is what keeps one oversized suite from
    # landing on top of an already-full shard.
    ordered = sorted(names, key=lambda n: (-weights.get(n, DEFAULT_WEIGHT), n))

    shards: list[list[str]] = [[] for _ in range(total)]
    loads = [0] * total

    for name in ordered:
        lightest = loads.index(min(loads))
        shards[lightest].append(name)
        loads[lightest] += weights.get(name, DEFAULT_WEIGHT)

    return shards


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--shard", type=int, required=True, help="1-based shard index")
    parser.add_argument("--of", type=int, required=True, help="total number of shards")
    parser.add_argument("--root", type=pathlib.Path, default=pathlib.Path.cwd())
    args = parser.parse_args()

    if not 1 <= args.shard <= args.of:
        sys.exit(f"--shard must be between 1 and {args.of}, got {args.shard}")

    names = suites(args.root / "phpunit.xml")

    weights_file = args.root / ".github" / "test-shard-weights.json"
    weights: dict[str, int] = {}
    if weights_file.is_file():
        weights = json.loads(weights_file.read_text())

    shard = assign(names, weights, args.of)[args.shard - 1]

    if not shard:
        # An empty shard would run nothing and exit 1 on "No tests found",
        # which reads as a broken build rather than as too many shards.
        sys.exit(f"shard {args.shard}/{args.of} is empty: fewer suites ({len(names)}) than shards")

    print(",".join(shard))


if __name__ == "__main__":
    main()
