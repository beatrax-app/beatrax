# Project Research Summary

**Project:** diederik v2.0 — Public Release (Desktop Packaging, Multi-User, Developer Mode)
**Domain:** Personal-finance desktop app (subsequent milestone — wrapping a shipped Laravel web app)
**Researched:** 2026-05-19
**Confidence:** HIGH

## Executive Summary

diederik v2.0 is a **shell, activation, and release** milestone on top of a validated v1.0 (11 modules, 1644 tests green, Larastan L10 strict, all `BoundaryArchTest` invariants holding). The work is *not* feature-extending: it wraps the existing Laravel 13 + Livewire 4 + SQLite app in a code-signed NativePHP/Electron desktop installer for macOS / Windows / Linux, activates the schema's already-built `user_id` multi-user layer with real Fortify auth, adds an in-app Developer Mode UI exposing the CLI, ships a GitHub Actions CI/CD pipeline (PR gates + tag-triggered release + code signing + auto-update), and publishes the source under Hippocratic License 3.0.

The recommended approach is **strict-dependency build order (A→H)** with three new modules (`Modules/Auth/`, `Modules/Desktop/`, `Modules/DevMode/`) that keep NativePHP and dev-mode concerns isolated from the existing 11 domain modules. The single largest stack adaptation is **dropping Laravel Horizon + Redis from the shipped desktop bundle** (Redis cannot ship inside Electron); the `database` queue driver covers single-user-machine throughput and `Cache::lock()` against the database driver preserves `ShouldBeUniqueUntilProcessing` semantics for chain resolution.

Three CRITICAL pitfalls own most of v2.0's risk: (1) hard-coded paths breaking inside the NativePHP read-only bundle — mitigated by an `AppPaths` abstraction that must land Phase B day-one before any NativePHP integration; (2) first-run migration corrupting the developer's existing v1.0 SQLite — mitigated by an explicit wizard with `VACUUM INTO` + sentinel-file idempotency + opt-in OAuth re-auth; (3) the multi-user activation accidentally smuggling `Auth::user()` facade calls into 50 places — mitigated by landing the DI-friendly `CurrentUserProvider`-style contract (the v1.0 codebase already has `Modules\Core\Public\Contracts\CurrentUser`) **before** the multi-user PR opens, plus an arch-test that ratchets violations to zero.

## Key Findings

### Recommended Stack

Three load-bearing additions; the v1.0 stack stays pinned. See [STACK.md](STACK.md) for the full version pin matrix and rejected-alternatives analysis.

**Core technologies (NEW):**
- **`nativephp/desktop` ^2.2** (2.2.0, 2026-04-29) — desktop shell; ships Electron 38 + electron-builder + electron-updater; PR #96 added Laravel 13 compatibility. Requires PHP ^8.3 + Node 22+. Replaces Laravel Herd as the runtime for shipped builds.
- **`laravel/fortify` ^1.37** (1.37.2, 2026-05-15) — headless auth actions; already in `composer.json` at ^1.21, upgrade pin to ^1.37 for Laravel 13. Flux + Volt + Livewire handle the UI; Fortify provides the actions and the password-reset hookpoints.
- **`nativephp/php-bin` ^1.1** — bundled PHP runtime. Ships PHP 8.1–8.4; project pin currently `^8.5`. Recommended: bundle PHP 8.4 (10-min Larastan-L10-on-8.4 spike validates) until php-bin 8.5 builds land. No package additions for queue driver, secrets store, or dev console — `database` driver + SQLite-encrypted `oauth_secrets` table + bespoke Livewire console reuse what's already there.

**CI / signing:**
- `apple-actions/import-codesign-certs v7.0.0` + Apple notarytool (via NativePHP env vars) for macOS Developer ID
- `Azure/trusted-signing-action v2.0.0` for Windows (GitHub-hosted-runner compatible, $10/mo, beats EV USB token which requires self-hosted Windows runners)
- `softprops/action-gh-release v2` for tag-triggered installer publishing
- Auto-update via `electron-updater` → GitHub Releases (NativePHP first-class support)

