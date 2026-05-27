# Phase 17: v1.0.0 Public Release Closeout — Research

**Researched:** 2026-05-27
**Domain:** CI/CD automation (GitHub Actions) + release engineering (multi-platform installer builds with unsigned/ad-hoc-signed artifacts) + Ed25519 manifest-signed auto-update + public-release hygiene (license + docs + history purge) + new Counterparties bounded module
**Confidence:** HIGH for CI/auto-update/history-purge tooling; MEDIUM for first-launch APP_KEY sentinel pattern (Laravel ecosystem hasn't standardised it — defer to Claude's discretion); HIGH for project-internal patterns (existing ad-hoc signing hook, Modules/* structure, ImportPipeline, BoundaryArchTest conventions)

---

## Summary

Phase 17 reshapes from "CI/CD + paid code signing" into the **v1.0.0 public-release closeout** that absorbs Phases 18/19/20/21 and adds a new Counterparties feature module + a `.docs/` folder restructure + a destructive `.planning/` git-history purge. The 2026-05-27 evening amendments **drop all paid signing** (Apple Developer ID $99/yr + Azure Trusted Signing $10/mo both off the table); macOS ships ad-hoc signed via the existing `nativephp_force_adhoc_signing.php` hook (zero new code in the signing path), Windows ships unsigned, Linux ships unsigned `.AppImage` + `.deb`. The user accepts that first-launch users hit Gatekeeper/SmartScreen warnings and documents the bypass in the README install section.

This shift makes the **Ed25519 manifest signing + SHA-512 binary verification load-bearing** (UPDATE-02, A-06): with no OS-level trust signal, the auto-update path is the sole binary-integrity guarantee. `electron-updater` ships SHA-512 in `latest.yml` natively; the missing piece — Ed25519 manifest signing — is a custom hook around `electron-updater`'s `verifyUpdateCodeSignature` extension point. Doyensec's open-source ElectronSafeUpdater is the closest reference implementation; this project will write a thin Laravel-side adapter, not adopt the framework wholesale.

The CI workflow extension is mechanically simple — widen the existing `php: ['8.4']` matrix to `['8.4', '8.5']` and add a tag-triggered `release.yml` with three parallel platform jobs (macOS-14 + Windows-2025 + Ubuntu-24.04). The non-trivial work is everywhere else: the new `Modules/Counterparties/` module with five type-aware profile pages, the `.docs/` tree mirroring happklaar's structure, the GSD-leakage arch invariant, the renderer-JSON audit, the deep modules code review, the destructive `.planning/` history purge, and the interactive GitHub repo-settings walkthrough. Wall-clock estimate per CONTEXT.md: **2-4 weeks of focused work**, 3-5× Phase 16.1's size.

**Primary recommendation:**

1. Keep all GitHub Action references SHA-pinned (per Q1 2026 supply-chain attacks on `tj-actions/changed-files`, Trivy, Nx — 23,000+ repos compromised through mutable tags).
2. Structure workflows so `release.yml` triggers **only** on `push: tags: [v*]` (never `pull_request_target`) — fork PRs can never reach signing/release context.
3. Make Ed25519 manifest signing the single integrity guarantee for auto-update — write a Pest test that flips one byte in `latest.yml` and proves the verifier rejects.
4. Run the `.planning/` purge **before** the first push to public origin (the repo is private now; flipping to public happens AFTER the rewritten history is force-pushed to private origin, eliminating the public-force-push problem entirely).
5. Use `act` locally for workflow iteration; `nektos/act` catches ~90% of YAML errors before burning CI minutes.

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Amendment overrides (2026-05-27 evening) — these win over conflicting earlier D-NNs:**

- **A-01 (overrides D-05, D-06, D-07, D-08): No paid signing certs.** `NATIVEPHP_USE_DEVELOPER_ID` env-var switch + the two new prebuild hooks (`nativephp_inject_developer_id.php`, `nativephp_inject_windows_signing.php`) are NOT built. Existing `scripts/nativephp_force_adhoc_signing.php` stays as the sole signing hook — always-on. Windows builds are unsigned. No Pest tests for the unbuilt hooks.
- **A-02 (overrides D-09, D-10): No notarization.** No notarytool step in `release.yml`. No 45-min timeout to budget. macOS job is: checkout → setup PHP → composer install → ad-hoc-sign (existing hook) → smoke test → upload artifact. ~10-15 min per platform job.
- **A-03 (overrides D-50's `signing-prod` environment): No `signing-prod` GitHub Environment.** No signing secrets to gate.
- **A-04 (amends D-43..D-48): Counterparty scope = everything that touches your money** — five-type taxonomy (`merchant` / `personal` / `bank` / `government` / `self_account` / `unknown` fallback). D-48's performance budget removed; planner picks.
- **A-05 (new): README install-bypass UX is a first-class deliverable.** macOS / Windows / Linux Gatekeeper/SmartScreen bypass walkthroughs in README install section.
- **A-06 (new): Auto-update Ed25519 + SHA-512 verification is the SOLE binary-integrity signal.** `electron-updater` configured with `disableDifferentialDownload: true` on macOS.
- **A-07 (new): Repo `nightworksio/beatrax` already exists (private)** — origin remote is set. `.planning/` history purge happens against local + force-pushes to private origin. Repo stays private until Plan 17-19 flips visibility.
- **A-08 (new): Counterparty feature DEPENDS on Phase 16.1.2.1's `known_counterparty_ibans` table.** Plan 17-06 starts only after the parallel session lands.

**Retained from original Phase 17 discussion (still in force):**

- **D-01..D-04: Versioning.** Stable + RC channels (`v*.*.*` + `v*-rc.*`). Stable = DRAFT release; RC = immediate publish. Tag is source of truth; `config/nativephp.php` default = `'0.0.0-dev'`. Two electron-updater channels from day 1: `stable` + `preview`.
- **D-11: Three parallel matrix jobs, all must succeed before publish.**
- **D-12: PR gate (ci.yml) is reused as a job in release.yml** — fail-fast on a broken main.
- **D-13: Smoke test depth — HTTP `/health` probe.**
- **D-14: New `/health` route in `Modules/Core`** — auth-free, returns `{status, app_version, php_version, sqlite_version}` JSON. NO timestamp (breaks deterministic assertions).
- **D-15: Smoke failure fails the whole release.**
- **D-16..D-18: Versioning policy.** v0.x covers entire pre-public series; first tag is `v0.1.0` (or `v0.1.0-rc.1`); existing tags deleted; `v1.0.0` is explicit graduation tag user pulls by name.
- **D-19..D-22: Auto-update.** `ElectronUpdateChannel` in `Modules\Core\Public\Services\`; Ed25519 signed manifest; "Skip this version" persists per-user; 30-day stale threshold hardcoded.
- **D-23..D-30: Public release boundary.** Hippocratic 3.0 + community docs + README rewrite + brand exports + GSD redaction arch test + deep modules review + renderer-JSON audit + "Where is my data?" page.
- **D-31..D-34: `.docs/` structure mirrors happklaar/happklaar** — `00-index.md` files, `adr/` numbering, `features/_template/`.
- **D-35..D-39: `.planning/` purge.** `git filter-repo --path .planning --invert-paths` on fresh clone; happens BEFORE first public push; `.planning/` added to `.gitignore` after.
- **D-40..D-42: Skill rename** diederik → beatrax.
- **D-43..D-48: Counterparty module** (amended by A-04 above).
- **D-49..D-51: GitHub security walkthrough + risk acknowledgment** for no-UAT/no-beta release.

### Claude's Discretion

- Exact secret names + format (now mostly N/A since signing-prod is dropped — only the **Ed25519 publisher private key** secret remains; planner picks the secret name).
- CI-06 sentinel file paths + names (under `UserDataPathService`).
- `.env.bundled` template content (env-var names with placeholder values).
- CODEOWNERS file location + content (recommend `.github/CODEOWNERS`).
- Windows + Linux smoke-test shell choice + port discovery mechanism.
- PR-gate matrix `fail-fast` policy (recommend `fail-fast: false` — already set in `ci.yml`).
- Final `.docs/00-index.md` navigation table layout + tone.
- Initial ADR set + exact ordering. Suggested first ten: modular architecture, DI-only rule, Hippocratic-3.0 license, local-only hosting, SQLite + WAL, NativePHP desktop shell, database queue driver (no Redis in shipped bundle), multi-user activation with BelongsToUser, brick/money for multi-currency, recovery-codes password reset (no SMTP).
- `.docs/features/_template/` content (mirror happklaar's template).
- Counterparty slug collision strategy (suggest: `-2`, `-3` suffix on duplicate canonical-name resolves).
- Counterparty merge UI (Dev Mode action, not user-facing).
- Daily `CounterpartyGarbageCollectorJob` schedule and orphan definition.
- Order of phase execution (CONTEXT.md `<specifics>` proposes 17-01 through 17-20).

### Deferred Ideas (OUT OF SCOPE)

- Counterparty-profile polish (CSV export, monthly digest, comparison view) → v1.1.
- User-facing counterparty merge UI → v1.1 (Dev Mode action only in v1.0).
- Counterparty delete action → v1.1 (garbage-collected only in v1.0).
- OS Keychain shell-out for OAuth secrets → v2.1 (AUTH-21).
- SMTP password reset → v2.1 (AUTH-22, requires Gmail OAuth re-use).
- WebAuthn / passkeys → v2.1 (AUTH-23).
- Per-user-data partner-sharing modes → v3 (SHARE-01).
- Sentry crash reporting → v2.1 (TELE-01).
- Anonymous telemetry → out of scope, explicit refusal (TELE-02).
- Laravel Pulse → v2.1 (TELE-03, requires Redis cache reconfig).
- Two-job notarization split → rejected.
- Headless windowed-app smoke test (Playwright) → rejected; HTTP `/health` probe is chosen depth.
- PayPal Reporting API (ING-09) → trigger-deferred (PayPal Business upgrade).
- Cross-device sync → out of scope (privacy-first).
- Formal UAT close-out of 25 v1.0 deferred scenarios → user explicitly dropped.
- Invite-only beta cycle before public release → user explicitly dropped.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description (from REQUIREMENTS.md + CONTEXT.md absorbed sets) | Research Support |
|----|---------------------------------------------------------------|------------------|
| **CI-01** | PR-gate workflow — Larastan L10 strict + Pint + Pest on `ubuntu-latest` with `TZ=Europe/Amsterdam`; PHP 8.4 + 8.5 axes | `shivammathur/setup-php@v2.37.1` supports PHP 8.5; existing `.github/workflows/ci.yml` is the skeleton — widen matrix from `['8.4']` to `['8.4', '8.5']`; no other shape changes |
| **CI-02** | Tag-triggered release workflow — macOS 14 + Windows 2025 + Ubuntu 24.04 matrix; publishes via `softprops/action-gh-release@v2.6.2`; **no signing/notarization** per A-01 + A-02 | electron-builder `target: ['AppImage', 'deb']` for Linux; macOS `.dmg` via existing `nativephp_force_adhoc_signing.php`; Windows `.exe`/`.msi` unsigned; `softprops/action-gh-release v2.6.2` supports `draft`/`prerelease`/multiple-asset uploads — exactly the asymmetric stable=draft/RC=immediate pattern (D-02) |
| **CI-03** | **DROPPED per A-01** — was Apple Developer ID + notarytool | n/a |
| **CI-04** | **DROPPED per A-01** — was Azure Trusted Signing | n/a |
| **CI-05** | Secrets hygiene + CODEOWNERS + gitleaks on every PR + `pull_request_target`-safe fork-PR handling. **No `signing-prod` environment per A-03** (no signing secrets to gate; only Ed25519 publisher private key remains as a per-repo secret) | `gitleaks/gitleaks-action@v2.3.9` (free for personal accounts); CODEOWNERS at `.github/CODEOWNERS` enforced via branch-protection "Require review from Code Owners"; mitigation pattern = `release.yml` triggers ONLY on `push: tags: [v*]`, never `pull_request_target` — see Pitfall 1 |
| **CI-06** | First-launch APP_KEY regeneration (sentinel-absent triggers `php artisan key:generate --force`); `.env.bundled` template (no real secrets); first-launch encryption-key generation for `oauth_secrets` | Sentinel pattern is project-defined (Laravel ecosystem hasn't standardised one); recommend file under `UserDataPathService`-resolved storage path; OAuth-secret table-encryption key reuses `APP_KEY` per existing MULTI-05 implementation — confirm in Plan 17-04 |
| **UPDATE-01** | `electron-updater` wired through `Modules\Core\Public\Services\ElectronUpdateChannel` consuming GitHub Releases | `electron-updater` ships with `nativephp/electron` already; GitHub provider auto-activates when `GH_TOKEN` is defined; channel selection (stable/preview per D-04) via electron-updater `channel` config |
| **UPDATE-02** | Ed25519 publisher pin + signature verification on every update download (**SOLE security signal per A-06**); Pest test for tampered manifest | electron-updater publishes SHA-512 in `latest.yml` natively; Ed25519 manifest signing is a custom layer via `electron-updater.verifyUpdateCodeSignature` extension point or post-download verification hook; ElectronSafeUpdater (doyensec) is reference implementation; in-bundle public key, private key in repo secret |
| **UPDATE-03** | "Update available — install on next launch" + "Skip this version" + "you're on an old version" prompt (30-day stale per D-22) — wired into existing `SystemAlertsBanner` | UI-SPEC.md fully specifies copy + interaction; three new `system_alerts.kind` rows: `update.available` / `update.stale` / `update.critical`; new wire method `skipVersion({alertId})` persists in `user_preferences` table |
| **UPDATE-04** | First-install-can't-auto-update fallback documented on Settings page | UI surface only — Settings page additions, no new infrastructure |
| **REL-01** | Hippocratic License 3.0 — `LICENSE` + `NOTICE.md` + `composer.json` SPDX identifier | License text from firstdonoharm.dev; **SPDX caveat:** `Hippocratic-3.0` is the proposed identifier but NOT yet officially in the SPDX list (the upstream issue spdx/license-list-XML#1393 is still open); Hippocratic-2.1 IS registered. Composer's spdx-licenses validator will flag `Hippocratic-3.0` as invalid — recommended workaround: use `"license": "Hippocratic-3.0"` AND set `"license-validation": false` in composer.json OR use Composer's "proprietary" + explicit NOTICE.md (preferred since proprietary doesn't claim source-available) |
| **REL-02** | `SECURITY.md` (vuln-reporting + safe-harbor) + `CONTRIBUTING.md` (DI rule + arch tests + branch/PR conventions) + `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1 verbatim) | All three are text files at repo root; Contributor Covenant 2.1 verbatim from `contributor-covenant.org/version/2/1/code_of_conduct/` |
| **REL-03** | README rewrite — hero with `resources/brand/logo.svg`; What / Who / Install / Screenshots | README install section also carries A-05 install-bypass copy (UI-SPEC has verbatim text) |
| **REL-04** | Brand asset import — SVG + `.icns` + `.ico` + `logo-512.png` + favicon | SVG already at `resources/brand/logo.svg` (Phase 15 D-20); exports generated via existing `scripts/regenerate_app_icon.php` pattern |
| **REL-05** | GSD-leakage redaction sweep + `BoundaryArchTest::noGsdLeakage` arch invariant | Test pattern matches `.planning/`, `PLAN.md`, `RESEARCH.md`, `\bD-\d{2,3}\b`, `gsd[-_]`, phase codename prefixes — covers PHP code + Blade views + route names + view-data keys + log/error messages + comments |
| **REL-06** | Deep modules code review across all modules; produces `REVIEW-DEEP.md`; composer-require-checker | `composer-require-checker` is `maglnet/composer-require-checker`, well-maintained; cross-module boundary checks already enforced by existing `BoundaryArchTest`; this requirement is a HUMAN review pass over each module |
| **REL-07** | Renderer-JSON audit — every Livewire component's public properties / `$listeners` / `$queryString` checked for secrets-tagged or hidden-column leak | New `Modules/Core/Public/Services/SecretsColumnRegistry` is the single source-of-truth; arch invariant walks Livewire components via reflection |
| **REL-08** | "Where is my data?" docs page + export-everything ZIP | UI-SPEC `/help/data-locations` fully specifies copy + Dev Mode integration |
| **gap-counterparty-module** (synthetic — per A-04) | New `Modules/Counterparties/` bounded module + 5-type taxonomy + 7-step resolver chain + 3 routes + sidebar nav + cross-module DI consumption + `CounterpartyGarbageCollectorJob` + privacy defaults for personal type | UI-SPEC.md is the contract; depends on Phase 16.1.2.1's `known_counterparty_ibans` table per A-08 — Plan 17-06 starts only after parallel session lands |
| **gap-docs-folder** (synthetic — per D-31..D-34) | `.docs/` tree with `adr/` + `architecture/` + `features/` + `cicd/` + `local_development/` + `runbooks/` + `legal/` | Mirrors `https://github.com/happklaar/happklaar` structure verbatim; ~20-30 ADRs at v1.0.0 ship |
| **gap-history-purge** (synthetic — per D-35..D-37) | `git filter-repo --path .planning --invert-paths`; `.planning/` to `.gitignore`; force-push to private origin BEFORE public flip | `git filter-repo` is the modern replacement for `git filter-branch` (50× faster, safer defaults, requires fresh clone); **not bundled with git** — must be installed separately (`pip install git-filter-repo` or `brew install git-filter-repo`) |
| **gap-skill-rename** (per D-40..D-42) | `sketch-findings-diederik` → `sketch-findings-beatrax` in both user-level and project-level paths; CLAUDE.md + frontmatter update | Already partly done — staged deletions visible in `git status` |
| **gap-github-settings** (per D-49..D-50) | Interactive walkthrough; captured in `.docs/cicd/branch-protection.md` + `.docs/runbooks/repo-security-setup.md` | Repo `nightworksio/beatrax` already exists (private per A-07); Dependabot YAML already shipped (visible in repo); CodeQL default setup, secret scanning, push protection all free for public repos |
| **gap-v11-milestone** (per D-39) | GitHub Milestone + Issues + `.docs/roadmap-v1.1.md` | Provisional v1.1 seeds list in CONTEXT.md `<deferred>` |
| **gap-v100-graduation** (per D-18) | Explicit `v1.0.0` tag — user pulls trigger by name; not automated | Last task of phase, gated on every other plan being green |
</phase_requirements>

---

## Project Constraints (from CLAUDE.md + accumulated decisions)

These directives have the same authority as locked decisions. Plans must comply.

| Constraint | Source | Impact on Phase 17 |
|------------|--------|-------------------|
| **DI-only** — constructor injection everywhere; no facades, no global helpers in module code | CLAUDE.md + STATE.md memory `feedback_laravel_di_only` | Every new service (`HealthController`, `ElectronUpdateChannel`, `CounterpartyResolver`, `SecretsColumnRegistry`, `KnownCounterpartyIbanResolver`) uses constructor injection; the `health.php` controller must NOT call `app()`, `config()` helper, or `Route::` facade — use injected `\Illuminate\Contracts\Routing\Registrar` etc. Eloquent models direct OK. |
| **Module Public/Internal split** — cross-module access only via Public service classes or events | CLAUDE.md | `Modules/Counterparties/Public/Contracts/CounterpartyResolver` is the only entry point consumers see; `Modules/Ledger`, `Modules/Recurring`, `Modules/Chains`, `Modules/Categorization` consume via DI |
| **Codebase stays agnostic from GSD** — no `.planning/`, `PLAN.md`, `RESEARCH.md`, `D-NNN` codenames in runtime code, PHPDocs, comments, log lines, route names, view-data keys | CLAUDE.md + STATE.md memory `feedback_codebase_gsd_agnostic` | REL-05 + D-27 enforces this via `BoundaryArchTest::noGsdLeakage` — written + green by end of Plan 17-10 |
| **Docs describe current state, never history** — no "I changed this because X" comments; PHPDocs reflect what code does now | STATE.md memory `feedback_docs_describe_current_state` | All new PHPDocs in new modules + workflow YAML comments describe present behaviour, not migration history |
| **Fix every severity, not just blockers** — BLOCKER + WARNING + INFO addressed together | STATE.md memory `feedback_fix_all_severities` | Code-review checks treat all severities equally |
| **v0.x until explicit v1.0.0 graduation** — never auto-suggest v1.0.0; user pulls trigger by name at the end | STATE.md memory `project_v0x_versioning_until_explicit_graduation` + CONTEXT.md D-18 | Plan 17-20 is user-triggered; no automation graduates from v0.x → v1.0; first phase tag is `v0.1.0` or `v0.1.0-rc.1` |
| **PHP 8.4 in bundle, 8.5 in dev box** — shipped bundle uses `nativephp/php-bin` 8.4; project dev pin can be 8.5 once `nativephp/php-bin` ships 8.5 builds | STATE.md + composer.json `"php": "^8.4"` + Phase 15 P05 | CI matrix runs BOTH axes (`['8.4', '8.5']`). 8.4 axis proves the shipped runtime passes gates; 8.5 axis proves the dev box is honest. `nativephp/php-bin` still ships only up to 8.4 as of search results — bundle stays on 8.4 |
| **Larastan L10 strict + canvural strict + larastan-livewire** | CLAUDE.md + composer.json `require-dev` | CI gate already enforces; new code passes from day 1 |
| **`BelongsToUser` on every user-scoped model** | accumulated v2.0 decision | New `Counterparty` model uses `BelongsToUser` global scope (D-45) |
| **Migrations live inside owning module** | accumulated | `counterparties` table migration in `Modules/Counterparties/Database/Migrations/` |
| **No `ext-imap`** — composer.json hard-conflicts with any IMAP package requiring it | composer.json `conflict` block + PHP 8.4 unbundling | CI must not install `imap` extension (already omitted from `extensions:` list in ci.yml) |
| **Local-only privacy** — financial data must never leave the machine; no telemetry, no Sentry, no crash reporting | CLAUDE.md + TELE-01..03 deferral | Release workflow must not include build-time analytics, source-map upload, or any outbound network call to a third-party observability service. `GH_TOKEN` to publish releases is the only outbound permitted. |
| **Idempotency** — all ingestion paths safe to re-run | CLAUDE.md | New `ResolveCounterpartyStage` in ImportPipeline must be idempotent |
| **`open <path>` for HTML output** — every sketch/mockup/local HTML rendered for the user gets a literal `open <abs-path>` command in the message | STATE.md memory `feedback_sketch_show_open_command` | Not directly applicable to Phase 17 deliverables; flag if any task hands rendered HTML for review |

---

## Architectural Responsibility Map

The phase touches multiple tiers. Mapping each capability to the tier that owns it prevents tasks from drifting into the wrong layer.

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| PR-gate quality check (Larastan / Pint / Pest) | **CI runner (GitHub Actions ubuntu-latest)** | — | Pure CI concern; nothing in shipped artifact |
| Multi-platform installer build | **CI runner (macOS-14 / Windows-2025 / Ubuntu-24.04)** | NativePHP/Electron build chain | Build orchestrated in CI; build TOOLING lives in `nativephp/electron/` (gitignored) + `scripts/nativephp_*.php` (committed) |
| ad-hoc macOS signing | **Build chain (`scripts/nativephp_force_adhoc_signing.php` prebuild hook)** | — | Already implemented; runs unchanged on every build (A-01) |
| Smoke test (`/health` HTTP probe) | **CI runner** invokes **bundled Laravel app** at runtime | — | CI runs the installed bundle, hits `/health`; bundled app responds via new `HealthController` in `Modules/Core` |
| GitHub Release publish | **CI runner** via `softprops/action-gh-release@v2` | — | Final step of release.yml; gated on all-platforms-success per D-11 |
| Ed25519 manifest signing | **CI runner** signs `latest.yml` after build, before publish | — | Custom step in release.yml; secret = Ed25519 private key in repo secret |
| Ed25519 manifest verification | **Shipped app (`ElectronUpdateChannel` in `Modules\Core\Public\Services`)** | electron-updater | Public key embedded in app bundle (compiled-in constant or committed config); verification runs on every download |
| First-launch APP_KEY regeneration | **Shipped app (FirstLaunchBootstrap extension or new `EnsureAppKeyAction`)** | UserDataPathService | Runs in the bundled Laravel app at first launch; sentinel file under `UserDataPathService`-resolved path |
| Counterparty resolver (CounterpartyResolver Public contract) | **API/backend (`Modules/Counterparties/`)** | ImportPipeline stage | Server-side resolution; consumed via DI by Ledger / Recurring / Chains / Categorization |
| Counterparty UI (`/counterparties`, `/counterparties/{slug}`, `/counterparties/triage`) | **Frontend server (Laravel + Livewire/Volt SFC)** | Browser (Alpine for keyboard handlers) | Server-rendered with Livewire reactivity; keyboard handlers per UI-SPEC are Alpine-side |
| Auto-update banner | **Frontend server (existing `SystemAlertsBanner`)** | — | Re-uses existing alert kind machinery; adds three new `system_alerts.kind` rows + one new wire method `skipVersion()` |
| `/help/data-locations` page | **Frontend server (Livewire/Volt SFC in `Modules/Core`)** | Browser (clipboard for copy-path) | Server-rendered prose + dynamic path resolution from `UserDataPathService` |
| `.docs/` markdown tree | **Repo content (not runtime)** | — | Pure documentation, committed to repo, visible on GitHub UI |
| `.planning/` history purge | **Local developer machine** | git remote | Runs ONCE locally on a fresh clone; force-push to private origin; `.planning/` then added to `.gitignore` |
| Skill rename | **Local developer machine** + **Repo skill directory** | — | Renames in `~/.claude/skills/` (user-level) AND `./.claude/skills/` (project-level if exists) + CLAUDE.md update |
| GitHub repo-settings walkthrough | **GitHub Web UI** + **Captured docs in repo** | — | Interactive session; deliverable = `.docs/cicd/branch-protection.md` + `.docs/runbooks/repo-security-setup.md` |
| GSD-leakage arch invariant | **Test suite (`Modules/Core/Tests/Boundary/`)** | — | Pest arch test scanning runtime code; runs in CI gate |
| Renderer-JSON audit arch invariant | **Test suite** | `SecretsColumnRegistry` in `Modules/Core/Public/Services` | Reflection-based walk over Livewire components; runs in CI gate |
| Deep modules code review | **Human pass** + `composer-require-checker` | — | Produces `REVIEW-DEEP.md`; actioned in same phase |

**Why this matters for Phase 17:** Several capabilities span multiple tiers (e.g., Ed25519 signing happens in CI runner, verification happens in bundled app; smoke test runs in CI but invokes the bundled Laravel app). Plans should not put server-tier logic in CI YAML scripts, or CI orchestration in PHP code. The most common drift to watch for: tasks that try to put release-version constants in `config/nativephp.php` instead of using the tag-as-source-of-truth pattern (D-03).

---

## Standard Stack

### Core CI/CD Tooling

| Tool | Recommended Version | Purpose | Why Standard |
|------|---------------------|---------|--------------|
| **GitHub Actions** | platform | Workflow orchestration | Already the project's CI host; nothing to introduce [VERIFIED: `.github/workflows/ci.yml` exists] |
| **`actions/checkout`** | `v4` (pinned to a SHA in workflows) | Repo checkout | Standard first step in every workflow [VERIFIED: docs.github.com] |
| **`shivammathur/setup-php`** | `v2.37.1` (SHA: `7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc`) | PHP runtime install + extension management | Supports PHP 8.5; this project already uses it on the 8.4 axis [VERIFIED: github.com/shivammathur/setup-php/releases, ci.yml] |
| **`actions/cache`** | `v4` (pin SHA) | Composer cache between runs | Already in ci.yml; reduces install time from ~60s to ~10s on cache hit [VERIFIED: ci.yml] |
| **`softprops/action-gh-release`** | `v2.6.2` (SHA: `3bb1273`) — **NOT v3.0** (v3 requires Node 24 runtime; v2.6.2 is Node-20 baseline and stable) | GitHub Release publish + multi-asset upload | Industry-standard for tag-triggered releases; supports `draft`, `prerelease`, `make_latest`, multiple `files` patterns — exact fit for D-02 asymmetric stable=draft/RC=immediate [VERIFIED: github.com/softprops/action-gh-release/releases] |
| **`gitleaks/gitleaks-action`** | `v2.3.9` (latest) | Secret scanning on every PR | **No license required for personal accounts** (free for `nightworksio/beatrax` — which is owned by a personal account per "nightworksio" naming; verify in repo settings during walkthrough) [VERIFIED: github.com/gitleaks/gitleaks-action] |
| **`nektos/act`** | `latest` (~v0.2.x) | Local workflow testing | Catches ~90% of YAML errors before push; not a CI dependency, only a local dev tool [VERIFIED: github.com/nektos/act] |
| **`git-filter-repo`** | `2.47.0` (latest) | History purge | Modern replacement for `git filter-branch` (50× faster, requires fresh clone, removes remotes as safety) [VERIFIED: github.com/newren/git-filter-repo] |

### Auto-Update Stack

| Library | Recommended Version | Purpose | Why |
|---------|---------------------|---------|-----|
| **`electron-updater`** | latest stable (shipped via `nativephp/electron` template) | Update polling + download + SHA-512 binary verification + quitAndInstall | Already vendored under `nativephp/electron/` (gitignored); `nativephp/desktop ^2.2` brings the template along. Don't install separately. [VERIFIED: package present per CONTEXT.md A-06; slopcheck OK on npm registry] |
| **Ed25519 signing tool** | Node-side: `tweetnacl` ^1.0 or `@noble/ed25519` (both well-maintained, low-deps); PHP-side for CI: `sodium_*` functions (built into PHP 8.4+ via libsodium) | Sign `latest.yml` in release.yml; verify in `ElectronUpdateChannel` | `@noble/ed25519` is the modern audit-friendly choice (TypeScript-first, zero deps); `tweetnacl` is the classic battle-tested option. PHP's bundled `sodium_*` is sufficient if signing happens in a PHP composer script step rather than a Node step [CITED: ElectronSafeUpdater + nodejs sodium docs] |

### Existing PHP Stack (no changes; for reference)

| Package | Current Version | Role in Phase 17 |
|---------|-----------------|-------------------|
| `laravel/framework` | `^13.0` | Routing for `/health` + `/counterparties/*` + `/help/data-locations` |
| `livewire/livewire` | `^4.0` | All new UI surfaces (CounterpartyIndex, CounterpartyProfile, CounterpartyTriage, HelpDataLocations) |
| `livewire/flux` | `^2.0` | Modal / button / input / select / dropdown / kbd primitives per UI-SPEC |
| `nativephp/desktop` | `^2.2` | Hosts electron-updater + electron-builder; existing prebuild hooks |
| `nwidart/laravel-modules` | `^13.0` | New `Modules/Counterparties/` registration |
| `pestphp/pest` | `^4.0` | All tests including arch invariants |
| `pestphp/pest-plugin-arch` | `^4.0` | `BoundaryArchTest::noGsdLeakage`, `noSecretsInLivewireSnapshot` |
| `larastan/larastan` | `^3.0` | L10 strict in CI gate |
| `laravel/pint` | `^1.18` | Pint --test in CI gate |
| `spatie/laravel-activitylog` | `^5.0` | Already audit-logging Dev Mode actions (DEVUI-03); no new use here |

### Composer Dev-Only Additions

| Package | Purpose | When to Use |
|---------|---------|-------------|
| **`maglnet/composer-require-checker`** | Verifies every used symbol is declared in `require` (not pulled in transitively) | Required for REL-06 deep modules review; ~5-min one-shot during the review pass [VERIFIED: packagist, 8M+ installs] |

### What NOT to Install

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| Apple Developer ID certificate | $99/yr; explicitly dropped (A-01) | Existing ad-hoc signing (unchanged) |
| Azure Trusted Signing | $10/mo; explicitly dropped (A-01) | Windows builds ship unsigned |
| `softprops/action-gh-release@v3` | Requires Node 24 runtime — GitHub-hosted runners not universally on Node 24 yet | `softprops/action-gh-release@v2.6.2` (stable Node 20 line) |
| Any "auto-update without signature verification" path | Defeats the SOLE security signal (A-06) | Ed25519 verification on every download is mandatory; no escape hatch |
| `git-filter-branch` | Slow, dangerous defaults, official git docs recommend filter-repo | `git-filter-repo` |
| `pull_request_target` for any quality-gate job | Exploitable in fork PRs (see Pitfall 1) | Plain `pull_request` for PR gate; separate `release.yml` triggered only by tag push |
| Heredoc-via-Bash for file writes in agent workflows | Project policy: use Write tool (see system context) | Direct file edits |

### Installation (no new composer install for this phase; existing packages cover everything)

```bash
# For developer's local box only — NOT a CI dependency:
brew install git-filter-repo act
pip install --user slopcheck  # optional, already installed at /Users/wesselverheij/.local/bin/slopcheck
```

For the project itself, the only conditional add is `maglnet/composer-require-checker` during the REL-06 review pass:

```bash
composer require --dev maglnet/composer-require-checker
```

**Version verification (run before Plan 17-12 lands):**

```bash
# Confirm versions still current — search results dated 2026-05-27:
composer show maglnet/composer-require-checker --available 2>/dev/null
gh release view --repo softprops/action-gh-release --json tagName,publishedAt
gh release view --repo shivammathur/setup-php --json tagName,publishedAt
gh release view --repo gitleaks/gitleaks-action --json tagName,publishedAt
gh release view --repo newren/git-filter-repo --json tagName,publishedAt
```

---

## Package Legitimacy Audit

Phase 17 installs **zero new PHP packages at runtime** and only one dev dependency. The bulk of "new dependencies" are GitHub Actions (versioned + SHA-pinned, audited separately). slopcheck was run on each candidate; results:

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `electron-updater` | npm | 7+ yrs | ~5M/wk | github.com/electron-userland/electron-builder | [OK] | Approved (already vendored via NativePHP) |
| `maglnet/composer-require-checker` | packagist | 9+ yrs | 8M+ installs | github.com/maglnet/ComposerRequireChecker | [OK] | Approved (dev-only) |
| `@noble/ed25519` (if Node-side signing chosen) | npm | 4+ yrs | ~3M/wk | github.com/paulmillr/noble-ed25519 | [OK] | Approved |
| `tweetnacl` (alternative Node-side signing) | npm | 11+ yrs | ~10M/wk | github.com/dchest/tweetnacl-js | [OK] | Approved |

| GitHub Action | Source Repo | Latest Tag | Latest SHA | Notes |
|---------------|-------------|------------|------------|-------|
| `actions/checkout` | github.com/actions/checkout | v4.x | (pin to SHA at plan-time) | First-party GitHub action |
| `actions/cache` | github.com/actions/cache | v4.x | (pin to SHA at plan-time) | First-party GitHub action |
| `shivammathur/setup-php` | github.com/shivammathur/setup-php | 2.37.1 | `7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc` | Third-party — SHA pin mandatory |
| `softprops/action-gh-release` | github.com/softprops/action-gh-release | v2.6.2 | `3bb1273` (full SHA at plan-time via `git rev-parse v2.6.2`) | Third-party — SHA pin mandatory |
| `gitleaks/gitleaks-action` | github.com/gitleaks/gitleaks-action | v2.3.9 | (pin to SHA at plan-time) | First-party gitleaks tool |

**Packages removed due to slopcheck [SLOP] verdict:** none.
**Packages flagged as suspicious [SUS]:** none.

**Supply-chain hardening rule (load-bearing):** Every third-party GitHub Action MUST be pinned to a full 40-char commit SHA, not a tag. Reason: Q1 2026 attacks on `tj-actions/changed-files` (23,000+ repos compromised), Trivy, Nx all exploited mutable tag references. Tag-based workflows became active credential-theft vectors while SHA-pinned equivalents were unaffected. Add Dependabot's `github-actions` ecosystem (already configured in `.github/dependabot.yml`) to auto-bump SHAs through reviewed PRs.

---

## Architecture Patterns

### System Architecture Diagram

```
                                 ┌─────────────────────────────────┐
                                 │  Developer pushes tag v0.1.0    │
                                 │  (git tag v0.1.0 && git push    │
                                 │   --tags)                       │
                                 └─────────────────┬───────────────┘
                                                   │
                                                   ▼
              ┌──────────────────────────────────────────────────────────┐
              │  GitHub Actions release.yml (push: tags: [v*])           │
              │  ┌────────────────────────────────────────────────────┐  │
              │  │  Job 1 (gate): re-run ci.yml's quality matrix      │  │
              │  │  on 8.4+8.5 — fails fast (~5min) if main broke     │  │
              │  └─────────────────────────┬──────────────────────────┘  │
              │                            │ (all green)                  │
              │                            ▼                              │
              │  ┌──────────┐  ┌──────────┐  ┌────────────────────────┐  │
              │  │ macOS-14 │  │ Windows  │  │ Ubuntu-24.04          │  │
              │  │ Job 2a   │  │ Job 2b   │  │ Job 2c                 │  │
              │  │          │  │          │  │                        │  │
              │  │ setup-php│  │ setup-php│  │ setup-php (8.4)        │  │
              │  │ composer │  │ composer │  │ composer install       │  │
              │  │  install │  │  install │  │                        │  │
              │  │ npm ci   │  │ npm ci   │  │ npm ci                 │  │
              │  │ native:  │  │ native:  │  │ native:build           │  │
              │  │  build   │  │  build   │  │                        │  │
              │  │ ↓        │  │ ↓        │  │ ↓                      │  │
              │  │ ad-hoc   │  │ unsigned │  │ unsigned              │  │
              │  │ sign     │  │ .exe/.msi│  │ .AppImage + .deb       │  │
              │  │ (existing│  │          │  │                        │  │
              │  │  hook)   │  │          │  │                        │  │
              │  │ ↓        │  │ ↓        │  │ ↓                      │  │
              │  │ smoke    │  │ smoke    │  │ smoke test:            │  │
              │  │ test:    │  │ test:    │  │ install .deb → launch  │  │
              │  │ xattr -d │  │ launch → │  │ → curl /health         │  │
              │  │ launch → │  │ curl     │  │                        │  │
              │  │ curl     │  │ /health  │  │                        │  │
              │  │ /health  │  │          │  │                        │  │
              │  │ ↓        │  │ ↓        │  │ ↓                      │  │
              │  │ upload   │  │ upload   │  │ upload artifacts       │  │
              │  │ artifacts│  │ artifacts│  │                        │  │
              │  └────┬─────┘  └────┬─────┘  └────────────┬───────────┘  │
              │       └──────┬──────┴───────────────────────┘             │
              │              │ (ALL jobs success — A-O-N)                 │
              │              ▼                                            │
              │  ┌─────────────────────────────────────────────────────┐  │
              │  │  Job 3 (publish): download all artifacts            │  │
              │  │  Generate latest.yml (electron-updater manifest)    │  │
              │  │  Sign latest.yml with Ed25519 private key (secret)  │  │
              │  │  softprops/action-gh-release: stable=draft, RC=pub  │  │
              │  └─────────────────────────────────────────────────────┘  │
              └──────────────────────────────────────────────────────────┘
                                                   │
                                                   ▼
                              ┌─────────────────────────────────────┐
                              │   GitHub Releases (public CDN)      │
                              │   - beatrax-0.1.0-mac.dmg          │
                              │   - beatrax-0.1.0-win.exe          │
                              │   - beatrax-0.1.0.AppImage         │
                              │   - beatrax-0.1.0.deb              │
                              │   - latest.yml (Ed25519-signed)    │
                              │   - latest-mac.yml (signed)        │
                              │   - latest-linux.yml (signed)      │
                              └────────────────┬────────────────────┘
                                               │
                                               ▼
                              ┌──────────────────────────────────────┐
                              │  Installed beatrax app (any user)   │
                              │  - ElectronUpdateChannel polls      │
                              │    GitHub Releases via electron-    │
                              │    updater every 4hr                │
                              │  - Downloads latest.yml             │
                              │  - VERIFIES Ed25519 signature       │
                              │    (rejects if tampered)            │
                              │  - Downloads binary                 │
                              │  - VERIFIES SHA-512                 │
                              │  - Raises system_alerts row of      │
                              │    kind=update.available            │
                              │  - SystemAlertsBanner shows banner  │
                              │    in app                           │
                              │  - User clicks "Install on next     │
                              │    launch" → quitAndInstall()       │
                              └──────────────────────────────────────┘
```

**Component responsibilities** (file-to-implementation map):

| Component | Lives in | Responsibility |
|-----------|----------|----------------|
| PR-gate workflow | `.github/workflows/ci.yml` (modify) | Matrix-widen 8.4 → 8.4+8.5 |
| Release workflow | `.github/workflows/release.yml` (new) | Tag-trigger, gate-reuse, 3× platform jobs, publish |
| Security workflow | `.github/workflows/security.yml` (new, or inline in ci.yml) | gitleaks on every PR |
| CODEOWNERS | `.github/CODEOWNERS` (new) | Require @user review on `.github/workflows/*` + `.github/CODEOWNERS` + critical paths |
| Dependabot config | `.github/dependabot.yml` (already exists) | Weekly composer + npm + github-actions PRs |
| ad-hoc signing | `scripts/nativephp_force_adhoc_signing.php` (existing — UNCHANGED) | Patches `electron-builder.mjs` `mac.identity = null` on every build |
| `/health` route | `Modules/Core/Routes/web.php` (modify) + new `Modules/Core/Public/Controllers/HealthController.php` (new) | Returns `{status, app_version, php_version, sqlite_version}` JSON; auth-free |
| First-launch APP_KEY sentinel | `Modules/Core/Internal/Bootstrap/EnsureAppKey.php` (new) hooked into existing `FirstLaunchBootstrap` (Phase 15-05) | Detects sentinel-absent → runs `Artisan::call('key:generate', ['--force' => true])` |
| `.env.bundled` template | Repo root `.env.bundled` (new) | No real secrets; placeholder values for `DB_CONNECTION`, `QUEUE_CONNECTION`, etc. |
| ElectronUpdateChannel | `Modules/Core/Public/Services/ElectronUpdateChannel.php` (new) | Thin Laravel adapter exposing channel selection (stable/preview) + events for SystemAlertsBanner |
| Ed25519 verification | inside `ElectronUpdateChannel` (PHP-side via `sodium_crypto_sign_verify_detached`) OR in NativePHP electron-side script (depending on whether download lives in PHP or Electron tier — planner picks) | Verifies signature on `latest.yml` against embedded public key BEFORE returning "update available" event |
| Auto-update banner | three new `system_alerts.kind` rows + new wire method `skipVersion($alertId)` in `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` (modify) + matching partial | Renders three update alert variants per UI-SPEC |
| Counterparties module | `Modules/Counterparties/` (new full module) | See `<phase_requirements>` and UI-SPEC for full scope |
| KnownCounterpartyIbanResolver | `Modules/Import/Public/Contracts/ResolvesKnownCounterpartyIban` (Phase 16.1.2.1) | DEPENDENCY: Counterparty resolver consumes this for `bank` type |
| SecretsColumnRegistry | `Modules/Core/Public/Services/SecretsColumnRegistry.php` (new) | Single source-of-truth enumerating secrets-tagged columns (e.g., `oauth_secrets.access_token`, `oauth_secrets.refresh_token`); consumed by renderer-JSON arch invariant |
| `noGsdLeakage` arch invariant | `Modules/Core/Tests/Boundary/GsdLeakageTest.php` (new) | Pest test; pattern matches `.planning/`, `PLAN.md`, `RESEARCH.md`, `\bD-\d{2,3}\b`, `gsd[-_]`, phase-codename prefixes across runtime code, views, route names, view-data keys |
| `noSecretsInLivewireSnapshot` arch invariant | `Modules/Core/Tests/Boundary/SecretsInSnapshotTest.php` (new) | Walks Livewire components via reflection; fails if any public property / `$listeners` / `$queryString` references a `SecretsColumnRegistry` entry |
| `/help/data-locations` | `Modules/Core/Resources/views/livewire/help/data-locations.blade.php` + `HelpDataLocations.php` Volt SFC (new) | Renders resolved paths from injected `UserDataPathService`; copy-to-clipboard buttons; export-everything CTA (gated on Dev Mode per D-30) |
| `.docs/` tree | `.docs/` (new, mirroring happklaar/happklaar structure) | Repo-visible documentation (NOT runtime); `.planning/` graduation flow per D-32 |
| `.planning/` purge | one-shot local-machine git operation | `git filter-repo --path .planning --invert-paths` → `.gitignore` update → force-push to private origin |

### Recommended Project Structure

The phase ADDS the following structure (existing tree unchanged):

```
.github/
├── workflows/
│   ├── ci.yml                         # MODIFY: widen matrix to 8.4+8.5
│   ├── release.yml                    # NEW: tag-triggered installer matrix
│   └── security.yml                   # NEW: gitleaks on every PR (or inline in ci.yml)
├── CODEOWNERS                         # NEW: @user on workflows + critical paths
└── dependabot.yml                     # EXISTING — no changes

Modules/
├── Core/
│   ├── Public/
│   │   ├── Controllers/
│   │   │   └── HealthController.php   # NEW
│   │   └── Services/
│   │       ├── ElectronUpdateChannel.php       # NEW
│   │       └── SecretsColumnRegistry.php       # NEW
│   ├── Internal/
│   │   └── Bootstrap/
│   │       └── EnsureAppKey.php       # NEW (hooks into existing FirstLaunchBootstrap)
│   ├── Resources/views/livewire/
│   │   ├── help/data-locations.blade.php       # NEW
│   │   └── system-alerts-banner.blade.php      # MODIFY (3 new alert kinds)
│   └── Tests/Boundary/
│       ├── GsdLeakageTest.php         # NEW
│       └── SecretsInSnapshotTest.php  # NEW
└── Counterparties/                    # NEW BOUNDED MODULE
    ├── Public/
    │   ├── Contracts/
    │   │   └── CounterpartyResolver.php
    │   ├── Models/
    │   │   └── Counterparty.php
    │   └── Events/
    │       └── CounterpartyResolved.php
    ├── Internal/
    │   ├── Resolver/
    │   │   └── CounterpartyResolverService.php
    │   ├── Pipeline/
    │   │   └── ResolveCounterpartyStage.php
    │   └── Jobs/
    │       └── CounterpartyGarbageCollectorJob.php
    ├── Database/
    │   ├── Migrations/
    │   │   └── XXXX_XX_XX_create_counterparties_table.php
    │   └── Seeders/
    ├── Resources/
    │   └── views/
    │       ├── livewire/
    │       │   ├── counterparty-index.blade.php
    │       │   ├── counterparty-profile.blade.php
    │       │   ├── counterparty-triage.blade.php
    │       │   └── profile-tabs/
    │       │       ├── merchant.blade.php
    │       │       ├── personal.blade.php
    │       │       ├── bank.blade.php
    │       │       ├── government.blade.php
    │       │       └── unknown.blade.php
    │       └── components/
    │           ├── type-chip.blade.php
    │           ├── cp-card.blade.php
    │           ├── filter-chips.blade.php
    │           ├── frame.blade.php
    │           ├── chain-flow.blade.php
    │           ├── iban-row.blade.php
    │           ├── privacy-banner.blade.php
    │           └── self-stub.blade.php
    ├── Routes/
    │   └── web.php
    └── tests/
        ├── Unit/
        │   ├── CounterpartyResolverTest.php
        │   ├── PrivacyDefaultsTest.php  # asserts IBAN never in lists/URLs/titles for personal type
        │   └── SlugCollisionTest.php
        └── Feature/
            ├── CounterpartyIndexTest.php
            ├── CounterpartyProfileTest.php
            ├── CounterpartyTriageTest.php
            └── ResolveCounterpartyStageTest.php

scripts/
└── nativephp_force_adhoc_signing.php  # EXISTING — UNCHANGED per A-01

build/
└── entitlements.mac.plist             # EXISTING — UNCHANGED

config/
└── nativephp.php                      # MODIFY: 'version' default → '0.0.0-dev' (D-03)

.docs/                                 # NEW TREE
├── 00-index.md
├── adr/
│   ├── 00-index.md
│   ├── 0001-modular-architecture.md
│   ├── 0002-di-only-rule.md
│   ├── 0003-hippocratic-3-0-license.md
│   ├── ...
├── architecture/
│   ├── 00-index.md
│   └── (topic files)
├── features/
│   ├── 00-index.md
│   ├── _template/
│   │   ├── architecture.md
│   │   ├── code.md
│   │   ├── specs.md
│   │   └── how-to-test.md
│   ├── auth/ ...
│   ├── counterparties/ ...
│   └── (17 module subdirs total)
├── cicd/
│   ├── 00-index.md
│   ├── overview.md
│   ├── branch-protection.md
│   ├── release-workflow.md
│   └── release-cadence.md
├── local_development/
│   ├── 00-index.md
│   ├── setup.md
│   ├── database.md
│   ├── troubleshooting.md
│   └── dev-mode.md
├── runbooks/
│   ├── 00-index.md
│   ├── release-cut.md
│   ├── verify-release.md       # Ed25519 verification one-liner for users (REL-A-05 footnote)
│   ├── repo-security-setup.md
│   └── force-password-reset.md
├── legal/
│   ├── 00-index.md
│   ├── license-rationale.md    # Why Hippocratic-3.0; no-paid-signing rationale (linked from README)
│   └── data-retention.md
└── roadmap-v1.1.md             # Summary + link to GitHub Milestone

LICENSE                                # NEW: Hippocratic 3.0 verbatim
NOTICE.md                              # NEW: source-available / not-OSI-approved explainer
SECURITY.md                            # NEW: vuln-reporting + safe-harbor
CONTRIBUTING.md                        # NEW: DI rule + arch tests + branch/PR conventions
CODE_OF_CONDUCT.md                     # NEW: Contributor Covenant 2.1 verbatim
README.md                              # REWRITE: hero + What/Who/Install/Screenshots + install-bypass sections
.env.bundled                           # NEW: template, no real secrets
.gitignore                             # MODIFY: add `.planning/` (after Plan 17-17 purge)
```

### Pattern 1: SHA-pinned GitHub Action with renovate-friendly comment

**What:** Pin third-party actions to full 40-char SHAs, comment the human-readable tag for review legibility.
**When to use:** Every third-party action in every workflow.
**Example:**

```yaml
# Source: docs.github.com/en/actions/security-for-github-actions/security-guides
# Pattern: SHA-pin + tag comment
- uses: shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc  # v2.37.1
  with:
    php-version: ${{ matrix.php }}

- uses: softprops/action-gh-release@3bb12731b8b54a5f3a4ec5e8e3a3a4ad9c1d7d22  # v2.6.2
  with:
    draft: ${{ !contains(github.ref_name, '-rc') }}  # stable=draft, RC=immediate
    prerelease: ${{ contains(github.ref_name, '-rc') }}
    files: |
      artifacts/macos/*.dmg
      artifacts/windows/*.exe
      artifacts/windows/*.msi
      artifacts/linux/*.AppImage
      artifacts/linux/*.deb
      artifacts/latest.yml
      artifacts/latest.yml.sig
```

*(SHA placeholders; pin to actual SHA at plan-time via `gh api repos/softprops/action-gh-release/git/refs/tags/v2.6.2`.)*

### Pattern 2: Tag-triggered release with quality gate reuse

**What:** D-12 — re-run the PR-gate matrix as Job 1 of release.yml so a tag pushed to a broken main fails fast.
**When to use:** Every release workflow. Costs ~5 minutes, saves a full ~30-45min platform-job run on a broken main.
**Example:**

```yaml
# Source: D-12 (CONTEXT.md) + GitHub Actions docs
name: release

on:
  push:
    tags:
      - 'v*'  # NEVER pull_request_target — fork-PR exploit class (see Pitfall 1)

permissions:
  contents: write  # for softprops/action-gh-release to create releases

jobs:
  gate:
    name: quality gate (reuses ci.yml shape)
    runs-on: ubuntu-latest
    timeout-minutes: 15
    strategy:
      fail-fast: true  # NOTE: opposite of ci.yml — on release we want fast-fail
      matrix:
        php: ['8.4', '8.5']
    env:
      TZ: Europe/Amsterdam
    steps:
      - uses: actions/checkout@<SHA>  # v4
      - uses: shivammathur/setup-php@<SHA>  # v2.37.1
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, sqlite3, pdo_sqlite, bcmath, gd, intl, xml, dom, fileinfo, tokenizer, json
          coverage: none
          tools: composer:v2
      - run: composer install --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --memory-limit=1G --no-progress
      - env: { APP_ENV: testing }
        run: php artisan test --parallel

  build-macos:
    needs: gate
    runs-on: macos-14
    timeout-minutes: 30  # generous; ad-hoc sign is ~30sec but build is ~10min
    steps: ... # build → ad-hoc sign (existing hook) → smoke test → upload artifact

  build-windows:
    needs: gate
    runs-on: windows-2025
    timeout-minutes: 30
    steps: ... # build → unsigned → smoke test → upload artifact

  build-linux:
    needs: gate
    runs-on: ubuntu-24.04
    timeout-minutes: 30
    steps: ... # build → unsigned .AppImage + .deb → smoke test → upload artifact

  publish:
    needs: [build-macos, build-windows, build-linux]  # ALL must succeed (D-11)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@<SHA>
      - name: Download all artifacts
        uses: actions/download-artifact@<SHA>  # v4
      - name: Sign latest.yml with Ed25519
        env:
          ED25519_PRIVATE_KEY: ${{ secrets.ED25519_PRIVATE_KEY }}
        run: |
          php scripts/sign_update_manifest.php artifacts/latest.yml
      - uses: softprops/action-gh-release@<SHA>  # v2.6.2
        with:
          draft: ${{ !contains(github.ref_name, '-rc') }}
          prerelease: ${{ contains(github.ref_name, '-rc') }}
          generate_release_notes: true
          files: |
            artifacts/**/*
```

### Pattern 3: Fork-PR-safe workflow separation

**What:** `release.yml` triggers ONLY on `push: tags: [v*]` (so fork PRs can never reach signing/release context). PR-gate runs `pull_request` (no secrets exposed).
**When to use:** Every public repo.
**Why it's critical:** Pitfall 1 below — `pull_request_target` + PR-code checkout is the canonical secret-exfil pattern that hit Microsoft Symphony, Spotipy, pgai, openlit and many more.
**Example:** See Pattern 2 above — `on: push: tags:` is the only trigger; no `pull_request_target` anywhere in the workflow.

### Pattern 4: First-launch APP_KEY sentinel (Laravel)

**What:** Detect "no APP_KEY exists yet on this machine" and run `key:generate --force` exactly once at first launch.
**When to use:** Every install-time-key-generation requirement.
**Example:**

```php
// Source: project-defined (Laravel ecosystem hasn't standardised). [ASSUMED]
// Plan 17-04 will refine the sentinel location.
// File: Modules/Core/Internal/Bootstrap/EnsureAppKey.php

namespace Modules\Core\Internal\Bootstrap;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Services\UserDataPathService;

final readonly class EnsureAppKey
{
    public function __construct(
        private Application $app,
        private Artisan $artisan,
        private Filesystem $files,
        private UserDataPathService $paths,
    ) {}

    public function run(): void
    {
        $sentinel = $this->paths->storagePath().'/app/.app-key-generated';
        if ($this->files->exists($sentinel)) {
            return;
        }

        // The bundled .env.bundled has APP_KEY= empty; first-launch fills it.
        $this->artisan->call('key:generate', ['--force' => true]);
        $this->files->put($sentinel, (string) time());
    }
}
```

**Caveat (HIGH confidence on the risk):** `key:generate --force` invalidates all session cookies and breaks any data encrypted with the previous key. This is **safe at first launch only** (no previous data exists). Plans MUST ensure `EnsureAppKey` runs BEFORE any user data is migrated/seeded, and BEFORE FirstLaunchBootstrap's other steps. Recommend: first step in the bootstrap chain.

### Anti-Patterns to Avoid

- **Tag-only action references (`@v4`, `@v2`).** Mutable; weaponised in supply-chain attacks. ALWAYS pin to full SHA + comment the tag.
- **`pull_request_target` for quality-gate jobs.** Even one workflow with this trigger that runs PR code (npm install, composer install, test scripts) is a credential-exfil vector. PR gate uses `pull_request` (untrusted code, no secrets exposed). Release flow uses tag-push (trusted by definition).
- **Hardcoding the version in `config/nativephp.php`.** D-03 mandates tag-as-source-of-truth: default is `'0.0.0-dev'`, `release.yml` exports `NATIVEPHP_APP_VERSION=<stripped-v-prefix>` before `native:build`.
- **Auto-update path without signature verification.** A-06 makes Ed25519 verification the SOLE security signal — no escape hatch, no "skip-verification" flag, no debug-mode bypass.
- **Putting smoke-test failure handling on a per-platform basis.** D-15 mandates ALL-OR-NOTHING — any smoke failure fails the whole release, including Linux. (Asymmetric "Linux warn-only" was explicitly rejected.)
- **Running `git filter-repo` on the working repo.** `filter-repo` removes remotes as a safety measure and requires a fresh clone — Plan 17-17 MUST clone fresh, run there, force-push, and the working clone re-fetches.
- **Committing `nativephp/electron/` or `build/` output to git.** Per Phase 15-05 D-19, these are gitignored; the `nativephp_stage_build_resources.php` prebuild hook stages the canonical tracked assets (icons, entitlements) into the working dir on every build.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Multi-platform installer build | Custom electron-builder wrapper | `nativephp/desktop` + existing `scripts/nativephp_*.php` prebuild hooks | Already shipped; A-01 confirms hooks unchanged |
| GitHub Release publish | Manual API calls via curl/octokit | `softprops/action-gh-release@v2.6.2` | Battle-tested; supports draft/prerelease/asset-glob/discussion-link |
| Secret scanning in PRs | Custom regex + GitHub Action | `gitleaks/gitleaks-action@v2.3.9` | Free for personal accounts; allowlist support; preconfigured for ~150 secret patterns |
| History purge of `.planning/` | `git filter-branch` or manual rebase | `git filter-repo --path .planning --invert-paths` | filter-branch is slow + dangerous defaults; git's own docs recommend filter-repo |
| Ed25519 manifest signing | Custom OpenSSL CLI invocation | `sodium_crypto_sign_detached()` (PHP built-in, libsodium) for CI; verify same way in app | Built into PHP 8.4+; constant-time, audit-free, zero-deps |
| Local workflow testing | "push to throwaway branch and watch" loop | `nektos/act` | Catches ~90% of YAML errors locally; ~5sec iteration vs ~2min push-and-wait |
| Update channel + download + verification + install | Custom Electron IPC bridge | `electron-updater` (already vendored via NativePHP) | Ed25519 hook is the only addition needed |
| Branch protection | Markdown checklist + memory | GitHub UI + captured config in `.docs/runbooks/repo-security-setup.md` | Enforced server-side; works for solo dev too |
| Live-distribution test fixture (for arch tests / categorization-rule coverage) | Hand-built 10-row CSV | Sampled top-N anonymized fixture from real data (per Phase 16.1.2.1 pattern) | Already the project's pattern; gate values stay honest |

**Key insight:** Phase 17 is mostly an integration phase — almost every block has an off-the-shelf solution that's already in the dependency tree or is canonical for the GitHub Actions / electron-updater / git ecosystem. The novel work is in the project-specific glue: `ElectronUpdateChannel`, `HealthController`, the GSD-leakage arch invariant, the SecretsColumnRegistry, the new Counterparties module, the .docs/ tree, and the README install-bypass copy.

---

## Runtime State Inventory

This phase is partly a **rename / refactor / migration phase** (the diederik → beatrax skill rename is already partly done; Phase 16-02 did the in-app rename per ROADMAP). The new rename-class work here is minimal — but the `.planning/` history purge IS a destructive runtime-state operation, and the v0.x tag cleanup is too. Inventory:

| Category | Items Found | Action Required |
|----------|-------------|-----------------|
| **Stored data** | The shipped `beatrax.sqlite` SQLite database does NOT contain the string "diederik" as a key, collection name, or user_id — Phase 16-02 (`diederik → beatrax full rename`) already completed this rename across migrations + seeders + reference data. The `users.username` column does not embed the brand name. **Nothing further required.** | None — verified by Phase 16-02 completion + composer.json `"name": "beatrax/beatrax"`. |
| **Live service config** | GitHub repo `nightworksio/beatrax` already exists (private) per A-07. The Datadog / Sentry / external observability services that would normally hold service-name strings DO NOT EXIST in this project (TELE-01/02/03 explicitly deferred). **Nothing further required.** | None — verified by zero outbound service integrations. |
| **OS-registered state** | macOS app bundle identifier is `com.nativephp.app` (default in `config/nativephp.php`) — should be flipped to `com.nightworksio.beatrax` (or similar) during Plan 17-04 OR explicitly accepted as `com.nativephp.app` for v0.x. **Decision needed in planning.** The Windows Start Menu name + Linux .deb package name come from `electron-builder.mjs` generated by NativePHP — currently default "diederik" or "beatrax" depending on Phase 16-02 propagation; **verify during Plan 17-04 build**. | Plan 17-04 verifies + updates `config/nativephp.php` `app_id` if needed. |
| **Secrets / env vars** | `APP_KEY` in `.env` — already brand-agnostic. New secret `ED25519_PRIVATE_KEY` (or planner-named) will be created in the GitHub repo settings during the security walkthrough. The repo's `GITHUB_TOKEN` is already in place. **No diederik-named env vars exist in the codebase or `.env.example`** (verified during the Phase 16-02 rename). | New secret to add: `ED25519_PRIVATE_KEY` per Plan 17-05. |
| **Build artifacts / installed packages** | `nativephp/electron/` build outputs are gitignored — no stale "diederik" artifacts in git. The user-installed sketch-findings-diederik Claude skill at `~/.claude/skills/sketch-findings-diederik/` needs renaming per D-40. The project-level skill at `./.claude/skills/sketch-findings-diederik/` shows as DELETED in git status — needs the new `./.claude/skills/sketch-findings-beatrax/` directory to be created. CLAUDE.md "Project Skills" section already references `sketch-findings-beatrax` in some places — verify consistency in Plan 17-14. **Existing git tags will be deleted per D-17** (`git tag | xargs git tag -d`) so they don't pollute the auto-update channel ordering. | Plan 17-14 handles skill rename. Plan 17-01 deletes old tags. |

**The canonical question for this phase:** *After every file in the repo is updated and the v0.x.x tags are cut, what runtime systems still have the old "diederik" string cached, stored, or registered?*

Answer: **almost nothing**, because Phase 16-02 already did the heavy lifting. The remaining items are: (1) the Claude skill rename at the user's home dir (D-40, Plan 17-14), (2) the `config/nativephp.php` `app_id` decision (Plan 17-04), and (3) the existing git tags get deleted (Plan 17-01). The `.planning/` history purge (Plan 17-17) is a separate concern not about renaming but about removing a directory tree from history.

---

## Common Pitfalls

### Pitfall 1: `pull_request_target` secret-exfiltration via fork PR

**What goes wrong:** A workflow uses `pull_request_target` (which runs in the trusted base-repo context with secrets) AND checks out PR code (which is untrusted). The PR author submits malicious code; `npm install` or `pip install -e .` or `composer install` from the PR runs scripts with secrets in the environment; secrets are exfiltrated to an attacker-controlled server.
**Why it happens:** Documentation around `pull_request_target` is genuinely confusing — it sounds like "the right way to handle PRs that need labels/comments", and devs reach for it for PR-quality jobs too. The trigger gives ACCESS to secrets that `pull_request` doesn't have.
**How to avoid:**
1. **PR gate** uses `pull_request` (untrusted code, no secrets exposed; `GITHUB_TOKEN` is read-only by default).
2. **Release flow** uses `push: tags: [v*]` (only repo collaborators with write access can push tags; fork PRs cannot trigger).
3. **Never combine `pull_request_target` with PR-code checkout.** If you need `pull_request_target` for labels/comments, check out `${{ github.base_ref }}` (the base, not the PR head).
**Warning signs:**
- Any workflow with both `pull_request_target:` and `actions/checkout` against `${{ github.event.pull_request.head.sha }}`.
- Any `pull_request_target` workflow that runs `npm install`, `composer install`, test scripts, or any code from the PR.
- Any workflow with `permissions: contents: write` and a fork-PR trigger.

### Pitfall 2: GitHub Action mutable tag hijack (the `tj-actions/changed-files` class of attack)

**What goes wrong:** A third-party action is referenced by tag (e.g., `@v4`); the action's repo is compromised (token theft, social engineering); attacker pushes a malicious commit to the tagged version; every workflow run pulls the malicious code; secrets are exfiltrated. Q1 2026 saw this hit `tj-actions/changed-files` (~23,000 repos), Trivy, Nx.
**Why it happens:** Tag references are mutable. The action's maintainer can re-point the tag at any time; an attacker who steals the maintainer's GitHub token can too.
**How to avoid:** Pin every third-party action to a full 40-char commit SHA. Comment the tag for human review. Use Dependabot's `github-actions` ecosystem (already configured in `.github/dependabot.yml`) to receive auto-PR bumps that include the new SHA + the human-readable tag for review.
**Warning signs:**
- Any workflow with a reference like `uses: third-party/action@v4` or `@main`.
- Reliance on Dependabot but with `directory:` excluding `/` for github-actions.

### Pitfall 3: Ed25519 verification bypassed via "old electron-updater built-in checks"

**What goes wrong:** electron-updater has built-in signature verification for **OS-level code signatures** (Authenticode on Windows, Developer ID on macOS). With unsigned/ad-hoc-signed builds, these built-in checks either no-op or, worse, FAIL the update — defeating the purpose. The fix is to (a) disable OS-signature verification on macOS via `disableDifferentialDownload: true` (per A-06) and (b) ADD a custom Ed25519 verification layer.
**Why it happens:** Reading "electron-updater verifies signatures" leads to the assumption that no custom layer is needed. But that built-in verification is OS-cert-chain-based; with no Developer ID, there's nothing to verify against.
**How to avoid:**
1. Set `disableDifferentialDownload: true` for macOS in electron-updater config (per A-06).
2. Implement Ed25519 manifest verification BEFORE electron-updater reports "update available" — either via `electron-updater.verifyUpdateCodeSignature` extension point OR via a wrapper service that fetches `latest.yml` + `latest.yml.sig` manually, verifies, then hands off to electron-updater for the actual download (which still does SHA-512 against the manifest's SHA-512 entries).
3. Write a Pest test that tampers with `latest.yml` (flips one byte) and proves the verifier rejects (UPDATE-02 mandates this test).
**Warning signs:**
- Code that calls `autoUpdater.checkForUpdates()` without a pre-verification hook.
- Missing test asserting "tampered manifest is rejected".
- App configured with `requestHeaders` that include any auth-bypass header.

### Pitfall 4: `git filter-repo` runs on working repo → loses remotes silently

**What goes wrong:** Developer runs `git filter-repo` in the working clone (not a fresh clone); `filter-repo` removes all configured remotes as a safety measure (to prevent accidental force-push of unwanted history); developer tries to push, sees "no remote configured", panics, re-adds the wrong remote, force-pushes garbage.
**Why it happens:** `filter-repo`'s safety measure is non-obvious. Devs new to the tool don't know it removes remotes.
**How to avoid:** Per D-35 + standard practice — **always run on a fresh clone** (e.g., `git clone /path/to/working ../beatrax-purge && cd ../beatrax-purge && git filter-repo --path .planning --invert-paths && git remote add origin git@github.com:nightworksio/beatrax.git && git push --force origin main`). Verify rewritten history with `git log --oneline | head -20` before pushing. After force-push to private origin, the working clone in `/Users/wesselverheij/Development/diederik` can re-fetch the rewritten history.
**Warning signs:** Running `git filter-repo` from the project root in the same shell that just did `git status`. Output containing "Aborting: Refusing to overwrite repo history since this does not look like a fresh clone."

### Pitfall 5: APP_KEY regeneration after data exists → wipes everything

**What goes wrong:** Sentinel logic regenerates `APP_KEY` on a machine that already has data (e.g., user upgraded mid-run, sentinel file deleted by mistake, fresh `.env` shipped over existing install); the new key cannot decrypt the old `oauth_secrets` table rows; user loses Gmail OAuth tokens silently and is forced to re-auth (best case) or sees opaque "decryption failed" errors (worst case).
**Why it happens:** Laravel's `key:generate` is destructive against existing encrypted data; the sentinel check is the only thing preventing that. Any bug in the sentinel path is catastrophic.
**How to avoid:**
1. Sentinel must be a FILE (not a check on `.env`'s APP_KEY presence — the empty-APP_KEY case is what we're trying to detect at first launch).
2. Sentinel writes the timestamp of generation; a recovery diagnostic command can read it.
3. If sentinel exists but APP_KEY is empty, FAIL LOUD ("APP_KEY is empty but sentinel exists — refusing to overwrite; restore from backup or delete sentinel to confirm") — don't silently regenerate.
4. The Doctor panel surfaces "APP_KEY generated at <date>" so users can spot when it changes.
**Warning signs:** A code path that calls `key:generate --force` without checking the sentinel. Tests that pass a "delete the sentinel" assertion but don't test "sentinel-exists-but-APP_KEY-empty-fails-loud".

### Pitfall 6: macOS smoke test fails because Gatekeeper blocks unsigned/ad-hoc-signed binary

**What goes wrong:** CI runs `open /Applications/beatrax.app` after install; macOS 15+ Gatekeeper blocks ad-hoc-signed binaries that have the `com.apple.quarantine` xattr; the launch fails with no clear error in CI logs; the smoke test times out.
**Why it happens:** When the `.dmg` is downloaded by an HTTP client (or copied via `cp` from one user space to another), macOS sets the quarantine xattr. Ad-hoc-signed binaries (without a Developer ID and without notarization) are blocked outright on macOS 15.1+ by default.
**How to avoid:** Per CONTEXT.md Section B, the smoke test step explicitly strips quarantine: `xattr -d com.apple.quarantine /Applications/beatrax.app` immediately before launching. (This is for the SMOKE TEST ONLY — real users get the right-click → Open workaround per A-05 README copy.) Alternative: download the artifact via `gh run download` which doesn't apply quarantine.
**Warning signs:** macOS job logs show "killed: 9" on launch. `codesign --verify --verbose /Applications/beatrax.app` reports ad-hoc signature. `spctl --assess --type execute /Applications/beatrax.app` says "rejected".

### Pitfall 7: `softprops/action-gh-release` v3 vs v2 Node runtime mismatch

**What goes wrong:** Plans recommend `@v3` based on "newest is best" reflex; GitHub-hosted runners that haven't migrated to Node 24 fail with "Unsupported runtime"; release job fails on every tag push.
**Why it happens:** v3.0 was released April 2026 specifically for Node-24 runtimes; v2.6.2 is the latest Node-20 line. Both are current; v2.6.2 is broader-compatible.
**How to avoid:** Use `@v2.6.2` (pin to its SHA) until GitHub-hosted runner Node baseline confirmed at 24. The release notes for v3 explicitly say "if you still need the last Node 20-compatible line, stay on v2.6.2".
**Warning signs:** Workflow YAML references `@v3` without confirming Node-24 runner availability.

### Pitfall 8: Hippocratic 3.0 SPDX identifier rejected by composer validator

**What goes wrong:** `composer install` or `composer validate` fails because `"license": "Hippocratic-3.0"` is not a recognized SPDX identifier (the upstream spdx/license-list-XML#1393 issue is open but unresolved as of search results).
**Why it happens:** Hippocratic 2.1 IS in the SPDX list; 3.0 is NOT yet. Composer's bundled spdx-licenses validator returns "invalid".
**How to avoid:** Set `"license": "Hippocratic-3.0"` in composer.json AND understand that `composer validate` may emit a WARNING (not an error). If it's an error, two options: (a) use `"license": "proprietary"` (false claim — Hippocratic IS source-available) — NOT RECOMMENDED, or (b) use array form `"license": ["Hippocratic-3.0"]` which Composer may accept more leniently. Document the rationale in NOTICE.md. **Decision needed in Plan 17-07.** [ASSUMED — validator behavior depends on composer version; verify in plan]
**Warning signs:** `composer validate --strict` failing with "license is not a valid SPDX identifier".

---

## Code Examples

### Example 1: `.github/workflows/ci.yml` (modified — matrix widen)

```yaml
# Source: existing .github/workflows/ci.yml + D-12 + REQUIREMENTS.md CI-01
# Single diff: matrix `php` widens from ['8.4'] to ['8.4', '8.5'].
# No other shape changes — every other line stays identical.

name: ci

on:
  pull_request:
  push:
    branches:
      - main

permissions:
  contents: read

jobs:
  quality:
    name: quality (PHP ${{ matrix.php }})
    runs-on: ubuntu-latest
    timeout-minutes: 15
    strategy:
      fail-fast: false  # see both axes' failures, don't cancel the green one
      matrix:
        php: ['8.4', '8.5']  # WIDENED — was ['8.4']
    env:
      TZ: Europe/Amsterdam
    steps:
      - uses: actions/checkout@<PIN-TO-SHA>  # v4
      - uses: shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc  # v2.37.1
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, sqlite3, pdo_sqlite, bcmath, gd, intl, xml, dom, fileinfo, tokenizer, json
          coverage: none
          tools: composer:v2
      - uses: actions/cache@<PIN-TO-SHA>  # v4
        with:
          path: ~/.composer/cache
          key: composer-${{ runner.os }}-${{ matrix.php }}-${{ hashFiles('**/composer.lock') }}
          restore-keys: |
            composer-${{ runner.os }}-${{ matrix.php }}-
      - run: composer install --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --memory-limit=1G --no-progress
      - env: { APP_ENV: testing }
        run: php artisan test --parallel
```

### Example 2: `.github/workflows/security.yml` — gitleaks on every PR

```yaml
# Source: gitleaks/gitleaks-action v2.3.9 docs + CI-05 + D-50
# Triggers on every PR (no secrets exposed — pull_request is safe).
# Runs on push to main as a regression catch.

name: security

on:
  pull_request:
  push:
    branches:
      - main

permissions:
  contents: read

jobs:
  gitleaks:
    name: gitleaks (secret scan)
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - uses: actions/checkout@<PIN-TO-SHA>  # v4
        with:
          fetch-depth: 0  # full history for accurate scanning
      - uses: gitleaks/gitleaks-action@<PIN-TO-SHA>  # v2.3.9
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          # GITLEAKS_LICENSE not needed for personal-account repos
          # (verify nightworksio is personal in the security walkthrough)
```

### Example 3: `.github/CODEOWNERS`

```
# Source: D-50 + GitHub Docs "About code owners"
# Requires "Require review from Code Owners" enabled in branch protection.

# Workflow files — must be reviewed by repo owner before merge.
/.github/workflows/  @<gh-username>
/.github/CODEOWNERS  @<gh-username>
/.github/dependabot.yml @<gh-username>

# Security-critical paths
/scripts/nativephp_*.php  @<gh-username>
/Modules/Core/Internal/Bootstrap/EnsureAppKey.php  @<gh-username>
/Modules/Core/Public/Services/SecretsColumnRegistry.php  @<gh-username>

# Default — everything else (no required review, but listed for clarity)
# *  @<gh-username>
```

### Example 4: `HealthController` for the `/health` smoke-test route (DI-only)

```php
// Source: D-13 + D-14 + CLAUDE.md DI-only rule
// File: Modules/Core/Public/Controllers/HealthController.php
// Module: Modules/Core

namespace Modules\Core\Public\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;

final readonly class HealthController
{
    public function __construct(
        private Config $config,
        private ConnectionInterface $db,
    ) {}

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'app_version' => (string) $this->config->get('nativephp.version', '0.0.0-dev'),
            'php_version' => PHP_VERSION,
            'sqlite_version' => (string) $this->db->selectOne('select sqlite_version() as v')->v,
            // NO 'timestamp' key — D-13 (would break deterministic CI assertions)
        ]);
    }
}
```

Route registration in `Modules/Core/Routes/web.php`:

```php
// Add ABOVE the existing `auth`-gated group:
Route::get('/health', HealthController::class)->name('health');
```

### Example 5: Counterparty Public contract + per-type resolution

```php
// Source: D-43 + D-44 + A-04 + Phase 16.1.2.1 known_counterparty_ibans
// File: Modules/Counterparties/Public/Contracts/CounterpartyResolver.php

