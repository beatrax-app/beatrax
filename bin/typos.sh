#!/usr/bin/env bash
# bin/typos.sh — the `hygiene / typos` gate, run the way CI runs it.
#
# That workflow lives in the spec repo rather than in .github/workflows/ here,
# so there is nothing local to read and the gate is otherwise first met as a red
# pull request. It was, on a comment quoting one Slovenian word.
#
# The guard is the point. `typos` is not a Composer or npm dependency, so a
# machine without it would otherwise run nothing and exit 0 — and a spell-check
# that scanned no files reports exactly what a clean tree reports.
set -euo pipefail

if ! command -v typos >/dev/null 2>&1; then
    echo "!!  typos is not installed, so NOTHING was checked here." >&2
    echo "!!  Install it and run this again:" >&2
    echo "!!    brew install typos-cli" >&2
    exit 127
fi

# typos.toml at the repo root carries the words this codebase spells its own
# way, including the shipped locales' vocabulary that tests quote verbatim.
exec typos "$@"
