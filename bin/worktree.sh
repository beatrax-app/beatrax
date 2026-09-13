#!/usr/bin/env bash
# bin/worktree.sh — create a git worktree that can actually run the suite.
#
# A fresh worktree is missing four gitignored things, and each absence looks
# like a real test failure rather than like missing setup:
#
#   vendor/        no vendor/bin/pest at all.
#   public/build/  22 tests across 4 DevMode/Shell files fail with
#                  ViteManifestNotFoundException, and one arch test cannot run.
#   .env           the FirstLaunchBootstrap path runs key:generate, which does
#                  file_get_contents('.env') and throws.
#   mobile-app/    the second Composer root: its bootstrap/cache and storage
#                  runtime directories are gitignored AND empty, so git creates
#                  neither and the framework cannot boot there at all.
#
# public/build/ is COPIED rather than hardlinked, for its mtimes. `git worktree
# add` stamps every source file at checkout time, so a hardlinked bundle carries
# the main checkout's older timestamps and RefuseToShipAStaleFrontEnd reads a
# fresh worktree as one whose bundle predates its sources. A copy is stamped
# after the checkout, so it is newer, which is also the truth: it is the bundle
# built from these exact sources. 1.5 MB across 4 files, against vendor/'s 1.4
# GB — which is why that one stays hardlinked.
#
# Both directories are hardlinked with `cp -al`, not symlinked. A SYMLINKED
# vendor/ makes Pest resolve the project root to the main checkout, so every
# test in the worktree loses its TestCase binding and fails with $this->app
# null — dozens of failures that look exactly like broken code. A hardlink tree
# is a real directory, resolves inside the worktree, and costs no extra disk.
#
# vendor/composer/ is the exception, and it is unhardlinked on purpose. Composer
# rewrites those files IN PLACE, so a `composer dump-autoload` in one worktree
# writes through every link and hands its own classmap to all the others. That
# happened: one worktree renaming a PSR-4 root left six siblings, the main
# checkout among them, failing static analysis with `Class "App\PhpStan\..."
# not found` for a change none of them had. A real copy is a few megabytes and
# makes each worktree's autoloader its own.
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

# Its own inodes and its own timestamps: hardlinked, a `touch` here would also
# restamp the main checkout's bundle and every other worktree's, which is how a
# genuinely stale bundle gets hidden everywhere at once.
copy_tree() {
    local what=$1
    if [[ ! -e $main/$what ]]; then
        echo "!!  $main/$what is missing, so $what cannot be copied into the worktree" >&2
        return 1
    fi
    rm -rf "$target/$what.incoming"
    mkdir -p "$(dirname "$target/$what")"
    cp -R "$main/$what" "$target/$what.incoming"
    rm -rf "$target/$what"
    mv "$target/$what.incoming" "$target/$what"
    find "$target/$what" -exec touch {} +
    echo "    $what copied and stamped after the checkout"
}

# Composer writes these in place, so they must not be shared. Done every run,
# not only on creation: a worktree bootstrapped before this existed still holds
# the links, and re-running is how it is repaired.
unshare_composer_metadata() {
    local root=${1:-vendor}
    local shared=$target/$root/composer

    if [[ ! -d $shared ]]; then
        return 0
    fi

    # Link count is the question, not whether this run made them: any file here
    # with more than one name is one a sibling's composer can rewrite.
    if [[ -z $(find "$shared" -type f -links +1 -print -quit) ]]; then
        echo "    $root/composer already this worktree's own"
        return 0
    fi

    rm -rf "$shared.unshared"
    cp -R "$shared" "$shared.unshared"
    rm -rf "$shared"
    mv "$shared.unshared" "$shared"
    echo "    $root/composer unhardlinked"
}

# The classmap comes across with vendor/ and describes the checkout it was
# written in, not this one. A worktree on a commit where a file has moved or
# gone inherits an entry pointing at a path it does not have, and the include
# fails inside the autoloader — which reads as thirty-one broken tests, not as
# a stale classmap. Done after the unshare, so it writes only here.
redump_autoload() {
    if (cd "$target" && composer dump-autoload --quiet 2>/dev/null); then
        echo "    autoload rebuilt against this checkout"
    else
        echo "!!  composer dump-autoload failed in $target; the classmap still describes $main" >&2
    fi
}

