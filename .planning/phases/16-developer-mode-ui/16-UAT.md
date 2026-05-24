---
status: testing
phase: 16-developer-mode-ui
source:
  - 16-VERIFICATION.md (human_verification section, 11 items)
  - 16-01..16-08 SUMMARY.md
started: 2026-05-24T19:50:00Z
updated: 2026-05-24T21:45:00Z
mvp_mode: true
pre_flight_findings:
  - id: PF-01
    summary: "IngestionServiceProvider declared loadViewsFrom() but module has zero blade views; bundle build dropped the empty Resources/views directory → view:cache exploded with DirectoryNotFoundException at boot."
    severity: blocker
    fix: "Deleted the unused loadViewsFrom() line in IngestionServiceProvider.php and removed the empty Modules/Ingestion/Resources/views/ directory. view:cache now exits 0; 212 Ingestion tests still green."
  - id: PF-02
    summary: "LogStreamController + ArtisanStreamController SSE callbacks never call set_time_limit(0); PHP's 30s max_execution_time killed both streams long before STREAM_TIMEOUT_SECONDS (600s for log tail). Cascading 'Cannot modify header information' errors were a downstream effect."
    severity: blocker
    fix: "Added @set_time_limit(0) inside both StreamedResponse callbacks alongside the existing @ini_set/@ignore_user_abort triplet. 19 SSE-related Pest tests still green; Larastan L10 still 0 errors."
  - id: PF-03
    summary: "One AuthenticationException ERROR at cold boot (auto-redirect to login before session established)."
    severity: noise
    fix: "Not a defect — Laravel's standard exception handler logs guest-on-protected-route as ERROR. Single occurrence per boot. Defer to a separate hygiene pass if the noise becomes annoying."

## Current Test
<!-- OVERWRITE each test - shows where we are -->

number: 1
name: ⌘K palette opens on macOS
expected: |
  Run `composer dev`. Sign in as a developer user (is_developer=true).
  On any authenticated page, press ⌘K — the command palette modal opens.
  Verify rows shown include views + dev + actions; ZERO destructive command
  names (db:restore, migrate:fresh, beatrax:install, beatrax:reset-password,
  beatrax:grant-dev, beatrax:regenerate-recovery-codes) appear in the list.
awaiting: user response

## Tests

### 1. ⌘K palette opens on macOS
expected: |
  Run `composer dev`. Sign in as a developer user. Press ⌘K — palette
  modal opens with view + dev + action rows; ZERO destructive command
  names shown.
result: [pending]

### 2. I-7 keybind carve-out — ⌘K inside input/textarea does NOT open palette
expected: |
  On /transactions (or any page with a text input), click into a text
  input and start typing a query; press ⌘K. The palette does NOT open
  (body onKey early-returns when document.activeElement.tagName is
  INPUT / TEXTAREA / contentEditable).
result: [pending]

### 3. App-menu Developer submenu only for is_developer=true (NativePHP)
expected: |
  Build the native bundle. Sign in as developer — the macOS app-menu
  shows a Developer submenu. Toggle is_developer=false in Settings, quit
  the bundle, relaunch — Developer submenu is absent.
result: [pending]

### 4. Settings → Developer Mode toggle persists across logout/login
expected: |
  Open Settings, toggle Developer Mode ON. Log out. Log back in. Open
  Settings — toggle is still ON (DB-persisted via users.is_developer,
  not session-scoped).
result: [pending]

### 5. Live stdout streaming feels sub-second for db:backup
expected: |
  Sign in as developer, open /dev/artisan. Run db:backup. Verify lines
  stream to the UI within ~500ms of process emit (perceived SSE latency
  — use a stopwatch / felt judgment).
result: [pending]

### 6. Triple-gate modal UX
expected: |
  Open the destructive db:restore confirm modal. Click outside the scrim
  — modal stays open (no close on outside-click). Press Esc — modal
  closes. Re-open. Type "Beatrax" (wrong case) — Run button stays
  disabled. Type "beatrax" — Run button enables and clicking fires.
result: [pending]

### 7. B-2 fallback runner modal — SAFE-only commands
expected: |
  Open /dev/artisan and trigger the fallback Run-command modal. Visually
  scan the offered list — NO db:restore, migrate:fresh, beatrax:install,
  beatrax:reset-password, beatrax:grant-dev, or
  beatrax:regenerate-recovery-codes buttons appear.
result: [pending]

### 8. Log tailer rotation detection
expected: |
  Open /dev/logs and tail the current day's laravel-YYYY-MM-DD.log.
  Manually delete or recreate the file (force a rotation). The stream
  re-opens without crashing — tri-signal detection fires on inode
  change OR shrinkage OR path change.
result: [pending]

### 9. Horizon iframe loads under NativePHP runtime
expected: |
  Run `composer dev`. Visit /dev/horizon (requires dev_mode=true AND
  Horizon class_exists). The Horizon UI loads inside the iframe with
  mouse + keyboard working.
result: [pending]

### 10. W-6 /dev/sql runaway killed by set_time_limit(5)
expected: |
  Open /dev/sql. Run a deliberate runaway SELECT against a large
  seeded dataset (e.g. cross-join `SELECT * FROM big_table a, big_table b LIMIT 1`).
  Query dies before 6 seconds — WallClockCap::apply(5) enforces the cap.
result: [pending]

### 11. Manual cut-over after 16-02 rename lands locally
expected: |
  Per D-09, the developer hand-edits: .env (BEATRAX_DEV_MODE), Herd
  hostname (beatrax.test), and the running launchd plists at
  ~/Library/LaunchAgents/com.diederik.*. Confirm all three local
  configs reflect the rename (no upgrade-migration code ships for this).
result: [pending]

## Summary

total: 11
passed: 0
issues: 0
pending: 11
skipped: 0

## Gaps

[none yet]
