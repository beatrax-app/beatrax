# Repo Security Setup — `nightworksio/beatrax`

> Runbook captured during the Phase 17 GitHub security walkthrough
> (2026-05-27). Reproduces the security posture on a freshly-forked or
> freshly-cloned org repo. Companion file:
> `.docs/cicd/branch-protection.md`.

The walkthrough hit one structural constraint: **GitHub gates several
features behind GitHub Pro on private repos** (branch protection /
rulesets, secret scanning, CodeQL default setup, private vulnerability
reporting). The repo will be public after Plan 17-19 — at that point
all these features become free. The runbook splits into two phases:

1. **Configure now** — works on private; carries over to public unchanged.
2. **Configure on flip** — apply immediately after Plan 17-19 toggles
   visibility to public.

---

## Phase 1 — Configure now (works on private)

### Repo metadata

Already applied:

```bash
gh repo edit nightworksio/beatrax \
  --description "Local-first personal finance dashboard that resolves cross-account routing chains across banking, ICS Cards, PayPal, and Google Play." \
  --add-topic personal-finance \
  --add-topic laravel \
  --add-topic php \
  --add-topic desktop-app \
  --add-topic nativephp \
  --add-topic local-first \
  --add-topic hippocratic-license \
  --add-topic livewire \
  --add-topic tailwindcss \
  --add-topic sqlite \
  --add-topic dutch-banks \
  --add-topic sepa \
  --add-topic camt053
```

Final topic list (13): camt053, desktop-app, dutch-banks,
hippocratic-license, laravel, livewire, local-first, nativephp,
personal-finance, php, sepa, sqlite, tailwindcss.

Homepage URL: not set (no project domain yet — revisit if/when one lands).

Social-preview image: not set (Plan 17-04 commits `resources/brand/social-preview-1280.png`; upload via UI when ready).

### Merge & branch hygiene

Already applied:

```bash
gh repo edit nightworksio/beatrax \
  --enable-projects=false \
  --enable-merge-commit=false \
  --enable-squash-merge=true \
  --enable-rebase-merge=true \
  --delete-branch-on-merge=true
```

| Setting | Value | Why |
|---|---|---|
| Projects | OFF | Issues + Milestones cover planning (Plan 17-18 sets up the v1.1 milestone) |
| Merge commit | OFF | Forces linear history at the merge level even before ruleset enforcement lands |
| Squash merge | ON | Primary merge strategy for PRs |
| Rebase merge | ON | Allowed for clean linear merges |
| Delete branch on merge | ON | Keeps the branch list tidy |
| Wiki | OFF | Documentation lives in `.docs/` (Plan 17-08+) |
| Issues | ON | Bug reports + feature requests |
| Discussions | ON | Community Q&A (default categories: Announcements, General, Ideas, Polls, Q&A, Show and tell — prune later if any feel unused) |

### Dependabot — security updates (works on private)

Vulnerability alerts + automated security fixes enabled (free on private):

```bash
gh api -X PUT repos/nightworksio/beatrax/vulnerability-alerts
gh api -X PUT repos/nightworksio/beatrax/automated-security-fixes
```

### Dependabot — version updates (works on private)

Config committed at `.github/dependabot.yml` (companion file). Covers three
ecosystems on a weekly Monday-morning Europe/Amsterdam cadence:

- **composer** at `/` — Laravel, modules, brick/money, etc. Grouped:
  `laravel` (laravel/livewire/spatie/nwidart), `dev-tooling` (larastan,
  phpstan, pestphp, pint).
- **npm** at `/` — Vite, Tailwind, ApexCharts, Axios, Fuse.js. Grouped:
  `build-tooling` (vite + vite plugins + concurrently), `tailwind`.
- **github-actions** at `/` — actions/checkout, setup-php, etc.

PR limit: 5 (composer + npm) / 3 (github-actions). Labels:
`dependencies` + ecosystem tag.

### Issue + PR templates (works on private; fully effective once public)

GitHub picks up templates from `.github/` automatically — no settings to
flip. The walkthrough confirms the files exist:

```bash
ls -1 .github/ISSUE_TEMPLATE/ .github/PULL_REQUEST_TEMPLATE.md
# Expect:
#   .github/PULL_REQUEST_TEMPLATE.md
#   .github/ISSUE_TEMPLATE/bug_report.md
#   .github/ISSUE_TEMPLATE/feature_request.md
```

| File | Purpose |
|---|---|
| `.github/ISSUE_TEMPLATE/bug_report.md` | Structured bug intake — reproduction steps, expected vs. actual, environment (OS + beatrax version from `/_dev/health`), redirects security issues to SECURITY.md |
| `.github/ISSUE_TEMPLATE/feature_request.md` | Structured feature intake — use case, proposed UX, why existing features don't cover it |
| `.github/PULL_REQUEST_TEMPLATE.md` | Pre-fills every PR body with Summary / Why / Test plan / Checklist (Pint, Larastan, Pest, docs, ADR-if-architectural) + Hippocratic-3.0 contribution acknowledgement |

