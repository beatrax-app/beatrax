# Roadmap: diederik

## Milestones

- ✅ **v1.0 MVP** — Cross-Account Personal Finance Dashboard — Phases 1–11 (shipped 2026-05-19) — see [milestones/v1.0-ROADMAP.md](milestones/v1.0-ROADMAP.md)
- 🚧 **v2.0 Public Release** — Desktop Packaging, Multi-User, Developer Mode — Phases 12–21 (started 2026-05-19)

## Phases

<details>
<summary>✅ v1.0 MVP — Cross-Account Personal Finance Dashboard (Phases 1–11) — SHIPPED 2026-05-19</summary>

- [x] **Phase 1**: Foundation + ASN CSV Vertical Slice (7/7 plans)
- [x] **Phase 2**: ASN Statement Coverage (CAMT.053 + MT940) (5/5 plans)
- [x] **Phase 3**: ICS Cards + Multi-Currency Display (7/7 plans)
- [x] **Phase 4**: PayPal Ingestion + Transfer Detection (5/5 plans)
- [x] **Phase 5**: Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition) (7/7 plans) — completed 2026-05-16
- [x] **Phase 6**: Email Receipt Ingestion Infrastructure (9/9 plans)
- [x] **Phase 7**: Email Template Matchers + Categorization Learning (5/5 plans)
- [x] **Phase 8**: Recurring Detection + Fixed Payments View (5/5 plans)
- [x] **Phase 9**: Subscription Drift Detection + Alerts (5/5 plans)
- [x] **Phase 10**: Cash-Flow Forecasting + What-If Scenarios (6/6 plans) — completed 2026-05-18
- [x] **Phase 11**: Operational Hardening (5/5 plans) — completed 2026-05-19

Full phase details, success criteria, and plan breakdowns are preserved in [milestones/v1.0-ROADMAP.md](milestones/v1.0-ROADMAP.md).

</details>

### v2.0 Public Release — Desktop Packaging, Multi-User, Developer Mode (Phases 12–21)

v2.0 takes the validated v1.0 core value to a non-technical partner via a code-signed desktop installer, with an in-app developer console so power users (and contributors) keep full CLI access, under a Hippocratic-3.0 source-available license. The work is shell, activation, and release — not feature extension. The build order is dictated by research's strict dependency chain: multi-user activation must precede everything because the auth contract is load-bearing for every later phase; the `AppPaths` abstraction must precede NativePHP integration because hard-coded paths break the moment Electron boots; queue rewire must precede desktop shell because the shipped bundle cannot ship Redis; and the public-release boundary phase must run last because it depends on every earlier deliverable being in place to redact, license, and document.

- [x] **Phase 12: Multi-User Activation** — Real Fortify auth + per-user data isolation built on the dormant `user_id` schema; new `Modules/Auth/`; profile selector + recovery-codes + owner-resets-partner flow (completed 2026-05-20)
- [x] **Phase 13: AppPaths** — `UserDataPath` contract replaces every hard-coded `database_path()` / `storage_path()` / `base_path()` call so paths resolve under NativePHP's `Application::storagePath()` in shipped builds and project-rooted paths in Herd dev mode; arch test + CI grep gate forbid the raw helpers outside the service (completed 2026-05-20)
- [x] **Phase 14: Queue Rewire + Horizon Carve-out** — Shipped bundle moves to `database` queue driver + `database` cache locks; Horizon gated to `DIEDERIK_RUNTIME=herd` dev mode only; chain-resolution proven end-to-end on the new driver under concurrent load (completed 2026-05-22)
- [x] **Phase 15: Desktop Shell (NativePHP Integration)** — `nativephp/desktop ^2.2` integration producing signed-ready `.dmg` / `.msi` / `.AppImage` / `.deb` installers; native chrome (window/menu/tray/notifications/dark-mode); file-association handlers for `.eml` + `.csv`; new `Modules/Desktop/` quarantines every `Native\Laravel\*` import (completed 2026-05-23)
- [x] **Phase 16: Developer Mode UI** — New `Modules/DevMode/`; `is_developer` flag + `EnsureDeveloperMode` middleware; SAFE / DESTRUCTIVE artisan runner with live stdout streaming; log tailer with secret-redaction; bespoke queue inspector replaces Horizon dashboard for shipped builds; embedded Horizon iframe behind dev-runtime flag; ⌘K command palette (completed 2026-05-24)
- [ ] **Phase 17: CI/CD Pipeline + Code Signing** — `.github/workflows/ci.yml` PR gate (Larastan L10 strict + Pint + Pest on PHP 8.4 + 8.5 axes); `.github/workflows/release.yml` tag-triggered installer matrix; macOS Developer ID + Apple notarytool; Windows Azure Trusted Signing; encrypted secrets with environment scoping + gitleaks
- [ ] **Phase 18: Auto-Update Plumbing** — `electron-updater` consuming GitHub Releases via `ElectronUpdateChannel` service; Ed25519 publisher pin + signature verification on every download; "skip this version" / "you're on an old version" / critical-update banner UX
- [ ] **Phase 19: Public Release Boundary** — Hippocratic License 3.0 + `SECURITY.md` + `CONTRIBUTING.md` + `CODE_OF_CONDUCT.md`; README rewrite with the supplied SVG hero + per-platform install + screenshots; brand asset import (`resources/brand/logo.svg` + PNG / ICNS / ICO exports); GSD-leakage redaction sweep; deep Modules code review across 14 modules; renderer-JSON audit; "Where is my data?" docs page + export-everything UX
- [ ] **Phase 20: v1.0 UAT Close-Out** — Walk through 25 deferred UAT scenarios across Phases 03 / 04 / 06 / 08 / 11 with real data; resolve 3 `human_needed` verification artifacts; add regression coverage for every bug found
- [ ] **Phase 21: Invite-Only Beta Cycle** — Partner + 1-2 other testers; fresh-account onboarding tested on macOS + Windows; OAuth callback validation on partner browsers; 1-2 weeks of daily-use feedback; decision gate (open repo publicly or run another beta round)

