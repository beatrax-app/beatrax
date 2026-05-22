---
phase: 15-desktop-shell-nativephp-integration
plan: 05
subsystem: infra
tags: [nativephp, electron, desktop, icons, branding, hardened-runtime, entitlements, ci, github-actions, php84, migrations, livewire]

# Dependency graph
requires:
  - phase: 15-desktop-shell-nativephp-integration
    plan: 01
    provides: NativePHP install + Modules/Desktop bounded module + the `noNativePhpImportsOutsideDesktopModule` arch invariant — this plan publishes the Electron project on top of that
  - phase: 15-desktop-shell-nativephp-integration
    plan: 06
    provides: Tailwind v4 dark-variant infrastructure (users.theme column, layout dark-class wiring, `darkCompanionUtilitiesOnThemedViews` arch guard) — the new SetupScreen / WelcomeScreen views are authored dark-aware
  - phase: 13-app-paths
    provides: UserDataPathService resolves the SQLite path under NATIVEPHP_STORAGE_PATH — FirstLaunchBootstrap routes its DB path through it
  - phase: 12-multi-user-activation
    provides: /signup gate (FirstUserOnlyMiddleware) — WelcomeScreen's "Get started" leads there
provides:
  - Published Electron project (php artisan native:install --publish ran once, exporting nativephp/electron/)
  - Brand asset moved from .planning/brand/logo.svg to resources/brand/logo.svg (D-20)
  - Platform icons: public/icon.png (512), public/icon.icns (macOS), public/icon.ico (Windows multi-resolution), resources/brand/tray-icon.png (44px monochrome template, D-19)
  - scripts/nativephp_stage_build_resources.php — prebuild hook copying committed icons + entitlements into the (gitignored) electron working dir
  - FirstLaunchBootstrap (Modules/Desktop/Internal/Native/) — idempotent every-launch migration runner with fresh-install detection (D-21/D-22/D-23)
  - EnsureDatabaseReady middleware (Modules/Desktop/Internal/Http/Middleware/) — gates app routes behind the migration-pending check
  - SetupScreen + WelcomeScreen stateless Livewire components + Blade views (dark-aware)
  - desktop.setup + desktop.welcome routes outside the auth group
  - build/entitlements.mac.plist — macOS Hardened Runtime entitlements (PKG-08)
  - .github/workflows/ci.yml — CI PHP 8.4 axis skeleton (PKG-07)
  - composer.json php constraint relaxed from ^8.5 to ^8.4 so the 8.4 CI axis can install
affects: [15-02-native-chrome, 15-03-worker-daemon, 15-04-file-associations, 15-07-dark-theme-retrofit, 16-developer-mode-ui, 17-cicd-pipeline-code-signing, 18-auto-update-plumbing]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Committed build inputs in `public/` + `build/` are staged into the (gitignored) `nativephp/electron/build/` directory by a `prebuild` hook so the published Electron project regenerates cleanly while the canonical assets stay in source control"
    - "`/build` is gitignored EXCEPT `build/entitlements.mac.plist` — a static build input, not a build artefact"
    - "FirstLaunchBootstrap routes the SQLite path through UserDataPathService (no raw `database_path()`); `isFreshInstall()` uses the query builder via DatabaseManager so the static-method-dynamic-call PHPStan rule stays satisfied"
    - "Livewire stateless screens — zero properties, no constructor, method-parameter DI on `render()` — mirroring the v1.0 SystemAlertsBanner shape"
    - "CI workflow shaped as a single-axis `php: ['8.4']` matrix so Phase 17 can extend to `['8.4','8.5']` by appending one entry"

key-files:
  created:
    - resources/brand/logo.svg
    - resources/brand/tray-icon.png
    - public/icon.png
    - public/icon.icns
    - public/icon.ico
    - scripts/nativephp_stage_build_resources.php
    - Modules/Desktop/Internal/Native/FirstLaunchBootstrap.php
    - Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php
    - Modules/Desktop/Internal/Http/Livewire/SetupScreen.php
    - Modules/Desktop/Internal/Http/Livewire/WelcomeScreen.php
    - Modules/Desktop/Resources/views/setup.blade.php
    - Modules/Desktop/Resources/views/welcome.blade.php
    - Modules/Desktop/tests/Feature/FirstLaunchBootstrapTest.php
    - Modules/Desktop/tests/Unit/HardenedRuntimeEntitlementsTest.php
    - build/entitlements.mac.plist
    - .github/workflows/ci.yml
  modified:
    - composer.json
    - composer.lock
    - config/nativephp.php
    - .gitignore
    - Modules/Desktop/Providers/DesktopServiceProvider.php
    - Modules/Desktop/Routes/web.php

