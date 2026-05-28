# Branch Protection — `nightworksio/beatrax` · main

Branch-protection enforcement for `main` uses GitHub **rulesets** (the
modern, free-on-public successor to classic branch protection).
Companion runbook: [`../runbooks/repo-security-setup.md`](../runbooks/repo-security-setup.md).

> **Visibility gating.** Several listed features (rulesets, secret
> scanning, CodeQL default setup, private vulnerability reporting) are
> only free once the repo is **public**. On a private repo they require
> GitHub Pro. The "configured-now" vs. "configured-on-flip" split is
> captured in the runbook.

## Default branch

`main`.

## Posture

**Light, solo-friendly.** Linear history, required status checks, signed
commits — but admins can push directly to `main` without a PR-of-one
ceremony. The bypass switches off the moment external contributors
arrive.

## Current configuration (works on private)

| Setting | Value |
|---|---|
| Default branch | `main` |
| Auto-delete merged head branches | ON |
| Merge commits | OFF (linear history at the merge level even before rulesets enforce) |
| Squash merge | ON |
| Rebase merge | ON |

## Ruleset (applies on public)

```bash
cat <<'EOF' > /tmp/main-ruleset.json
{
  "name": "main protection",
  "target": "branch",
  "enforcement": "active",
  "bypass_actors": [
    { "actor_id": 5, "actor_type": "RepositoryRole", "bypass_mode": "always" }
  ],
  "conditions": {
    "ref_name": { "include": ["~DEFAULT_BRANCH"], "exclude": [] }
  },
  "rules": [
    { "type": "deletion" },
    { "type": "non_fast_forward" },
    { "type": "required_linear_history" },
    { "type": "required_signatures" },
    {
      "type": "required_status_checks",
      "parameters": {
        "strict_required_status_checks_policy": false,
        "required_status_checks": [
          { "context": "quality (PHP 8.4)" },
          { "context": "quality (PHP 8.5)" }
        ]
      }
    }
  ]
}
EOF
gh api -X POST repos/nightworksio/beatrax/rulesets --input /tmp/main-ruleset.json
```

Secret-scanning is provided by the repo-level GitHub feature (Settings →
Code security → Secret scanning + Push protection); no custom workflow is
gated by this ruleset.

### Rules enforced

| Rule | Effect | Why |
|---|---|---|
| `deletion` | Blocks branch deletion | `main` cannot be deleted, accidentally or otherwise |
| `non_fast_forward` | Blocks force-push | Published history cannot be rewritten |
| `required_linear_history` | No merge commits | All merges via squash or rebase |
| `required_signatures` | Every commit on `main` is signed | Repo-level integrity |
| `required_status_checks` | Listed CI jobs must pass before merge | See "Status checks required" below |

### Bypass

`RepositoryRole actor_id 5` (`Admin`) — admins can push directly when
the situation demands it. Switch to `bypass_mode: "pull_request"` once
external contributors arrive so admins still go through PRs.

## Branch protection: main (full enumeration)

| Rule | Setting | Notes |
|---|---|---|
| Require pull request before merging | Optional (admin bypass on) | Solo posture; tighten when external contributors arrive |
| Require approvals | 0 (or 1 with a second account) | Self-merge allowed when CI is green |
| Dismiss stale approvals on new commits | ON | Standard hygiene |
| Require status checks to pass | ON | See "Status checks required" below |
| Require branches up to date before merging | ON | Forces a rebase against `main` before merge |
| Require signed commits | ON | All commits to `main` carry a verified signature |
| Require linear history | ON | Squash + rebase only; no merge commits |
| Restrict force-push | Blocked for everyone | `non_fast_forward` rule — even admins must use a fresh branch |
| Restrict deletion | Blocked for everyone | `deletion` rule — `main` cannot be deleted |
| Auto-delete merged head branches | ON | Repo-level setting (separate from ruleset) |

## Status checks required