## Phase Details

### Phase 12: Multi-User Activation

**Goal**: Two users can sign up, log in, log out, and each sees only their own data; the codebase enforces this via the existing `BelongsToUser` global scope + a DI-friendly `CurrentUserProvider` contract, with `Auth::user()` / `auth()` / `request()->user()` forbidden by arch test across every module.
**Slug:** `12-multi-user-activation`
**Mode:** mvp
**Depends on**: Nothing new (consumes v1.0's existing `Modules\Core\Public\Contracts\CurrentUser`, `Modules\Core\Public\Concerns\BelongsToUser`, `Modules\Core\Public\Scopes\UserScope`)
**Requirements**: MULTI-01, MULTI-02, MULTI-03, MULTI-04, MULTI-05, MULTI-06
**Success Criteria** (what must be TRUE):

  1. Two users can sign up, log in, and each see only their own transactions / chains / forecasts — verified by a cross-user 404-not-403 Pest test set covering every route registered in `Modules/*/Routes/web.php`
  2. The owner can reset a partner's password via the profile-selector UI (recovery-codes flow); the partner sees the new code without any SMTP dependency; `php artisan diederik:reset-password` CLI fallback works the same way
  3. Per-user OAuth secrets live in a SQLite-encrypted `oauth_secrets` table keyed by `user_id` (encrypted via `APP_KEY`); the legacy single-file `storage/app/secrets/imap.json` is migrated in-place; `OAuthSecretsRepository` swap is transparent to every existing EmailScan consumer
  4. `BoundaryArchTest::noAuthFacadeOrHelper` extends the DI-only rule — forbids `Auth::user()` / `auth()` / `request()->user()` / `request()->session()` across every module except `Modules\Core\Public\Services\CurrentUserService`
  5. The owner can switch profile to act as the partner (during debugging) via the app menu without a full logout/login dance — session lifecycle handled by Laravel session driver compatible with the upcoming NativePHP bundle

**Plans:** 8/8 plans complete

Plans:

- [x] 12-01-PLAN.md — Auth module skeleton + noAuthFacadeOrHelper arch invariant
- [x] 12-02-PLAN.md — users-schema reshape (email->username) + user_recovery_codes + oauth_secrets tables
- [x] 12-03-PLAN.md — username-based Fortify login/logout surface
- [x] 12-04-PLAN.md — first-user signup ceremony + recovery-code generation
- [x] 12-05-PLAN.md — signup race fix (CR-01) + force-password-change enforcement (CR-02) + owner-adds-partner
- [x] 12-06-PLAN.md — recovery-code redemption: /reset-password + CLI fallback + owner-resets-partner (CR-03)
- [x] 12-07-PLAN.md — OAuth secrets repository swap to per-user SQLite (MULTI-05)
- [x] 12-08-PLAN.md — profile switching/impersonation (MULTI-06) + cross-user 404-not-403 test set (MULTI-03)

### Phase 13: AppPaths

**Goal**: Every filesystem path the app reads or writes flows through a single injectable `UserDataPath` contract whose implementation defers to NativePHP's `Application::storagePath()` in shipped builds and the existing project-rooted paths in Herd dev mode; an arch test plus a CI grep gate guarantee no raw `database_path()` / `storage_path()` / `base_path()` call — or equivalent hard-coded string literal — survives outside that service.
**Slug:** `13-app-paths`
**Mode:** mvp
**Depends on**: Nothing new (a path-abstraction refactor over the existing v1.0 + Phase 12 codebase)
**Requirements**: PKG-01
**Success Criteria** (what must be TRUE):

  1. `BoundaryArchTest::noStoragePathHardCodedOutsideUserDataPathService` is green — no `database_path()` / `storage_path()` / `base_path()` call appears anywhere outside `Modules\Core\Public\Services\UserDataPathService`; CI grep gate enforces the same rule against string literals (`database.sqlite`, `storage/app/`)
  2. Running `php artisan migrate:fresh` under a simulated NativePHP env (`NATIVEPHP_STORAGE_PATH=<tmp>`) creates the SQLite file under the temp dir; `php artisan db:backup` writes to `<tmp>/storage/app/backups/`; OAuth secrets land at `<tmp>/storage/app/secrets/`; all proven by Pest feature test

**Plans:** 3/3 plans complete

Plans:

**Wave 1**

- [x] 13-01-PLAN.md — UserDataPathService (static-core + instance-delegate) + singleton binding + Wave-0 test scaffolding (arch invariant stub, simulated-env feature-test stub, `composer check:paths` gate)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 13-02-PLAN.md — migrate every production call site: 3 config files, Core backup/restore D-04 binding cleanup, EmailScan + Auth migration, OAuthClientWizardModal error-string de-hardcode

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 13-03-PLAN.md — fill the simulated-NativePHP-env feature test (migrate:fresh / db:backup / OAuth secrets) + verify arch invariant + CI grep gate green

### Phase 14: Queue Rewire + Horizon Carve-out

**Goal**: The shipped desktop bundle runs Laravel's `database` queue driver + `database` cache lock store; Horizon stays installed but only boots when `DIEDERIK_DEV_MODE=true` (developer's Herd box); chain resolution + email backfill + drift detection + recurring sweep + forecast all succeed under concurrent load on the new driver.
**Slug:** `14-queue-rewire-horizon-carveout`
**Mode:** mvp
**Depends on**: Phase 13 (the `jobs` / `failed_jobs` / `job_batches` tables must live at the new `UserDataPath`-rooted SQLite location), Phase 12 (per-user lock keys partition cleanly via `CurrentUser`)
**Requirements**: PKG-03
**Success Criteria** (what must be TRUE):

  1. `QUEUE_CONNECTION=database` is the shipped default; every `ShouldBeUniqueUntilProcessing` job's `uniqueVia()` reads `config('cache.locks_store')` which defaults to `'database'` in shipped builds and `'redis'` in dev mode — uniqueness lock is honored under both stores
  2. End-to-end Pest test imports a multi-month ASN CAMT.053 + ICS PDF + PayPal CSV against the `database` queue driver under concurrent dispatch and proves chain resolution completes without duplicate `chain_resolution_runs` rows
  3. `HorizonServiceProvider::boot()` early-exits when `config('app.dev_mode') !== true` so the `/horizon` route never registers in shipped builds; `BoundaryArchTest::noHorizonImportsInShippedBuildCode` invariant green
  4. `predis/predis` moves from `require` to `require-dev` in `composer.json` (since Redis is dev-only); shipped composer.lock produces a smaller dependency tree

