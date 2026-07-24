# CI/CD overview

The pipeline has three GitHub Actions workflows, a CODEOWNERS gate, and a small set of
in-app patterns that exist specifically so the shipped bundle behaves identically
whether it was built by CI or by a developer's local `php artisan native:build`.

## The three workflows

| Workflow | Trigger | Purpose |
|---|---|---|
| `.github/workflows/ci.yml` | Every `pull_request` and every push to `main` | Quality gate: Larastan level 10 strict + Laravel Pint + Pest, across the PHP 8.4 + PHP 8.5 matrix |
| `.github/workflows/release.yml` | `push: tags: [v*]` only | Re-runs the quality gate, builds three platform installers in parallel, signs the update manifest with Ed25519, publishes the GitHub Release |
| `.github/workflows/release-build.yml` | `workflow_dispatch` (manual) on an existing tag | Build-only verification: runs the per-platform `native:build` matrix and uploads the installers as workflow artifacts. No smoke test, no publish, no Ed25519 signing, no GitHub Release — use it to compile-check the desktop bundlers or regenerate artifacts for a tag without cutting a release |

Secret-scanning is handled by GitHub's repo-level **secret scanning** + **push
protection** features (enabled in Settings → Code security), not a workflow.
That covers every push and PR against the canonical provider patterns; the
repo holds no project-specific high-entropy tokens that need a custom rule.

`release.yml` triggers **only** on tag push. It never runs on `pull_request_target` —
that combination is the canonical fork-PR secret-exfiltration pattern, and the project's
posture is to make it impossible by construction.

## Matrix and PHP version policy

The quality gate runs on both PHP 8.4 and PHP 8.5. The shipped desktop bundle currently
uses `nativephp/php-bin` 8.4 (8.5 binaries do not yet exist for the bundle), while the
developer workstation runs PHP 8.5 via the Docker toolchain. Running both axes in CI catches any code
that drifts toward an 8.5-only construct before it can break a release build.

## SHA-pinning rule

Every third-party GitHub Action is pinned to a full 40-character commit SHA, not a tag.
Tag references on GitHub Actions are mutable — `tj-actions/changed-files` was hijacked
mid-2025 by exactly this class of attack — and SHA pinning is the only mitigation that
the platform itself enforces. Each pinned line carries an inline comment naming the
released version so renovate / dependabot can recognise updates:

```yaml
- uses: actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11  # v4.1.1
```

The matching dependabot config under `.github/dependabot.yml` watches the
`github-actions` ecosystem on a weekly cadence, so the inline-comment version tags do not
silently rot.

## CODEOWNERS gating

`.github/CODEOWNERS` lists the repo owner against the workflow directory and the
CODEOWNERS file itself. Once branch protection is on (deferred to the public-visibility
flip), any change to `.github/workflows/*` or `.github/CODEOWNERS` requires an owner
approval on the PR, even if the rest of the branch-protection ruleset allows admin
direct-push. That carve-out is intentional: workflow files are the most security-sensitive
thing in the repo because they execute with `GH_TOKEN`, and they need the second pair of
eyes that admin bypass would otherwise skip.

## `.env.bundled` + APP_KEY sentinel

The shipped bundle ships with a checked-in `.env.bundled` template that holds only
placeholder values — `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=database`, no real
secrets. On first launch, `FirstLaunchBootstrap` copies the template into the user-data
directory and notices that `APP_KEY` is still the sentinel placeholder. When that
sentinel is detected, the bootstrap runs `php artisan key:generate --force` once,
writes the real key to the user-data copy, and never touches it again on subsequent
launches.

Two correctness rails make this safe:

- The sentinel check runs **before** any code that needs `APP_KEY` to decrypt existing
  data. If the user-data DB already exists, the bootstrap aborts the regeneration
  because that path would otherwise wipe every encrypted column.
- The sentinel value itself is committed to `.env.bundled` so the check is deterministic
  rather than a "if the key looks short" heuristic.

The pattern means a developer running `php artisan native:build` locally produces a
bundle that boots identically to a CI-built bundle: both ship the same sentinel, and
both produce a real key on first launch on the user's machine.

## Anti-patterns

A small number of CI patterns are forbidden by posture rather than by tooling:

- **No `pull_request_target` for any quality job.** A fork PR with malicious code in
  the diff would run with the destination repo's secrets if combined with a PR-code
  checkout.
- **No build-time telemetry, Sentry initialisation, or source-map upload to a
  third-party.** The local-only privacy contract extends to the build pipeline. The only
  outbound network call permitted from CI is the GitHub API (`GH_TOKEN` for release
  publish).
- **No auto-update path that skips signature verification.** With no paid OS-level
  signing certificates, the Ed25519 manifest signature is the sole binary-integrity
  signal. Any code path that downloads an update without verifying that signature is
  treated as a release blocker.