The bug-report template explicitly tells contributors NOT to open public
issues for security vulnerabilities — the link points at the GitHub
private-vulnerability-reporting endpoint that lights up in Phase 2.

---

## Phase 2 — Configure on flip (public-only)

Run all of these immediately after Plan 17-19's
`gh repo edit nightworksio/beatrax --visibility public` succeeds.

### 1. Branch-protection ruleset

See `.docs/cicd/branch-protection.md` — copy the ruleset JSON and POST it.

### 2. Secret scanning + push protection (free on public)

```bash
gh api -X PATCH repos/nightworksio/beatrax \
  -f 'security_and_analysis[secret_scanning][status]=enabled' \
  -f 'security_and_analysis[secret_scanning_push_protection][status]=enabled'
```

What this gives you:

- Scans commits, PRs, and existing code for known secret patterns (AWS
  keys, GitHub tokens, Stripe keys, etc.)
- Push protection rejects pushes that contain detected secrets (you
  bypass only with an explicit "I confirm this is intentional" comment)

### 3. CodeQL default setup (free on public)

This is a UI-driven setup — there's no CLI shortcut. Steps:

1. Go to `https://github.com/nightworksio/beatrax/settings/security_analysis`
2. Under "Code scanning", click "Set up" next to "CodeQL analysis"
3. Pick "Default" (auto-detected languages — should pick PHP + JavaScript)
4. Confirm. GitHub commits the workflow + runs the first scan.

### 4. Private vulnerability reporting (free on public)

```bash
gh api -X PUT repos/nightworksio/beatrax/private-vulnerability-reporting
```

Then cross-link from `SECURITY.md` (Plan 17-07 deliverable) with copy
like:

> Report security vulnerabilities privately at
> <https://github.com/nightworksio/beatrax/security/advisories/new>.

### 5. CODEOWNERS

CODEOWNERS at `.github/CODEOWNERS` lands as part of Plan 17-04 (the
release-workflow plan), then becomes load-bearing once branch protection
is on. Minimum content:

```
# Workflow changes always require the maintainer to approve, even when
# rulesets allow admin bypass elsewhere.
/.github/workflows/  @<github-username>
/.github/CODEOWNERS  @<github-username>
```

Replace `<github-username>` with the actual owner handle.

### 6. Required signed commits — Git client config

Required signatures only enforces on the GitHub side. To make local
signing work without surprise, the dev box needs:

```bash
# One-time per machine
gh auth setup-git              # SSH auth for gh
gpg --full-generate-key        # Generate GPG key if none exists
git config --global user.signingkey <KEY-ID>
git config --global commit.gpgsign true
gh ssh-key add ~/.ssh/id_ed25519.pub  # If using SSH commit signing instead
```

Or via Sigstore (`gitsign`) — see GitHub docs on commit signing.

### 7. Going-public checklist (one pass, all in order)

When the time comes:

```bash
# 0. Confirm Plan 17-17 (.planning purge) has landed and force-pushed
git log --oneline | grep -q ".planning" && echo "STOP — .planning still in history" || echo "ok"

# 1. Flip visibility
gh repo edit nightworksio/beatrax --visibility public

# 2. Apply branch ruleset (see branch-protection.md)
gh api -X POST repos/nightworksio/beatrax/rulesets --input /tmp/main-ruleset.json

# 3. Enable secret scanning + push protection
gh api -X PATCH repos/nightworksio/beatrax \
  -f 'security_and_analysis[secret_scanning][status]=enabled' \
  -f 'security_and_analysis[secret_scanning_push_protection][status]=enabled'

# 4. Enable private vulnerability reporting
gh api -X PUT repos/nightworksio/beatrax/private-vulnerability-reporting

# 5. CodeQL default setup — UI only, see Step 3 above
echo "Visit https://github.com/nightworksio/beatrax/settings/security_analysis and enable CodeQL default setup"

# 6. Verify Discussions categories — UI only
echo "Review https://github.com/nightworksio/beatrax/discussions/categories and prune any unused"

# 7. Add social-preview image — UI only
echo "Upload resources/brand/social-preview-1280.png via Settings → General → Social preview"
```

---

## What did NOT happen during the walkthrough

- `signing-prod` GitHub Environment — dropped per Phase 17 A-03 (no paid
  signing certs means no signing secrets to gate).
- macOS Developer ID / Azure Trusted Signing secrets — dropped per A-01.
- Branch protection ruleset itself — deferred per Phase 1/2 split above.
- CodeQL / secret scanning / private vulnerability reporting — same.

---

## Why nothing was paid for

User constraint (Phase 17 amendment A-01..A-03): no paid signing certs,
no paid GitHub Pro, no paid Azure Trusted Signing. Everything in this
runbook works on free GitHub plans (free on public for the deferred
items; free on private for the configured-now items).