**Plans:** 3/3 plans complete

Plans:

**Wave 1**

- [x] 14-01-PLAN.md — queue/cache/cache_locks framework migrations + published config/cache.php with locks_store key + config('app.dev_mode') wiring

**Wave 2** *(blocked on Wave 1)*

- [x] 14-02-PLAN.md — flip QUEUE_CONNECTION default to database + shared LockStore helper + migrate 9 jobs' uniqueVia() + SC1/SC2 tests

**Wave 3** *(blocked on Wave 1 + Wave 2)*

- [x] 14-03-PLAN.md — HorizonServiceProvider dev-mode gate + class_exists() registration guard + Horizon/Predis to require-dev + noHorizonImportsInShippedBuildCode invariant + SC3/SC4 tests

### Phase 15: Desktop Shell (NativePHP Integration)

**Goal**: The user can run `php artisan native:build` and produce an installable `.dmg` on macOS, `.msi/.exe` on Windows, and `.AppImage/.deb` on Linux; double-clicking the installer launches diederik as a native window with menu / dock-icon / system-tray / OS-notifications / dark-mode-follows-OS; double-clicking a `.eml` or `.csv` file in the OS opens diederik with an ingestion intent.
**Slug:** `15-desktop-shell-nativephp-integration`
**Mode:** mvp
**Depends on**: Phase 12 (auth must work inside the bundle) + Phase 13 (paths must resolve under `Application::storagePath()`) + Phase 14 (queue driver must not need Redis)
**Requirements**: PKG-04, PKG-05, PKG-06, PKG-07, PKG-08
**Success Criteria** (what must be TRUE):

  1. `php artisan native:build` on the developer's macOS box produces a `.dmg` that installs into `/Applications/diederik.app` and launches a native window showing the dashboard — verified by smoke test
  2. Native chrome is complete — window, dock/taskbar icon, app menu (File / Edit / View / Window / Help with standard items), system tray, OS notifications, dark mode follows OS; every `Native\Laravel\*` import is contained in `Modules/Desktop/` and forbidden elsewhere by `BoundaryArchTest::noNativePhpImportsOutsideDesktopModule`
  3. Double-clicking a `.eml` file on macOS / Windows / Linux opens diederik with the file routed via the new `FileOpenedFromOs` event to `Modules/Receipts`; double-clicking a `.csv` routes the same event to `Modules/Ingestion`
  4. The shipped bundle uses PHP 8.4 (until `nativephp/php-bin` 8.5 builds land); Larastan L10 strict + Pint + Pest all pass on PHP 8.4 — proven by adding an 8.4 axis to the CI matrix (built in Phase 17 but skeleton lands here)
  5. macOS Hardened Runtime entitlements file is configured with `com.apple.security.cs.allow-unsigned-executable-memory` + `com.apple.security.cs.disable-library-validation` so the bundled PHP runtime can execute under notarization