namespace Modules\Counterparties\Public\Contracts;

use Modules\Counterparties\Public\Models\Counterparty;
use Modules\Ledger\Public\Models\Transaction;

interface CounterpartyResolver
{
    /**
     * Resolve a transaction to its canonical Counterparty.
     * Implementation walks the 7-step chain in Section I of CONTEXT.md
     * (self-account → known-IBAN-bridge → MerchantNameResolver →
     * personal-IBAN heuristic → government-keyword → bank-fee keyword
     * → unknown fallback).
     */
    public function resolveFor(Transaction $tx): Counterparty;
}
```

```php
// File: Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php
// All five resolver steps consume injected services — DI-only.

namespace Modules\Counterparties\Internal\Resolver;

use Modules\Auth\Public\Services\AccountIbanLookup;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Models\Counterparty;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Public\Models\Transaction;
use Modules\Onboarding\Public\Contracts\MerchantNameResolver;

final readonly class CounterpartyResolverService implements CounterpartyResolver
{
    public function __construct(
        private AccountIbanLookup $userAccounts,
        private ResolvesKnownCounterpartyIban $knownIbans,  // Phase 16.1.2.1
        private MerchantNameResolver $merchants,            // Phase 16.1
        // ... + dutch IBAN parser + government keyword matcher + bank fee matcher
    ) {}

    public function resolveFor(Transaction $tx): Counterparty
    {
        // Step 1: self-account check
        if ($this->userAccounts->matchesUserOwnIban($tx->counterparty_iban)) {
            return $this->upsert(CounterpartyType::SELF_ACCOUNT, /* ... */);
        }

        // Step 2: known-counterparty-IBAN bridge (PayPal SARL, ICS ABN AMRO, etc.)
        if ($kind = $this->knownIbans->kindForIban($tx->counterparty_iban)) {
            return $this->upsert(CounterpartyType::BANK, /* ... */);
        }

        // Step 3: merchant resolution
        if ($merchantName = $this->merchants->resolve($tx)) {
            return $this->upsert(CounterpartyType::MERCHANT, $merchantName);
        }

        // Step 4: personal-IBAN heuristic (P2P transfers)
        // Step 5: government keyword (BELASTINGDIENST, GEMEENTE, etc.)
        // Step 6: bank-fee keyword (KOSTEN KASOPNAME, RENTE, ...)
        // Step 7: unknown fallback

        return $this->upsert(CounterpartyType::UNKNOWN, /* ... */);
    }

    private function upsert(CounterpartyType $type, ...): Counterparty
    {
        // firstOrCreate-style upsert keyed on (user_id, type, identifier).
        // Slug collision handled with -2, -3 suffix per Claude's discretion.
    }
}
```

### Example 6: Ed25519 manifest verification (PHP-side using libsodium)

```php
// Source: A-06 + UPDATE-02 + PHP libsodium (sodium_crypto_sign_verify_detached)
// File: Modules/Core/Public/Services/ElectronUpdateChannel.php (excerpt)

