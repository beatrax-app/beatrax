---
phase: 15-desktop-shell-nativephp-integration
verified: 2026-05-23T12:00:00Z
human_verified: 2026-05-23T23:40:00Z
status: passed
score: 7/7 must-haves verified
overrides_applied: 0
human_verification_outcome: "All manual UAT items confirmed by the user post-fix. Item 4 (Electron close-intercept JS glue) is the acknowledged carry-forward stub documented in Modules/Desktop/Routes/web.php and 15-04-SUMMARY.md — phase goal does not require the Electron-side trigger. Resolution chain captured in 15-HUMAN-UAT.md."
human_verification:
  - test: "Launch the fresh-install .dmg on a clean macOS machine (or clean ~/Library/Application Support) — confirm the 'Setting up...' screen appears while migrations run, transitions to the 'Welcome to diederik' screen, and the 'Get started' button leads to /signup"
    expected: "Sequential flow: Setting up... -> Welcome -> /signup without manual intervention"
    why_human: "FirstLaunchBootstrap.runPendingMigrations() is wired and EnsureDatabaseReady is registered, but the first-launch sequence requires a real NativePHP bundle launch with no pre-existing DB — cannot be replicated by grep or test suite alone (feature tests use RefreshDatabase)"
  - test: "In the running bundled app, navigate to Settings and toggle Appearance between Light, Dark, and System. Confirm the UI repaints correctly in each mode with no unthemed white surfaces visible in any of the 11 modules."
    expected: "Dark mode applies across every module view; no bg-white or text-slate-900 surfaces without dark: companions; System mode follows OS preference without a flash"
    why_human: "darkCompanionUtilitiesOnThemedViews arch guard passes (verified), but PHP-built class strings (chain-node tierClasses/cardClasses, OAuth wizard badges, recurring Save button) are not scanned by the guard — visual confirmation is required for WCAG AA on dark surfaces"
  - test: "With the bundled app running, double-click a .csv file and then a .eml file in Finder. Confirm diederik opens (or focuses if already open) and the file-staging page shows the received file with the correct copy for each extension type."
    expected: "OS 'Open With diederik' surfaces for both extensions; the file-staging page shows 'A bank or PayPal export' for .csv and 'An email receipt' for .eml; single-instance: second double-click when app is already open focuses the existing window"
    why_human: "The PHP-side FileOpenIntake and HandleNativeOpenFile are tested; the Electron-side cross-OS argv/second-instance handling (Windows/Linux cold-start path) is untested against real installers — manual macOS cold-start verification and single-instance focus confirmation are needed"
  - test: "With the bundled app running (after first launch + account setup), click the window close button (X). Confirm the close-prompt modal ('Quit diederik?' with Quit and Keep in Tray options) appears and that selecting each option behaves correctly."
    expected: "First close: modal appears; selecting Quit closes the app; selecting Keep in Tray hides the window to the tray. Subsequent closes apply the remembered choice without showing the modal again."
    why_human: "CR-03 is fixed: CloseWindowPrompt.mount() now dispatches modal-show and the route is registered. However, the Electron-side close-intercept (the JS hook in nativephp/electron/src/main/index.js that navigates to /desktop/close-prompt on X-click) is explicitly documented as deferred ('JS glue deferred from plan 15-03' in Routes/web.php line 76 and CloseActionController docblock). The PHP side is fully wired; the Electron trigger is not yet committed. Manual verification is required to confirm whether the close intercept works end-to-end."
---

# Phase 15: Desktop Shell (NativePHP Integration) Verification Report

**Phase Goal:** Ship the NativePHP-packaged macOS desktop shell — installable `.dmg`, native chrome (window, app menu, system tray), OS file associations (`.csv` -> Import, `.eml` -> Receipts), self-supervising bundled worker, first-launch DB bootstrap with setup/welcome screens, dark-theme support across every module, and the Hardened Runtime / CI PHP 8.4 build-readiness scaffolding.