**UI hint**: yes
**Plans**: 7 plans

Plans:

**Wave 1**

- [x] 15-01-PLAN.md — NativePHP install + Modules/Desktop/ skeleton + arch invariant + Wave 0 scaffolding + launchable .dmg

**Wave 2** *(blocked on Wave 1)*

- [x] 15-05-PLAN.md — first-launch DB bootstrap + welcome/setup screens + brand icons + macOS entitlements + CI 8.4 axis
- [x] 15-06-PLAN.md — dark-theme infrastructure + Settings control + shell/Auth/Ledger/Import/Forecasting dark variants

**Wave 3** *(blocked on Wave 2)*

- [x] 15-02-PLAN.md — native chrome: window/menu/tray + context-aware OS notifications
- [x] 15-07-PLAN.md — dark variants for remaining modules + full-coverage arch guard

**Wave 4** *(blocked on Wave 3)*

- [x] 15-03-PLAN.md — bundled worker/scheduler supervision + worker-health alert + close-window prompt

**Wave 5** *(blocked on Wave 4)*

- [x] 15-04-PLAN.md — file-association spike + FileOpenedFromOs event + staging pages + single-instance/pending-intent

### Phase 16: Developer Mode UI

**Goal**: A user with `is_developer = true` can open an in-app Developer Console from the app menu and run whitelisted artisan commands with live stdout/stderr streaming, tail logs with OAuth-token redaction, inspect queue + failed-jobs + job-batches, run `diederik:doctor` from the UI, browse `system_alerts` + env snapshot + effective-config tree, and execute SELECT-only SQL — all gated by the `EnsureDeveloperMode` middleware + the `User::is_developer` flag; destructive commands require triple confirmation (Dev Mode on + Advanced toggle on + typed-app-name modal).
**Slug:** `16-developer-mode-ui`
**Mode:** mvp
**Depends on**: Phase 12 (`is_developer` flag needs `users` table + auth) + Phase 15 (Dev Mode UI is a desktop-runtime feature)
**Requirements**: DEVUI-01, DEVUI-02, DEVUI-03, DEVUI-04, DEVUI-05, DEVUI-06, DEVUI-07, DEVUI-08, DEVUI-09
**Success Criteria** (what must be TRUE):

  1. A signup whose user is the first row in the `users` table is automatically granted `is_developer = true`; an authenticated non-developer hitting `/dev/*` gets HTTP 404 (not 403); `BoundaryArchTest::everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` is green
  2. The developer can click `db:backup` in the artisan runner and see live stdout streaming to the page; running `db:restore` from the same runner requires the typed-app-name confirm modal + the Advanced toggle being on + Dev Mode being on (triple gate); every command execution writes a row to `dev_mode_audit` via `spatie/laravel-activitylog ^4.12`
  3. The log tailer streams `storage/logs/laravel.log` filtered through a Monolog redaction processor — `Authorization: Bearer …` headers, OAuth tokens, and any value reachable from the `oauth_secrets` table are scrubbed before render; proven by a Pest test that injects a known secret into a log line and asserts the redacted output never contains it
  4. The queue inspector replaces the v1.0 Horizon dashboard in shipped builds — reads `jobs` + `failed_jobs` + `job_batches` directly with retry / cancel / delete actions; the embedded Horizon iframe is reachable only when `DIEDERIK_RUNTIME=herd`
  5. The Doctor panel runs `diederik:doctor` from the UI and displays results inline; the SELECT-only SQL panel rejects any non-SELECT query at parse time + a schema viewer enumerates tables / columns / indexes
  6. The command palette (⌘K on macOS, Ctrl+K on Windows/Linux) opens from any page with fuzzy search across registered views + Dev Console commands (Linear/Raycast aesthetic)

**UI hint**: yes
**Plans**: 9 plans

Plans:

**Wave 1**

- [x] 16-01-PLAN.md — App-wide sidebar restructure + base layout polish + Tailwind v4 theme tokens

**Wave 2** *(blocked on Wave 1)*

- [x] 16-02-PLAN.md — diederik → beatrax full rename + Phase 12 impersonation surface removal

**Wave 3** *(blocked on Wave 2)*

- [x] 16-03-PLAN.md — DevMode module skeleton + EnsureDeveloperMode + Settings toggle + Wave 0 infra (DEVUI-01)

**Wave 4** *(blocked on Wave 3 — 16-04 → 16-04b SERIAL within wave per B-5 split)*

- [x] 16-04-PLAN.md — Artisan SAFE pipeline + CommandRegistry + Spawner (architecture b spawn-then-tail per D-16) + RunRegistry + FileTailer + ArtisanStreamController + cancel + advanced-toggle + missing commands (DEVUI-02 partial)
- [x] 16-04b-PLAN.md — Audit pipeline (SpatieAuditWriter + FinalizeRunAudit hook) + AuditEvent enum + TripleGateModal + DestructiveSpawnController + Queue::looping heartbeat (DI form) + ArtisanRunnerPage + AuditLogPage + sidebar Artisan + Audit enable (DEVUI-02 complete, DEVUI-03)

