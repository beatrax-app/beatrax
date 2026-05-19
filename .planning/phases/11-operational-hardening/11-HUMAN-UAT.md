---
status: partial
phase: 11-operational-hardening
source: [11-VERIFICATION.md, 11-05-PLAN.md]
started: 2026-05-19
updated: 2026-05-19
---

## Current Test

[awaiting human testing — user deferred QA until after the desktop-app phase]

## Tests

### 1. db:backup --force on the real Herd-mounted DB produces a chmod-600 .sqlite + .meta.json pair
expected: `ls -la storage/app/backups/` shows the new file at mode `-rw-------` plus a `.meta.json` sidecar also at 0600
result: [pending]
why_human: File-permission semantics on the actual filesystem (not the Pest temp dir under sys_get_temp_dir) cannot be asserted programmatically by the verifier without running the command.

### 2. Second `db:backup` (no --force) on an unchanged DB prints "Skipped — no commits since last backup" and creates no new file
expected: Output contains the skip line and `ls storage/app/backups/` shows the same files as before
result: [pending]
why_human: Smart-skip behaviour depends on PRAGMA data_version cache state on the live process, which only the operator's running Herd substrate can reproduce.

### 3. `php artisan diederik:doctor` against the local DB prints WAL / synchronous / backup-freshness probe lines and exits 0 in a clean state
expected: Three probe lines visible alongside the inline PHP/Composer/SQLite/Node checks; exit code 0 when WAL is active, synchronous=NORMAL, and a fresh sidecar exists
result: [pending]
why_human: Exit code + console output of a real `artisan` run on the developer's machine cannot be inspected from the verifier process.

### 4. Banner appears in the browser at https://diederik.test after seeding a critical alert via tinker, then disappears on click
expected: Visual: rose-coloured banner with "Mark as resolved" button at top of authenticated pages; click → row gone within ~500ms; persists gone on reload
result: [pending]
why_human: Visual appearance, Livewire round-trip latency feel, and browser DOM interaction cannot be verified without a real browser session.

### 5. `php artisan diederik:failed-jobs prune --older-than=30d --dry-run` prints candidate-row summary and exits 0 without modifying the failed_jobs table
expected: Output shows "Would delete N rows" footer; running `select count(*) from failed_jobs` before and after returns the same number
result: [pending]
why_human: Confirms the dry-run guard does not write — programmatic feature tests cover the unit but operator-felt safety needs the operator to run it once on real data.

### 6. README ## Backups and ## Operator recovery sections render correctly when read in a markdown viewer
expected: All five new ## Backups subsections plus four new ## Operator recovery subsections are present and the existing Stuck Redis lock recovery is preserved verbatim
result: [pending]
why_human: Visual rendering of markdown headings and code fences cannot be verified from a grep; the ReadmeOperationalDocsTest pins substrings but not visual layout.

### 7. db:restore --confirm --force-maintenance <known-good-source.sqlite> round-trips a backup and creates a pre-restore-*.sqlite snapshot at chmod 0600
expected: Source DB rows visible after restore; pre-restore snapshot exists at `storage/app/backups/pre-restore-<timestamp>.sqlite`; app comes back up automatically
result: [pending]
why_human: Restore is destructive and operates on the live DB file handle — the only meaningful end-to-end smoke is on the operator's real machine.

## Summary

total: 7
passed: 0
issues: 0
pending: 7
skipped: 0
blocked: 0

## Gaps