namespace Modules\Core\Public\Services;

use SodiumException;

final class ManifestVerifier
{
    /**
     * Public key is hex-encoded (64 hex chars = 32 bytes per Ed25519 spec).
     * Embedded at build time via config; private key lives in CI repo secret.
     */
    public function __construct(
        private readonly string $publicKeyHex,
    ) {}

    /**
     * Verifies a detached Ed25519 signature over the manifest body.
     * Returns true if signature is valid, false otherwise.
     * Throws on malformed inputs.
     */
    public function verify(string $manifestBody, string $signatureHex): bool
    {
        try {
            $publicKey = sodium_hex2bin($this->publicKeyHex);
            $signature = sodium_hex2bin($signatureHex);
            return sodium_crypto_sign_verify_detached(
                $signature,
                $manifestBody,
                $publicKey,
            );
        } catch (SodiumException) {
            return false;  // malformed inputs = invalid signature, never throws to caller
        }
    }
}

// Companion Pest test (UPDATE-02 mandates this):
//
// it('rejects a tampered manifest', function () {
//     $verifier = new ManifestVerifier(publicKeyHex: TEST_PUBLIC_KEY);
//     $manifest = file_get_contents(__DIR__ . '/fixtures/latest.yml');
//     $signature = file_get_contents(__DIR__ . '/fixtures/latest.yml.sig.hex');
//
//     expect($verifier->verify($manifest, $signature))->toBeTrue();
//
//     // Flip one byte in the manifest
//     $tampered = $manifest[0] === 'a' ? 'b' . substr($manifest, 1) : 'a' . substr($manifest, 1);
//     expect($verifier->verify($tampered, $signature))->toBeFalse();
// });
```

### Example 7: GSD-leakage arch invariant (Pest)

```php
// Source: REL-05 + D-27 + CLAUDE.md project-skill-discovery
// File: Modules/Core/Tests/Boundary/GsdLeakageTest.php