**Wave 5** *(blocked on Wave 4, parallel)*

- [x] 16-05-PLAN.md — Log tailer + Monolog redaction (belt+braces) + OAuth scrub-set (DEVUI-04)
- [x] 16-06-PLAN.md — Queue inspector + Horizon iframe + dashboard toast retarget (DEVUI-05, DEVUI-08)
- [x] 16-07-PLAN.md — Dev overview + Doctor + System snapshot + SELECT-only SQL + schema viewer (DEVUI-06, DEVUI-07)

**Wave 6** *(blocked on Wave 5)*

- [x] 16-08-PLAN.md — Command palette + sidebar Dev block live data + app-menu Developer submenu (DEVUI-09)

### Phase 16.1: Zero-friction first-run setup wizard, manual transaction renaming, payment-type classification (PIN vs online vs transfer vs direct-debit), and crowd-sourced merchant identification corpus (INSERTED)

**Goal:** Ship six tightly-coupled user-facing surfaces as one vertical MVP — a first-run `/setup` wizard, a click-italic merchant-rename popover with smart pattern generalization, a payment-type chip (PIN / online / transfer / direct-debit / cash / fee / refund / unknown) derived by a shared post-parse classifier stage, a crowd-sourced merchant identification corpus bundled as YAML with a `/community/mystery-merchants` browse destination + suggest-as-draft-PR flow via the system browser, an OAuth re-consent banner wired to the existing `SystemAlertsBanner`, and a power-user `Settings → Aliases` surface with live "Test against my transactions" preview + bulk-merge + YAML import/export — every locked decision (D-01 through D-25) implemented, no scope reduction.
**Requirements**: WIZ-01, WIZ-02, WIZ-03, WIZ-04, WIZ-05, WIZ-06, RENAME-01, RENAME-02, RENAME-03, PTYPE-01, PTYPE-02, PTYPE-03, CORPUS-01, CORPUS-02, CORPUS-03, CORPUS-04, CORPUS-05, OAUTH-RC-01, OAUTH-RC-02, OAUTH-RC-03, ALIAS-01, ALIAS-02, ALIAS-03, ALIAS-04, ALIAS-05 *(REQ-IDs proposed in 16.1-RESEARCH.md; planner registers them; a follow-up REQUIREMENTS.md update will fold them into the Traceability table)*
**Depends on:** Phase 16
**Plans:** 8/8 plans complete

Plans:

**Wave 1**

- [x] 16.1-01-PLAN.md — Schema foundation: Onboarding + Community modules + 5 migrations (wizard_progress, community_merchant_mappings, transactions.payment_type, merchant_aliases, users.community_settings) + PaymentType enum + User-model community_settings cast + 4 arch invariants

**Wave 2** *(blocked on Wave 1)*

- [x] 16.1-02-PLAN.md — Payment-type classifier pipeline: PaymentTypeHinter contract + PaymentTypeClassifierStage + 6 tagged hinters (ASN CSV/CAMT.053/MT940 + ICS PDF + PayPal CSV + description-keyword fallback)
- [x] 16.1-03a-PLAN.md — Wizard backbone: /setup route + state services (registry, initializer, resolver, query) + UserInstalled listener + EnsureDatabaseReady exemption + signup-redirects-to-setup + SetupWizard parent + WelcomeStep + DoneStep + app-wizard layout + WIZ-01/02/06 acceptance tests

**Wave 3** *(blocked on Waves 1 + 2)*

- [x] 16.1-03b-PLAN.md — Wizard connector steps: 4 connector step components (ConnectBankStep, ConnectCardStep, ConnectEmailStep, FirstImportStep) + 8 atomic Blade components (wiz-card, wiz-dots, wiz-actions, vd-glyph, mini-step, format-chip, drop-zone, privacy-pill) + wizard CSS (no new theme tokens) + WIZ-03/04/05 acceptance tests
- [x] 16.1-06-PLAN.md — OAuth re-consent detection + banner: ReconsentRequiredException + InboxTokenFailed event + RaiseReconsentAlertOnTokenFailure listener (deduped) + provider-specific exception catches in Gmail/Graph clients + system-alert-message blade branch + InboxesPage ?reconnect query-param handoff + OAuthClientWizardModal optional inboxId

**Wave 4** *(blocked on Waves 1 + 2 + 3 — `resources/css/app.css` shared with Plan 03b forces sequential ordering after the wave-3 wizard CSS lands)*

- [x] 16.1-04-PLAN.md — Manual rename + alias learning: PatternGeneralizer + MerchantNameResolver (5-step precedence; community tail seam) + RenameCounterpartyPopover Flux modal + CreateMerchantAlias + AliasMatchPreviewQuery + ImportPipeline wiring + in-place row refresh

**Wave 5** *(blocked on Waves 1 + 2 + 3 + 4)*

