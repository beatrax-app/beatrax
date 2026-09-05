# Cutting a release

The operational procedure for shipping a new Beatrax release. Everything happens by
pushing a git tag — the release pipeline is the only path that produces a published
build.

For the underlying mechanics of what the workflow does after you push the tag, and for
the version policy and channel semantics, see
[`70-operations/releasing.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md).

## Before pushing the tag

1. Confirm `main` is green. The PR-gate workflow (`ci.yml`) runs on PHP 8.5 only —
   three test shards plus a static-analysis job, collapsed into the single
   `quality (PHP 8.5)` check — and that check must be passing on the latest commit.
   There is no 8.4 axis to wait for. If it is not green, the release workflow's gate
   job will fail in the same way — fix it on `main` first rather than chasing it
   through the release.
2. Confirm the change set is what you mean to ship. `git log --oneline <last-tag>..HEAD`
   is the raw material; if any commit looks unfinished, land the fix before tagging.
   The published notes are narrower than that log — git-cliff builds them from
   `cliff.toml`, which skips `docs`, `ci`, `build`, `test`, `style` and `chore` commits
   and groups the rest by conventional-commit type.
3. Pick the version. The version follows semver — bug fixes bump the patch, feature
   additions bump the minor, and a breaking change bumps the major. The spec owns the
   policy behind that; see
   [`70-operations/releasing.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md).

## Push the tag

```sh
# Stable release on the stable channel — produces a DRAFT GitHub Release
git tag v1.4.0
git push origin v1.4.0

# Release candidate on the preview channel — published immediately as a prerelease
git tag v1.4.0-rc.1
git push origin v1.4.0-rc.1
```

The tag itself is the trigger — `push: tags: ['v*']` and nothing else. There is no
`workflow_dispatch` button, no second-step "start build" action. As soon as the push
completes, GitHub Actions runs the spec gate and the quality gate within seconds; the
spec gate fails the run outright if the canonical spec does not consider the tagged
version releasable.

## Watching the build

Follow the run live:

```sh
gh run watch
```

Or browse to the Actions tab on GitHub. Four things to confirm during the run:

- The spec gate and the quality gate (a single PHP 8.5 axis) complete green. About
  five minutes.
- All four build jobs — macOS, Windows, Linux, Android — complete green. About fifteen
  to twenty minutes wall-clock once they kick off; they run in parallel. The macOS and
  Windows jobs refuse to build at all when a signing credential is missing, and then
  interrogate the artifact they produced rather than trusting the build's exit code.
- The publish job runs, signs each auto-update manifest with Ed25519, and uploads every
  artifact plus the detached signatures. About two minutes.
- The `verify published` job re-downloads the manifests from the release page and
  re-verifies every signature against the publisher key committed in
  `config/auto_update.php`. If it fails, the assets on the page are not what the
  pipeline signed.

If any platform job fails, the workflow stops and the publish job is skipped. Fix the
underlying cause on `main`, then either delete and re-push the same tag (acceptable for
an RC that has not been distributed) or bump to the next patch version (the safe choice
for a stable tag that has been seen by anyone).

## After the run completes

### For an RC tag (`v*-rc.*`)

The release is already published as a prerelease. Subscribers on the preview channel
will receive the update on their next auto-update poll (within four hours). No further
action is required.

### For a stable tag (`v*.*.*`)

The release exists as a DRAFT. The artifacts are uploaded and visible to anyone with
repo write, but no end user can see or download the release. To promote:

1. Open the release in the GitHub UI under Releases.
2. Verify the auto-generated release notes read correctly. Edit if needed.
3. Confirm the asset list. There is no checksum file to look for — the hashes live
   inside the manifests — so what to check is that each of `latest.yml`,
   `latest-mac.yml` and `latest-linux.yml` is present, that each has a `.sig` sibling,
   and that the installer each one names in its `path:` field is on the page too. The
   Windows `.exe`, the macOS `.dmg`, the Linux `.AppImage` and the Android `.apk` are
   the artifacts the four build jobs upload; `.msi` and `.deb` appear when
   `electron-builder` produced them.
4. Click Publish release.

Once published, the stable channel sees the new version on its next auto-update poll.

## Re-running a failed publish

If only the publish job fails (platform builds succeeded, manifest signing or release
upload failed), the workflow can be re-run from the GitHub UI without re-building. The
platform artifacts remain attached to the run for the standard retention window and
the publish job downloads them again.

If a platform job fails, the artifacts are not produced; the only recovery is to fix
the failure cause on `main`, then push a new tag — even a re-pushed tag of the same
name does not re-trigger the workflow reliably because GitHub Actions deduplicates by
tag SHA, and the SHA changes only with new commits.

## Rolling back

There is no "unpublish" path that preserves user trust. A published stable release that
turns out to be broken gets superseded by a new patch release, not retracted. The
correct procedure:

1. Fix the issue on `main`.
2. Tag a new patch version (e.g., `v1.4.1` after a broken `v1.4.0`).
3. Let the new release publish as DRAFT, promote it, and let auto-update pull it down
   on subscribed installs.

The corrupt release can be edited in the GitHub UI to add a warning note in its
description, but the binaries themselves should not be deleted — anyone who downloaded
them already has them, and the auto-update path needs the older `latest.yml` references
to stay reachable for at least one cycle.