| Status check context | Workflow file | Job | Purpose |
|---|---|---|---|
| `quality (PHP 8.4)` | `.github/workflows/ci.yml` | `quality` (matrix `php: 8.4`) | Pint, Larastan L10 strict, Pest full suite on PHP 8.4 — the runtime `nativephp/php-bin` ships |
| `quality (PHP 8.5)` | `.github/workflows/ci.yml` | `quality` (matrix `php: 8.5`) | Same gates on the next supported PHP — catches forward-compat breakage early |

Secret-scanning runs at the repo-platform level (GitHub Secret Scanning +
Push Protection), not as a status check. Findings surface in the Security
tab and can block pushes that introduce a recognised provider token.

The ruleset references both contexts already, so the gates become
enforced the moment the matching workflows merge.

## Repository settings

| Setting | Value | Where |
|---|---|---|
| Default branch | `main` | Settings → General |
| Auto-delete merged head branches | ON | Settings → General → Pull Requests |
| Merge commits | OFF | Settings → General → Pull Requests |
| Squash merging | ON | Settings → General → Pull Requests |
| Rebase merging | ON | Settings → General → Pull Requests |
| Wiki | OFF | Settings → General → Features (docs live in `.docs/`) |
| Projects | OFF | Settings → General → Features (Issues + Milestones cover planning) |
| Discussions | ON | Settings → General → Features (Q&A, Show & Tell, Announcements + default General) |
| Issues | ON | Settings → General → Features |
| Secret scanning | ON (public-only) | Settings → Code security and analysis |
| Push protection | ON (public-only) | Settings → Code security and analysis |
| Dependabot alerts | ON | Settings → Code security and analysis |
| Dependabot security updates | ON | Settings → Code security and analysis |
| Dependabot version updates | ON | `.github/dependabot.yml` (composer + npm + github-actions, weekly) |
| Code scanning (CodeQL default) | ON (public-only) | Settings → Code security and analysis |
| Private vulnerability reporting | ON (public-only) | Settings → Code security and analysis |

Public-only items light up automatically after the visibility flip — see
the runbook for the apply order.

## Repository metadata

| Field | Value |
|---|---|
| Description | "Local-first personal finance dashboard that resolves cross-account routing chains across banking, ICS Cards, PayPal, and Google Play." |
| Homepage | Not set (no project domain yet) |
| Topics (13) | `camt053`, `desktop-app`, `dutch-banks`, `hippocratic-license`, `laravel`, `livewire`, `local-first`, `nativephp`, `personal-finance`, `php`, `sepa`, `sqlite`, `tailwindcss` |
| Social preview image | `resources/brand/social-preview-1280.png` (uploaded via UI) |

## Update procedure when CI matrix changes

The ruleset references status-check contexts by exact string. If the CI
matrix or a workflow job name changes, the ruleset's
`required_status_checks` list needs the same edit:

```bash
gh api repos/nightworksio/beatrax/rulesets/{ruleset_id} | jq -r .
# edit the JSON with the new context, then:
gh api -X PUT repos/nightworksio/beatrax/rulesets/{ruleset_id} --input /tmp/updated.json
```

## Why no classic branch protection

Classic branch protection requires GitHub Pro on private repos and is
being phased out by GitHub in favor of rulesets. Rulesets are the
single source of truth.

## Cross-references

- [`../runbooks/repo-security-setup.md`](../runbooks/repo-security-setup.md) — step-by-step reproduction walkthrough with the matching `gh` recipes.
- `release-cadence.md` (sibling doc) — how the protected `main` branch interacts with the SemVer + release-branch cadence.
- `release-workflow.md` (sibling doc) — how the release pipeline reads `main`, builds the tagged artefacts, and respects the `required_signatures` rule.
- `../../.github/CODEOWNERS` — required reviewers for `/.github/workflows/` once the ruleset enforces PR review.
- `../../.github/dependabot.yml` — the version-update config Dependabot reads weekly.