**Verified:** 2026-05-23T12:00:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `nativephp/desktop ^2.2` installed and verified | VERIFIED | `composer show nativephp/desktop` reports `2.2.0`; wired in `config/nativephp.php` |
| 2 | `php artisan native:build` produces a launchable macOS `.dmg` | VERIFIED | Human-verified in plan 15-01 Task 4 after a 6-cycle debug investigation; all root causes (Team ID mismatch, empty NativeAppServiceProvider.boot(), stale bootstrap cache, missing post-autoload-dump script) fixed and committed |
| 3 | Native chrome: window, app menu, system tray, dark-mode follows OS | VERIFIED | `NativeAppServiceProvider.boot()` opens window via `WindowManager`, calls `Menu::create(...$this->appMenu->build())` and `MenuBar::create()->icon(...)->withContextMenu(...)`. `AppMenuBuilder` and `TrayMenuBuilder` exist and are unit-tested. `OsThemeSignal` contract is bundle-gated and layout-wired. |
| 4 | OS file associations: double-clicking `.csv`/`.eml` opens diederik with ingestion intent | VERIFIED | `fileAssociations` block in `nativephp/electron/electron-builder.mjs` registers both extensions. `HandleNativeOpenFile` listener, `FileOpenIntake` security boundary, `PendingFileIntent` session store, and `ContinuePendingFileIntentAfterLogin` all exist and are tested. Electron `index.js` has single-instance lock + argv path extraction + `second-instance` handler. |
| 5 | Self-supervising bundled worker (crash detection, OS notification, system alert) | VERIFIED | `SurfaceWorkerCrashAlert` listener with crash-loop detection (`WindowFocusState`, de-dup guard, `system_alerts` row insert, bundle-gated OS notification) exists and has 16 passing unit/feature tests. |
| 6 | First-launch DB bootstrap + setup/welcome screens | VERIFIED | `FirstLaunchBootstrap.runPendingMigrations()` called from `NativeAppServiceProvider.boot()` (line 80); `EnsureDatabaseReady` registered on web middleware group in `bootstrap/app.php` (line 32); `SetupScreen` and `WelcomeScreen` stateless Livewire components exist with dark-aware views; 15 passing FirstLaunchBootstrap tests |
| 7 | Dark-theme across every module + arch guard regression lock | VERIFIED | `darkCompanionUtilitiesOnThemedViews` arch test passes (1 test, 1 assertion); allow-list deleted (plan 15-07 Task 3); all 11 modules themed; `resources/css/app.css` uses `@custom-variant dark`; layout dark-class wiring handles null `OsThemeSignal` correctly |