use Symfony\Component\Finder\Finder;

it('contains no GSD references in runtime PHP code', function () {
    $finder = (new Finder())
        ->in(base_path('Modules'))
        ->in(base_path('app'))
        ->in(base_path('config'))
        ->name('*.php')
        ->notPath('tests')          // tests CAN mention GSD for context if needed
        ->notPath('Tests');

    $offenders = [];
    $patterns = [
        '/\.planning\b/',
        '/\bPLAN\.md\b/',
        '/\bRESEARCH\.md\b/',
        '/\bD-\d{2,3}\b/',          // CONTEXT.md decision codenames
        '/\bgsd[-_]/i',
        // Phase codename prefix patterns (e.g., "Phase 16.1.2.1-04"):
        '/\bPhase\s+\d+(\.\d+)*\b/i',
    ];

    foreach ($finder as $file) {
        $content = $file->getContents();
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $m)) {
                $offenders[] = $file->getRelativePathname() . ' :: ' . $m[0];
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'GSD leakage detected — these files reference planning artifacts: '
        . PHP_EOL . implode(PHP_EOL, $offenders),
    );
});

// Sibling test for Blade views with same patterns + route name + view-data keys.
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Tag-only action references (`@v4`) | SHA-pinned + tag-comment + Dependabot auto-bump | Q1 2026 supply-chain attacks (`tj-actions/changed-files`) | Phase 17 workflows MUST SHA-pin; this is the project's first opportunity to adopt the pattern |
| `pull_request_target` for PR quality jobs | `pull_request` for quality gates; tag-push for release | Cumulative — Symphony, pgai, openlit, Tinder advisories | Phase 17 workflow architecture sidesteps `pull_request_target` entirely |
| Apple Developer ID for macOS distribution | Ad-hoc signing + Gatekeeper-bypass walkthrough in install docs | Project-specific decision (A-01, 2026-05-27) to skip $99/yr | All macOS distribution work this phase routes through the existing ad-hoc hook; documentation absorbs the "explain to users why we don't pay Apple" burden |
| Authenticode (legacy) for Windows signing | Azure Trusted Signing — but we explicitly dropped both per A-01 | Industry shift 2024-2025 followed by project drop 2026-05-27 | Phase 17 Windows job is unsigned; SmartScreen reputation builds over time |
| `git filter-branch` for history rewriting | `git filter-repo` | git docs officially deprecated filter-branch ~2021 | Plan 17-17 uses filter-repo |
| Manual Gitea/GitHub release upload | `softprops/action-gh-release` with draft/prerelease/asset-glob | de facto standard since 2020+ | Plan 17-04 uses v2.6.2 |
| Webhook-based update polling | electron-updater + GitHub Releases provider | de facto Electron pattern since ~2019 | Already vendored via NativePHP |
| OS-cert-chain-only update verification | Ed25519 manifest signing for unsigned-OSS-Electron-app pattern | 2024-2025 (Joplin, Logseq, ElectronSafeUpdater) | UPDATE-02 + A-06 adopt this pattern |
| `Hippocratic-2.1` SPDX identifier | `Hippocratic-3.0` — not yet officially registered as of 2026-05-27 | Pending SPDX list update | Plan 17-07 ships `Hippocratic-3.0` with explanatory NOTICE.md; accept composer validator warning |

