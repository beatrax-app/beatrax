# Phase 17: v1.0.0 Public Release Closeout - Context

**Gathered:** 2026-05-27
**Status:** Ready for planning

> **Scope reshape — 2026-05-27.** Phase 17 was originally "CI/CD Pipeline +
> Code Signing" (CI-01..CI-06, 6 requirements). The user reshaped it into the
> final phase of the milestone, absorbing Phases 18 + 19 + 20 + 21 plus
> additional housekeeping. Phases 18, 19, 20, 21 are **deleted** from the
> roadmap. The phase number stays at 17 to avoid file-rename churn.
> Counterparty-profile pages (a new feature) are explicitly included. No
> formal UAT or beta cycle precedes the public release — the user
> acknowledged the risk that first-run issues will land in the public GitHub
> Issues tab on day 1.

<domain>
## Phase Boundary

Everything between "internal v0.x dev box" and "public open-source repo
running v1.0.0." This phase produces the first public-shippable version of
the app. The user's mental model: pre-v1.0 territory until the explicit
v1.0.0 graduation moment at the end of this phase.

Phase 17 ships, grouped by area:

### A. Versioning policy + tag hygiene (NEW — item #1)

- All tags during this phase use `v0.x.x` (e.g., `v0.1.0`, `v0.1.0-rc.1`).
- ALL existing tags are deleted (none of them point to public-ship-quality
  builds; they're internal dev milestones).
- `config/nativephp.php` default version flips from `'1.0.0'` to
  `'0.0.0-dev'`.
- The first tag of this phase is `v0.1.0` (or `v0.1.0-rc.1`).
- `v1.0.0` is reserved as the explicit "go-public" graduation tag —
  user pulls the trigger by name; no automation jumps the version.

### B. CI/CD pipeline (original Phase 17 — CI-01..CI-06)

- `.github/workflows/ci.yml` PR gate widened from single-axis 8.4 to
  the PHP `[8.4, 8.5]` matrix.
- `.github/workflows/release.yml` (NEW) — tag-triggered. Two patterns:
  `v*.*.*` → stable channel (DRAFT release); `v*-rc.*` → preview channel
  (immediate publish).
- Three parallel platform jobs (macOS-14 + Windows-2025 + Ubuntu-24.04)
  with all-must-succeed publish.
- macOS signing via `apple-actions/import-codesign-certs v7.0.0` +
  notarytool `submit --wait --timeout 45m` + staple.
- Windows signing via `Azure/trusted-signing-action v2.0.0`.
- Linux: unsigned `.AppImage` + `.deb`.
- Smoke tests per platform: install → launch → HTTP `/health` probe →
  exit. Failure on any platform fails the whole release.
- Three new prebuild hooks (env-gated, symmetric pattern):
  `nativephp_inject_developer_id.php` (macOS release),
  `nativephp_inject_windows_signing.php` (Windows release); existing
  `nativephp_force_adhoc_signing.php` gets an env-var early-return.
- Per-install APP_KEY regeneration at first launch via sentinel file
  (CI-06); first-launch encryption-key generation for `oauth_secrets`.
- `.env.bundled` template with no real secrets.
- `signing-prod` GitHub Environment + CODEOWNERS on `.github/workflows/`
  + `gitleaks-action@v2` on every PR + `pull_request_target`-safe trigger
  shape (release.yml fires only on tag push, never on fork PRs).

### C. Auto-update plumbing (absorbed from Phase 18 — UPDATE-01..04)

- `Modules\Core\Public\Services\ElectronUpdateChannel` wired through
  `electron-updater`; consumes GitHub Releases as the channel source.
- Ed25519 publisher pin + signature verification on every download
  (no unsigned auto-update path). Pest test proves verification fails
  on a tampered manifest.
- "Update available — install on next launch" + "Skip this version" +
  "you're on an old version" (30-day stale prompt) integrated into
  the existing `SystemAlertsBanner`.
- First-install-can't-auto-update note on a Settings page so the
  first user knows to grab v0.1.1 manually after v0.1.0.

### D. Public release boundary (absorbed from Phase 19 — REL-01..08)

- **Hippocratic License 3.0**: `LICENSE` + `NOTICE.md` explainer +
  composer.json `"license": "Hippocratic-3.0"` SPDX identifier.
- **Community docs at repo root**: `SECURITY.md` (vulnerability-reporting
  policy + safe-harbor) + `CONTRIBUTING.md` (DI rule + arch tests + branch
  + PR conventions) + `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1).
- **README rewrite**: public-audience hero with `resources/brand/logo.svg`,
  "What is beatrax?" / "Who is this for?" / per-platform install /
  screenshots of dashboard / chains / forecast / dev console / multi-user.
- **Brand asset import**: SVG already at `resources/brand/logo.svg`
  (Phase 15 D-20); generate `.icns`, `.ico`, `logo-512.png`, and favicon.
- **GSD-leakage redaction sweep** (REL-05): purge `.planning/` / `PLAN.md`
  / `RESEARCH.md` / `D-NNN` / GSD phase codenames from runtime code,
  comments, views, error messages, log lines, route names, view-data keys.
  Add a `BoundaryArchTest::noGsdLeakage` arch invariant to prevent
  regression.
- **Deep modules code review** (REL-06): cross-module boundary hygiene +
  DI compliance + dead code + perf smells + composer dep analyzer across
  all modules. Produce REVIEW-DEEP.md and action findings in the same
  phase.
- **Renderer-JSON audit** (REL-07): every Livewire component's public
  properties / `$listeners` / `$queryString` checked for `oauth_secrets`
  / hidden-column / cross-user-id leak through the wire snapshot.
  Arch test enforces "no secrets-tagged columns in `$listeners` /
  `$queryString` / public properties."
- **"Where is my data?" docs page** (REL-08): in-app + on README; one-click
  export-everything ZIP (canonical SQLite + brand assets + user-data dir).

### E. `.docs/` folder (NEW — item #4)

Mirrors the happklaar/happklaar format. **Critically: the writing rule from
happklaar — `.planning/` is gitignored, local-only; promote graduated
artifacts here.** That rule is what makes item #2 (history purge) safe and
sustainable.

Top-level structure:
- `.docs/00-index.md` — navigation table to subtrees
- `.docs/adr/00-index.md` + `0001-…md`, `0002-…md`, … — Architecture
  Decision Records derived from PROJECT.md "Key Decisions" + the load-bearing
  decisions accumulated across v0.x phases (e.g., DI-only, modular boundary,
  Hippocratic-3.0, multi-user activation contract, NativePHP shell,
  database queue driver, etc.)
- `.docs/architecture/00-index.md` + topic files — module boundaries,
  ingestion pipeline, chain resolution, categorization, recurring detection,
  forecasting, dev mode, desktop shell, data model
- `.docs/features/00-index.md` + `<feature>/` per Module — each containing
  `architecture.md`, `code.md`, `specs.md`, `how-to-test.md`
  (template at `features/_template/`)
- `.docs/cicd/00-index.md` + `overview.md`, `branch-protection.md`,
  `release-workflow.md` — captures the CI/CD decisions from sections B above
- `.docs/local_development/00-index.md` + `setup.md`, `database.md`,
  `troubleshooting.md`, `dev-mode.md` — drawn from CLAUDE.md and Phase 15
  bootstrap learnings
- `.docs/runbooks/00-index.md` + `release-cut.md`, `notarization-fail.md`,
  `force-password-reset.md` — operational runbooks
- `.docs/legal/00-index.md` + `license-rationale.md` (why Hippocratic-3.0,
  why source-available) + `data-retention.md`

### F. `.planning/` purge from git history (NEW — item #2)

**DESTRUCTIVE OPERATION.** The current repo has never been pushed to a
public remote (229 commits ahead of origin/main, origin is private), so
this purge can run BEFORE the first public push — meaning no force-push
to a public history is required.

Sequence:
1. Identify "leftovers" — items worth preserving for v1.1+. Move them to:
   - `.docs/adr/` for graduated architectural decisions
   - `.docs/architecture/` for graduated technical patterns
   - GitHub Issues under a new `v1.1` milestone for deferred work items
2. `git filter-repo --path .planning --invert-paths` to remove `.planning/`
   from every commit in history.
3. Add `.planning/` to `.gitignore` so future planning work stays local.
4. First public push is fresh history (no force needed).
5. Working tree retains `.planning/` for ongoing local GSD work.

### G. v1.1 milestone (NEW — item #2 tail)

A fresh `v1.1` milestone holds the deferred items. Lives as:
- A GitHub Milestone in the public repo with issues attached
- A short `.docs/roadmap-v1.1.md` summarizing the milestone goal
- The `.planning/` v1.1 work that follows happens entirely locally
  (`.planning/` is now gitignored)

### H. Skill rename (NEW — item #3)

- `~/.claude/skills/sketch-findings-diederik/` → `sketch-findings-beatrax/`
  (the skill that's already locally installed; not a repo-level concern
  since skills live under the user's home dir)
- Project skills in `./.claude/skills/sketch-findings-diederik/` →
  `./.claude/skills/sketch-findings-beatrax/`
- CLAUDE.md "Project Skills" section updated to reference the new name

### I. Counterparty-profile feature (NEW — item #7)

A new user-facing feature: a page per counterparty (entity that appears in
transactions) showing all transactions, totals, trends, recurring detection,
category breakdown.

Scope:
- `Modules/Counterparties/` (new bounded module) — Public services +
  Internal resolver + Eloquent models for an `entities` / `counterparties`
  table
- A `Counterparty` aggregate keyed by a canonical identity (resolved across
  PayPal merchant strings + ASN IBAN + ASN counterparty name + ICS PDF
  merchant strings). Identity resolution reuses Phase 16.1's
  `MerchantNameResolver` + `PatternGeneralizer` + `MerchantAlias` corpus.
- `/counterparties` index page (search + sort by total spend / recency)
- `/counterparties/{slug}` profile page:
  - Total spend (all-time + last 12 months) with multi-currency display
  - Transaction list (paginated, filterable by account + date)
  - Category breakdown (pie / bar)
  - Recurring detection hits (links to Recurring module surfaces)
  - Funding-chain visualization (links to Chains module — "this Amazon
    charge was funded by ASN → ICS → Amazon")
  - "Add alias for this counterparty" button (reuses Phase 16.1's
    `RenameCounterpartyPopover`)
- Sidebar nav entry under the main app shell
- Transaction-row click-through: clicking a counterparty name on any
  transaction row navigates to its profile page
- Cross-module surface: `Modules/Counterparties/Public/Contracts/CounterpartyResolver`
  is the new Public contract; `Modules/Ledger`, `Modules/Recurring`,
  `Modules/Chains` consume it via DI

### J. GitHub security walkthrough (NEW — item #8)

**Conversational task during phase execution**, not a code deliverable.
The deliverable IS a captured config in `.docs/cicd/branch-protection.md`
+ `.docs/runbooks/repo-security-setup.md` documenting what was configured,
so future maintainers can reproduce.

Topics to walk through interactively (proposed agenda — order TBD with user):
1. Branch protection on `main` (require PR, require reviews, require CI
   pass, require signed commits, restrict force-push, restrict deletion)
2. Required status checks (the `quality (PHP 8.4)` + `quality (PHP 8.5)`
   jobs from ci.yml)
3. Default branch + auto-delete merged branches
4. Secret scanning + push protection (free for public repos)
5. Dependabot version updates + Dependabot security updates
6. CodeQL default setup (PHP support)
7. `signing-prod` Environment + required reviewers + deployment branches
   restricted to `main`
8. CODEOWNERS configured (already covered in section B but verify in UI)
9. Vulnerability-reporting policy: enable private vulnerability reporting
   (Security tab → "Private vulnerability reporting"); cross-link from
   SECURITY.md
10. GitHub Discussions (Q&A + Show & Tell categories)
11. Issue templates + PR template
12. Repo metadata: description, topics, social-preview image, website link
13. Releases visibility (auto-archive old releases? Set "latest" pin
    behavior?)
14. Repo activity defaults: wiki off, projects off (use Issues + Milestones)

Phase 17 does NOT ship:

- **A formal UAT close-out of the 25 v1.0 deferred scenarios.** User
  explicitly dropped this. Those scenarios stay as paper-cuts; if any
  reappears during the v1.0.0 ship, they get fixed reactively.
- **An invite-only beta cycle before public release.** User explicitly
  dropped this. The first non-developer user is the first member of the
  public via GitHub.
- **Counterparty-profile polish features** (CSV export, monthly digest
  email, comparison view, etc.) — deferred to v1.1.
- **OS Keychain shell-out for OAuth secrets** — out of scope per
  REQUIREMENTS.md (AUTH-21, v2.1 candidate).
- **Sentry crash reporting** — out of scope per PROJECT.md (TELE-01).

</domain>

<decisions>
## Implementation Decisions

### Versioning Policy (item #1)

- **D-01: Tag patterns.** `v*.*.*` triggers the stable channel.
  `v*-rc.*` triggers the preview channel. No alpha tier.
- **D-02: Asymmetric publish mode.** Stable tags create a DRAFT GitHub
  Release (human eyeballs first); RC tags publish immediately to preview
  channel.
- **D-03: Tag is source of truth for version.** `config/nativephp.php`
  default = `'0.0.0-dev'`. `release.yml` strips the leading `v` from the
  pushed tag and exports `NATIVEPHP_APP_VERSION=<version>` before
  `native:build`.
- **D-04: Two electron-updater channels from day 1** — `stable` + `preview`.
- **D-16: v0.x is the entire pre-public series.** Every tag from now until
  the explicit "ship v1.0.0" graduation moment is `v0.x.x`. First tag is
  `v0.1.0` (or `v0.1.0-rc.1` if the user wants to verify the release
  pipeline first). Subsequent dev-build tags increment minor or patch as
  appropriate (`v0.1.1`, `v0.2.0`).
- **D-17: All existing git tags get deleted before the first release-pipeline
  run.** They point to internal dev milestones, not ship-quality builds,
  and would confuse the auto-update channel ordering. Done as a one-shot
  task at the start of the phase: `git tag | xargs git tag -d` (local
  only; nothing has been pushed to a public remote yet).
- **D-18: `v1.0.0` is the explicit graduation tag.** The user pulls the
  trigger by name; no automation graduates from `v0.x` to `v1.0`. The
  graduation is the last task of this phase, done only after every other
  closeout item is green.

### CI/CD Pipeline (Phase 17 original)

(Decisions D-05..D-15 retained verbatim from the original Phase 17 discussion.)

- **D-05: Env-var gate on the existing ad-hoc hook.** Early-return when
  `NATIVEPHP_USE_DEVELOPER_ID=1`. Local + dev builds remain ad-hoc-signed.
- **D-06: macOS signing uses a symmetric prebuild hook
  (`nativephp_inject_developer_id.php`).** Reads `MAC_SIGNING_IDENTITY` env
  and injects `mac.identity` explicitly. **NEVER** rely on electron-builder
  keychain auto-discovery.
- **D-07: Windows signing uses a symmetric prebuild hook
  (`nativephp_inject_windows_signing.php`).** Reads `AZURE_*` env vars and
  patches `win.signtoolOptions`.
- **D-08: Hook composition rules.** Three signing-related prebuild hooks;
  each env-var-gated; each gets a Pest unit test mirroring
  `ForceAdhocSigningScriptTest`.
- **D-09: Single macOS job with `notarytool submit --wait`.** Simplest
  topology; matches personal-velocity release cadence.
- **D-10: 45-min notarytool timeout, 60-min job timeout, fail-loud, no
  auto-retry.**
- **D-11: Three parallel matrix jobs, all must succeed before publish.**
- **D-12: PR gate (ci.yml) is reused as a job in release.yml.** A tag
  pushed to a broken main fails fast (~5 min) before signing jobs start.
- **D-13: Smoke test depth — HTTP `/health` probe.**
- **D-14: New `/health` route in `Modules/Core`** — auth-free, returns
  `{status, app_version, php_version, sqlite_version}` JSON.
- **D-15: Smoke failure fails the whole release.**

### Auto-Update Plumbing (absorbed Phase 18)

- **D-19: `ElectronUpdateChannel` lives in `Modules\Core\Public\Services\`.**
  It's a small adapter over `electron-updater` exposing channel selection
  (stable / preview) + the events the Livewire banner subscribes to.
  Single class; no new module.
- **D-20: Ed25519 publisher key generated once + committed public half +
  encrypted private half in `signing-prod` GitHub Environment.** Manifest
  signing happens in `release.yml` after notarization but before publish.
  Verification on every download in-bundle — no unsigned auto-update
  code path anywhere.
- **D-21: "Skip this version" persists in user_preferences table (per-user
  row).** Survives app restarts; not synced across devices (single-machine
  v1 anyway).
- **D-22: 30-day stale-version threshold = hardcoded in
  `ElectronUpdateChannel`** (no per-user config). Banner copy: "You're on
  version X — version Y has been available for 30 days. Update now."

### Public Release Boundary (absorbed Phase 19)

- **D-23: Hippocratic License 3.0 verbatim from firstdonoharm.dev.** Plus
  `NOTICE.md` explaining source-available / not-OSI-approved trade-off.
  README clearly says "source-available", never "open source".
- **D-24: Community docs at repo root**: `SECURITY.md` + `CONTRIBUTING.md`
  + `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1 verbatim).