**Score:** 7/7 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Desktop/Providers/DesktopServiceProvider.php` | Desktop module registration entry point | VERIFIED | `final class DesktopServiceProvider`, registered unconditionally in `bootstrap/providers.php` line 40 |
| `Modules/Desktop/Internal/NativeAppServiceProvider.php` | NativePHP-booted provider | VERIFIED | Calls `runPendingMigrations()`, opens window, installs app menu and system tray |
| `Modules/Desktop/Public/Events/FileOpenedFromOs.php` | Public file-open intent event DTO | VERIFIED | `final readonly class FileOpenedFromOs` with `string $path`, `string $extension` |
| `Modules/Desktop/Internal/Native/FirstLaunchBootstrap.php` | Idempotent migration runner + fresh-install detection | VERIFIED | Exists, routes DB path through `UserDataPathService`, `runPendingMigrations()` is called from `NativeAppServiceProvider.boot()` |
| `Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php` | Setup-screen gate middleware | VERIFIED | Registered in `bootstrap/app.php` on web middleware group; exempt routes use name-prefix matching (`desktop.setup.*`) |
| `build/entitlements.mac.plist` | macOS Hardened Runtime entitlements (PKG-08) | VERIFIED | Both required keys (`com.apple.security.cs.allow-unsigned-executable-memory` and `com.apple.security.cs.disable-library-validation`) present and `true`; `plutil -lint` clean |
| `.github/workflows/ci.yml` | CI PHP 8.4 axis skeleton (PKG-07) | VERIFIED | Exists; `pull_request` trigger; `ubuntu-latest` with `TZ: Europe/Amsterdam`; single-axis `php: ['8.4']` matrix; SKELETON comment present |
| `resources/brand/logo.svg` | Canonical brand asset | VERIFIED | Present at canonical path; `.planning/brand/logo.svg` is absent |
| `public/icon.png`, `public/icon.icns`, `public/icon.ico`, `resources/brand/tray-icon.png` | Platform icons | VERIFIED | All four files present; monochrome tray icon (44 px, black silhouette + alpha) wired in `NativeAppServiceProvider` |
| `tests/Contracts/BoundaryArchTest.php` | Three arch invariants for Desktop module | VERIFIED | `noNativePhpImportsOutsideDesktopModule` (passes, 1 assertion), `Modules\Desktop\Internal is only used inside Modules\Desktop` (present), facade carve-out with Desktop listeners in `ignoring([])` list |
| `Modules/Desktop/Internal/Native/FileAssociationSpike.md` | Cross-OS file association design record | VERIFIED | Documents macOS/Windows/Linux handling decisions, electron-builder config edits, security notes, and open items deferred to Phase 17/21 |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `bootstrap/providers.php` | `Modules\Desktop\Providers\DesktopServiceProvider` | provider registration array | WIRED | Line 40: `DesktopServiceProvider::class` |
| `config/nativephp.php` | `Modules\Desktop\Internal\NativeAppServiceProvider` | `provider` config key | WIRED | Line 56: `'provider' => NativeAppServiceProvider::class` |
| `bootstrap/app.php` | `EnsureDatabaseReady` | `$middleware->web(append: [...])` | WIRED | Lines 10, 32 — imported and registered on web group |
| `NativeAppServiceProvider::boot()` | `FirstLaunchBootstrap::runPendingMigrations()` | constructor DI + direct call | WIRED | Line 80: `$this->bootstrap->runPendingMigrations()` — before window open |
| `nativephp/electron/electron-builder.mjs` | `.csv`/`.eml` OS file associations | `fileAssociations` block | WIRED | Lines 107-130: both extensions registered with correct IANA MIME types and macOS roles |
| `Modules/Desktop/Providers/DesktopServiceProvider.php` | NativePHP HTTP client subscribers | `nativephp-internal.running` gate | WIRED | Lines 148, 192: bundle-gating confirmed — OsThemeProbe binding and OS notification listeners only subscribe inside bundle |
| `resources/css/app.css` | Tailwind dark variant | `@custom-variant dark (&:where(.dark, .dark *))` | WIRED | Line 8: class-strategy dark variant definition |
| `resources/views/layouts/app.blade.php` | `OsThemeSignal` contract | `app()->bound(OsThemeSignal::class)` guard + nullable `currentOsTheme()` | WIRED | Lines 24-33: bundle-gated OS theme probe; null falls through to pre-paint script correctly (WR-05 fix) |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `SetupScreen` | `hasPendingMigrations()` | `FirstLaunchBootstrap` via `MigrationRepository` | Yes — queries real migration repository | FLOWING |
| `WelcomeScreen` | Stateless — no data fetch; navigates to `/signup` | N/A | N/A | N/A |
| `FileStagingPage` | `$this->intent->pending()` | `PendingFileIntent` session store | Yes — reads from session | FLOWING |
| `CloseWindowPrompt` | `$user->close_behavior` | User Eloquent model, `close_behavior` column | Yes — DB column via Eloquent | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| `noNativePhpImportsOutsideDesktopModule` arch test | `php artisan test --filter="noNativePhpImportsOutsideDesktopModule"` | 1 passed (1 assertion) | PASS |
| `darkCompanionUtilitiesOnThemedViews` arch test | `php artisan test --filter="darkCompanionUtilitiesOnThemedViews"` | 1 passed (1 assertion) | PASS |
| No facades outside carve-out | `php artisan test --filter="no Laravel facade usage in module code"` | 1 passed (2 assertions) | PASS |
| FirstLaunchBootstrap tests | `php artisan test --filter="FirstLaunchBootstrap"` | 15 passed + 1 todo (29 assertions) | PASS |
| HardenedRuntimeEntitlements tests | `php artisan test --filter="hardened runtime entitlements"` | 4 passed (10 assertions) | PASS |
| Desktop module full suite | `php artisan test Modules/Desktop/tests/` | 89 passed + 8 todos (173 assertions) | PASS |
| Full project test suite | `php artisan test` | 2172 passed, 8 todos, 6 skipped, 0 failures | PASS |
| Contracts arch suite | `php artisan test --testsuite=Contracts` | 111 passed (607 assertions) | PASS |

### Probe Execution

Step 7c: SKIPPED — no conventional `scripts/*/tests/probe-*.sh` probes exist. Build verification (`.dmg` launch) was a manual human checkpoint.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| PKG-04 | 15-01 | NativePHP desktop ^2.2 producing `.dmg`/`.msi`/`.AppImage` via `native:build` | SATISFIED | `composer show nativephp/desktop` reports 2.2.0; human-verified `.dmg` builds and launches |
| PKG-05 | 15-01, 15-02, 15-07 | Native chrome — window, dock icon, app menu, system tray, OS notifications, dark-mode follows OS | SATISFIED | `NativeAppServiceProvider.boot()` opens window + menu + tray; `OsThemeSignal` contract bundle-gated; dark theme arch guard passes |
| PKG-06 | 15-04 | File-association handlers for `.eml`/`.csv` | SATISFIED | `fileAssociations` in electron-builder config; Electron `index.js` handles all three paths (macOS open-file, Windows/Linux cold-start argv, second-instance); PHP-side `HandleNativeOpenFile` + `FileOpenIntake` + `PendingFileIntent` wired and tested |
| PKG-07 | 15-05 | PHP 8.4 bundle validation — all gates pass on PHP 8.4 | SATISFIED | `.github/workflows/ci.yml` exists with 8.4 axis; Larastan L10 strict + Pint + Pest all pass locally (verified at plan HEAD) |
| PKG-08 | 15-05 | macOS Hardened Runtime entitlements file with both required keys | SATISFIED | `build/entitlements.mac.plist` contains both keys; `plutil -lint` clean; wired into electron-builder mac config |

### Anti-Patterns Found

The code review identified 14 findings (3 BLOCKER, 6 WARNING, 5 INFO). All 14 are confirmed fixed by commits `cc735e2`..`383f7fb`:

| Finding | File | Fix Commit | Status |
|---------|------|------------|--------|
| CR-01: `runPendingMigrations()` had no production caller | `NativeAppServiceProvider.php` | `cc735e2` | FIXED — called at line 80 before window open |
| CR-02: `EnsureDatabaseReady` not registered | `bootstrap/app.php` | `dec726a` | FIXED — appended to web middleware group |
| CR-03: Close prompt never triggered; modal never opened | `CloseWindowPrompt.php`, `Routes/web.php` | `4879905` | FIXED — `mount()` dispatches `modal-show`; Electron trigger documented as deferred (see note below) |
| WR-01: `WindowFocusState` default was `unfocused` | `WindowFocusState.php` | `021be9b` | FIXED — default is now `focused = true` |
| WR-02: `CloseActionController` 422 silent with no logging | `CloseActionController.php` | `3fdc1fb` | FIXED — PSR-3 `LoggerInterface` injected, `logger->warning()` on rejection |
| WR-03: `HandleNativeOpenFile::normalize()` accepted first string of associative array | `HandleNativeOpenFile.php` | `78ffae3` | FIXED — key-aware `$raw['path'] ?? null` extraction first |
| WR-04: Ad-hoc signing idempotency regex matched `identity:` outside `mac:` block | `nativephp_force_adhoc_signing.php` | `c780bc2` | FIXED — regex scoped to `\bmac\s*:\s*\{[^{}]*\bidentity\s*:/s` |
| WR-05: `OsThemeProbe` collapsed `SYSTEM` to `'light'` silently | `OsThemeProbe.php`, `OsThemeSignal.php`, `app.blade.php` | `2990ea5` | FIXED — `currentOsTheme()` returns `?string`; `SYSTEM => null`; layout falls through to pre-paint script on null |
| WR-06: OS notification fired even for un-acknowledged crash-loop | `SurfaceWorkerCrashAlert.php` | `2ded05d` | FIXED — `if ($alreadyAlerted \|\| $this->focus->isFocused()) return;` |
| IN-01: `EnsureDatabaseReady` exempt list used exact-match not prefix | `EnsureDatabaseReady.php` | `09227a0` | FIXED — `str_starts_with($name, $prefix)` loop |
| IN-02: `ContinuePendingFileIntentAfterLogin` side-effect unclear | `ContinuePendingFileIntentAfterLogin.php` | `9d125a4` | FIXED — explicit side-effect comment |
| IN-03: `WelcomeScreen` used raw `/signup` href | `welcome.blade.php` | `22e83b7` | FIXED — `route('signup')` |
| IN-04: `FileStagingPage::startImport()` docblock contradicted implementation | `FileStagingPage.php` | `a8ba541` | FIXED — docblock updated to reflect single-target redirect; wizard owns per-format branch |
| IN-05: `FileOpenIntake::MAX_BYTES` single constant for both extensions | `FileOpenIntake.php` | `24c0184`, `368f251` | FIXED — per-extension array `['csv' => 50MB, 'eml' => 5MB]` |

**Documented carry-forward stub (not a phase failure):** The Electron main-process JS close-intercept hook that navigates to `/desktop/close-prompt` on X-click when `users.close_behavior IS NULL` is deferred to a later phase. This is explicitly documented in `Modules/Desktop/Routes/web.php` (lines 43-55) and `Modules/Desktop/Internal/Http/CloseActionController.php` (docblock). The PHP side of the close prompt is fully wired (route, Livewire component with `mount()` dispatching `modal-show`, `CloseActionController`, `ApplyCloseWindowChoice`). Only the Electron trigger — the JS that intercepts `close`/`before-quit` and navigates to the route — is absent. Without the Electron trigger, clicking X closes the window directly without showing the prompt on first close.

### Human Verification Required

#### 1. First-launch flow on a fresh install

**Test:** Launch the built `.dmg` on a clean macOS machine with no existing `~/Library/Application Support/diederik` directory (or equivalent `NATIVEPHP_STORAGE_PATH`). Observe the startup sequence.

**Expected:** The "Setting up..." screen (brand mark + "Setting up..." heading + "diederik is preparing your data. This only takes a moment." body + `wire:poll` auto-advance) appears briefly while migrations run, then transitions automatically to the "Welcome to diederik" screen (brand mark + "Welcome to diederik" heading + emerald "Get started" button). Clicking "Get started" navigates to `/signup`.

**Why human:** `FirstLaunchBootstrap.runPendingMigrations()` is wired into production code and `EnsureDatabaseReady` is registered on the web middleware group — both confirmed. However, the first-launch sequence requires a real NativePHP bundle boot with a completely empty DB. The Pest feature tests use `RefreshDatabase` (which pre-migrates), so they cannot simulate a pre-migration state. A real install on a clean machine is the only way to verify the `hasPendingMigrations() == true` -> poll -> `hasPendingMigrations() == false` -> redirect to welcome path end-to-end.

#### 2. Dark theme visual across all modules

**Test:** With the bundled app running and an account created, navigate to Settings and set Appearance to "Dark". Walk through every module: Dashboard, Transactions/Ledger, Chains, Import, Recurring, Forecasting, DriftAlerts, Categorization, Receipts, EmailScan, and the Desktop-specific setup/welcome screens (requires a second fresh install or simulating the state).

**Expected:** Every surface renders correctly in dark mode. No unthemed white surfaces (raw `bg-white` without `dark:bg-slate-950` or equivalent) visible anywhere. WCAG AA contrast on all text. Switch back to "System" — the theme should follow the OS `prefers-color-scheme` without a flash.

**Why human:** The `darkCompanionUtilitiesOnThemedViews` arch guard passes (verified), but the guard does not scan PHP source files. Three sets of PHP-built class strings — `chain-node.blade.php`'s `$tierClasses`/`$cardClasses` match-arms, the recurring inline-edit Save button's PHP-built string, and the OAuth wizard's `$stepBadgeClasses` ternaries — were upgraded manually. Visual confirmation is required to ensure these render correctly at WCAG AA contrast on dark surfaces.

#### 3. OS file association: double-click to open

**Test:** With the `.dmg` installed, right-click a `.csv` file in Finder and confirm "Open With" lists diederik. Double-click the `.csv` — the app should open (or focus if already running) and the file-staging page should appear with the message "A bank or PayPal export" and a "Start import" button. Repeat for a `.eml` file — the staging page should show "An email receipt".

**Expected:** OS "Open With diederik" surfaces for both extensions. The app single-instances (if already running, the existing window focuses). The file-staging page shows extension-appropriate copy and a functioning "Start import" CTA.

**Why human:** The PHP-side `FileOpenIntake`, `HandleNativeOpenFile`, `PendingFileIntent`, and `FileStagingPage` are tested. The Electron-side argv/second-instance handling on macOS (`app.on('open-file')`) and especially Windows/Linux cold-start (`process.argv` scan) are not exercised by the test suite. Real macOS verification of the cold-start and warm-start paths is needed; Windows/Linux verification is deferred to Phase 17 CI / Phase 21 beta.

#### 4. Window close prompt (partial — Electron trigger deferred)

**Test:** With the bundled app running and the account set up (first launch complete), click the window close button (X). **Note:** this test may not show the close-prompt yet — the Electron main-process JS close-intercept is documented as deferred. If the close-intercept IS present in `nativephp/electron/src/main/index.js` (it may have been added during the file-association spike), confirm: (a) the modal appears asking "Quit diederik?" with Quit and Keep in Tray options, (b) "Quit" closes the app, (c) "Keep in Tray" hides to tray with the tray icon visible, (d) subsequent closes skip the prompt and apply the remembered choice.

**Expected if JS trigger is present:** Close prompt modal appears on first close; remembered choice applies on subsequent closes.
**Expected if JS trigger is absent (as documented):** X closes the window directly. This is the known carry-forward stub — the PHP close-action route and Livewire component are wired and ready; only the Electron trigger awaits a later phase.

**Why human:** The PHP side is fully verified in code. The Electron trigger status can only be confirmed at runtime in the bundled app.

### Gaps Summary

No gaps blocking the phase goal. All seven observable truths are verified in code. All five requirements (PKG-04, PKG-05, PKG-06, PKG-07, PKG-08) are satisfied. All 14 code review findings are fixed. Four items require human verification before the phase can be marked fully `passed`:

1. First-launch flow (Setting up -> Welcome -> /signup) in a real bundle.
2. Dark theme visual walkthrough across all 11 modules.
3. OS file association end-to-end: double-click .csv and .eml opens app + staging page.
4. Window close-prompt behavior (status of the Electron JS trigger).

The documented carry-forward stub (Electron close-intercept JS) is not a blocker: it is explicitly deferred, documented in two source files, and the PHP implementation it depends on is complete and wired.

---

_Verified: 2026-05-23T12:00:00Z_
_Verifier: Claude (gsd-verifier)_