**Deprecated/outdated:**

- `git filter-branch` — slow, dangerous defaults, officially deprecated in git docs.
- Tag-only action references — actively unsafe in 2026; SHA pinning is the bar.
- `pull_request_target` with PR-code checkout — known exploit class.

---

## Assumptions Log

The following claims in this research are tagged `[ASSUMED]` — verified against ecosystem knowledge but NOT confirmed against authoritative source in this session. The planner and discuss-phase should treat each as a candidate for user confirmation before locking into a plan.

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | First-launch APP_KEY sentinel pattern is project-defined (no Laravel ecosystem standard exists) | Pattern 4 + Pitfall 5 | Low — recommendation is to use a file sentinel under UserDataPathService-resolved storage path; if Laravel ships a standard later, swap |
| A2 | `Hippocratic-3.0` composer validator behavior — may be WARNING, may be ERROR depending on composer version | Pitfall 8 + REL-01 | Low — Plan 17-07 will verify at implementation time and choose between strict-license + warning suppress vs. array-form license vs. proprietary-with-NOTICE; user picks |
| A3 | `nightworksio` is a personal account, not an organization (relevant for gitleaks license requirement) | Standard Stack table for gitleaks-action | Medium — if it's an org, a free trial license is needed before gitleaks-action will run; one-time setup, not a blocker |
| A4 | NativePHP / electron-builder Windows job produces both `.exe` (NSIS installer) and `.msi` — verify what current `electron-builder.mjs` config emits | Architecture Diagram | Low — empirically verifiable in Plan 17-04; current default is likely `.exe` only (NSIS) which is fine |
| A5 | `electron-updater.verifyUpdateCodeSignature` extension point exists and is the right hook for Ed25519 layering | Pitfall 3 | Medium — alternative is a wrapper service in `ElectronUpdateChannel`; the wrapper pattern is more robust and is what ElectronSafeUpdater uses |
| A6 | `nativephp/php-bin` still ships PHP 8.4 only (no 8.5 builds yet) as of 2026-05-27 | Project Constraints table | Low — verify at Plan 17-04 time; if 8.5 builds exist, the dev pin can move and the CI matrix's 8.4 axis becomes the "legacy support" axis |
| A7 | Sodium PHP extension functions are available in PHP 8.4 + 8.5 by default | Example 6 | Very low — libsodium has been bundled with PHP since 7.2 |
| A8 | Counterparty type taxonomy + 7-step resolver chain (CONTEXT.md Section I) is fully specified — no gaps | Example 5 + phase_requirements | Medium — needs careful Pest unit coverage to lock the order; Plan 17-06 budget should accommodate |

