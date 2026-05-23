---
status: partial
phase: 15-desktop-shell-nativephp-integration
source: [15-VERIFICATION.md]
started: 2026-05-23T00:30:00Z
updated: 2026-05-23T00:30:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. First-launch flow on a fresh install
expected: Launch the `.dmg` on a machine with no existing DB (or delete the SQLite file under `~/Library/Application Support/diederik/database/` before launching). A "Setting up…" screen appears, auto-advances when migrations finish, transitions to "Welcome to diederik", and the "Get started" CTA navigates to `/signup`. The poll-loop does not spin forever (CR-01 / CR-02 fixed: `FirstLaunchBootstrap::runPendingMigrations()` is wired into `NativeAppServiceProvider::boot()`; `EnsureDatabaseReady` middleware is registered in `bootstrap/app.php`).
result: [pending]

### 2. Dark theme visual sweep across all modules
expected: Toggle Settings → Appearance to Dark (or set the OS to Dark and pick "System"). Walk every module surface: Dashboard, Ledger, Categorization (rules + triage), Chains (review queue + drawer), Recurring (page + review + series detail), Forecasting, Import, Receipts (wizard + conflict toast), DriftAlerts (dashboard tile + page + threshold editor), EmailScan (inboxes + backfill modal + OAuth wizard). Confirm no contrast regressions, no flash of light on navigation, and the three manually-upgraded PHP-built class strings render correctly: chain-node tier classes (`Modules/Chains/Resources/views/livewire/partials/chain-node.blade.php`), the Recurring "Save" button, and the OAuth wizard step badges. WCAG AA contrast on light and dark.
result: [pending]

### 3. OS file association — double-click .csv and .eml from Finder
expected: macOS Finder shows "Open With diederik" in the `.csv` and `.eml` "Get Info" → "Open with…" picker. Double-clicking a `.csv` file launches (or focuses, if already open) diederik, lands on the file-staging page, and routes the user through to the Import flow with the file pre-staged. Double-clicking an `.eml` file does the same but routes to the Receipts pipeline. Single-instance behavior (D-03): a second double-click does NOT spawn a second app — it focuses the existing window. Pending-intent (D-04): if the user is logged out when the file is opened, the staging page survives the login round-trip.
result: [pending]

### 4. Window close-prompt (D-08) — Quit vs Keep-in-tray
expected: Clicking the macOS window red-X surfaces a "Quit / Keep running in tray" modal on first close (Livewire `CloseWindowPrompt` mounted at `/desktop/close-prompt`). The user's choice is persisted to `users.close_behavior` and skipped on subsequent closes.
note: KNOWN CARRY-FORWARD STUB — the PHP side is fully wired (CR-03 fix: `mount()` dispatches `modal-show`); the Electron main-process `closable(false)` pre-prompt intercept that navigates the focused window to `/desktop/close-prompt` is documented as deferred in `Modules/Desktop/Routes/web.php:76` and `CloseActionController`'s docblock. Until the Electron JS glue lands, clicking X still closes the window directly without surfacing the prompt. **This item should be marked as `deferred` rather than `failed` after manual testing.**
result: [pending]

## Summary

total: 4
passed: 0
issues: 0
pending: 4
skipped: 0
blocked: 0

## Gaps
