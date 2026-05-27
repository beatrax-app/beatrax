# Branch Protection — `nightworksio/beatrax` · main

> Captured during the Phase 17 GitHub security walkthrough (2026-05-27).
> Repo is private at capture time. Branch-protection enforcement
> (rulesets) is **deferred to Plan 17-19** because GitHub gates both
> classic branch protection AND rulesets behind GitHub Pro on private
> repos — the repo gets these features for free immediately after the
> visibility flip to public.

## Default branch

`main` (unchanged).

## Posture chosen

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
