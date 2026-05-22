# Requirements: diederik v2.0 — Public Release

**Defined:** 2026-05-19
**Milestone:** v2.0 — Public Release (Desktop Packaging, Multi-User, Developer Mode)
**Core Value:** Show me, in one place, what I actually owe and where the money truly came from — across every account chain — so my monthly finances stop being a manual reconciliation puzzle.
**v2.0 thesis:** Take the validated v1.0 core value to a non-technical partner via a code-signed desktop installer, with an in-app developer console so power users (and contributors) keep full CLI access, under a Hippocratic-3.0 source-available license.

## v2.0 Requirements

47 requirements across 8 categories. Each maps to exactly one roadmap phase (see Traceability section below).

### PKG (Desktop Packaging)

- [x] **PKG-01**: AppPaths / UserDataPath abstraction service replacing every `database_path()` / `storage_path()` / `base_path()` call, with an arch-test forbidding their use outside the service
- [x] **PKG-03**: Queue rewire — `database` queue driver + `cache.locks_store=database` + `ShouldBeUniqueUntilProcessing` jobs' `uniqueVia()` lock store migrated; Horizon gated on `DIEDERIK_RUNTIME=herd` dev-mode only; chain-resolution end-to-end Pest test against `database` driver under concurrent load
- [x] **PKG-04**: `nativephp/desktop ^2.2` integration producing `.dmg` (macOS), `.msi/.exe` (Windows), and `.AppImage/.deb` (Linux) installers via `php artisan native:build`
- [x] **PKG-05**: Native chrome — window, dock/taskbar icon, app menu (File / Edit / View / Window / Help with standard items), system tray, OS notifications, dark-mode follows OS
- [ ] **PKG-06**: File-association handlers — double-clicking a `.eml` or `.csv` file on macOS / Windows / Linux opens diederik with ingestion intent
- [ ] **PKG-07**: PHP 8.4 bundle validation — Larastan L10 strict + Pint + Pest all pass on PHP 8.4 (project dev pin stays 8.5; bundle ships 8.4 until `nativephp/php-bin` 8.5 builds land)
- [ ] **PKG-08**: macOS Hardened Runtime entitlements file (`com.apple.security.cs.allow-unsigned-executable-memory`, `com.apple.security.cs.disable-library-validation`) configured so bundled PHP runs under notarization

### MULTI (Multi-User Activation)