key-decisions:
  - "Platform icons are pre-rendered + committed (D-17 mechanism is Claude's discretion) — sips + iconutil for .icns + PHP/GD for .ico + qlmanage for the base PNG. Simpler than a build-time rasteriser and deterministic on every machine"
  - "Tray icon is a black silhouette with original alpha (template image) — macOS/Windows/Linux tint it for the active menu-bar theme (D-19)"
  - "Brand assets stay tracked in source control (`public/icon.*`, `build/entitlements.mac.plist`, `resources/brand/*`) while the `nativephp/` Electron working dir stays gitignored. A `prebuild` script stages the tracked files into the working dir on every build"
  - "Deleted the `--publish`-generated `app/Providers/NativeAppServiceProvider.php` stub (it uses the `Window` facade outside `Modules/Desktop`, violating `noNativePhpImportsOutsideDesktopModule`). The module already has its own provider — the stub is dead code"
  - "Migrator delegation tested via a spy migrator (constructed from the real container's collaborators) — the RefreshDatabase transaction forbids the DDL / VACUUM the real migrate path would issue, so a behaviour-level spy is the honest test shape"
  - "Relaxed composer.json `php` from `^8.5` to `^8.4` so the 8.4 CI axis can `composer install`. The dev box still runs 8.5; the relaxation matches the v2.0 milestone decision (bundle 8.4, dev 8.5)"
  - "`EnsureDatabaseReady` exempts the setup route by NAME (not URL), so future setup-route variants can be added by registering them under the same name prefix"

patterns-established:
  - "Prebuild staging hook (`scripts/nativephp_stage_build_resources.php`) registered in `config/nativephp.php` `prebuild` — copies committed build inputs into the gitignored electron working dir before each `native:build`"
  - "Idempotent every-launch migration pattern — runs pending migrations on every boot, no-op when none pending (D-23). Phase 18 auto-updates will absorb new migrations through this same path"
  - "Centered full-screen layout shell for pre-auth pages (setup + welcome) reuses the login-screen pattern: `min-h-screen flex items-center justify-center bg-white dark:bg-slate-950`"
  - "CI skeleton matrix shape — single-axis `php: ['8.4']` with explicit `TZ=Europe/Amsterdam` so date-bucketing semantics match the dev box"

requirements-completed: [PKG-04, PKG-07, PKG-08]

# Metrics
duration: ~50min
completed: 2026-05-23
---

# Phase 15 Plan 05: First-Launch DB Bootstrap + Build-Readiness Scaffolding Summary

**The Electron project is published; brand icons + a monochrome tray template ship at canonical paths; a fresh install boots through a "Setting up…" screen → "Welcome to diederik" → /signup with pending migrations running idempotently on every launch; macOS Hardened Runtime entitlements are configured; and a CI PHP 8.4 axis skeleton runs the three quality gates.**

## Performance

- **Duration:** ~50 min (Task 1 start → final commit)
- **Started:** 2026-05-23T00:38Z
- **Completed:** 2026-05-23T01:00Z (approx)
- **Tasks:** 4 of 4
- **Files created:** 16
- **Files modified:** 6

## Accomplishments

- Published the Electron project (`php artisan native:install --publish`) and committed the canonical brand assets — the logo SVG moved to `resources/brand/logo.svg`, with `public/icon.png` (512), `.icns` (macOS), `.ico` (Windows multi-resolution) and the monochrome `resources/brand/tray-icon.png` (44 px template silhouette) all derived and staged into the electron-builder `buildResources` directory by a new prebuild hook.
- Delivered the first-launch DB bootstrap: `FirstLaunchBootstrap` runs pending migrations idempotently every launch (D-23) through the framework `Migrator` against the `UserDataPathService`-resolved SQLite path, reports fresh-install state (D-22), and the `EnsureDatabaseReady` middleware redirects gated routes to `desktop.setup` while migrations are pending (D-21).
- Added the two-screen first-launch flow as stateless Livewire components (`SetupScreen` / `WelcomeScreen`), with views authored dark-aware so they pass Plan 07's tighter `darkCompanionUtilitiesOnThemedViews` arch guard.
- Created `build/entitlements.mac.plist` carrying the two boolean-true entitlements required for the bundled static PHP interpreter under macOS Hardened Runtime (PKG-08); narrowed `.gitignore` to track only the entitlements file inside `/build`.
- Landed the CI PHP 8.4 axis skeleton (`.github/workflows/ci.yml`): `pull_request` + `main` push triggers, `ubuntu-latest` with `TZ=Europe/Amsterdam`, single-axis `php: ['8.4']` matrix shaped for Phase 17 to extend, running Pint + Larastan L10 strict + Pest. Relaxed `composer.json` `php` from `^8.5` to `^8.4` so the runner can install.

