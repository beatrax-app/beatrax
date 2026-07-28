# Repo Security Setup — `beatrax-app/beatrax`

Reproduces the GitHub security posture on a freshly-cloned or freshly-
forked instance of this repo. Companion file:
[`../cicd/branch-protection.md`](https://github.com/beatrax-app/spec/blob/main/30-repos/README.md#the-repo-r-namespace).

GitHub gates several features behind GitHub Pro on private repos
(branch-protection rulesets, secret scanning, CodeQL default setup,
private vulnerability reporting). All of them are free on public repos.
This runbook splits accordingly:

1. **Configure now** — works on private; carries over to public unchanged.
2. **Configure on flip** — apply immediately after the repo flips to public.

---

## Phase 1 — Configure now (works on private)

### Repo metadata

```bash
gh repo edit beatrax-app/beatrax \
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

Topic list (13): `camt053`, `desktop-app`, `dutch-banks`,
`hippocratic-license`, `laravel`, `livewire`, `local-first`, `nativephp`,
`personal-finance`, `php`, `sepa`, `sqlite`, `tailwindcss`.

Homepage URL: not set (no project domain).

Social-preview image: upload `resources/brand/social-preview-1280.png`
via Settings → General → Social preview once the asset is committed.

### Merge & branch hygiene

```bash
gh repo edit beatrax-app/beatrax \
  --enable-projects=false \
  --enable-merge-commit=false \
  --enable-squash-merge=true \
  --enable-rebase-merge=true \
  --delete-branch-on-merge=true
```

| Setting | Value | Why |
|---|---|---|
| Projects | OFF | Issues + Milestones cover planning |
| Merge commit | OFF | Forces linear history at the merge level even before the ruleset enforces it |
| Squash merge | ON | Primary merge strategy for PRs |
| Rebase merge | ON | Allowed for clean linear merges |
| Delete branch on merge | ON | Keeps the branch list tidy |
| Wiki | OFF | Documentation lives in `.docs/` |
| Issues | ON | Bug reports + feature requests |
| Discussions | ON | Community Q&A (default categories: Announcements, General, Ideas, Polls, Q&A, Show and tell — prune unused) |

### Security updates (works on private)

Vulnerability alerts + automated security fixes are a platform toggle and
stay on regardless of which bot proposes version updates:

```bash
gh api -X PUT repos/beatrax-app/beatrax/vulnerability-alerts
gh api -X PUT repos/beatrax-app/beatrax/automated-security-fixes
```

### Version updates — Renovate

Config committed at [`../../renovate.json`](../../renovate.json), which
extends the organisation preset in `beatrax-app/.github`. One preset, one
cadence, one commit convention across every repo — the same reason the
CI workflows are shared rather than copied.

The preset supplies the schedule, the `Spec: GOV-R12` trailer and
sign-off every commit needs to pass the governance gate, the
`dependencies` label, and the rule that groups every non-major update
into a single pull request.

This repository adds only what is specific to it:

- `nativephp/`, `mobile-app/`, `vendor/` and `node_modules/` are ignored —
  the Electron toolchain is vendor-managed by NativePHP and tracked there.
- Majors of `laravel/framework`, `livewire/livewire` and
  `nwidart/laravel-modules` are grouped and labelled `release-blocker`:
  they are one deliberate piece of work, never a drive-by.
- `php` itself is not proposed. The runtime is pinned to what the bundle
  ships, so a bump is a platform decision
  ([platform-matrix](https://github.com/beatrax-app/spec/blob/main/20-architecture/platform-matrix.md)).

Renovate needs the GitHub App installed on the organisation; there is no
per-repo switch to flip beyond committing this file.

### Issue + PR templates (works on private; fully effective once public)

GitHub picks up templates from `.github/` automatically — no settings to
flip. Verify the files exist:

```bash
ls -1 .github/ISSUE_TEMPLATE/ .github/PULL_REQUEST_TEMPLATE.md
# Expected:
#   .github/PULL_REQUEST_TEMPLATE.md
#   .github/ISSUE_TEMPLATE/bug_report.md
#   .github/ISSUE_TEMPLATE/feature_request.md
```

| File | Purpose |
|---|---|
| `.github/ISSUE_TEMPLATE/bug_report.md` | Structured bug intake — reproduction steps, expected vs. actual, environment (OS + beatrax version from `/_dev/health`), redirects security issues to `SECURITY.md` |
| `.github/ISSUE_TEMPLATE/feature_request.md` | Structured feature intake — use case, proposed UX, why existing features don't cover it |
| `.github/PULL_REQUEST_TEMPLATE.md` | Pre-fills every PR body with Summary / Why / Test plan / Checklist (Pint, Larastan, Pest, docs, ADR-if-architectural) + Hippocratic-3.0 contribution acknowledgement |

The bug-report template explicitly tells contributors NOT to open public
issues for security vulnerabilities — the link points at the GitHub
private-vulnerability-reporting endpoint that lights up in Phase 2.

---

## Phase 2 — Configure on flip (public-only)

Run all of these immediately after
`gh repo edit beatrax-app/beatrax --visibility public` succeeds.

### 1. Branch-protection ruleset

See [`../cicd/branch-protection.md`](https://github.com/beatrax-app/spec/blob/main/30-repos/README.md#the-repo-r-namespace) —
copy the ruleset JSON and POST it.

### 2. Secret scanning + push protection (free on public)

```bash
gh api -X PATCH repos/beatrax-app/beatrax \
  -f 'security_and_analysis[secret_scanning][status]=enabled' \
  -f 'security_and_analysis[secret_scanning_push_protection][status]=enabled'
```

What this gives you:

- Scans commits, PRs, and existing code for known secret patterns (AWS
  keys, GitHub tokens, Stripe keys, etc.).
- Push protection rejects pushes containing detected secrets — bypass
  requires an explicit "I confirm this is intentional" comment.

### 3. CodeQL default setup (free on public)

UI-driven — there is no CLI shortcut:

1. Go to `https://github.com/beatrax-app/beatrax/settings/security_analysis`.
2. Under "Code scanning", click "Set up" next to "CodeQL analysis".
3. Pick "Default" (auto-detected languages — PHP + JavaScript).
4. Confirm. GitHub commits the workflow and runs the first scan.

### 4. Private vulnerability reporting (free on public)

```bash
gh api -X PUT repos/beatrax-app/beatrax/private-vulnerability-reporting
```

`SECURITY.md` cross-links to it:

> Report security vulnerabilities privately at
> <https://github.com/beatrax-app/beatrax/security/advisories/new>.

### 5. CODEOWNERS

`.github/CODEOWNERS` becomes load-bearing once branch protection turns
on. Minimum content:

```
# Workflow changes always require the maintainer to approve, even when
# rulesets allow admin bypass elsewhere.
/.github/workflows/  @<github-username>
/.github/CODEOWNERS  @<github-username>
```

Replace `<github-username>` with the actual owner handle.

### 6. Required signed commits — Git client config

`required_signatures` only enforces on the GitHub side. The local dev
box needs:

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

```bash
# 0. Confirm the most recent commit on main is the intended post-scrub tip
git log -1 --oneline

# 1. Flip visibility
gh repo edit beatrax-app/beatrax --visibility public

# 2. Apply branch ruleset (see branch-protection.md)
gh api -X POST repos/beatrax-app/beatrax/rulesets --input /tmp/main-ruleset.json

# 3. Enable secret scanning + push protection
gh api -X PATCH repos/beatrax-app/beatrax \
  -f 'security_and_analysis[secret_scanning][status]=enabled' \
  -f 'security_and_analysis[secret_scanning_push_protection][status]=enabled'

# 4. Enable private vulnerability reporting
gh api -X PUT repos/beatrax-app/beatrax/private-vulnerability-reporting

# 5. CodeQL default setup — UI only, see Step 3 above
echo "Visit https://github.com/beatrax-app/beatrax/settings/security_analysis and enable CodeQL default setup"

# 6. Verify Discussions categories — UI only
echo "Review https://github.com/beatrax-app/beatrax/discussions/categories and prune any unused"

# 7. Add social-preview image — UI only
echo "Upload resources/brand/social-preview-1280.png via Settings → General → Social preview"
```

---

## What this runbook does not cover

- **`signing-prod` GitHub Environment.** Not created — the codebase
  ships no paid signing certs, so there are no signing secrets to gate.
  The single repo secret in use is `ED25519_PRIVATE_KEY` for the
  in-house manifest signature, configured separately during the release
  workflow setup.
- **macOS Developer ID / Azure Trusted Signing secrets.** Not used —
  the public-release posture relies on the user-side first-launch
  unblock for unsigned binaries.

---

## Why nothing on this runbook is paid

Every feature listed is free on the GitHub plan the repo uses (free on
public for the deferred items; free on private for the configured-now
items). No paid Pro, no paid Trusted Signing, no paid Developer ID.