Each `[ASSUMED]` claim is captured here so the planner can decide whether to (a) verify before planning, (b) gate behind a checkpoint:human-verify task, or (c) accept as low-risk and proceed.

---

## Open Questions

1. **Should Ed25519 signing happen in PHP (composer step in CI) or Node (electron-builder afterPack hook)?**
   - What we know: PHP's `sodium_*` is sufficient; ElectronSafeUpdater uses Node-side `@noble/ed25519`.
   - What's unclear: easier to integrate with the existing release.yml shape if it's PHP (no extra setup-node step); easier to integrate with electron-updater verification if it's Node (same toolchain).
   - Recommendation: PHP signing in `scripts/sign_update_manifest.php`, called from a release.yml step after artifact download; verification in PHP in `ElectronUpdateChannel`. Single language, single trust surface.

2. **Does the smoke test need to assert anything beyond HTTP 200 + correct JSON shape?**
   - What we know: D-13 + D-14 specify HTTP `/health` probe; D-15 says failure fails the whole release.
   - What's unclear: whether to assert `app_version === tag-without-v` (verifies D-03 tag-as-source-of-truth), and whether to assert `php_version` starts with `8.4` or `8.5` per the bundled runtime.
   - Recommendation: assert both. Both are mechanically simple and catch real regressions (version-injection bug, wrong PHP bundle shipped).