- [x] 16.1-05-PLAN.md — Community corpus + browse + Settings: bundled YAML + CorpusLoader (with unknown-category warning) + SeedCommunityCorpus listener + OpenExternalUrlAction (https + github.com allow-list) + NoOpShell + arch invariant + GitHubCompareUrlBuilder + MysteryMerchantsPage + SuggestMappingModal + SharedListSettingsPanel + HelpOthersTriageButton (reads users.community_settings) + MerchantNameResolver community-tail wiring

**Wave 6** *(blocked on Waves 1 + 2 + 3 + 4 + 5)*

- [x] 16.1-07-PLAN.md — Settings → Aliases power-user surface: AliasesSettingsPage + LongestCommonPrefix + MergeMerchantAliases (merged_from provenance) + AliasYamlExporter + AliasYamlImporter (PARSE_EXCEPTION_ON_INVALID_TYPE) + live debounced Test-against-my-transactions preview + bulk merge + YAML import diff/confirm

### Phase 16.1.1: First-run wizard UAT close-out — starting balance + upload→persist + multi-PDF + bank-format copy + OAuth modal mount-once + ICS PDF preview + Dev Mode artisan popover + brand logo (UAT close-out from Phase 16.1) (INSERTED)

**Goal:** Make the first-run setup wizard actually work end-to-end on a fresh install so a new user can sign up → run the wizard → see their real transactions AND their real starting balance on the dashboard — not just transaction deltas, not "we did nothing" silence, not "upload twice", not "ASN-only" copy. All nine UAT findings (G1–G9) close in this phase.
**Slug:** `16.1.1-starting-balance-setup-bank-format-driven-wizard-copy-ics-pdf-preview-fix-dev-mode-artisan-popover-fix-app-chrome-brand-logo`
**Depends on:** Phase 16.1
**Plans:** 10/10 plans complete

Plans:
- [x] 16.1.1-01-PLAN.md — Brand logo + supporting CSS (G5)
- [x] 16.1.1-02-PLAN.md — OAuth modal global mount in both authenticated layouts (G6)
- [x] 16.1.1-03-PLAN.md — Dev Mode artisan popover reproduce-and-fix (G2)
- [x] 16.1.1-04-PLAN.md — ICS PDF preview reproduce-and-fix (G4)
- [x] 16.1.1-05-PLAN.md — Starting-balance schema + backfill + Account fillable (G1 schema half)
- [x] 16.1.1-05b-PLAN.md — Tagged starting-balance detectors + aggregator query with D-04 conflict resolution (G1 detection half)
- [x] 16.1.1-06-PLAN.md — Format-first wizard copy + multi-file ICS upload + ImportRun stash plumbing (G3 + G8)
- [x] 16.1.1-07-PLAN.md — Consolidated preview query + DTOs + ConfirmImport.dispatchChain flag (G7 query/contract surface)
- [x] 16.1.1-07b-PLAN.md — StartingBalanceCard + consolidated-preview-section + FirstImportStep rewrite + commit-everything (G1 + G7 + G9 UI/commit surface)
- [x] 16.1.1-08-PLAN.md — ROADMAP.md title + goal update (D-22)

### Phase 16.1.2: First-run wizard polish + PayPal connector step + preview pagination + regression test gap-fill (UAT follow-up from Phase 16.1.1) (INSERTED)

**Goal:** Close the remaining UAT polish items left open after 16.1.1 — add a PayPal CSV step to the SetupWizard so the connector trio (bank, card, PayPal) is complete before FirstImportStep, let the user expand any preview section beyond the 5-row sample, tighten the starting-balance / preview visual layout per UAT feedback, and back-fill the three missing FirstImportStep commit-path regression tests called for in Plan 07b. Result: a first-run wizard that covers every funding source the user actually has and a preview that scales past 5 rows.
**Slug:** `16.1.2-paypal-wizard-step-preview-pagination-visual-polish-regression-tests`
**Depends on:** Phase 16.1.1
**Plans:** 2/5 plans executed

Plans:

**Wave 1**

- [x] 16.1.2-01-PLAN.md — EnsurePaypalAccountAction extraction + PreviewWizard swap (shared synthetic-PayPal-account auto-create)

**Wave 2** *(blocked on Wave 1)*

- [x] 16.1.2-02-PLAN.md — PayPal connector wizard step (`ConnectPaypalStep` + WizardStepRegistry order change + FirstImportStep stash read for `paypal_import_run_id`)

**Wave 3** *(blocked on Wave 2 — file-overlap on FirstImportStep.php)*

- [ ] 16.1.2-03-PLAN.md — Per-section preview pagination — "Load more (N remaining)" cursor button, 25-row chunks, server-side clamp

**Wave 4** *(blocked on Wave 3 — file-overlap on resources/css/app.css)*

- [ ] 16.1.2-04-PLAN.md — FirstImportStep visual polish + body-block layout (sketch 006 winner B: sub-card per source + framed balances block + 3-up grid; merges handover A-3 + A-4 per CONTEXT.md D-16)

