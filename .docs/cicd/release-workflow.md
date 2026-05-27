# Release workflow

What happens, in order, when a tag matching `v*` is pushed to the repository. The
workflow definition lives at `.github/workflows/release.yml`; this document narrates
what it does at the wall-clock level a release operator needs in their head.

## Trigger

The workflow's only trigger is `push: tags: [v*]`. There is no `workflow_dispatch`
button, no `pull_request_target` listener, no schedule. Pushing a tag is the single
entry point.

The tag's leading `v` is stripped and exported as `NATIVEPHP_APP_VERSION` for every
downstream job, so a tag of `v0.1.0` produces a bundle that self-identifies as `0.1.0`.
Any build produced outside this pipeline reports `0.0.0-dev` because nothing else sets
the env var — see [release-cadence.md](release-cadence.md) for the source-of-truth
rationale.

## Job 1 — Quality gate

The first job re-runs the same Larastan + Pint + Pest matrix that the PR gate runs, on
both PHP 8.4 and 8.5. Unlike the PR gate it uses `fail-fast: true`: on a release we
want the matrix to abort the instant one axis fails, because a broken `main` should not
spend forty minutes building three platform installers it cannot publish.

Typical runtime: about five minutes when nothing breaks.

## Jobs 2a/2b/2c — Platform builds (parallel)

After the quality gate clears, three platform jobs start in parallel:

| Job | Runner | Output |
|---|---|---|
| `build-macos` | `macos-14` | `.dmg`, ad-hoc-signed via the existing `scripts/nativephp_force_adhoc_signing.php` prebuild hook |
| `build-windows` | `windows-2025` | `.exe` + `.msi`, unsigned |
| `build-linux` | `ubuntu-24.04` | `.AppImage` + `.deb`, unsigned |

Each job does the same shape: `setup-php` → `composer install --no-dev` → `npm ci` →
`php artisan native:build` → platform-specific smoke test → upload artifact.

The smoke test on each platform installs (or extracts) the produced bundle, launches it
headless or windowless where possible, and curls the `/health` endpoint to confirm the
shipped Laravel app boots and answers. The endpoint returns a small JSON payload
containing the app version, PHP version, and SQLite version — enough to catch a bundle
that boots but reports a wrong version, which is the most common silent regression for
this class of build.

Each platform job has a thirty-minute timeout. macOS is the slowest of the three because
ad-hoc signing and `.dmg` packaging together take noticeable time on a CI runner; the
other two finish in roughly half that.

If any platform build fails, the workflow stops. Job 3 has an explicit `needs: [build-macos, build-windows, build-linux]`
dependency, so a partial release never reaches the publish step.

## Job 3 — Publish

Once all three platform builds succeed, the publish job runs:

1. Downloads every artifact from the three platform jobs into a single staging
   directory.
2. Generates the `electron-updater` manifest files (`latest.yml`, `latest-mac.yml`,
   `latest-linux.yml`) with SHA-512 hashes for every produced binary.
3. Signs each manifest with the repo's Ed25519 publisher private key (held in a single
   GitHub Actions secret) using libsodium via a short PHP script. The signature is
   appended to the manifest in a dedicated field that the shipped app reads.
4. Calls `softprops/action-gh-release` to create the GitHub Release with every binary
   and every signed manifest attached.

The release's `draft` flag is set asymmetrically:

- If the tag name contains `-rc` (e.g., `v0.1.0-rc.1`), the release is published
  immediately and flagged as a prerelease. This is the preview channel.
- Otherwise (e.g., `v0.1.0`), the release is created as a DRAFT. The artifacts are
  uploaded and visible to anyone with repo write, but no end user can download the
  release until a human reviewer clicks Publish in the GitHub UI. This is the stable
  channel.

The asymmetry exists so a mistaken `git push --tags` of a stable tag cannot ship
straight to users. RC tags trust the operator pushed them deliberately.

## Verification of the published release

Two things happen after the GitHub Release goes live:

- An installed app on the stable or preview channel polls GitHub Releases every four
  hours through the in-app `ElectronUpdateChannel`. It downloads the matching manifest,
  verifies the Ed25519 signature against the public key embedded in the bundle, and
  refuses to proceed if the signature does not check out. The binary itself is then
  verified against the SHA-512 hash in the (now-trusted) manifest before any install
  step runs.
- An end user can manually verify any downloaded asset by following the recipe in
  [`../runbooks/verify-release.md`](../runbooks/verify-release.md). The recipe relies on
  the same signed manifest as the auto-updater, so the chain is reproducible by hand.

The Ed25519 verification path is the sole binary-integrity signal in the absence of
paid OS-level signing certificates. See
[`../legal/license-rationale.md#no-paid-signing`](../legal/license-rationale.md#no-paid-signing)
for the trade-off rationale.