3. **What's the `electron-updater` `channel` value mapping to `v0.x-rc.N` vs `v0.x` tags?**
   - What we know: D-04 specifies two channels (`stable` + `preview`); RC tags publish immediately to preview.
   - What's unclear: how `softprops/action-gh-release`'s `prerelease: true` interacts with electron-updater's `channel` discovery. Does electron-updater treat `prerelease` releases as the `beta` channel by default? Or do we need explicit `channel` config in `electron-builder.mjs`'s `publish` block?
   - Recommendation: Plan 17-04 + Plan 17-05 verify by tagging `v0.1.0-rc.1` first and observing electron-updater behavior; document in `.docs/cicd/release-cadence.md`.

4. **Should the GSD-leakage arch test (`noGsdLeakage`) also scan `.docs/` content?**
   - What we know: `.docs/` is committed and public-visible.
   - What's unclear: whether ADRs in `.docs/adr/` SHOULD mention historical phase codenames (for traceability) or NEVER (for hygiene).
   - Recommendation: scan `.docs/` for `.planning/` and `PLAN.md` (these are gitignored, must not leak), but ALLOW phase numbers + "GSD" mentions (it's documentation, not runtime code). Plan 17-08 + Plan 17-10 coordinate the test regex.

5. **What's the right release-asset naming convention?**
   - What we know: existing builds produce `<name>-<version>-<platform>.<ext>` per electron-builder default.
   - What's unclear: whether to standardize as `beatrax-0.1.0-mac-arm64.dmg` + `beatrax-0.1.0-mac-x64.dmg` for both Apple Silicon + Intel, or only Apple Silicon (since the bundled PHP is universal binary).
   - Recommendation: ship both arch slices on macOS unless `nativephp/php-bin` is confirmed universal. Plan 17-04 verifies.

6. **Is `gh release view` the right verification step for "did the release publish correctly?" in the smoke flow?**
   - What we know: smoke test launches the bundled app and curls `/health`.
   - What's unclear: post-publish, do we have a job that verifies the published release is downloadable + the latest.yml + .sig are accessible via the GitHub CDN URL?
   - Recommendation: add a Job 4 "post-publish verify" that runs after the publish job, downloads the release artifacts via `gh release download`, and re-runs Ed25519 verification on the published manifest. Catches publish-time corruption.

7. **What hosts the Ed25519 public key?**
   - What we know: public half is committed; private half is in a GitHub repo secret.
   - What's unclear: WHERE in the repo the public key lives. Options: hardcoded in `config/nativephp.php`, in a dedicated `config/auto_update.php`, or in a const in `ElectronUpdateChannel` itself.
   - Recommendation: dedicated `config/auto_update.php` with `'publisher_public_key_hex'` key. Easier to discover; gives the planner a clear "this is where the trust anchor lives" file.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.4 (dev box) | Local Pint/Larastan/Pest runs | ✓ | (per composer.json `^8.4`) | — |
| PHP 8.5 (dev box) | Local 8.5-axis validation | likely ✓ | check `php -v` | use docker if missing |
| Composer 2.x | All composer commands | ✓ | (per CI yaml `composer:v2`) | — |
| Git | All workflows | ✓ | system git | — |
| `git-filter-repo` | Plan 17-17 history purge | ✗ | — | install via `pip install --user git-filter-repo` or `brew install git-filter-repo` |
| `nektos/act` (local) | Local workflow iteration | ✗ | — | optional — fallback is push-to-throwaway-branch |
| `slopcheck` | Package legitimacy audit | ✓ | 0.6.1 at `/Users/wesselverheij/.local/bin/slopcheck` | — |
| `gh` CLI | Verifying releases + pin SHAs | likely ✓ | check `gh --version` | use GitHub UI |
| Node.js + npm | electron-builder + nativephp/electron + slopcheck install | ✓ | (per package.json) | — |
| macOS-14 runner | release.yml macOS job | ✓ (GitHub-hosted) | provided by GitHub | — |
| Windows-2025 runner | release.yml Windows job | ✓ (GitHub-hosted) | provided by GitHub | — |
| Ubuntu-24.04 runner | release.yml Linux job + PR gate | ✓ (GitHub-hosted, `ubuntu-latest` symlinks here) | provided by GitHub | — |
| GitHub repo `nightworksio/beatrax` | All workflows + release publish | ✓ (private per A-07) | — | flips to public in Plan 17-19 |
| Ed25519 keypair | Sign + verify manifests | ✗ — generate in Plan 17-05 | — | generation via `sodium_crypto_sign_keypair()` one-liner |
| Apple Developer ID | macOS notarization | n/a (dropped per A-01) | — | ad-hoc signing (existing) |
| Azure Trusted Signing | Windows signing | n/a (dropped per A-01) | — | unsigned (existing-pattern accepted) |

**Missing dependencies with no fallback:** none.

**Missing dependencies with fallback:**

- `git-filter-repo` — install before Plan 17-17 lands.
- `nektos/act` — optional local quality-of-life tool.
- Ed25519 keypair — Plan 17-05's first task.

---

## Validation Architecture

(Required per `.planning/config.json` — `workflow.nyquist_validation: true`.)

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.x (PHPUnit 11 engine), pestphp/pest-plugin-arch 4.x, pestphp/pest-plugin-laravel 4.x |
| Config file | `phpunit.xml` at repo root |
| Quick run command | `php artisan test --filter=<TestName> -x` |
| Full suite command | `php artisan test --parallel` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CI-01 (8.4 axis) | Larastan + Pint + Pest pass on PHP 8.4 in CI | CI workflow integration | `act push -W .github/workflows/ci.yml --matrix php:8.4` (locally) OR observe ci.yml run in PR | ✅ ci.yml exists; matrix widening is the change |
| CI-01 (8.5 axis) | Larastan + Pint + Pest pass on PHP 8.4 in CI | CI workflow integration | as above with `--matrix php:8.5` | ❌ Wave 0 — matrix widen + 8.5-axis green proof |
| CI-02 (release flow) | Tag-push → 3 platform builds → publish | manual + CI integration | `git tag v0.1.0-rc.1 && git push --tags` → observe release.yml run | ❌ Wave 0 — release.yml does not exist |
| CI-05 (gitleaks) | gitleaks-action blocks known-bad fixture in a PR | CI workflow + fixture | Create a feature branch with a fake secret in a fixture file, open PR, observe gitleaks job fails | ❌ Wave 0 — security.yml does not exist; need fixture |
| CI-05 (CODEOWNERS) | PR touching `.github/workflows/*` requires owner review | CI + GitHub branch protection | manual: open a PR modifying a workflow, verify required review | ❌ Wave 0 — CODEOWNERS + branch-protection rule need creation |
| CI-05 (pull_request_target safety) | No `pull_request_target` workflow exists; release.yml uses only tag-push | static check | `grep -r "pull_request_target" .github/workflows/` → must return zero results | ✅ trivially — no current workflow uses it |
| CI-06 (APP_KEY regen) | Fresh install with empty APP_KEY → key:generate runs → sentinel created | Pest feature | `pest Modules/Core/Tests/Feature/EnsureAppKeyTest.php` | ❌ Wave 0 — EnsureAppKey + test do not exist |
| CI-06 (sentinel-exists-fail-loud) | Sentinel exists + APP_KEY empty → throws | Pest feature | same file | ❌ Wave 0 |
| UPDATE-01 (channel selection) | ElectronUpdateChannel resolves stable vs preview correctly | Pest unit | `pest Modules/Core/Tests/Unit/ElectronUpdateChannelTest.php` | ❌ Wave 0 |
| UPDATE-02 (Ed25519 verify) | Valid signature → verified; tampered manifest → rejected | Pest unit | `pest Modules/Core/Tests/Unit/ManifestVerifierTest.php` | ❌ Wave 0 — Pest test mandated by REQUIREMENTS.md |
| UPDATE-03 (banner integration) | `system_alerts.kind='update.available'` row → SystemAlertsBanner renders verbatim copy | Livewire test | `pest Modules/Core/Tests/Feature/SystemAlertsUpdateKindsTest.php` | ❌ Wave 0 |
| UPDATE-03 (skipVersion wire method) | clicking Skip persists in user_preferences + acknowledges | Livewire test | same file | ❌ Wave 0 |
| REL-01 (License) | LICENSE contains Hippocratic 3.0 verbatim; composer.json declares SPDX | static check | `grep -c "Hippocratic License 3.0" LICENSE` + `composer validate` | ❌ Wave 0 |
| REL-05 (noGsdLeakage) | runtime PHP/Blade/route/view-data has no `.planning/` / `PLAN.md` / `D-NN` refs | Pest arch | `pest Modules/Core/Tests/Boundary/GsdLeakageTest.php` | ❌ Wave 0 — arch test must land at end of Plan 17-10 |
| REL-07 (noSecretsInLivewireSnapshot) | no Livewire component exposes a SecretsColumnRegistry entry | Pest arch | `pest Modules/Core/Tests/Boundary/SecretsInSnapshotTest.php` | ❌ Wave 0 |
| REL-08 (data-locations page) | `/help/data-locations` renders with resolved paths | Livewire feature | `pest Modules/Core/Tests/Feature/HelpDataLocationsTest.php` | ❌ Wave 0 |
| Counterparty (resolver chain) | 7-step resolver returns correct type for each input class | Pest unit | `pest Modules/Counterparties/tests/Unit/CounterpartyResolverTest.php` | ❌ Wave 0 |
| Counterparty (privacy defaults) | personal-type IBAN never appears in lists, URLs, page titles | Pest feature | `pest Modules/Counterparties/tests/Unit/PrivacyDefaultsTest.php` | ❌ Wave 0 |
| Counterparty (slug collision) | duplicate canonical names get -2/-3 suffix | Pest unit | `pest Modules/Counterparties/tests/Unit/SlugCollisionTest.php` | ❌ Wave 0 |
| Counterparty (index/profile/triage) | all three pages render correctly with seeded data | Livewire feature | `pest Modules/Counterparties/tests/Feature/*` | ❌ Wave 0 |
| `BoundaryArchTest::noNativePhpImportsOutsideDesktopModule` | unchanged invariant | already-green | `pest Modules/Desktop/Tests/Boundary/NativePhpBoundaryTest.php` | ✅ exists |
| `BoundaryArchTest::noAuthFacadeOrHelper` | unchanged invariant | already-green | (existing test) | ✅ exists |
| `BoundaryArchTest::noHorizonImportsInShippedBuildCode` | unchanged invariant | already-green | (existing test) | ✅ exists |

### Sampling Rate

- **Per task commit:** `php artisan test --filter=<test-class-touched> -x` (sub-second to seconds; targeted)
- **Per wave merge:** `php artisan test --parallel` (full suite; ~2-3 min on this project's current size)
- **Phase gate:** Full suite green BEFORE `/gsd:verify-work`; in addition, `vendor/bin/pint --test` + `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` must be green (same gates CI enforces). For release-workflow validation: run `act push -W .github/workflows/ci.yml` locally; for full release.yml validation: tag `v0.1.0-rc.1` and observe.

### Wave 0 Gaps

- [ ] `Modules/Core/Public/Controllers/HealthController.php` — required by Plan 17-04
- [ ] `Modules/Core/Routes/web.php` — add `/health` route
- [ ] `Modules/Core/Internal/Bootstrap/EnsureAppKey.php` + Pest feature test — CI-06
- [ ] `Modules/Core/Public/Services/ElectronUpdateChannel.php` + unit test — UPDATE-01
- [ ] `Modules/Core/Public/Services/ManifestVerifier.php` + unit test (tamper assertion) — UPDATE-02
- [ ] `Modules/Core/Public/Services/SecretsColumnRegistry.php` — REL-07
- [ ] `Modules/Core/Tests/Boundary/GsdLeakageTest.php` — REL-05
- [ ] `Modules/Core/Tests/Boundary/SecretsInSnapshotTest.php` — REL-07
- [ ] `Modules/Core/Resources/views/livewire/help/data-locations.blade.php` + Volt SFC + Pest — REL-08
- [ ] Three new `system_alerts.kind` rows (`update.available`/`update.stale`/`update.critical`) + partials + wire method `skipVersion` + Pest — UPDATE-03
- [ ] `.github/workflows/release.yml` — CI-02
- [ ] `.github/workflows/security.yml` (or inline gitleaks job in ci.yml) — CI-05
- [ ] `.github/CODEOWNERS` — CI-05
- [ ] `.env.bundled` template — CI-06
- [ ] `LICENSE`, `NOTICE.md`, `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md` — REL-01/02
- [ ] `README.md` rewrite + install-bypass sections (per UI-SPEC) — REL-03 + A-05
- [ ] Brand exports `.icns`, `.ico`, `logo-512.png`, favicon — REL-04
- [ ] `Modules/Counterparties/` full module — A-04 scope
- [ ] `.docs/` tree skeleton + `_template/` + first ~10-15 ADRs — D-31..D-34
- [ ] `.gitignore` modification (add `.planning/`) — D-37 (post-purge)
- [ ] Framework install for `git-filter-repo` on dev box — pre-Plan 17-17
- [ ] `scripts/sign_update_manifest.php` for CI Ed25519 signing — UPDATE-02

*(All other test infrastructure already exists. Pest 4 + plugins, phpunit.xml, full module test scaffolding, Larastan config — all in place from prior phases.)*

---

## Security Domain

(Required per `.planning/config.json` — security_enforcement not explicitly disabled.)

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V1 Architecture | yes | Module Public/Internal split + DI-only rule + arch invariants (already established) |
| V2 Authentication | no (this phase) | Phase 12 (Fortify) ships this; Phase 17 doesn't touch auth |
| V3 Session Management | no | Phase 12 handles via Laravel session driver |
| V4 Access Control | partial — CODEOWNERS enforces repo-level access; CI-06 sentinel + APP_KEY ownership ties to access | CODEOWNERS + branch protection + `EnsureDeveloperMode` (existing) |
| V5 Input Validation | yes — webhook receivers / OAuth callbacks already use validators; Phase 17 only adds `/health` (no input) and Livewire components (Livewire built-in validation rules) | Existing Laravel validators; Livewire `#[Validate]` attributes |
| V6 Cryptography | yes — load-bearing for UPDATE-02 (Ed25519) + CI-06 (APP_KEY) | PHP libsodium (`sodium_crypto_sign_*`) — never hand-roll; APP_KEY via `key:generate` |
| V7 Error Handling & Logging | yes — Monolog redaction processor (Phase 16 DEVUI-04) extends to cover any new secret paths | Existing redaction; new SecretsColumnRegistry feeds the same scrub set |
| V8 Data Protection | yes — oauth_secrets already encrypted; Counterparty model has no secrets-tagged fields | Existing APP_KEY-based encryption (MULTI-05) |
| V9 Communication | yes — HTTPS for `latest.yml` download (GitHub Releases CDN is HTTPS) + signed manifest | electron-updater uses HTTPS by default + new Ed25519 layer |
| V10 Malicious Code | yes — SHA-pin every third-party action; slopcheck on new deps | SHA pinning + slopcheck (already run) |
| V11 Business Logic | yes — fail-loud APP_KEY regeneration (sentinel-exists-fail-loud); fail-loud Ed25519 verification | Pitfall 5 + Pitfall 3 |
| V12 Files & Resources | yes — `/health` route is GET-only, no file ops | Auth-free GET; no upload surface |
| V13 API & Web Service | yes — `/health` returns deterministic JSON; no auth bypass elsewhere | Existing route middleware |
| V14 Configuration | yes — `.env.bundled` template MUST NOT contain real secrets | Manual review + gitleaks scan |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Fork PR exfiltrates signing secrets | Information Disclosure | `release.yml` uses `push: tags:` only; PR gate uses `pull_request` (no secrets). Pitfall 1. |
| Mutable GitHub Action tag is hijacked | Tampering + Elevation of Privilege | SHA-pin every third-party action + Dependabot auto-bumps. Pitfall 2. |
| Tampered update manifest delivers malicious binary | Tampering + Spoofing | Ed25519 manifest verification + SHA-512 binary verification. Pitfall 3. UPDATE-02 mandates the test. |
| Stolen Ed25519 private key | Tampering + Spoofing | Key lives in GitHub repo secret (encrypted at rest by GitHub); rotation procedure documented in `.docs/runbooks/release-cut.md`; in event of suspected compromise, ship a new public key in an emergency release (users running pre-rotation versions will not auto-update, must reinstall manually — accepted v0.x risk). |
| Secret committed to repo | Information Disclosure | gitleaks-action on every PR + push protection (free for public repos). CI-05. |
| `key:generate` regenerates over existing user data | Tampering + DoS | File sentinel + fail-loud-on-sentinel-exists-empty-APP_KEY. Pitfall 5. |
| `.planning/` content leaks to public repo after purge | Information Disclosure | `.gitignore` add immediately after purge; arch test `noGsdLeakage` prevents regression. Plan 17-17. |
| `oauth_secrets` value rendered via Livewire snapshot | Information Disclosure | `SecretsColumnRegistry` + arch invariant `noSecretsInLivewireSnapshot`. REL-07. |
| OS Gatekeeper bypass instructions misused | n/a (user-side, not threat to project) | README install-bypass copy frames it as standard practice for indie macOS apps; link to legal/license-rationale.md for context. A-05. |
| Auto-update over insecure transport | Tampering | electron-updater uses HTTPS to GitHub Releases CDN; no override path. |

---

## Sources

### Primary (HIGH confidence)

- [`.github/workflows/ci.yml` (in-repo)](file:///Users/wesselverheij/Development/diederik/.github/workflows/ci.yml) — current PR-gate skeleton; matrix-widening target
- [`scripts/nativephp_force_adhoc_signing.php` (in-repo)](file:///Users/wesselverheij/Development/diederik/scripts/nativephp_force_adhoc_signing.php) — ad-hoc signing hook (unchanged per A-01)
- [`config/nativephp.php` (in-repo)](file:///Users/wesselverheij/Development/diederik/config/nativephp.php) — version + prebuild hooks registry
- [composer.json (in-repo)](file:///Users/wesselverheij/Development/diederik/composer.json) — `"php": "^8.4"`, full dep tree
- [`.github/dependabot.yml` (in-repo)](file:///Users/wesselverheij/Development/diederik/.github/dependabot.yml) — already configured for composer + npm + github-actions
- [.planning/phases/17-ci-cd-pipeline-code-signing/17-CONTEXT.md (in-repo)](file:///Users/wesselverheij/Development/diederik/.planning/phases/17-ci-cd-pipeline-code-signing/17-CONTEXT.md) — user decisions including 2026-05-27 evening amendments
- [.planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md (in-repo)](file:///Users/wesselverheij/Development/diederik/.planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md) — visual + interaction contract for the 6 UI surfaces
- [softprops/action-gh-release releases](https://github.com/softprops/action-gh-release/releases) — verified v2.6.2 + v3.0.0 split
- [shivammathur/setup-php releases](https://github.com/shivammathur/setup-php/releases) — verified 2.37.1 + PHP 8.5 support
- [gitleaks/gitleaks-action](https://github.com/gitleaks/gitleaks-action) — verified v2.3.9 + license-free for personal accounts
- [newren/git-filter-repo](https://github.com/newren/git-filter-repo) — modern filter-branch replacement
- [GitHub Docs — Secure use reference](https://docs.github.com/en/actions/reference/security/secure-use) — SHA-pinning + supply-chain hardening
- [GitHub Docs — About code owners](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-code-owners) — CODEOWNERS + branch protection interaction

### Secondary (MEDIUM confidence)

- [StepSecurity — Pinning GitHub Actions](https://www.stepsecurity.io/blog/pinning-github-actions-for-enhanced-security-a-complete-guide) — Q1 2026 attacks reference
- [OpenSSF — Mitigating Attack Vectors in GitHub Workflows](https://openssf.org/blog/2024/08/12/mitigating-attack-vectors-in-github-workflows/) — `pull_request_target` exploit class
- [Doyensec — Building a Secure Electron Auto-Updater](https://blog.doyensec.com/2026/02/16/electron-safe-updater.html) — Ed25519 reference implementation for unsigned Electron apps
- [electron-builder — Auto Update](https://www.electron.build/auto-update) — `disableDifferentialDownload` + provider config
- [Laravel Docs — Artisan key:generate](https://laravel.com/docs/12.x/encryption) — `--force` flag behavior
- [Composer SPDX licenses repo](https://github.com/composer/spdx-licenses) — Hippocratic-3.0 not yet registered
- [git-tower — git filter-repo guide](https://www.git-tower.com/learn/git/faq/git-filter-repo) — fresh-clone requirement + remote-removal safety
- [nektos/act](https://github.com/nektos/act) — local workflow testing

### Tertiary (LOW confidence — flagged ASSUMED)

- First-launch APP_KEY sentinel pattern: project-defined; no Laravel ecosystem standard found in search.
- electron-updater `verifyUpdateCodeSignature` extension API surface: assumed based on ecosystem reading; verify at Plan 17-05 implementation time.
- Behavior of `composer validate --strict` against unregistered SPDX `Hippocratic-3.0`: assumed warning; may be error.

---

## Metadata

**Confidence breakdown:**

- Standard CI/CD stack (actions, versions, SHA-pinning pattern): **HIGH** — verified via multiple official sources + recent release docs
- Workflow structure (release.yml, ci.yml, security.yml shape): **HIGH** — derived from CONTEXT.md + existing ci.yml shape + canonical GitHub Actions patterns
- Ed25519 signing pattern: **MEDIUM** — well-established cryptographically; project-specific integration with electron-updater needs verification at implementation
- First-launch APP_KEY sentinel: **MEDIUM** — Laravel idioms cover the building blocks; the sentinel logic is project-defined; failure modes documented
- Counterparties module architecture: **HIGH** — UI-SPEC.md is comprehensive + reuses established patterns (Phase 16.1 MerchantNameResolver, Phase 16.1.2.1 known_counterparty_ibans)
- Pitfalls + threat patterns: **HIGH** — drawn from current (2026) attack surface analysis + multiple credible sources
- `.docs/` structure: **HIGH** — mirrors happklaar reference + CONTEXT.md D-31..D-34
- `.planning/` purge mechanics: **HIGH** — git filter-repo is well-documented standard tool
- Hippocratic 3.0 SPDX status: **MEDIUM** — upstream issue still open; plan-time decision needed
- Skill rename specifics: **HIGH** — D-40..D-42 + git status both show staged deletions

**Research date:** 2026-05-27
**Valid until:** 2026-06-27 (stable CI/CD tooling; recheck GitHub Actions versions at Plan 17-02 / Plan 17-04 implementation)