**Wave 5** *(blocked on Wave 4 — regression tests assert against final FirstImportStep + Blade shape)*

- [ ] 16.1.2-05-PLAN.md — FirstImportStep commit-path regression tests (commit-everything, rollback-on-throw, stale-id filter)

### Phase 17: CI/CD Pipeline + Code Signing

**Goal**: Every PR runs the full quality gate (Larastan L10 strict + Pint + Pest) on both PHP 8.4 and 8.5 axes via `.github/workflows/ci.yml`; pushing a `v*` tag triggers `.github/workflows/release.yml` which builds + signs + notarizes installers on macOS 14 + Windows 2025 + Ubuntu 24.04 runners and publishes them as GitHub Release assets via `softprops/action-gh-release v2`.
**Slug:** `17-cicd-pipeline-code-signing`
**Mode:** mvp
**Depends on**: Phase 15 (build target must exist) + Phase 16 (internal dev build must be smoke-testable)
**Requirements**: CI-01, CI-02, CI-03, CI-04, CI-05, CI-06
**Success Criteria** (what must be TRUE):

  1. A PR that introduces a Larastan L10 strict violation, a Pint formatting drift, or a failing Pest test fails CI on `ubuntu-latest` with `TZ=Europe/Amsterdam` on both PHP 8.4 + 8.5 axes; a green PR proves the gate runs across both axes
  2. Pushing tag `v2.0.0-beta.1` triggers `release.yml`; the macOS job produces a signed + notarized `.dmg` via `apple-actions/import-codesign-certs v7.0.0` + Apple notarytool with stapling; the Windows job produces a signed `.msi/.exe` via `Azure/trusted-signing-action v2.0.0`; the Linux job produces unsigned `.AppImage` + `.deb`; first-launch smoke test passes on each platform
  3. GitHub Encrypted Secrets are configured under a `signing-prod` environment with branch restrictions; CODEOWNERS protects `.github/workflows/`; `pull_request_target`-safe handling proves fork PRs cannot exfiltrate signing certificates; gitleaks scan runs on every PR with a known-bad fixture causing failure
  4. First-launch on a fresh machine regenerates `APP_KEY` via `php artisan key:generate --force` (sentinel-absent triggers it); `.env.bundled` template contains no real secrets; first-launch encryption-key generation for `oauth_secrets` succeeds

**Plans**: TBD

### Phase 18: Auto-Update Plumbing

**Goal**: A running diederik install detects when a newer version is available on GitHub Releases, downloads it in the background with Ed25519 signature verification, and prompts the user to "Restart to install" or "Skip this version"; tampering with the published manifest causes verification to fail before any binary executes.
**Slug:** `18-auto-update-plumbing`
**Mode:** mvp
**Depends on**: Phase 17 (signed update artifacts must exist for `electron-updater` to verify)
**Requirements**: UPDATE-01, UPDATE-02, UPDATE-03, UPDATE-04
**Success Criteria** (what must be TRUE):

  1. Bumping the app version, tagging `v2.0.1`, and waiting for `release.yml` to publish results in an existing `v2.0.0` install showing a non-dismissable `SystemAlertsBanner` saying "Update available — install on next launch" within 4 hours of release
  2. A Pest test that tampers with a signed `latest.yml` manifest (flips one byte) proves `ElectronUpdateChannel` rejects the download via Ed25519 signature verification; there is no unsigned auto-update code path anywhere
  3. The user can click "Skip this version" and the banner does not re-appear for that version; the user can click "Restart" and `electron-updater.quitAndInstall()` completes the upgrade with the new binary running last-route resume; an "you're on an old version" prompt fires after 30 days on a stale install
  4. First-install-can't-auto-update behavior is documented on a Settings page so beta partners know to grab v2.0.1 manually after v2.0.0

**Plans**: TBD

### Phase 19: Public Release Boundary

**Goal**: The repository is publishable — license + legal docs + brand + redaction + deep cross-module review all complete; cloning the public repo on a fresh machine and following the README installs and launches diederik with no leaked planning artifacts, no secret-table renderer leaks, and a calm Linear/Notion-style README hero built around the supplied SVG.
**Slug:** `19-public-release-boundary`
**Mode:** mvp
**Depends on**: Phases 12–18 (every prior deliverable must be in place to audit, redact, license, and document)
**Requirements**: REL-01, REL-02, REL-03, REL-04, REL-05, REL-06, REL-07, REL-08
**Success Criteria** (what must be TRUE):

  1. `LICENSE` contains the verbatim Hippocratic License 3.0 text; `composer.json` declares `"license": "Hippocratic-3.0"` SPDX identifier; `NOTICE.md` explains the source-available / not-OSI-approved trade-off; README clearly says "source-available", not "open source"
  2. `SECURITY.md` documents the vulnerability-reporting policy + scope + safe-harbor; `CONTRIBUTING.md` documents the DI rule + arch tests + branch + PR conventions; `CODE_OF_CONDUCT.md` is Contributor Covenant 2.1
  3. README hero uses the supplied SVG (committed at `resources/brand/logo.svg`) + "What is diederik?" / "Who is this for?" / per-platform install instructions / screenshots of dashboard / chains / forecast / dev console / multi-user; PNG exports (`logo-512.png`, macOS `.icns`, Windows `.ico`, Linux PNG-512) are committed for installer bundles + favicon
  4. `BoundaryArchTest::noGsdLeakage` is green — no `.planning/` / `PLAN.md` / `RESEARCH.md` / `D-NNN` / GSD phase codenames in runtime code, comments, views, error messages, log lines, route names, or view-data keys; arch test prevents regression
  5. The deep Modules code review across all 14 modules (11 existing + 3 new: Auth + Desktop + DevMode) produces a `REVIEW-DEEP.md` actioned in the same phase — cross-module boundary hygiene + DI compliance + dead code + perf smells + composer dep analyzer all clean
  6. The renderer-JSON audit confirms every Livewire component's public properties / `$listeners` / `$queryString` are free of `oauth_secrets` / hidden-column / cross-user-id leak; "Where is my data?" docs page renders in-app and on README; one-click export-everything ZIP produces a portable archive of canonical SQLite + brand assets + user-data dir contents

