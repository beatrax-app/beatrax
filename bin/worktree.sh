#!/usr/bin/env bash
# bin/worktree.sh — create a git worktree that can actually run the suite.
#
# A fresh worktree is missing three gitignored things, and each absence looks
# like a real test failure rather than like missing setup:
#
#   vendor/        no vendor/bin/pest at all.
#   public/build/  22 tests across 4 DevMode/Shell files fail with
#                  ViteManifestNotFoundException, and one arch test cannot run.
#   .env           the FirstLaunchBootstrap path runs key:generate, which does
#                  file_get_contents('.env') and throws.
#
# Both directories are hardlinked with `cp -al`, not symlinked. A SYMLINKED
# vendor/ makes Pest resolve the project root to the main checkout, so every
# test in the worktree loses its TestCase binding and fails with $this->app
# null — dozens of failures that look exactly like broken code. A hardlink tree
# is a real directory, resolves inside the worktree, and costs no extra disk.
#
# Usage:
#   bin/worktree.sh <name> [branch] [base]
#
#   bin/worktree.sh parsers                     -> ../wt-parsers, branch wt/parsers off origin/main
#   bin/worktree.sh parsers fix/camt-dates      -> ../wt-parsers on that branch
#   bin/worktree.sh parsers fix/x origin/other  -> ... off a different base
#
# Run from inside any checkout of this repository. Re-running against an
# existing worktree re-bootstraps it without touching its commits.
set -euo pipefail

name=${1:-}
if [[ -z $name ]]; then
    echo "usage: bin/worktree.sh <name> [branch] [base]" >&2
    exit 64
fi

branch=${2:-wt/$name}
base=${3:-origin/main}

# The shared .git lives in the main checkout even when this runs from another
# worktree, so the source of vendor/ and public/build/ is found rather than
# assumed to be the current directory.
common=$(git rev-parse --git-common-dir)
main=$(cd "$(dirname "$common")" && pwd)
target=$(cd "$(dirname "$main")" && pwd)/wt-$name

git -C "$main" fetch --quiet origin

if [[ -d $target ]]; then
    echo "==> $target exists; re-bootstrapping it"
else
    echo "==> creating $target on $branch off $base"
    if git -C "$main" show-ref --verify --quiet "refs/heads/$branch"; then
        git -C "$main" worktree add "$target" "$branch"
    else
        git -C "$main" worktree add "$target" -b "$branch" "$base"
    fi
fi

link_tree() {
    local what=$1
    if [[ ! -e $main/$what ]]; then
        echo "!!  $main/$what is missing, so $what cannot be linked into the worktree" >&2
        return 1
    fi
    if [[ -e $target/$what ]]; then
        echo "    $what already present"
        return 0
    fi
    cp -al "$main/$what" "$target/$what"
    echo "    $what hardlinked"
}

echo "==> bootstrapping"
link_tree vendor
link_tree public/build

if [[ -e $target/.env ]]; then
    echo "    .env already present"
else
    cp "$main/.env" "$target/.env"
    echo "    .env copied"
fi

# A symlink here is the failure this script exists to prevent, so it is checked
# rather than trusted.
for what in vendor public/build; do
    if [[ -L $target/$what ]]; then
        echo "!!  $target/$what is a SYMLINK. Pest will resolve the project root to" >&2
        echo "!!  the main checkout and every test will fail with \$this->app null." >&2
        exit 1
    fi
done

# A positive control: one file known to pass, so "the suite is broken" and "the
# worktree is not set up" are told apart before any real work starts.
echo "==> positive control"
if (cd "$target" && vendor/bin/pest tests/Contracts/BoundaryArchTest.php >/dev/null 2>&1); then
    echo "    the harness binds and a known-green file passes"
else
    echo "!!  the control file did not pass. Do not trust a failure in this worktree" >&2
    echo "!!  until this does — run it directly to see why:" >&2
    echo "!!    cd $target && vendor/bin/pest tests/Contracts/BoundaryArchTest.php" >&2
    exit 1
fi

echo "==> ready: cd $target"