- [x] **MULTI-01**: `CurrentUserProvider` DI contract (extending existing `Modules\Core\Public\Contracts\CurrentUser`) bound in `Modules/Auth/`, with arch-test forbidding `Auth::user()` / `auth()` / `request()->user()` / `request()->session()` calls across every module
- [x] **MULTI-02**: Fortify login / signup / logout / session lifecycle in Flux + Volt UI; sessions stored via Laravel session driver compatible with NativePHP bundle; "remember me" cookie
- [x] **MULTI-03**: `BelongsToUser` global scope extension + cross-user 404-not-403 test set on every route (no cross-user data leak; partner cannot probe IDs to discover owner's data)
- [x] **MULTI-04**: Recovery-codes password reset — printed at signup, 10 single-use codes; owner-resets-partner flow; `diederik:reset-password` CLI fallback; NO SMTP-based reset flow in v2.0
- [x] **MULTI-05**: Per-user OAuth secrets migration — single `storage/app/secrets/imap.json` (chmod 600) replaced by SQLite-encrypted `oauth_secrets` table keyed by `user_id`, encrypted via `APP_KEY`; `OAuthSecretsRepository` swap; existing `PLT-03` invariant generalizes
- [x] **MULTI-06**: Profile selector + quick-switch via app menu (owner can act as partner during debugging without full logout/login dance)

### DEVUI (In-App Developer Mode)

- [ ] **DEVUI-01**: `User::is_developer` boolean flag (migration + factory + UI toggle in Settings, off by default) + `EnsureDeveloperMode` middleware gating every dev-mode route
- [ ] **DEVUI-02**: Whitelisted artisan runner — SAFE tier (`db:backup`, `diederik:doctor`, `diederik:failed-jobs prune`, `cache:clear`, `route:list`, etc.) with form-built args, live stdout/stderr streaming, command history, cancel-running-command
- [ ] **DEVUI-03**: Destructive artisan runner — DESTRUCTIVE tier (`db:restore`, `migrate:fresh`, `diederik:reset-password`, etc.) with triple-gating (Dev Mode on + Advanced toggle on + typed-app-name confirm modal) and audit log via `spatie/laravel-activitylog ^4.12`
- [ ] **DEVUI-04**: Log tailer with Monolog redaction processor — live tail of `storage/logs/laravel.log` with filter; redaction processor scrubs `Authorization: Bearer …`, OAuth tokens, and the `oauth_secrets` table before render
- [ ] **DEVUI-05**: Queue inspector — `jobs` + `failed_jobs` + `job_batches` tabular view with retry / cancel / delete actions; ~200-line Livewire component; replaces Horizon dashboard in the shipped build
- [ ] **DEVUI-06**: Doctor panel (runs `diederik:doctor` from UI) + `system_alerts` viewer + env snapshot (PHP version + SQLite PRAGMAs + extension list) + effective-config tree viewer
- [ ] **DEVUI-07**: Read-only SQLite query panel — SELECT-only query runner with schema viewer + table-row browser; gated by Dev Mode + Advanced
- [ ] **DEVUI-08**: Embedded Horizon iframe inside Dev Console — dev-mode-only, behind `DIEDERIK_RUNTIME=herd` flag (loopback-bound; available to the developer on Herd, never to the partner)
- [ ] **DEVUI-09**: Command palette (⌘K on macOS, Ctrl+K on Windows/Linux) for view + command navigation with fuzzy search — Linear/Raycast calm-shell aesthetic

### CI (CI/CD Pipeline)

- [ ] **CI-01**: PR gate workflow (`.github/workflows/ci.yml`) — Larastan L10 strict + Pint + Pest on ubuntu-latest with `TZ=Europe/Amsterdam`; PHP 8.4 + 8.5 axes
- [ ] **CI-02**: Tag-triggered release workflow (`.github/workflows/release.yml`) — macOS 14 + Windows 2025 + Ubuntu 24.04 matrix producing signed installers + electron-updater manifest published to GitHub Releases via `softprops/action-gh-release v2`
- [ ] **CI-03**: macOS code signing via Apple Developer ID + notarytool — `apple-actions/import-codesign-certs v7.0.0` + notarization step with stapling; first-launch smoke test
- [ ] **CI-04**: Windows code signing via Azure Trusted Signing — `Azure/trusted-signing-action v2.0.0` (GitHub-hosted-runner compatible; ~$10/month) with smoke test on Windows 11
- [ ] **CI-05**: GitHub Encrypted Secrets configured with environment scoping (`signing-prod` environment); CODEOWNERS on `.github/workflows/`; `pull_request_target`-safe handling so fork PRs cannot exfiltrate signing certificates; gitleaks scan on every PR
- [ ] **CI-06**: Per-install `APP_KEY` regeneration at first launch (`php artisan key:generate --force` triggered by sentinel-absent) + `.env.bundled` template (no real secrets in bundle) + first-launch encryption-key generation for `oauth_secrets`

### UPDATE (Auto-Update Plumbing)

- [ ] **UPDATE-01**: `electron-updater` wired through `Modules\Core\Public\Services\ElectronUpdateChannel` consuming GitHub Releases as the update channel
- [ ] **UPDATE-02**: Ed25519 publisher pin + signature verification on every update download (no unsigned auto-update path) + Pest test proving verification fails for tampered manifest
- [ ] **UPDATE-03**: "Update available — install on next launch" + "Skip this version" UX + "You're on an old version" prompt + `SystemAlertsBanner` integration when a critical update is available
- [ ] **UPDATE-04**: First-install-can't-auto-update fallback — documented on a Settings page so beta partners know to grab v2.0.1 manually after v2.0.0

### REL (Public Release Hygiene)

- [ ] **REL-01**: Hippocratic License 3.0 — `LICENSE` file (verbatim text from firstdonoharm.dev) + `NOTICE.md` explainer ("source-available, not OSI-approved; chosen for ethical-use clause") + composer.json `"license": "Hippocratic-3.0"` SPDX identifier
- [ ] **REL-02**: `SECURITY.md` (vulnerability-reporting policy + scope + safe-harbor) + `CONTRIBUTING.md` (DI rule + arch tests + branch + PR conventions) + `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1)
- [ ] **REL-03**: README rewrite — public-audience hero with the supplied SVG, "What is diederik?" / "Who is this for?" / per-platform install instructions / screenshots of dashboard / chains / forecast / dev console / multi-user; tone-matched to the calm Linear/Notion aesthetic
- [ ] **REL-04**: Brand asset import — supplied SVG committed at `resources/brand/logo.svg` + PNG exports (`logo-512.png`, macOS `.icns`, Windows `.ico`, Linux PNG-512) generated and committed for installer bundles + favicon
- [ ] **REL-05**: GSD-leakage redaction sweep — runtime code, comments, views, error messages, log lines, route names, view-data keys audited and purged of `.planning/` / `PLAN.md` / `RESEARCH.md` / `D-NNN` / GSD phase codenames; arch test prevents regression
- [ ] **REL-06**: Deep Modules code review across all 14 modules (11 existing + 3 new: Auth + Desktop + DevMode) — cross-module boundary hygiene + DI compliance + dead code + perf smells + composer dep analyzer; produces a REVIEW-DEEP.md actioned in the same phase
- [ ] **REL-07**: Renderer-JSON audit — every Livewire component's bound props checked for `oauth_secrets` / hidden-column / cross-user-id leak through the wire snapshot; arch test enforces "no secrets-tagged columns in `$listeners` / `$queryString` / public properties"
- [ ] **REL-08**: "Where is my data?" docs page (in-app + on README) + export-everything UX (one-click ZIP of canonical SQLite + brand assets + user-data dir contents)

### UAT (v1.0 Carry-Over Close-Out)

- [ ] **UAT-01**: Walk through 25 deferred UAT scenarios across Phases 03 / 04 / 06 / 08 / 11 with real ASN + ICS + PayPal + Gmail data; resolve or convert to known-issue with a tracked workaround
- [ ] **UAT-02**: Resolve 3 `human_needed` verification artifacts (Phases 03 / 08 / 11)
- [ ] **UAT-03**: Add regression test coverage for every bug found during UAT walk-through

### BETA (Invite-Only Beta Cycle)

- [ ] **BETA-01**: Invite-only release to partner + 1-2 other beta testers; fresh-account onboarding tested on macOS + Windows (Linux beta optional)
- [ ] **BETA-02**: OAuth callback validation on partner's browsers (Chrome / Edge / Safari); NativePHP first-run permission prompts (Notifications, file access) validated
- [ ] **BETA-03**: In-app "Send feedback" link to GitHub Issues + 1-2 weeks of daily-use feedback collected from each beta tester
- [ ] **BETA-04**: Beta blocker fixes + decision-gate review — open repo publicly OR run another beta round (artefact: BETA-GO-NOGO.md)

## v2.1+ Requirements

Deferred to a later milestone. Acknowledged but not in the v2.0 roadmap.

### Telemetry / Observability

- **TELE-01**: Sentry crash reporting (with dedup-by-stack-hash, no install UUID) — privacy-first design requires explicit user decision before adoption
- **TELE-02**: Anonymous usage telemetry — likely never; surface as explicit decision in v2.1 if asked
- **TELE-03**: `laravel/pulse` — requires Redis cache reconfig; revisit when desktop bundle is stable

### Auth + Secrets

- **AUTH-21**: OS-keychain shell-out for OAuth secrets (`security` on macOS / `secret-tool` on Linux / `wincred` on Windows) — upgrade path from v2.0's SQLite-encrypted rows
- **AUTH-22**: SMTP password reset via Gmail-OAuth from `Modules/EmailScan` — restores the email-link reset flow for users who have Gmail linked
- **AUTH-23**: WebAuthn / passkeys

### Sharing

- **SHARE-01**: Per-user-data partner-sharing modes (Firefly-III-style "spaces") — v2.0 contract is one shared SQLite, both users see everything; mode-based privacy is v3 scope
- **SHARE-02**: Cross-device sync (remains out of scope — privacy-first)

### Deferred from v1.0

- **ING-09**: PayPal Reporting API (Transaction Search) via OAuth2 — trigger: user upgrades to PayPal Business account. CSV path (ING-05) covers the same data without API gating.

## Out of Scope

Explicitly excluded for v2.0. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Sentry crash reporting (any form) | Privacy-first stance; defer the decision to v2.1 where it gets explicit treatment. |
| Anonymous telemetry by default | Hard refusal; never ship telemetry without explicit per-install opt-in. |
| OS-keychain shell-out for OAuth secrets | SQLite-encrypted rows cover v2.0 isolation + uninstall portability. Keychain integration is a v2.1 quality upgrade. |
| SMTP password reset | Desktop context cannot reliably relay mail; recovery codes + owner-resets-partner + CLI fallback are sufficient. SMTP is a v2.1 enhancement if Gmail OAuth re-use makes it cheap. |
| `laravel/pulse` | Requires Redis cache reconfig; redundant with the bespoke Dev Console queue inspector. v2.1 candidate. |
| Partner-sharing "spaces" (read/write modes) | v2.0 contract is one shared SQLite; both users see everything. Spaces add Firefly-III-scale scope and aren't required for the partner use case. v3 candidate. |
| Cloud hosting / multi-device sync | Privacy-first; unchanged from v1.0. |
| Bank PSD2 / open-banking APIs | Unchanged from v1.0; GoCardless stopped accepting Dutch banks mid-stack; remaining options are paid. |
| iCloud Mail integration | Unchanged from v1.0; no public API; user confirmed iCloud is not where financial receipts arrive. |
| Mobile native client | Unchanged from v1.0; the v2.0 desktop installer is the partner-sharing answer. |
| Investment / brokerage / portfolio | Unchanged from v1.0; scope is cash and card flow. |
| Tax / VAT / bookkeeping | Unchanged from v1.0; visibility tool, not accounting. |
| Receipt-image OCR | Unchanged from v1.0; email + CSV is the data spine. |
| Budgeting / envelope / goals (YNAB-style) | Different product. |
| Full double-entry accounting | Adds complexity Firefly's own creator says drives users away. |
| LLM categorization | Rules + per-merchant memory proved sufficient in v1.0; privacy + cold-start concerns also apply. |
| Auto-applied recurring detection | Always-suggest-never-auto-apply; validated as the right call in v1.0 Phase 8. |
| Outbound payments / iDEAL initiation | Unchanged from v1.0; system recommends, user pays via their bank. |
| ICS Cards API integration | Unchanged from v1.0; no buyer-side API exists. |
| Google Play buyer-side API | Unchanged from v1.0; no public API; email receipts cover this. |
| ING-09 PayPal Reporting API | Deferred with trigger (PayPal Business upgrade); CSV path covers same data. |

## Traceability

Which phase covers which requirement. Updated during roadmap creation (2026-05-19).

| Requirement | Phase | Status |
|-------------|-------|--------|
| PKG-01 | Phase 13 | Complete |
| PKG-03 | Phase 14 | Complete |
| PKG-04 | Phase 15 | Complete |
| PKG-05 | Phase 15 | Complete |
| PKG-06 | Phase 15 | Pending |
| PKG-07 | Phase 15 | Pending |
| PKG-08 | Phase 15 | Pending |
| MULTI-01 | Phase 12 | Complete |
| MULTI-02 | Phase 12 | Complete |
| MULTI-03 | Phase 12 | Complete |
| MULTI-04 | Phase 12 | Complete |
| MULTI-05 | Phase 12 | Complete |
| MULTI-06 | Phase 12 | Complete |
| DEVUI-01 | Phase 16 | Pending |
| DEVUI-02 | Phase 16 | Pending |
| DEVUI-03 | Phase 16 | Pending |
| DEVUI-04 | Phase 16 | Pending |
| DEVUI-05 | Phase 16 | Pending |
| DEVUI-06 | Phase 16 | Pending |
| DEVUI-07 | Phase 16 | Pending |
| DEVUI-08 | Phase 16 | Pending |
| DEVUI-09 | Phase 16 | Pending |
| CI-01 | Phase 17 | Pending |
| CI-02 | Phase 17 | Pending |
| CI-03 | Phase 17 | Pending |
| CI-04 | Phase 17 | Pending |
| CI-05 | Phase 17 | Pending |
| CI-06 | Phase 17 | Pending |
| UPDATE-01 | Phase 18 | Pending |
| UPDATE-02 | Phase 18 | Pending |
| UPDATE-03 | Phase 18 | Pending |
| UPDATE-04 | Phase 18 | Pending |
| REL-01 | Phase 19 | Pending |
| REL-02 | Phase 19 | Pending |
| REL-03 | Phase 19 | Pending |
| REL-04 | Phase 19 | Pending |
| REL-05 | Phase 19 | Pending |
| REL-06 | Phase 19 | Pending |
| REL-07 | Phase 19 | Pending |
| REL-08 | Phase 19 | Pending |
| UAT-01 | Phase 20 | Pending |
| UAT-02 | Phase 20 | Pending |
| UAT-03 | Phase 20 | Pending |
| BETA-01 | Phase 21 | Pending |
| BETA-02 | Phase 21 | Pending |
| BETA-03 | Phase 21 | Pending |
| BETA-04 | Phase 21 | Pending |

**Coverage:**

- v2.0 requirements: 47 total
- Mapped to phases: 47 ✓
- Unmapped: 0 ✓

### Phase-to-requirement summary

| Phase | Requirement count | Requirements |
|-------|-------------------|--------------|
| 12. Multi-User Activation | 6 | MULTI-01, MULTI-02, MULTI-03, MULTI-04, MULTI-05, MULTI-06 |
| 13. AppPaths | 1 | PKG-01 |
| 14. Queue Rewire + Horizon Carve-out | 1 | PKG-03 |
| 15. Desktop Shell (NativePHP Integration) | 5 | PKG-04, PKG-05, PKG-06, PKG-07, PKG-08 |
| 16. Developer Mode UI | 9 | DEVUI-01, DEVUI-02, DEVUI-03, DEVUI-04, DEVUI-05, DEVUI-06, DEVUI-07, DEVUI-08, DEVUI-09 |
| 17. CI/CD Pipeline + Code Signing | 6 | CI-01, CI-02, CI-03, CI-04, CI-05, CI-06 |
| 18. Auto-Update Plumbing | 4 | UPDATE-01, UPDATE-02, UPDATE-03, UPDATE-04 |
| 19. Public Release Boundary | 8 | REL-01, REL-02, REL-03, REL-04, REL-05, REL-06, REL-07, REL-08 |
| 20. v1.0 UAT Close-Out | 3 | UAT-01, UAT-02, UAT-03 |
| 21. Invite-Only Beta Cycle | 4 | BETA-01, BETA-02, BETA-03, BETA-04 |
| **Total** | **47** | |

---
*Requirements defined: 2026-05-19*
*Last updated: 2026-05-20 — PKG-02 (first-run migration wizard) dropped; Phase 13 narrowed to AppPaths-only. 47/47 coverage.*