# The second Composer root needs more than a vendor tree. bootstrap/cache and
# the storage runtime directories are gitignored and empty, so `git worktree
# add` creates neither and the framework cannot boot there — mobile-plugin
# cases skip in the repo root, where they are meant to, and FAIL in the mobile
# one, where they are meant to run. The classmap is stale for the reason the
# repo root's is: it came across with vendor/ and describes $main.
bootstrap_mobile_root() {
    mkdir -p "$target/mobile-app/bootstrap/cache" "$target/mobile-app/database" \
        "$target/mobile-app/storage/framework/cache" "$target/mobile-app/storage/framework/sessions" \
        "$target/mobile-app/storage/framework/testing" "$target/mobile-app/storage/framework/views" \
        "$target/mobile-app/storage/logs"
    [[ -e $target/mobile-app/database/database.sqlite ]] || : > "$target/mobile-app/database/database.sqlite"

    if [[ ! -e $target/mobile-app/.env && -e $main/mobile-app/.env ]]; then
        cp "$main/mobile-app/.env" "$target/mobile-app/.env"
    fi

    if (cd "$target/mobile-app" && composer dump-autoload --quiet 2>/dev/null); then
        echo "    mobile-app root bootstrapped, autoload rebuilt against this checkout"
    else
        echo "!!  composer dump-autoload failed in $target/mobile-app; its classmap still describes $main" >&2
    fi
}

echo "==> bootstrapping"
link_tree vendor
unshare_composer_metadata vendor

# The second Composer root. DocsNameSymbolsThatExistArchTest reads a classmap
# from each and SKIPS when either is unreadable, so without this the rule was
# inert in all 25 worktrees and ran only in CI.
if [[ -d $main/mobile-app/vendor ]]; then
    link_tree mobile-app/vendor
    unshare_composer_metadata mobile-app/vendor
    bootstrap_mobile_root
else
    echo "    mobile-app/vendor absent from the main checkout; the docs-symbol rule will skip"
fi
redump_autoload
copy_tree public/build

if [[ -e $target/.env ]]; then
    echo "    .env already present"
else
    cp "$main/.env" "$target/.env"
    echo "    .env copied"
fi

# A symlink here is the failure this script exists to prevent, so it is checked
# rather than trusted.
for what in vendor public/build mobile-app/vendor; do
    [[ -e $target/$what ]] || continue
    if [[ -L $target/$what ]]; then
        echo "!!  $target/$what is a SYMLINK. Pest will resolve the project root to" >&2
        echo "!!  the main checkout and every test will fail with \$this->app null." >&2
        exit 1
    fi
done

# Checked rather than trusted for the same reason: a shared inode is silent here
# and loud in a sibling worktree nobody is looking at.
for shared in vendor/composer public/build mobile-app/vendor/composer; do
    [[ -d $target/$shared ]] || continue
    if [[ -n $(find "$target/$shared" -type f -links +1 -print -quit) ]]; then
        echo "!!  $target/$shared still shares files with another checkout." >&2
        echo "!!  Writing there — a dump-autoload, a touch — would rewrite theirs too." >&2
        exit 1
    fi
done

# A positive control: one file known to pass, so "the suite is broken" and "the
# worktree is not set up" are told apart before any real work starts.
echo "==> positive control"
if (cd "$target" && vendor/bin/pest tests/Contracts/BoundaryArchTest.php >/dev/null 2>&1); then
    echo "    the harness binds and a known-green file passes"
    # The mobile root has its own control, because its own bootstrap can fail
    # while the repo root's passes. This file needs the framework to boot there
    # AND nativephp/mobile's shipped Xcode template to be readable, which is
    # both halves of what the block above lays down.
    if [[ -d $target/mobile-app/vendor ]]; then
        if (cd "$target/mobile-app" && APP_ENV=testing vendor/bin/pest \
            Modules/Mobile/tests/Unit/TheZoneAnIPhoneCouldNotReadTest.php >/dev/null 2>&1); then
            echo "    the mobile-app root boots and reads its shipped shell"
        else
            echo "!!  the repo root is set up and the mobile-app root is not. Mobile tests will" >&2
            echo "!!  fail there rather than skip. Run it directly to see why:" >&2
            echo "!!    cd $target/mobile-app && vendor/bin/pest Modules/Mobile/tests/Unit/TheZoneAnIPhoneCouldNotReadTest.php" >&2
            exit 1
        fi
    fi
else
    # Named first because it is the usual cause and it does not look like one:
    # the worktree is created on origin/main while vendor/ is hardlinked from a
    # main checkout that may be older. The bootstrap above rebuilt the classmap,
    # which is the half a dump-autoload can repair; what it cannot repair is the
    # installed package set, which is still the older checkout's.
    behind=$(git -C "$main" rev-list --count HEAD..origin/main 2>/dev/null || echo 0)
    if [[ ${behind:-0} -gt 0 ]]; then
        echo "!!  $main is $behind commit(s) behind origin/main, and this worktree was" >&2
        echo "!!  created on origin/main. Its vendor/ came from there, so the packages" >&2
        echo "!!  installed there are not this tree's. Fix that first:" >&2
        echo "!!    git -C $main pull --ff-only && (cd $main && composer install)" >&2
    fi
    echo "!!  the control file did not pass. Do not trust a failure in this worktree" >&2
    echo "!!  until this does — run it directly to see why:" >&2
    echo "!!    cd $target && vendor/bin/pest tests/Contracts/BoundaryArchTest.php" >&2
    exit 1
fi

echo "==> ready: cd $target"
