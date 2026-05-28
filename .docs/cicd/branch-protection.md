# Branch Protection — `nightworksio/beatrax` · main

> Captured during the Phase 17 GitHub security walkthrough (2026-05-27).
> Repo is private at capture time. Branch-protection enforcement
> (rulesets) is **deferred to Plan 17-19** because GitHub gates both
> classic branch protection AND rulesets behind GitHub Pro on private
> repos — the repo gets these features for free immediately after the
> visibility flip to public.

## Default branch

`main` (unchanged).

## Posture chosen (per Phase 17 CONTEXT.md D-50)

**Light, solo-friendly:** linear history + required status checks + signed
commits, but admin can push directly to main without a PR-of-one
ceremony. Tighten when external contributors arrive post-public-release.

## Currently configured (works on private)

- ✓ Default branch: `main`
- ✓ Auto-delete merged head branches
- ✓ Merge commits disabled (forces linear history at the merge level even
  before rulesets enforce it)
- ✓ Squash merge + rebase merge both allowed

## Deferred-to-public (apply immediately after Plan 17-19 flips visibility)

The full ruleset below assumes the visibility flip has happened. Apply via
the gh CLI:

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
          { "context": "quality (PHP 8.5)" },
          { "context": "gitleaks" }
        ]
      }
    }
  ]
}
EOF
gh api -X POST repos/nightworksio/beatrax/rulesets --input /tmp/main-ruleset.json
```

What this enforces:

| Rule | Effect | Why |
|---|---|---|
| `deletion` | Blocks branch deletion | Can't accidentally `git push --delete origin main` |
| `non_fast_forward` | Blocks force-push | Can't rewrite published history |
| `required_linear_history` | No merge commits | All merges via squash or rebase |
| `required_signatures` | All commits to main must be signed | Repo-level integrity |
| `required_status_checks` | Listed CI jobs must pass before any push | The 3 quality gates from Phase 17 |

Bypass:

- `RepositoryRole actor_id 5` = `Admin` — admins (just the owner for now)
  can push directly when needed. Switch to `bypass_mode: "pull_request"`
  later if you want admins to still go through PRs.

## Update procedure when CI matrix changes

If Plan 17-02 widens to `[8.4, 8.5]` BEFORE this ruleset is created, leave
the `quality (PHP 8.5)` context in the ruleset above as-is. If the matrix
is still single-axis when you create the ruleset, drop the 8.5 line then
add it back later via:

```bash
gh api repos/nightworksio/beatrax/rulesets/{ruleset_id} | jq -r .  # find current
# edit json with the additional context, then:
gh api -X PUT repos/nightworksio/beatrax/rulesets/{ruleset_id} --input /tmp/updated.json
```

The `gitleaks` context lands when `.github/workflows/ci.yml` adds the
gitleaks job (Plan 17-04). Add it to the ruleset's required checks at
that point, not before.

## Why no classic branch protection

Classic protection requires Pro/paid on private repos AND is being phased
out by GitHub in favor of rulesets. Skipped entirely.

## Branch protection: main (full enumeration)

Once the ruleset above is applied, the following rules govern every push
to `main`:

| Rule | Setting | Notes |
|---|---|---|
| Require pull request before merging | Optional (admin bypass on) | Solo posture per D-50; tighten when external contributors arrive |
| Require approvals | 0 (or 1 with a second account) | Self-merge allowed when CI is green |
| Dismiss stale approvals on new commits | ON | Standard hygiene |
| Require status checks to pass | ON | See "Status checks required" table below |
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
| `gitleaks` | `.github/workflows/security.yml` | `gitleaks` | Secret-scan every PR + push for leaked credentials |

The matrix axis `[8.4, 8.5]` lands in Plan 17-02; the `gitleaks` job in
Plan 17-03. The ruleset above already references all three contexts so
the moment those workflows merge, the gates become enforced.

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

Public-only items light up automatically after Plan 17-15's visibility
flip — see `../runbooks/repo-security-setup.md` for the apply order.

## Repository metadata

| Field | Value |
|---|---|
| Description | "Local-first personal finance dashboard that resolves cross-account routing chains across banking, ICS Cards, PayPal, and Google Play." |
| Homepage | Not set (no project domain yet) |
| Topics (13) | `camt053`, `desktop-app`, `dutch-banks`, `hippocratic-license`, `laravel`, `livewire`, `local-first`, `nativephp`, `personal-finance`, `php`, `sepa`, `sqlite`, `tailwindcss` |
| Social preview image | `resources/brand/social-preview-1280.png` (uploaded via UI once Plan 17-04 commits the asset) |

## Cross-references

- `../runbooks/repo-security-setup.md` — step-by-step reproduction
  walkthrough with the matching `gh` recipes.
- `release-cadence.md` (lands with Plan 17-01) — how the protected `main`
  branch interacts with the SemVer + release-branch cadence.
- `release-workflow.md` (lands with Plan 17-09b) — how the release
  pipeline reads `main`, builds the tagged artefacts, and respects the
  `required_signatures` rule.
- `../../.github/CODEOWNERS` (lands with Plan 17-03) — required reviewers
  for `/.github/workflows/` once the ruleset enforces PR review.
- `../../.github/dependabot.yml` — the version-update config that
  Dependabot reads weekly.