- **D-25: README hero uses `resources/brand/logo.svg`** + the four
  required sections (What / Who / Install / Screenshots).
- **D-26: Brand exports generated at build time, not committed**, EXCEPT
  the canonical SVG + the `.icns` / `.ico` / `logo-512.png` / favicon
  bundles that installer + browser need. The 4 exports ARE committed
  (under `resources/brand/`) because installer builds need them
  deterministically.
- **D-27: GSD redaction is a fail-loud arch test**
  (`BoundaryArchTest::noGsdLeakage`) covering: runtime PHP code, Blade
  views, route names, view-data keys, error messages, log lines, and
  comments. Test pattern matches `.planning/`, `PLAN.md`, `RESEARCH.md`,
  `\bD-\d{2,3}\b`, `gsd[-_]`, and the GSD phase codename prefix patterns.
- **D-28: Deep modules review produces REVIEW-DEEP.md actioned in the
  same phase.** Each module gets one section: boundary hygiene + DI
  compliance + dead code + perf smells. Composer dep analyzer
  (`composer-require-checker`) runs as part of the review.
- **D-29: Renderer-JSON audit adds an arch invariant** that walks every
  Livewire component's public properties + `$listeners` + `$queryString`
  and fails if any references a secrets-tagged column or a hidden-column
  attribute. Secrets-tagged columns are enumerated from a single registry
  in `Modules/Core` (`SecretsColumnRegistry`).