## Task Commits

Each task was committed atomically (TDD tasks 2 and 3 produced separate test + impl commits):

1. **Task 1: Publish Electron project + brand asset + platform icons** — `042acbc` (feat)
2. **Task 2: First-launch DB bootstrap + Setting up… / Welcome screens (TDD)**
   - RED: `fcdbe2c` (test) — 10 failing FirstLaunchBootstrap expectations
   - GREEN: `8fabd53` (feat) — FirstLaunchBootstrap + EnsureDatabaseReady + Setup/WelcomeScreen + views/routes/provider wiring
3. **Task 3: macOS Hardened Runtime entitlements file (TDD)**
   - RED: `4cde9b0` (test) — 4 failing HardenedRuntimeEntitlementsTest expectations
   - GREEN: `dada476` (feat) — `build/entitlements.mac.plist` + `.gitignore` carve-out
4. **Task 4: CI PHP 8.4 axis skeleton** — `dc71ccc` (ci)

**Plan metadata:** committed separately with this SUMMARY.

## Files Created/Modified

### Created

- `resources/brand/logo.svg` — canonical brand asset (moved from `.planning/brand/logo.svg`, D-20)
- `resources/brand/tray-icon.png` — 44 px monochrome / template tray icon (D-19); RGB collapsed to black with original alpha so macOS/Windows/Linux tint it for the active menu-bar theme
- `public/icon.png` — 512×512 app icon (Linux + base)
- `public/icon.icns` — macOS bundle/dock icon (16/32/64/128/256/512 + @2x variants via `iconutil`)
- `public/icon.ico` — Windows multi-resolution icon (16/32/48/256, PNG-embedded ICO format)
- `scripts/nativephp_stage_build_resources.php` — prebuild hook copying `public/icon.*` + `build/entitlements.mac.plist` into `nativephp/electron/build/` before each build
- `Modules/Desktop/Internal/Native/FirstLaunchBootstrap.php` — idempotent migration runner + fresh-install detection + `UserDataPathService`-routed SQLite path
- `Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php` — redirects gated routes to `desktop.setup` while migrations are pending; exempts the setup route by name
- `Modules/Desktop/Internal/Http/Livewire/SetupScreen.php` — stateless Livewire component for the "Setting up…" boot screen
- `Modules/Desktop/Internal/Http/Livewire/WelcomeScreen.php` — stateless Livewire component for the welcome screen
- `Modules/Desktop/Resources/views/setup.blade.php` — centered "Setting up…" layout, polls every 2s via `wire:poll`, dark-aware
- `Modules/Desktop/Resources/views/welcome.blade.php` — centered "Welcome to diederik" + emerald "Get started" → `/signup`, dark-aware
- `Modules/Desktop/tests/Feature/FirstLaunchBootstrapTest.php` — 10 expectations covering D-21/D-22/D-23 + middleware redirect
- `Modules/Desktop/tests/Unit/HardenedRuntimeEntitlementsTest.php` — 4 expectations validating the entitlements plist
- `build/entitlements.mac.plist` — XML plist with the two Hardened Runtime keys
- `.github/workflows/ci.yml` — CI PHP 8.4 axis skeleton

### Modified

- `composer.json` — relaxed `php` constraint from `^8.5` to `^8.4`; `post-update-cmd` auto-updated to include `--publish`
- `composer.lock` — regenerated to record the new platform requirement (no package versions changed)
- `config/nativephp.php` — added the `nativephp_stage_build_resources.php` prebuild hook
- `.gitignore` — narrowed `/build` to `/build/*` with `!/build/entitlements.mac.plist` carve-out
- `Modules/Desktop/Providers/DesktopServiceProvider.php` — registers the two Livewire components in `boot()`
- `Modules/Desktop/Routes/web.php` — adds `desktop.setup` and `desktop.welcome` routes (outside the `auth` group)