**UI hint**: yes
**Plans**: TBD

### Phase 20: v1.0 UAT Close-Out

**Goal**: Every deferred v1.0 UAT scenario and `human_needed` verification artifact is walked through with real ASN + ICS + PayPal + Gmail data; bugs found during walk-through are either fixed (with regression coverage) or converted to known-issues with tracked workarounds, so the beta partner starts from a known-good baseline.
**Slug:** `20-v1-uat-close-out`
**Mode:** mvp
**Depends on**: Phase 19 (the public-release boundary must be defined so UAT bugs are categorised against the right surface)
**Requirements**: UAT-01, UAT-02, UAT-03
**Success Criteria** (what must be TRUE):

  1. All 25 deferred UAT scenarios across Phases 03 / 04 / 06 / 08 / 11 are walked through with real data and each result is recorded as `resolved` or `known-issue-with-workaround`; the 5 + 2 + 8 + 3 + 7 split in [STATE.md → Deferred Items](STATE.md) is fully closed out
  2. The 3 `human_needed` verification artifacts (Phases 03 / 08 / 11) are resolved with documented evidence written back into the original phase verification files
  3. Every bug found during UAT walk-through that turns into a code fix is accompanied by a Pest regression test that fails on the pre-fix codebase and passes on the post-fix codebase; the project test count grows by at least the number of fixes

**Plans**: TBD

### Phase 21: Invite-Only Beta Cycle

**Goal**: The partner and 1-2 other beta testers install diederik on their own macOS / Windows machines, run fresh-account onboarding end-to-end (signup + OAuth + first import + chain resolve + dashboard), and use the app daily for 1-2 weeks; collected feedback feeds blocker fixes and a final go/no-go decision on opening the repo publicly.
**Slug:** `21-invite-only-beta-cycle`
**Mode:** mvp
**Depends on**: Phase 20 (known-good baseline) + Phases 17 + 18 (signed installers + auto-update for blocker-fix rollout during the cycle)
**Requirements**: BETA-01, BETA-02, BETA-03, BETA-04
**Success Criteria** (what must be TRUE):

  1. At least one beta tester (the partner) completes fresh-account onboarding on macOS and one on Windows — signup + OAuth + first ASN/ICS import + chain resolve + dashboard renders correctly without developer intervention; Linux beta is optional
  2. OAuth callback validation passes on the partner's actual browsers (Chrome / Edge / Safari); NativePHP first-run permission prompts (Notifications, file access) are validated and documented
  3. Every beta tester has an in-app "Send feedback" link wired to a GitHub Issues template; 1-2 weeks of daily-use feedback is collected per tester and triaged into blocker / non-blocker queues
  4. A `BETA-GO-NOGO.md` artifact records the decision-gate review: blocker fixes shipped + remaining-known-issues list + the explicit decision to either open the repo publicly OR run another beta round

## Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| v1.0 MVP — Phases 1–11 | 66/66 | Complete | 2026-05-19 |
| 12. Multi-User Activation | 8/8 | Complete    | 2026-05-20 |
| 13. AppPaths | 3/3 | Complete    | 2026-05-20 |
| 14. Queue Rewire + Horizon Carve-out | 3/3 | Complete    | 2026-05-22 |
| 15. Desktop Shell (NativePHP Integration) | 7/7 | Complete    | 2026-05-23 |
| 16. Developer Mode UI | 9/9 | Complete   | 2026-05-24 |
| 16.1. First-run wizard + rename + payment-type + crowd corpus + OAuth re-consent + Aliases settings | 8/8 | Complete   | 2026-05-25 |
| 17. CI/CD Pipeline + Code Signing | 0/0 | Not started | - |
| 18. Auto-Update Plumbing | 0/0 | Not started | - |
| 19. Public Release Boundary | 0/0 | Not started | - |
| 20. v1.0 UAT Close-Out | 0/0 | Not started | - |
| 21. Invite-Only Beta Cycle | 0/0 | Not started | - |