- **D-30: "Where is my data?" docs**: in-app at `/help/data-locations`
  (Livewire page in `Modules/Core`) + on the README. Export-everything
  ZIP is a Dev-Mode-promoted feature (lives behind Dev Mode UI per
  Phase 16) but the link is also reachable from `/help/data-locations`
  via a button gated on Dev Mode being on. Non-developer users see a
  "How to export your data" instructional section in-page.

### `.docs/` Folder (item #4)

- **D-31: Structure follows happklaar/happklaar verbatim.** Top-level
  `00-index.md` with navigation table; subdirs `adr/`, `architecture/`,
  `features/`, `cicd/`, `local_development/`, `runbooks/`, `legal/`,
  `api/`. Each subdir has its own `00-index.md`. New feature/module
  follows `features/_template/`.
- **D-32: Writing rule from happklaar applies verbatim**: "`.planning/`
  is gitignored, local-only. If a discovery from `.planning/` graduates
  into a long-lived reference, promote it into the right subtree here
  (usually `architecture/` or an ADR)."
- **D-33: ADRs are numbered + named** (`0001-modular-architecture.md`,
  `0002-di-only-rule.md`, etc.). Initial ADR set derived from PROJECT.md
  "Key Decisions" table + the load-bearing decisions accumulated across
  v0.x phases. Target: ~20-30 ADRs at v1.0.0 ship.