**Critical change versus v1.0 stack:**
- **Drop Laravel Horizon + Redis from shipped desktop builds.** Keep Horizon for `DIEDERIK_RUNTIME=herd` dev mode behind a feature flag. Shipped build runs NativePHP's built-in `queue_workers` config against the `database` driver. `ShouldBeUniqueUntilProcessing` lock store moves from `redis` to `database` via `config('cache.locks_store')` — existing `BoundaryArchTest` carve-outs stay legal.

### Expected Features

See [FEATURES.md](FEATURES.md) for the full 5-area decomposition with table-stakes / differentiator / anti-feature split and S/M/L complexity tags.

**Must have (table stakes):**
- Native desktop chrome (window, dock/taskbar icon, app menu, system tray, OS notifications, dark-mode follows OS)
- Login / signup / logout / session lifecycle / per-user data scoping (404 not 403 on cross-user access)
- First-run wizard (max 4 screens: welcome → create first user + recovery codes → solo-vs-shared → data dir + start fresh/restore)
- In-app whitelisted artisan runner with live stdout/stderr streaming (SAFE / DESTRUCTIVE / FORBIDDEN tiers)
- Log tailer, queue inspector, `diederik:doctor` runner, `system_alerts` viewer, env snapshot, read-only SQLite query panel
- In-app version display + "Check for updates" + auto-update install
- Hippocratic 3.0 LICENSE + SECURITY.md + CONTRIBUTING.md + CODE_OF_CONDUCT.md + README rewrite with the supplied SVG hero
- File-association handlers (`.eml` / `.csv` double-click opens diederik)

