# Cutting a release

The operational procedure for shipping a new Beatrax release. Everything happens by
pushing a git tag — the release pipeline is the only path that produces a published
build.

For the underlying mechanics of what the workflow does after you push the tag, see
[`../cicd/release-workflow.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md). For the version policy
and channel semantics, see [`../cicd/release-cadence.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md).

## Before pushing the tag

1. Confirm `main` is green. The PR-gate workflow (`ci.yml`) on the latest commit must
   be passing for both PHP 8.4 and PHP 8.5. If it is not, the release workflow's gate
   job will fail in the same way — fix it on `main` first rather than chasing it
   through the release.
2. Confirm the change set is what you mean to ship. `git log --oneline <last-tag>..HEAD`
   reads as the changelog GitHub will auto-generate; if any commit looks unfinished,
   land the fix before tagging.
3. Pick the version. The version follows semver inside the `v0.x` series — bug fixes
   bump the patch, feature additions bump the minor. The series stays on `0` until the
   explicit graduation moment described in
   [`../cicd/release-cadence.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md).

## Push the tag

```sh
# Stable release on the stable channel — produces a DRAFT GitHub Release
git tag v0.1.0
git push origin v0.1.0

# Release candidate on the preview channel — published immediately as a prerelease
git tag v0.1.0-rc.1
git push origin v0.1.0-rc.1
```

The tag itself is the trigger. There is no `workflow_dispatch` button, no second-step
"start build" action. As soon as the push completes, GitHub Actions runs the gate job
within seconds.

## Watching the build

Follow the run live:

```sh
gh run watch
```

Or browse to the Actions tab on GitHub. Three things to confirm during the run:

- The gate job (both 8.4 and 8.5 axes) completes green. About five minutes.
- All three platform build jobs complete green. About fifteen to twenty minutes
  wall-clock once they kick off — they run in parallel.
- The publish job runs and uploads every binary plus the signed manifest. About two
  minutes.

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
3. Confirm the asset list contains:
   - `beatrax-{version}-mac.dmg`
   - `beatrax-{version}-win.exe` (and `.msi`)
   - `beatrax-{version}.AppImage` and `beatrax-{version}.deb`
   - `latest.yml`, `latest-mac.yml`, `latest-linux.yml` (each Ed25519-signed)
   - `beatrax-{version}-checksums.txt`
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
2. Tag a new patch version (e.g., `v0.1.1` after a broken `v0.1.0`).
3. Let the new release publish as DRAFT, promote it, and let auto-update pull it down
   on subscribed installs.

The corrupt release can be edited in the GitHub UI to add a warning note in its
description, but the binaries themselves should not be deleted — anyone who downloaded
them already has them, and the auto-update path needs the older `latest.yml` references
to stay reachable for at least one cycle.