- **D-34: features/ has one subdir per Module.** Mirror the existing
  module list: `auth/`, `chains/`, `categorization/`, `community/`,
  `core/`, `desktop/`, `dev-mode/`, `drift-alerts/`, `email-scan/`,
  `forecasting/`, `import/`, `ingestion/`, `ledger/`, `onboarding/`,
  `receipts/`, `recurring/`, `counterparties/` (the new one in section I).
  Each has the four template files.

### `.planning/` Purge from Git History (item #2)

⚠️ **DESTRUCTIVE — REQUIRES EXPLICIT USER CONFIRMATION BEFORE EXECUTION.**

- **D-35: Use `git filter-repo`** (modern replacement for `git filter-branch`,
  recommended by git's own docs). Command:
  `git filter-repo --path .planning --invert-paths`. Run on a fresh clone
  to avoid touching the working repo's hooks/config.
- **D-36: Purge happens BEFORE the first public push.** Since origin has
  not received the 229 commits ahead, no force-push is required — the
  first push is the public history. This is the **safe path** that
  avoids history conflicts entirely. The user must NOT push to a public
  remote before the purge.
- **D-37: `.planning/` added to `.gitignore` after the purge.** Working
  tree retains the folder for ongoing local GSD work; future commits
  ignore it.
- **D-38: Pre-purge graduation pass.** Before the filter runs, walk
  `.planning/` and graduate worth-keeping artifacts to `.docs/` (per
  D-32). PROJECT.md "Key Decisions" → ADRs. Architectural patterns
  documented in CLAUDE.md tech-stack section → `.docs/architecture/`.
  Module conventions → `.docs/features/<module>/architecture.md`.
- **D-39: v1.1 leftovers move to GitHub Milestones + Issues.** A short
  `.docs/roadmap-v1.1.md` summarizes the milestone goal (one paragraph
  + a link to the GitHub Milestone view). Detail-level v1.1 planning
  stays in `.planning/` (now gitignored) and may eventually be promoted
  to `.docs/` if it graduates.

### Skill Rename (item #3)

- **D-40: Rename in two places.**
  1. User-level: `~/.claude/skills/sketch-findings-diederik/` →
     `sketch-findings-beatrax/` (the auto-loaded skill).
  2. Project-level: `./.claude/skills/sketch-findings-diederik/` →
     `./.claude/skills/sketch-findings-beatrax/` (if it exists at the
     project level; check first).
- **D-41: CLAUDE.md "Project Skills" section updated** to reference the
  new name.
- **D-42: Skill SKILL.md frontmatter `name:` field updated** to match.

### Counterparty-Profile Feature (item #7)

- **D-43: New bounded module `Modules/Counterparties/`** following the
  established Public / Internal / Routes / Resources split. Public
  contract: `CounterpartyResolver` (resolves a transaction → its
  canonical Counterparty) + Eloquent `Counterparty` model.
- **D-44: Counterparty identity reuses Phase 16.1's resolver chain.**
  `MerchantNameResolver` already does the heavy lifting (5-step
  precedence with community-tail seam). The new module's `CounterpartyResolver`
  is a thin upcast: same input (raw transaction) → same merchant name →
  hashed into a stable Counterparty ID (slug = kebab-case of the resolved
  display name, with collision suffixing).
- **D-45: Schema.** `counterparties` table: `id` (auto), `user_id` (BelongsToUser
  global scope), `slug` (unique per user), `display_name`, `created_at`,
  `updated_at`. Derivation: backfilled on every import via a new
  `ResolveCounterpartyStage` in the `ImportPipeline` (between
  `ApplyAutoCategoryStage` and the post-commit boundary). Transactions
  get a nullable `counterparty_id` column (FK with `cascadeOnDelete`
  from the user, NOT from the counterparty — orphaned counterparties
  are pruned by a `CounterpartyGarbageCollectorJob` on a daily schedule).
- **D-46: UI surfaces.**
  - `/counterparties` — index page (Livewire/Volt component in
    `Modules/Counterparties/Resources/views/Pages/`); columns: name,
    total-12m, transaction count, last activity; search + sort.
  - `/counterparties/{slug}` — profile page; sections enumerated in
    section I of `<domain>` above.
  - Sidebar nav entry under main app shell (between Chains and
    Recurring).
  - Click-through from any transaction-row counterparty name → profile.
- **D-47: Cross-module consumers.** `Modules/Recurring`,
  `Modules/Chains`, `Modules/Categorization`, `Modules/Ledger` all gain
  read-only consumption of `CounterpartyResolver` via DI. No new arch
  invariants beyond the standard module boundary rule (counterparty
  models must not be reached from outside the module — consumers use
  the Public contract).
- **D-48: Performance budget**: profile page must render in ≤200ms for
  a counterparty with up to 10k transactions (eager-loaded paginated).
  Total spend + category breakdown computed via SQL aggregates, not
  PHP-side loops. Backed by indexes on `transactions.counterparty_id`
  + `transactions.user_id` (compound).

### GitHub Security Walkthrough (item #8)

- **D-49: Walkthrough is interactive during phase execution**, not a
  scripted artisan command. User + Claude session: open each setting
  in the GitHub UI, discuss, configure, capture the final state in
  `.docs/cicd/branch-protection.md` + `.docs/runbooks/repo-security-setup.md`.
- **D-50: Repo settings baseline (proposed, refinable in walkthrough)**:
  - Visibility: Public
  - Default branch: `main`
  - Require PR for changes to `main`, no direct push
  - Require 1 review (you reviewing your own work via a 2nd account is
    fine for a solo project, OR allow self-merge with required CI pass)
  - Require status checks: `quality (PHP 8.4)`, `quality (PHP 8.5)`,
    `gitleaks`
  - Require linear history (no merge commits, only squash + rebase)
  - Restrict force-push + restrict deletion of `main`
  - Auto-delete merged head branches
  - Secret scanning + push protection: ON
  - Dependabot version + security updates: ON (weekly schedule)
  - Code scanning (CodeQL default setup, PHP): ON
  - Private vulnerability reporting: ON (cross-linked from SECURITY.md)
  - Signed commits: required on `main`
  - Wiki: OFF (use `.docs/`)
  - Projects: OFF (use Issues + Milestones)
  - Discussions: ON, with Q&A + Show & Tell + Announcements categories
  - Topics: `personal-finance`, `laravel`, `php`, `desktop-app`,
    `nativephp`, `local-first`, `hippocratic-license`
  - Description: one-line tagline matching the README

### No Safety Net (acknowledged risk)

- **D-51: Public release proceeds with no formal UAT pass and no beta
  cohort.** User accepted the risk. First-run / Gatekeeper / SmartScreen
  / install-flow issues will land in the public GitHub Issues tab on
  day 1. Mitigations relied on instead: comprehensive smoke tests
  (D-13/D-15), Dev Mode + Doctor panel (Phase 16) for diagnostic
  collection, auto-update (D-19..D-22) for fast remediation.

### Claude's Discretion

- Exact `signing-prod` secret names + format (working list in original
  Phase 17 context; planner refines against action docs).
- CI-06 sentinel file paths + names (under `UserDataPathService`).
- `.env.bundled` template content (env-var names with placeholder values).
- CODEOWNERS file location + content (standard `.github/CODEOWNERS`).
- Windows + Linux smoke-test shell choice + port discovery mechanism.
- PR-gate matrix `fail-fast` policy (recommend `fail-fast: false`).
- Final `.docs/00-index.md` navigation table layout + tone.
- Initial ADR set + exact ordering. Suggested first ten: modular
  architecture, DI-only rule, Hippocratic-3.0 license, local-only
  hosting, SQLite + WAL, NativePHP desktop shell, database queue
  driver (no Redis in shipped bundle), multi-user activation with
  BelongsToUser, brick/money for multi-currency, recovery-codes
  password reset (no SMTP).
- `.docs/features/_template/` content (mirror happklaar's template).
- Counterparty slug collision strategy (suggest: `-2`, `-3` suffix on
  duplicate canonical-name resolves).
- Counterparty merge UI (when two slugs turn out to be the same
  real-world entity) — likely a Dev Mode action, not user-facing.
- Daily `CounterpartyGarbageCollectorJob` schedule and exact orphan
  definition (suggest: counterparty with zero transactions in the
  last 365 days AND zero alias entries).
- Order of phase execution: high-level suggestion below in
  `<specifics>`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements

- `.planning/ROADMAP.md` § "Phase 17" (will be heavily edited during
  this phase to reflect the reshape; treat the current version as the
  pre-reshape baseline).
- `.planning/REQUIREMENTS.md` — CI-01..CI-06 (original Phase 17),
  UPDATE-01..UPDATE-04 (absorbed Phase 18), REL-01..REL-08 (absorbed
  Phase 19). Phase 20 (UAT-01..UAT-03) and Phase 21 (BETA-01..BETA-04)
  are **deleted** — do not implement.
- `.planning/STATE.md` § "Blockers/Concerns" — three Phase 17 items:
  PHP 8.4-vs-8.5 spike (closed); Windows signing pricing (closed —
  Azure Trusted Signing $10/mo); macOS notarization timing (mitigated
  by D-09 + D-10).

### Project conventions & milestone context

- `.planning/PROJECT.md` — v0.x → v1.0.0 milestone goal, supplied logo
  asset, Hippocratic-3.0 posture, local-only constraint, DI-only rule,
  modular boundary rule.
- `CLAUDE.md` — DI-only rule (constructor injection, no facades /
  global helpers); module Public/Internal split; cross-module access
  only via Public service classes or events; Larastan L10 strict +
  Pint + Pest gate.

### Prior-phase context this phase depends on

- `.planning/phases/15-desktop-shell-nativephp-integration/15-CONTEXT.md`
  — first-launch bootstrap (D-21..D-23); brand asset location at
  `resources/brand/logo.svg` (D-20); ad-hoc signing background.
- `.planning/phases/16.1-…/16.1-CONTEXT.md` — `MerchantNameResolver` +
  `MerchantAlias` corpus + `PatternGeneralizer` (counterparty
  resolution reuses these per D-44).
- `.planning/phases/14-queue-rewire-horizon-carve-out/14-CONTEXT.md`
  — shipped bundle uses `QUEUE_CONNECTION=database`; the
  `CounterpartyGarbageCollectorJob` schedules on the database queue.
- `.planning/phases/12-multi-user-activation/12-CONTEXT.md` — Fortify
  auth + `BelongsToUser` global scope (counterparty schema follows).

### External reference

- `https://github.com/happklaar/happklaar` — `.docs/` folder structure
  to mirror. Specifically: `00-index.md` layout, `adr/` numbering
  convention, `features/_template/` shape, the writing-rule about
  `.planning/` being gitignored and graduation flow.
- `https://firstdonoharm.dev` — Hippocratic License 3.0 verbatim source.
- `https://www.contributor-covenant.org/version/2/1/code_of_conduct/`
  — Contributor Covenant 2.1 verbatim source.
- `https://docs.github.com/en/code-security` — GitHub Security feature
  docs (consulted during the section J walkthrough).
- `https://github.com/newren/git-filter-repo` — `git filter-repo` for
  the `.planning/` history purge (D-35).

### Existing CI + signing assets (in-repo)

- `.github/workflows/ci.yml` — current PR-gate skeleton; widens to
  `['8.4', '8.5']` matrix. All other shape stays as-is.
- `scripts/nativephp_force_adhoc_signing.php` — gets the env-var
  early-return (D-05). Head comment documents the partial-signing
  failure mode that justifies the no-auto-discovery rule (D-06).
- `build/entitlements.mac.plist` — already configured; Phase 17
  references it in the macOS signing step but does not modify it.
- `Modules/Desktop/tests/Unit/ForceAdhocSigningScriptTest.php` —
  Pest test pattern for the two new hook tests.
- `config/nativephp.php` — `'version'` default flips to `'0.0.0-dev'`
  (D-03); two new prebuild hooks registered.

No external ADRs — every architectural decision lives in `.planning/`
(to be promoted to `.docs/adr/` during this phase per D-38).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- `scripts/nativephp_force_adhoc_signing.php` — prebuild-hook pattern
  for the two new sibling hooks (D-06, D-07).
- `.github/workflows/ci.yml` — skeleton; cache key already parameterized
  on `${{ matrix.php }}`.
- `Modules/Core/Public/Services/SystemAlertQuery.php` + `SystemAlertsBanner`
  — reused for auto-update notifications (D-22 stale-version banner).
- `Modules/Core/Public/Services/UserDataPathService.php` — CI-06 sentinel
  files land at `UserDataPathService`-resolved locations.
- `Modules/Onboarding/.../MerchantNameResolver.php` (from Phase 16.1) +
  `PatternGeneralizer` + `MerchantAlias` Eloquent model — counterparty
  identity resolution (D-44) is built on these.
- `Modules/Import/Public/Pipeline/ImportPipeline.php` (from Phase 16.1) —
  new `ResolveCounterpartyStage` slots in here (D-45).
- `Modules/Categorization/.../categorization_rules` table (from Phase
  16.1.2.1, in flight on parallel session) — category breakdown on
  counterparty profile pages (D-46) reads from already-categorized
  transactions.
- `Modules/Core/Public/Events/UserInstalled` — counterparty seeder for
  per-user defaults (if any; none planned at v1.0.0) would hook here.
- `Modules/Recurring`, `Modules/Chains` — both gain DI consumption of
  `CounterpartyResolver`; "linked from profile page" surfaces require
  these modules to expose Public read queries by counterparty ID.

### Established Patterns

- DI-only: constructor injection everywhere; no facades / global helpers
  in module code.
- Module Public/Internal split with cross-module access only via Public
  service classes or events. The new `Modules/Counterparties/` follows
  this; `Modules/Counterparties/Public/Contracts/CounterpartyResolver`
  is the only entry point consumers see.
- Every new boundary gets a Pest arch invariant. Phase 17 adds:
  `noGsdLeakage` (D-27), `noSecretsInLivewireSnapshot` (D-29), and the
  standard `Counterparties` boundary tests.
- Tests live in `Modules/<Name>/tests/Unit/` or `tests/Feature/`.
- Pint preset is Laravel default; Larastan L10 strict + canvural strict
  + larastan-livewire from day 1.
- Migrations live inside the owning module's `Database/Migrations/`.
- `BelongsToUser` global scope on every user-scoped model — the new
  `Counterparty` model uses it.

### Integration Points

- **`.github/workflows/ci.yml`** — extends in place; matrix axis change.
- **`.github/workflows/release.yml`** — NEW. Tag-triggered, three
  parallel platform jobs, publish gate.
- **`.github/CODEOWNERS`** — NEW.
- **`scripts/nativephp_inject_developer_id.php` + `nativephp_inject_windows_signing.php`**
  — NEW prebuild hooks; registered in `config/nativephp.php` `prebuild`.
- **`config/nativephp.php`** — version default + prebuild registrations.
- **`Modules/Core/Routes/web.php` + new `HealthController`** — `/health`
  route (D-14).
- **`Modules/Core/Public/Services/ElectronUpdateChannel`** — NEW single
  class wrapping `electron-updater` (D-19).
- **`Modules/Core/Public/Services/SecretsColumnRegistry`** — NEW single
  registry consumed by the renderer-JSON audit arch invariant (D-29).
- **`Modules/Counterparties/`** — NEW module per D-43..D-48.
- **`.docs/` tree** — NEW; per D-31..D-34. Note: this tree is committed
  (visible in public repo), unlike `.planning/`.
- **`.gitignore`** — adds `.planning/` after the purge (D-37).
- **`LICENSE`, `NOTICE.md`, `SECURITY.md`, `CONTRIBUTING.md`,
  `CODE_OF_CONDUCT.md`, `README.md`** — repo-root community docs.
- **Skill rename**: `./.claude/skills/sketch-findings-diederik/` →
  `./.claude/skills/sketch-findings-beatrax/` (project-level if it exists)
  + user-level (D-40).

### Cross-session collision risk

- `.planning/STATE.md`, `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md`
  are heavily-edited shared files. A parallel session is currently
  running Phase 16.1.2.1 and will touch all three. Phase 17's planner
  must coordinate timing — likely wait until the parallel session
  completes before doing the roadmap-restructure + requirements-shuffle
  + state updates. Alternatively, the planner uses a workstream/worktree
  to isolate Phase 17 edits.

</code_context>

<specifics>
## Specific Ideas

### Verbatim copies

- **Tag commands**: `git tag v0.1.0 && git push --tags` (stable);
  `git tag v0.1.0-rc.1 && git push --tags` (preview).
- **Env-var name for the ad-hoc-hook switch**: `NATIVEPHP_USE_DEVELOPER_ID=1`;
  check `getenv(...) === '1'` (explicit opt-in).
- **`mac.identity` format**: `"Developer ID Application: <legal name> (<team-id>)"`.
- **`notarytool` invocation**: `xcrun notarytool submit <path-to-dmg>
  --apple-id <issuer> --key <key> --key-id <key-id> --wait --timeout 45m`.
- **`/health` response shape**:
  ```json
  {
    "status": "ok",
    "app_version": "0.1.0",
    "php_version": "8.4.7",
    "sqlite_version": "3.45.1"
  }
  ```
- **`git filter-repo` command**: `git filter-repo --path .planning --invert-paths`
  (run on a fresh clone).
- **Phase 17 commit/branch prefix**: `feat(17-closeout): …`,
  `chore(17-closeout): …`, etc. — replace original Phase 17's
  `chore(17-cicd): …` once the rename takes effect.

### Proposed execution order (high-level suggestion for planner)

Phase 17 is huge. A sensible plan grouping (the planner refines into
PLAN files):

1. **Plan 17-01: Versioning + tag cleanup** (D-16..D-18). Delete old
   tags; flip `config/nativephp.php` default to `0.0.0-dev`; update
   any version references; document `v0.x` policy in
   `.docs/cicd/release-cadence.md`. Sets the stage.
2. **Plan 17-02: PR-gate matrix widen** (CI-01). Trivial; ci.yml's
   `php` matrix `['8.4']` → `['8.4', '8.5']`; verify both axes green.
3. **Plan 17-03: Signing hooks** (D-05..D-08). Three Pest tests + the
   env-var switch + two new prebuild hooks.
4. **Plan 17-04: release.yml + smoke test** (CI-02..CI-06, D-09..D-15).
   Full release workflow; new `/health` route; per-platform smoke;
   gitleaks; CODEOWNERS; `.env.bundled`; APP_KEY sentinel.
5. **Plan 17-05: Auto-update plumbing** (UPDATE-01..UPDATE-04,
   D-19..D-22). `ElectronUpdateChannel`; Ed25519 manifest signing in
   release.yml + verification in-bundle; banner UX.
6. **Plan 17-06: Counterparty module** (D-43..D-48). Module skeleton +
   schema migration + resolver + `ResolveCounterpartyStage` + index
   page + profile page + cross-module wiring.
7. **Plan 17-07: Community docs at repo root** (D-23..D-25). LICENSE,
   NOTICE.md, SECURITY.md, CONTRIBUTING.md, CODE_OF_CONDUCT.md,
   README rewrite, brand exports.
8. **Plan 17-08: `.docs/` folder skeleton + initial ADRs** (D-31..D-34,
   D-38 prep). Tree structure + index files + ~10 graduated ADRs from
   PROJECT.md Key Decisions.
9. **Plan 17-09: `.docs/` feature pages** (D-34). One subdir per module
   with `architecture.md` / `code.md` / `specs.md` / `how-to-test.md`.
10. **Plan 17-10: GSD redaction sweep + arch invariant** (REL-05, D-27).
    Find + purge GSD references from runtime; add `noGsdLeakage` arch
    test.
11. **Plan 17-11: Renderer-JSON audit + secrets registry** (REL-07,
    D-29). Add `SecretsColumnRegistry`; add arch invariant; fix any
    findings.
12. **Plan 17-12: Deep modules review** (REL-06, D-28). REVIEW-DEEP.md;
    composer-require-checker; cross-module hygiene fixes.
13. **Plan 17-13: "Where is my data?" page + export-everything**
    (REL-08, D-30).
14. **Plan 17-14: Skill rename** (D-40..D-42). Sketch-findings skill
    rename, CLAUDE.md update.
15. **Plan 17-15: GitHub repo settings walkthrough** (D-49..D-50).
    Interactive session; capture in `.docs/cicd/branch-protection.md`
    + `.docs/runbooks/repo-security-setup.md`.
16. **Plan 17-16: `.planning/` graduation pass** (D-38). Walk
    `.planning/` and move worth-keeping artifacts to `.docs/`. PRE-PURGE.
17. **Plan 17-17: `.planning/` history purge** (D-35..D-37). DESTRUCTIVE.
    Requires explicit user confirmation in-session. Run on a fresh
    clone. Then add `.planning/` to `.gitignore`.
18. **Plan 17-18: v1.1 milestone setup** (D-39). GitHub Milestone +
    issues + `.docs/roadmap-v1.1.md`.
19. **Plan 17-19: First public release (`v0.1.0` or `v0.1.0-rc.1`)**.
    Trigger release.yml end-to-end. Verify smoke + notarization +
    publish. Validate auto-update channel reads correctly.
20. **Plan 17-20: v1.0.0 graduation** (D-18). The explicit ship moment.
    User-triggered; not automated. Final tag, final draft → published.

### Performance / scope notes

- Wall-clock estimate: this phase is ~3-5x the size of Phase 16.1.
  Realistic completion target: 2-4 weeks of focused work.
- The `.planning/` purge (Plan 17-17) must be the LAST git-history
  modification before the first public push. If anything else needs
  to land after, it lands as normal commits on the post-purge history.
- The v1.0.0 graduation (Plan 17-20) is gated on every other plan
  being green + the user's explicit "ship it" call.

</specifics>

<deferred>
## Deferred Ideas (now or in this phase but later in v1.1+)

- **Counterparty-profile polish**: CSV export per profile, monthly
  digest email, comparison view (compare two counterparties side by
  side), counterparty merge UI for end users — v1.1.
- **Counterparty merging UI** (when two slugs turn out to be the same
  real-world entity) — likely Dev Mode action in v1.0; user-facing
  merge in v1.1.
- **OS Keychain shell-out for OAuth secrets** — out of scope per
  REQUIREMENTS.md AUTH-21 (v2.1 candidate).
- **SMTP password reset** — out of scope per REQUIREMENTS.md AUTH-22
  (v2.1 candidate, requires Gmail OAuth re-use).
- **Sentry crash reporting** — out of scope per PROJECT.md (TELE-01).
- **Anonymous telemetry** — out of scope (TELE-02; explicit refusal
  per PROJECT.md).
- **Laravel Pulse** — out of scope (TELE-03; requires Redis cache
  reconfig).
- **Two-job notarization split** — rejected per D-09 (single-job
  with `--wait`).
- **Headless windowed-app smoke test (Playwright)** — rejected;
  HTTP `/health` probe is the chosen smoke depth.
- **PayPal Reporting API** (ING-09) — out of scope (deferred with
  trigger: PayPal Business upgrade).
- **Cross-device sync** — out of scope (privacy-first, unchanged
  from v1.0).

## v1.1 Milestone Seeds (item #2 leftovers, captured for the new milestone)

These move to GitHub Issues under the `v1.1` milestone during Plan
17-18. The list is provisional — refine during the graduation pass
(Plan 17-16):

- Counterparty profile polish (above)
- OS Keychain integration (AUTH-21)
- SMTP password reset (AUTH-22)
- WebAuthn / passkeys (AUTH-23)
- Per-user-data partner-sharing modes (SHARE-01)
- Sentry crash reporting decision + UX (TELE-01)
- Anonymous-telemetry decision (TELE-02; likely a hard "no" but
  document the call)
- Laravel Pulse evaluation (TELE-03)
- Any unresolved post-public-release issues from day-1 GitHub Issues

None of the above are in Phase 17's scope. Capture as v1.1 milestone
items; nothing more.

</deferred>

---

*Phase: 17-v1.0.0 Public Release Closeout*
*Context gathered: 2026-05-27 (reshape from "CI/CD Pipeline + Code Signing")*