**Should have (differentiators):**
- Command palette (⌘K) — Linear/Raycast aesthetic; calm shell
- Profile selector with quick-switch via app menu
- Embed v1.0's Horizon dashboard via iframe in Dev Console (loopback-bound; dev-mode-only)
- Recovery-code-printed-at-signup password reset (no SMTP — desktop context can't send mail without a relay)
- Owner-resets-partner password flow (partner forgets password → owner clicks "reset" → partner gets a new recovery code)
- Triple gating on destructive artisan: Dev Mode on + Advanced toggle on + typed-app-name confirm

**Defer (v2.1+):**
- `laravel/pulse` (requires Redis cache reconfig)
- Read/write partner-sharing modes (one-shared-DB read-everything is the v2.0 contract — partner-sharing modes is a Firefly-III-scale scope expansion)
- OS-keychain shell-out for OAuth secrets (`security` / `secret-tool` / `wincred`) — SQLite-encrypted row keyed by `user_id` covers v2.0
- SMTP password reset (could ship later via Modules/EmailScan's Gmail OAuth)
- Anonymous telemetry (off by default in v2.0; opt-in screen if added)
- Sentry crash reporting (privacy-first apps either go without, or ship dedup-by-stack-hash + no UUID — defer the decision)

**Explicit anti-features:**
- No telemetry by default
- No auto-launching agents
- No SMTP-dependent flows in shipped build
- No exposing destructive artisan to non-Developer-Mode users
- No copying OAuth secrets during first-run migration (re-auth instead)

### Architecture Approach

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full 8-piece architecture decomposition with new arch-test invariants, data-flow changes, and build-order dependencies.

**Major components:**
1. **`Modules/Auth/`** (NEW) — Fortify-backed login/signup/logout, profile selector, `is_developer` flag, `CurrentUserProvider` interface binding. Becomes the only module that imports `Illuminate\Contracts\Auth\Factory`.
2. **`Modules/Desktop/`** (NEW) — All `Native\Laravel\*` imports localized here. Owns: `SystemTrayService`, `AppMenuBuilder`, `FileOpenRouter`, `NativePhpEventListener`. Other modules consume via `Modules\Desktop\Public\Contracts\*` — keeps NativePHP off-bundle-able for headless test runs.
3. **`Modules/DevMode/`** (NEW) — In-app developer console; gated by `User::is_developer` + `EnsureDeveloperMode` middleware. Owns: `ArtisanCommandRegistry` (SAFE/DESTRUCTIVE/FORBIDDEN allowlist), `DevConsolePage`, `LogTailer`, `QueueInspector`, `DoctorPanel`, `ConfigInspector`.
4. **`Modules/Core/Public/Services/AppPaths`** (NEW, in existing Core module) — `UserDataPath` contract resolving per-OS paths via NativePHP's `Application::storagePath()`. Single source of truth for any code that touches the filesystem. Replaces every direct `database_path()` / `storage_path()` / `base_path()` call.
5. **One shared SQLite per machine** (data-flow transformation) — `database/database.sqlite` → `~/Library/Application Support/diederik/data.sqlite` (macOS), `%APPDATA%\diederik\data.sqlite` (Windows), `~/.config/diederik/data.sqlite` (Linux). Backups + per-user OAuth secrets follow. Migration wizard handles v1.0 → v2.0 first-launch flow.
6. **Queue rewire** (data-flow transformation) — `redis` driver (v1.0 dev) → `database` driver (shipped build); Horizon stays for `DIEDERIK_RUNTIME=herd` only; `ShouldBeUniqueUntilProcessing` jobs migrate their `uniqueVia()` lock store to `database`.
7. **Per-user OAuth secrets** (data-flow transformation) — single `storage/app/secrets/imap.json` chmod-600 → SQLite-encrypted `oauth_secrets` table keyed by `user_id`, encrypted via `APP_KEY`. `OAuthSecretsRepository` swap; existing `PLT-03` invariant generalises to "secrets never leave SQLite".
8. **GitHub Actions CI/CD + auto-update plumbing** — `.github/workflows/ci.yml` (PR gates: Larastan L10 strict + Pint + Pest on ubuntu-latest), `.github/workflows/release.yml` (tag-triggered matrix: macOS 14 + Windows 2025 + Ubuntu 24.04 + signing + notarization + electron-updater manifest publish to GitHub Releases).

**~20 new `BoundaryArchTest` invariants** (enumerated in ARCHITECTURE.md), including: `noAuthFacadeOrHelper` (extends DI-only rule), `noStoragePathHardCodedOutsideUserDataPathService`, `noNativePhpImportsOutsideDesktopModule`, `noHorizonImportsInShippedBuildCode`, `noUserIdScopeBypass`, `noCurrentUserResolutionInJobConstructor` (jobs take `int $userId`, not the contract — request guard is gone post-dispatch).

### Critical Pitfalls

Top 3 from [PITFALLS.md](PITFALLS.md) (21 total: 14 critical, 7 moderate):

1. **Hard-coded `database/database.sqlite` path breaks inside NativePHP** — Symptoms: `SQLSTATE[HY000]: General error: 8 attempt to write a readonly database` on first user action because PHP is running inside a read-only `app.asar` bundle. **Prevention:** `AppPaths` / `UserDataPath` abstraction lands **Phase B day-one** before any NativePHP integration; arch test `PKG-01` forbids `database_path()` / `storage_path()` / `base_path()` outside the new service; CI grep gate enforces it.

2. **First-run migration corrupts v1.0 production data** — Symptoms: developer opens v2.0, app silently copies a WAL-mode SQLite mid-write, partner opens app, sees a corrupt DB or empty DB; OAuth secrets leak to the new user-data dir. **Prevention:** Explicit migration wizard ("Start fresh" / "Import from v1.0" / "Quit"); `VACUUM INTO` against a read-only attached source; sentinel file prevents wizard re-runs; OAuth secrets NOT auto-copied (re-auth prompt); pre-migration rollback snapshot; v1.0 launchd plists uninstalled before import; UAT phase before public release.

3. **Horizon/Redis absent in shipped build silently kills chain resolution** — Symptoms: chain-resolver jobs dispatch to a non-existent Redis, fail in background, forecasts ignore fuzzy resolution, partner doesn't see the Netflix → ICS → ASN chain. **Prevention:** **Drop Horizon from shipped build entirely.** Use NativePHP's `queue_workers` config + SQLite `database` queue driver. `ShouldBeUniqueUntilProcessing` lock moves to `Cache::lock()` against database driver (officially supported in Laravel 11+). Replace Horizon dashboard with a bespoke job inspector inside `Modules/DevMode/` (~200-line Livewire component reading `jobs` + `failed_jobs` directly). End-to-end Pest test proves chain resolution works against the `database` driver under concurrent load.

**Other critical pitfalls** (full coverage in PITFALLS.md): `Auth::user()` facade leakage (Phase A — land `CurrentUserProvider` first + arch-test ratchet), missing `where('user_id', ...)` in queries (Phase A — extend `BelongsToUser` global scope + cross-user 404 test set), destructive artisan commands behind web UI (Phase E — triple-gating), unsigned auto-update bypasses signing (Phase G — signature verification + Ed25519 publisher pin), `.env` leaks into bundle (Phase F — `.env.bundled` template + per-install `APP_KEY` regen + gitleaks scan), code-signing secrets exposed to forked PRs (Phase F — `pull_request_target` semantics + environment-scoped secrets), Apple Hardened Runtime entitlements clash with bundled PHP (Phase D/F — `com.apple.security.cs.allow-unsigned-executable-memory` + JIT-disable), Hippocratic 3.0 mislabelled as OSI-approved (Phase H — README says "source-available", not "open source"; SPDX `Hippocratic-3.0`; NOTICE file explains the trade-off).

## Implications for Roadmap

Based on research, the suggested phase structure follows the **strict dependency-driven 8-phase order** that ARCHITECTURE and PITFALLS converged on, with one additional phase ahead of public release for v1.0 UAT close-out and a final beta-cycle phase.

### Phase 12 (A): Multi-User Activation
**Rationale:** No NativePHP integration depends on it, but the desktop bundle needs auth before it ships. Landing first means every subsequent phase can rely on a real `CurrentUserProvider`. PITFALLS #4 + #5 explicitly require this be first.
**Delivers:** `Modules/Auth/`, `CurrentUserProvider` interface, Fortify activation, login/signup/logout/recovery-code UI in Flux+Volt, `is_developer` flag, `BelongsToUser` global scope extension, cross-user 404 test set per route, password-reset-via-recovery-codes + owner-resets-partner flow.
**Addresses:** MULTI-01…05 from FEATURES.
**Avoids:** PITFALL #4 (`Auth::user()` smuggling), PITFALL #5 (missing `where('user_id')`), PITFALL #7 (SMTP password reset).

### Phase 13 (B): AppPaths + First-Run Migration Wizard
**Rationale:** AppPaths must precede NativePHP integration (Phase D); migration wizard is the highest-UX-risk piece in v2.0 (PITFALL #2) — earning its own phase. Can run in parallel with Phase A on a separate branch.
**Delivers:** `UserDataPath` contract + service, `config/database.php` rewrite, every `base_path()` / `database_path()` / `storage_path()` call audited and rewired, `OAuthSecretsRepository` swap to SQLite-encrypted `oauth_secrets` table, `BackupDatabaseCommand` + `RestoreDatabaseCommand` + `BackupFreshnessProbe` updated, inbox drop-folder scanner updated, `MigrateUserDataCommand` + first-run wizard ("Start fresh" / "Import from v1.0" / "Quit"), arch test forbidding path helpers outside service, grep gate in CI.
**Addresses:** PKG-01 + PKG-02 from FEATURES.
**Avoids:** PITFALL #1 (read-only bundle paths), PITFALL #2 (migration data loss).

### Phase 14 (C): Queue Rewire + Horizon Carve-out
**Rationale:** Required before NativePHP integration so the shipped build has a working queue driver. Audit of every `ShouldBeUniqueUntilProcessing` job + lock-store migration is non-trivial.
**Delivers:** `QUEUE_CONNECTION=database`, `cache.locks_store=database`, Horizon gated on `DIEDERIK_RUNTIME=herd`, audit of every `uniqueVia()` job, chain-resolution end-to-end Pest test against `database` driver under concurrent load, removal of `predis/predis` from prod `require` (move to `require-dev`) if no other dep needs it.
**Addresses:** PKG-03 from FEATURES.
**Avoids:** PITFALL #3 (silent chain-resolution death).

### Phase 15 (D): Desktop Shell (NativePHP Integration)
**Rationale:** Depends on A (auth) + B (paths) + C (queue). First phase where the app actually runs as a desktop binary on the developer's machine.
**Delivers:** `Modules/Desktop/`, `nativephp/desktop ^2.2` install + config, native chrome (window/tray/menu/notifications/dark-mode follow-OS/file-association handlers for `.eml`+`.csv`), `SystemTrayService` + `AppMenuBuilder` + `FileOpenRouter` + `NativePhpEventListener`, PHP 8.4 bundling validation, NativePHP-vs-DI-rule spike, macOS Hardened Runtime entitlements file.
**Uses:** `nativephp/desktop`, `nativephp/php-bin`.
**Implements:** Architecture component #2 (Desktop module).

### Phase 16 (E): Developer Mode UI
**Rationale:** Depends on A (auth — `is_developer` flag) and D (desktop shell — the dev console is a desktop-runtime feature). The user explicitly wants this; lands before public release so partners and contributors have it.
**Delivers:** `Modules/DevMode/`, `EnsureDeveloperMode` middleware, `ArtisanCommandRegistry` with SAFE/DESTRUCTIVE/FORBIDDEN tiers, triple-gating modal for destructive commands, live-streaming stdout/stderr `DevConsolePage`, `LogTailer` with Monolog redaction processor (no OAuth tokens in tailed logs), `QueueInspector` (replaces v1.0's Horizon dashboard for shipped builds), `DoctorPanel`, `ConfigInspector`, embedded Horizon iframe (dev mode only), optional `spatie/laravel-activitylog ^4.12` for audit of destructive runs.
**Addresses:** DEVUI-01…05 from FEATURES.
**Avoids:** Destructive artisan-via-web pitfall, log-tailer-leaks-secrets pitfall.

### Phase 17 (F): CI/CD Pipeline + Code Signing
**Rationale:** Depends on D (desktop shell — the build target must exist) and E (dev mode — for "internal dev build" testing). Apple notarization + Azure Trusted Signing are the longest single integration block (~2-3 days).
**Delivers:** `.github/workflows/ci.yml` (PR gates: Larastan L10 strict + Pint + Pest, narrow matrix, TZ=Europe/Amsterdam, 3-times-green stability check), `.github/workflows/release.yml` (tag-triggered, macOS 14 + Windows 2025 + Ubuntu 24.04, electron-builder config, GitHub Encrypted Secrets for Apple Developer ID + Azure Trusted Signing, CODEOWNERS on workflows, `pull_request_target`-safe secret handling), per-install APP_KEY regen at first launch, `.env.bundled` template, gitleaks scan.
**Avoids:** Secrets-in-workflow-logs pitfall, fork-PR secret exposure, signing on every PR (only on tag).

### Phase 18 (G): Auto-Update Plumbing
**Rationale:** Depends on F (signing infrastructure must exist for signed update artifacts). Auto-update must verify signatures or it bypasses every signing investment.
**Delivers:** `electron-updater` wired through `Modules\Core\Public\Services\ElectronUpdateChannel`, GitHub Releases as update channel, Ed25519 publisher pin, "skip this version" UX, "you're on an old version" prompt, `SystemAlertsBanner` integration, signature-verification Pest test, first-install-can't-auto-update documentation for the beta partner.

### Phase 19 (H): Public Release Boundary
**Rationale:** Final pre-public scope — depends on every earlier phase. Catches anything that should be private but leaked. Big surface area, but per-item small.
**Delivers:** Deep Modules code review across 14 modules (cross-module hygiene + DI compliance + dead code + perf smells), GSD-leakage redaction sweep across runtime code + comments + views + error messages + log lines, Hippocratic License 3.0 LICENSE file + NOTICE explainer + SPDX `Hippocratic-3.0` in composer.json, `SECURITY.md` + `CONTRIBUTING.md` + `CODE_OF_CONDUCT.md`, README rewrite with the supplied SVG as hero + install instructions per platform + screenshots of every major view (committed alongside the brand-asset import), `resources/brand/logo.svg` committed + PNG exports for installer bundles, renderer-JSON audit (no secrets table leak), "Where is my data?" docs page, export-everything UX.

### Phase 20 (UAT): v1.0 UAT Close-Out
**Rationale:** User explicitly chose to close out the 25 deferred UAT scenarios + 3 `human_needed` verification artifacts inside v2.0 before public release. Sits between H (release boundary) and BETA so anything found during UAT is fixed before the partner sees it.
**Delivers:** Walk-through of all 25 UAT scenarios across Phases 03 / 04 / 06 / 08 / 11 with real data, resolution of 3 `human_needed` artifacts, divergence-from-synthesised-fixtures fixes, regression coverage for any bugs found.

### Phase 21 (BETA): Invite-Only Beta Cycle
**Rationale:** Last phase. Validates everything else on a real partner machine before opening the repo publicly. ING-09 stays deferred — out of scope.
**Delivers:** Invite-only release to partner + 1-2 others, fresh-account UAT on macOS + Windows, OAuth callback validation on partner's browsers, NativePHP first-run permission prompts validated, "where is my data?" answered from a real install, 1-2 weeks of daily-use feedback collected, blocker fixes, GitHub Issues link wired into the app, decision-gate: open repo publicly or run another beta round.

### Phase Ordering Rationale

- **Dependencies are strict and load-bearing.** A (auth) → B (paths) — parallel-able. C (queue) depends on B (config paths) + A (per-user uniqueness). D (desktop shell) depends on A + B + C — desktop is the first phase where the app runs as a native binary. E (dev mode) depends on A (is_developer) + D (desktop runtime). F (CI/CD) depends on D + E. G (auto-update) depends on F (signed artifacts). H (release boundary) depends on every earlier phase. UAT before BETA so beta starts from a known-good baseline. BETA last.
- **Three-new-modules pattern (Auth / Desktop / DevMode)** keeps NativePHP and dev-mode concerns isolated from the existing 11 domain modules. New `BoundaryArchTest` invariants enforce the boundary. No existing module needs more than constructor changes to consume `CurrentUserProvider`.
- **Horizon-drop is a milestone-level decision, not per-phase** — surfaces in Phase C (the actual rewire), but assumed by D, E, F, G as a baseline.
- **AppPaths-first** is the single most important sequencing rule. Every PITFALL-aware researcher independently flagged it.

### Research Flags

Phases likely needing deeper research during planning:
- **Phase 13 (B):** First-run migration UAT — the developer's real v1.0 SQLite is the worst possible test subject; needs a recoverable test environment + UAT plan
- **Phase 15 (D):** NativePHP file-association handler behavior on Windows + Linux (docs are macOS-leaning); 2-day spike
- **Phase 17 (F):** Apple notarization workflow on GitHub Actions runners; Azure Trusted Signing for Windows (untested with real code in this project); Linux `.AppImage` + `.deb` installer validation
- **Phase 18 (G):** electron-updater signature verification across all three platforms; first-install-can't-auto-update UX
- **Phase 21 (BETA):** Beta cohort >1 user concurrency on the same SQLite (file-locking behaviour under WAL)

Phases with standard patterns (skip deeper research):
- **Phase 12 (A):** Fortify integration is well-documented; CurrentUserProvider already exists in v1.0
- **Phase 14 (C):** `database` queue driver + `Cache::lock()` pattern is canonical Laravel 11+
- **Phase 16 (E):** Bespoke Livewire component pattern is the v1.0 default
- **Phase 19 (H):** Cross-module review + license + docs is standard release hygiene

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | All package versions verified against Packagist + GitHub Releases 2026-05-19. Only gap: PHP 8.5 bundling (10-min Larastan-L10-on-8.4 spike validates the workaround; trivially recoverable). |
| Features | HIGH | Desktop shell + multi-user patterns cribbed from Linear / Slack / 1Password / Tinkerwell with sources cited. Password-reset-without-SMTP required design invention; recovery-codes + CLI-fallback is the lowest-risk of three evaluated options. |
| Architecture | HIGH | All integration points with v1.0 invariants read directly from the codebase. Build order derived from dependency analysis, not opinion. AppPaths abstraction follows v1.0's `Modules/Core/Public/Services/*` pattern. |
| Pitfalls | HIGH (critical 3) / MEDIUM (moderate 7) | Critical pitfalls have concrete arch-testable prevention strategies. Moderate pitfalls require phase-specific spikes during v2.0 execution; none are blockers. |

**Overall confidence:** HIGH

### Gaps to Address

- **PHP 8.5-vs-8.4 in shipped bundle** — `nativephp/php-bin` 1.1.1 ships 8.1–8.4 only; project pin is `^8.5`. Run a Larastan-L10-on-8.4 spike during Phase B start; if it passes, bundle 8.4 and keep dev pin at 8.5 + add an 8.4 axis to the PR-gate matrix.
- **Windows code-signing pricing + provider final pick** — Recommended Azure Trusted Signing ($10/mo, GitHub-hosted-runner compatible). Validate during Phase F start before committing the matrix.
- **First-launch v1.0 → v2.0 migration UX** — Native Electron modal vs in-app Livewire splash; how to discover old install path heuristically (`$HOME/code/diederik/database/database.sqlite`? Read Herd's pinned-sites list?). Decide during Phase B planning.
- **Anonymous install UUID for crash reporting** — Even an opaque UUID is identifying data. If Sentry ships (deferred decision), recommend dedup-by-stack-hash + no UUID. Re-evaluate at Phase 21 / BETA.
- **Hippocratic License 3.0 dependency-bundling compatibility** — NativePHP / Electron's NPM-side dependencies have their own licenses; audit during Phase H to confirm bundling-and-redistribution is OK under HL3.
- **macOS notarization timing in CI** — First notarization can take 5-15 minutes; CI matrix needs a generous timeout. Verify in Phase F.

## Sources

### Primary (HIGH confidence)
- [NativePHP Desktop v2 — official docs](https://nativephp.com/docs/desktop/2/) — installation, configuration, files, databases, queues, building, updating, menu, application
- [NativePHP v2 announcement](https://nativephp.com/blog/nativephp-for-desktop-v2-released)
- [nativephp/desktop on Packagist](https://packagist.org/packages/nativephp/desktop)
- [nativephp/php-bin on Packagist](https://packagist.org/packages/nativephp/php-bin)
- [Laravel 13 release notes](https://laravel.com/docs/13.x/releases)
- [Laravel 13 — Artisan, Passwords, Queues docs](https://laravel.com/docs/13.x/)
- [laravel/fortify on Packagist](https://packagist.org/packages/laravel/fortify) — 1.37.2, 2026-05-15
- [laravel/horizon on Packagist](https://packagist.org/packages/laravel/horizon)
- [apple-actions/import-codesign-certs v7.0.0](https://github.com/apple-actions/import-codesign-certs)
- [Azure/trusted-signing-action v2.0.0](https://github.com/Azure/trusted-signing-action)
- [electron-builder Auto-Update + Code-Signing docs](https://www.electron.build/auto-update.html)
- [Apple — Configuring the Hardened Runtime](https://developer.apple.com/documentation/xcode/configuring-the-hardened-runtime)
- [Hippocratic License 3.0 — firstdonoharm.dev](https://firstdonoharm.dev/learn/)
- [Hippocratic License FAQ (intentional OSI/OSD non-compliance)](https://github.com/EthicalSource/hippocratic-license/blob/main/content/faq.md)
- [SPDX license-list-XML — Hippocratic 3.0 listing](https://github.com/spdx/license-list-XML/issues/1393)

### Secondary (MEDIUM confidence)
- [Distributing NativePHP Apps with Auto-Update Support — TheCodingDev](https://www.thecodingdev.com/2025/04/distributing-nativephp-apps-with-auto.html)
- [Code Signing and Security Considerations for NativePHP Apps — TheCodingDev](https://www.thecodingdev.com/2025/04/code-signing-and-security.html)
- [Doyensec ElectronSafeUpdater reference](https://github.com/doyensec/ElectronSafeUpdater)
- [Linear — How we redesigned the Linear UI (part II)](https://linear.app/now/how-we-redesigned-the-linear-ui)
- [Raycast Manual — Search Bar / command palette](https://manual.raycast.com/search-bar)
- [Firefly III — Multi-User documentation + GitHub #6331 retrospective](https://docs.firefly-iii.org/how-to/firefly-iii/features/multi-user/)
- [Slack — Switch between workspaces (profile selector pattern)](https://slack.com/help/articles/1500002200741)
- [1Password — How to use multiple accounts + recovery codes](https://blog.1password.com/introducing-1password-recovery-codes/)
- [Sentry Electron SDK — privacy + native crash docs](https://docs.sentry.io/platforms/javascript/guides/electron/)
- [Signal — Debug Logs and Crash Reports policy](https://support.signal.org/hc/en-us/articles/360007318591)

### Tertiary (LOW confidence)
- `cleaniquecoders/laravel-artisan-runner` (1 GitHub star, evaluated and rejected for bespoke approach)
- `stepanenko3/nova-command-runner` (Nova-specific reference pattern only)
- Beekeeper Studio + TablePlus desktop dev-tooling UX (referenced as design precedent, not direct dependency)

---
*Research completed: 2026-05-19*
*Ready for roadmap: yes*