## Decisions Made

- **Icons are pre-rendered + committed.** Stack-native macOS tools (`sips`, `iconutil`, `qlmanage`) cover the macOS + Linux icons; PHP/GD encodes the multi-resolution `.ico` (PNG-embedded format) without pulling a new dependency. The brand SVG is a base64-embedded raster, so a build-time vector rasteriser would have offered no advantage.
- **Tray icon = template image.** RGB collapsed to black, alpha preserved — the macOS template-image convention (D-19); identical bytes work on Windows + Linux because they consume the silhouette via alpha.
- **Committed build inputs stay outside the gitignored `nativephp/` working dir.** The canonical icons live at `public/icon.*` (the in-repo brand surface), the entitlements file at `build/entitlements.mac.plist`. A `prebuild` hook stages them into `nativephp/electron/build/` on every build — cleaner than special-casing the published electron-builder config (which `native:install --publish` regenerates).
- **`FirstLaunchBootstrap::isFreshInstall()` uses `DatabaseManager::table('users')->count()`, not `User::query()->count()`.** PHPStan flags the latter as a dynamic call to a static method; the `FirstUserOnlyMiddleware` already established the query-builder pattern in this project.
- **The "runs pending migrations" test uses a spy migrator** (constructed from the real container's collaborators) rather than driving the real Migrator against the RefreshDatabase-transactional DB. The transaction forbids the DDL + VACUUM the real migrate path would need, so a behaviour-level spy is the honest expression of the contract ("`FirstLaunchBootstrap::runPendingMigrations` invokes `Migrator::run` with the registered paths").
- **`composer.json` `php: ^8.4`.** The shipped bundle's PHP is 8.4 (`nativephp/php-bin`), the dev box runs 8.5; the constraint allows both. This is the spike outcome the planner anticipated in STATE.md.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `--publish` generated a duplicate, facade-using `NativeAppServiceProvider` stub at `app/Providers/`**
- **Found during:** Task 1 (after `php artisan native:install --publish`).
- **Issue:** `native:install --publish` published `app/Providers/NativeAppServiceProvider.php` from a NativePHP stub that calls `Native\Desktop\Facades\Window`. That file lives outside `Modules/Desktop/`, so `noNativePhpImportsOutsideDesktopModule` would fail. The project already has `Modules/Desktop/Internal/NativeAppServiceProvider.php` and `config/nativephp.php` points `provider` at the module-internal one — the published stub is dead code that only contaminates the arch guard.
- **Fix:** Deleted `app/Providers/NativeAppServiceProvider.php`. The module-internal provider remains the sole NativePHP boot path.
- **Files modified:** `app/Providers/NativeAppServiceProvider.php` (deleted).
- **Verification:** `php artisan test --filter='noNativePhpImportsOutsideDesktopModule'` → 1 passed; the arch invariant stayed green.
- **Committed in:** `042acbc` (Task 1 commit).

**2. [Rule 3 - Blocking] `/build` was wholly gitignored, but Task 3 needs `build/entitlements.mac.plist` tracked**
- **Found during:** Task 3 (preparing to commit the entitlements file).
- **Issue:** The plan's `files_modified` list expects `build/entitlements.mac.plist` to be tracked, but the existing `.gitignore` entry `/build` swallows the entire directory because NativePHP also uses `base_path('build')` as build-output staging. Without a carve-out, the file would never enter source control.
- **Fix:** Narrowed `/build` to `/build/*` and added an explicit negation `!/build/entitlements.mac.plist`. The lone committed file is now tracked while every other path under `/build` (build outputs from `native:build`) stays ignored.
- **Files modified:** `.gitignore`.
- **Verification:** `git status --short` shows the entitlements file as tracked; `git check-ignore build/some-other-file` confirms the rest of `/build` still ignores everything.
- **Committed in:** `dada476` (Task 3 commit).

**3. [Rule 2 - Missing Critical] CI 8.4 axis needs the composer `php` constraint relaxed**
- **Found during:** Task 4 (writing the CI workflow).
- **Issue:** `composer.json` required `^8.5` so a GitHub Actions runner on PHP 8.4 would refuse to `composer install`. The 8.4 axis would have failed on the very first step.
- **Fix:** Relaxed the `php` constraint from `^8.5` to `^8.4`. Matches the v2.0 milestone decision recorded in STATE.md ("bundle 8.4, dev pin 8.5, add 8.4 axis to PR-gate matrix"). Ran `composer update --lock` to regenerate the lock file's platform metadata without bumping any package versions.
- **Files modified:** `composer.json`, `composer.lock`.
- **Verification:** `composer validate --strict` → clean; the dev-side 8.5 environment still runs `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` clean post-change.
- **Committed in:** `dc71ccc` (Task 4 commit).

---

**Total deviations:** 3 auto-fixed (2 blocking, 1 missing-critical).
**Impact on plan:** Every auto-fix is necessary scaffolding the plan implicitly assumes — none represent scope creep. No architectural decisions required (the gitignore carve-out is the only nuanced one; it follows the standard "narrow the rule, negate the exception" gitignore pattern).

## Issues Encountered

- **`RefreshDatabase` transaction forbids the DDL the "run pending migrations" test wanted.** Multiple attempts to drop+remigrate inside the test failed (`VACUUM in transaction`, `PRAGMA foreign_keys` ineffective inside a transaction, FK-constrained drops failing). Resolved by switching that test to a spy-migrator strategy — the same testing shape v1.0 used for behaviour-only checks on framework collaborators.
- **`sips` cannot output `.ico` natively.** Resolved by writing a 24-line PHP encoder that wraps PNG payloads in the standard ICONDIR/ICONDIRENTRY header (Vista+ accepts PNG-embedded ICOs). No new dependency.

## Quality Gates (verified on HEAD `dc71ccc`)

- **Larastan level 10 strict:** `vendor/bin/phpstan analyse --memory-limit=1G` → **No errors.**
- **Laravel Pint:** `vendor/bin/pint --test` → **passed.**
- **Pest suite (full project):** `composer test` → **2080 passed** (24637 assertions), 6 todos, 6 skipped, 14 notices, **0 failures.** The 6 todos are the Wave 0 scaffolding stubs from plan 15-01 (intentional, picked up by plans 15-02 and 15-04).
- **Targeted suites:**
  - `php artisan test --filter='FirstLaunchBootstrap'` → **10 passed** (19 assertions).
  - `php artisan test Modules/Desktop/tests/Unit/HardenedRuntimeEntitlementsTest.php` → **4 passed** (10 assertions).
  - `php artisan test --testsuite=Contracts` → **111 passed** (607 assertions) — every arch invariant still green, including `noNativePhpImportsOutsideDesktopModule`, `noStoragePathHardCodedOutsideUserDataPathService`, and `darkCompanionUtilitiesOnThemedViews`.
- **`plutil -lint build/entitlements.mac.plist`** → `OK`.

## User Setup Required

None — no external service configuration. The CI workflow runs on GitHub-hosted runners with no extra credentials; macOS notarization secrets are deliberately deferred to Phase 17.

## Next Phase Readiness

- **Plan 15-02 (native chrome):** No new blockers. The published Electron project is now in place for plan 02's window/menu/tray work.
- **Plan 15-03 (worker daemon + close-window prompt):** `EnsureDatabaseReady` middleware is reusable; the desktop module Livewire-component registration pattern is established for the close-prompt modal.
- **Plan 15-04 (file associations):** No new blockers. The published Electron project's `protocols` / file-association config is the surface plan 04 edits.
- **Plan 15-07 (dark-theme retrofit):** The new SetupScreen / WelcomeScreen views are authored dark-aware; the `darkCompanionUtilitiesOnThemedViews` arch guard's allow-list can drop `Desktop` once plan 07 runs its full sweep.
- **Phase 17 (CI/CD + signing):** The workflow shape (single-axis matrix, `TZ=Europe/Amsterdam`, three gates) is ready for Phase 17 to extend to `['8.4','8.5']` and add build/release/signing jobs. The entitlements file is in place for hardened-runtime notarization.
- **Phase 18 (auto-update):** `FirstLaunchBootstrap`'s every-launch idempotent migration is the supporting hook — new migrations shipped with auto-update releases will absorb cleanly on the next boot.

## Self-Check: PASSED

- All 16 created files FOUND on disk.
- All 6 task commits (`042acbc`, `fcdbe2c`, `8fabd53`, `4cde9b0`, `dada476`, `dc71ccc`) FOUND in `git log`.
- `composer validate --strict` → clean.
- `plutil -lint build/entitlements.mac.plist` → OK.
- Pint, Larastan L10 strict, Pest full suite, Contracts arch suite — all green.

---
*Phase: 15-desktop-shell-nativephp-integration*
*Completed: 2026-05-23*
