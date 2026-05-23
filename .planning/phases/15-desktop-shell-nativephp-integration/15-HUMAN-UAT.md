---
status: partial
phase: 15-desktop-shell-nativephp-integration
source: [15-VERIFICATION.md]
started: 2026-05-23T00:30:00Z
updated: 2026-05-23T01:00:00Z
---

## Current Test

[3 gaps surfaced from manual testing — see Gaps section below]

## Tests

### 1. First-launch flow on a fresh install
expected: Launch the `.dmg` on a machine with no existing DB (or delete the SQLite file under `~/Library/Application Support/diederik/database/` before launching). A "Setting up…" screen appears, auto-advances when migrations finish, transitions to "Welcome to diederik", and the "Get started" CTA navigates to `/signup`. The poll-loop does not spin forever (CR-01 / CR-02 fixed: `FirstLaunchBootstrap::runPendingMigrations()` is wired into `NativeAppServiceProvider::boot()`; `EnsureDatabaseReady` middleware is registered in `bootstrap/app.php`).
result: failed — even after deleting the SQLite DB, the app opens straight to the signin page. The "Setting up…" / "Welcome to diederik" flow never appears. See Gap UAT-1.

### 2. Dark theme visual sweep across all modules
expected: Toggle Settings → Appearance to Dark (or set the OS to Dark and pick "System"). Walk every module surface: Dashboard, Ledger, Categorization (rules + triage), Chains (review queue + drawer), Recurring (page + review + series detail), Forecasting, Import, Receipts (wizard + conflict toast), DriftAlerts (dashboard tile + page + threshold editor), EmailScan (inboxes + backfill modal + OAuth wizard). Confirm no contrast regressions, no flash of light on navigation, and the three manually-upgraded PHP-built class strings render correctly: chain-node tier classes (`Modules/Chains/Resources/views/livewire/partials/chain-node.blade.php`), the Recurring "Save" button, and the OAuth wizard step badges. WCAG AA contrast on light and dark.
result: passed — dark mode works (user confirmed). Full per-module visual sweep still pending but baseline confirmed.

### 3. OS file association — double-click .csv and .eml from Finder
expected: macOS Finder shows "Open With diederik" in the `.csv` and `.eml` "Get Info" → "Open with…" picker. Double-clicking a `.csv` file launches (or focuses, if already open) diederik, lands on the file-staging page, and routes the user through to the Import flow with the file pre-staged. Double-clicking an `.eml` file does the same but routes to the Receipts pipeline. Single-instance behavior (D-03): a second double-click does NOT spawn a second app — it focuses the existing window. Pending-intent (D-04): if the user is logged out when the file is opened, the staging page survives the login round-trip.
result: [pending]

### 4. Window close-prompt (D-08) — Quit vs Keep-in-tray
expected: Clicking the macOS window red-X surfaces a "Quit / Keep running in tray" modal on first close (Livewire `CloseWindowPrompt` mounted at `/desktop/close-prompt`). The user's choice is persisted to `users.close_behavior` and skipped on subsequent closes.
note: KNOWN CARRY-FORWARD STUB — the PHP side is fully wired (CR-03 fix: `mount()` dispatches `modal-show`); the Electron main-process `closable(false)` pre-prompt intercept that navigates the focused window to `/desktop/close-prompt` is documented as deferred in `Modules/Desktop/Routes/web.php:76` and `CloseActionController`'s docblock. Until the Electron JS glue lands, clicking X still closes the window directly without surfacing the prompt. **This item should be marked as `deferred` rather than `failed` after manual testing.**
result: [pending]

## Summary

total: 4
passed: 1
issues: 3
pending: 2
skipped: 0
blocked: 0

## Gaps

### UAT-1: First-launch flow not firing on fresh install
test: 1 (First-launch flow on a fresh install)
severity: blocker
observed: After deleting the bundled SQLite DB and re-launching `/Applications/diederik.app`, the app opens directly on the signin page. The "Setting up…" poll-loop screen and the "Welcome to diederik" screen never appear.
expected: First launch should show "Setting up…" while migrations run, then "Welcome to diederik", then `/signup` on "Get started" (D-21, D-22, D-23).
likely_root_cause: The `EnsureDatabaseReady` middleware (now correctly registered in `bootstrap/app.php`) checks `Schema::hasTable('migrations')` or `hasPendingMigrations()` — but `FirstLaunchBootstrap::runPendingMigrations()` is called from `NativeAppServiceProvider::boot()` BEFORE the first HTTP request, so by the time the middleware fires the migrations are already done and `hasPendingMigrations()` returns false. The setup/welcome screens are unreachable because the redirect predicate never returns true on a fresh install. The fix likely needs to: (a) use a separate "first launch ever" sentinel (e.g., `users` table empty) rather than `hasPendingMigrations()` to gate the welcome screen, OR (b) defer the migration run so the middleware catches the pending state, OR (c) explicitly redirect to the welcome screen on first launch via the bootstrap path.
debug_session: [to be created]

### UAT-2: Tray icon disappears from menu bar when app window opens
test: implicit (D-09 system tray)
severity: warning
observed: The macOS menu-bar tray icon vanishes the moment the app's main window opens. Closing the window does not restore it.
expected: The tray icon (D-09) persists in the menu bar across the entire app lifecycle — that's the whole point of "keep running in tray". `TrayMenuBuilder` should bind the tray once and the reference must not be GC'd or replaced when the window state changes.
likely_root_cause: Either (a) the `Tray` instance in `TrayMenuBuilder` is created on the wrong NativePHP lifecycle event and replaced when the window opens, OR (b) NativePHP's window-open code path destroys the tray, OR (c) the tray instance is being held in a non-persistent scope and JS GC reclaims it after `boot()` returns. Inspect `Modules/Desktop/Internal/Native/TrayMenuBuilder.php` and the boot sequence in `NativeAppServiceProvider::boot()`.
debug_session: [to be created]

### UAT-3: Tray icon not formatted as a macOS template pictogram
test: implicit (D-09 system tray + brand)
severity: warning
observed: The tray icon does not render in the macOS native-pictogram style (the monochrome system-tray look that adapts to light/dark menu bar). It renders as a full-color image instead.
expected: The macOS tray icon should be a Template Image — a black-on-transparent PNG (or a `.png` with `Template` in its filename), so macOS recolors it to match the menu-bar appearance (white in dark menu bar, black in light).
likely_root_cause: Either (a) the source `resources/brand/tray-icon.png` is not actually a monochrome template image (per CLAUDE.md / 15-05 D-20 it should be a monochrome tray-icon, distinct from the colored app icon), OR (b) NativePHP / Electron's `Tray` needs to be set with `nativeImage.createFromPath(...).setTemplateImage(true)` and that flag isn't being passed by `TrayMenuBuilder`. The Electron docs require the template flag explicitly even for filenames containing "Template". Inspect both the image file and the `TrayMenuBuilder` instantiation.
debug_session: [to be created]

