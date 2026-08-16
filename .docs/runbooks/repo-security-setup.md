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

## Release signing

macOS is signed with a real Developer ID certificate and notarised, proven
end to end on a CI runner. **Windows is not yet signable** — see below.
The values live as repository secrets and variables; the originals are in
the Beatrax 1Password vault.

| Platform | Secrets | Variables |
|---|---|---|
| Windows | `AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET` | `NATIVEPHP_AZURE_ENDPOINT`, `NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME`, `NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME`, `NATIVEPHP_AZURE_PUBLISHER_NAME` |
| macOS | `CSC_LINK`, `CSC_KEY_PASSWORD`, `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS` | `NATIVEPHP_MAC_IDENTITY`, `NATIVEPHP_APPLE_TEAM_ID` |

The Azure entries are variables rather than secrets deliberately: they
name an endpoint, an account, a certificate profile and a publisher, none
of which is confidential, and masking them turns a failed signing run into
a log full of `***` at the moment those values are what you need to read.

### Windows: blocked on a certificate profile

The Azure Trusted Signing account (`beatraxsigning` in
`rg-beatrax-signing`) exists, the service principal authenticates, and it
holds the Trusted Signing Certificate Profile Signer role. But the account
has **no certificate profile**:

```sh
az rest --method get --url "https://management.azure.com/subscriptions/<sub>/resourceGroups/rg-beatrax-signing/providers/Microsoft.CodeSigning/codeSigningAccounts/beatraxsigning/certificateProfiles?api-version=2024-09-30-preview"
# {"value": []}
```

The last two variables are therefore unset on purpose, so the guard blocks
a Windows release rather than a build silently producing an unsigned
artifact. To unblock, create a certificate profile in the Trusted Signing
portal against the approved identity validation, then set:

- `NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME` — the profile's name
- `NATIVEPHP_AZURE_PUBLISHER_NAME` — the certificate subject's common
  name, which is the validated legal entity name. signtool compares this
  against the certificate, so a guess fails at sign time rather than at
  config validation.

Record both on the Azure service principal item in 1Password, replacing
the `(pending — set after cert profile is created)` placeholder that field
currently holds.

`publisherName` is required by electron-builder 26 but not emitted by
NativePHP, so `scripts/nativephp_azure_publisher_name.php` patches it into
the generated config. Without that patch every Trusted Signing build
aborts with `configuration.win.azureSignOptions misses the property
'publisherName'`.

`CSC_LINK` holds ONLY the Developer ID identity. The Keychain export that
produces it (`security export -t identities`) emits every identity on the
machine, which here includes the Apple Distribution key used for iOS —
that key has no business in a desktop CI secret, so the bundle is split
and rebuilt around the Developer ID certificate alone before upload.

### Every gap here fails silently

This is the property to design against, not the individual credentials.
`electron-builder.mjs` omits `azureSignOptions` entirely when any
`NATIVEPHP_AZURE_*` value is empty; the macOS Developer ID prebuild hook
exits non-zero but NativePHP's `HasPreAndPostProcessing::runProcess()`
swallows that with a `return` that leaves the closure rather than the
build; electron-builder then falls back to an ad-hoc signature with a
warning, and `notarize.js` skips notarisation with another. Four ways to
ship something unsigned from a build that reports success.

So the release workflows guard on both sides: the build step fails when a
required value is empty, and the produced artifact is verified to carry a
real signature before it is uploaded.

### Expiry

The Developer ID certificate expires **2027-02-01** — a short window,
capped by the Developer Program membership rather than the usual five
years. The Azure client secret expires **2027-07-15**. Neither expiry
fails the build on its own; macOS in particular degrades back to ad-hoc,
which is what the artifact verification exists to catch.

## What this runbook does not cover

- **`signing-prod` GitHub Environment.** Not created. The signing secrets
  above are repository-scoped; gating them behind an environment with
  required reviewers is a reasonable hardening step and has not been done.
- **Mobile signing.** Android and iOS are built and signed by Bifrost from
  credentials uploaded to its own panel, not from this repository. The
  Android release keystore and its passwords live in 1Password; a local
  release build reads them from `mobile-app/.env` (see
  `mobile-app/.env.example`).

---

## Why nothing on this runbook is paid

Every feature listed is free on the GitHub plan the repo uses (free on
public for the deferred items; free on private for the configured-now
items). No paid Pro, no paid Trusted Signing, no paid Developer ID.
