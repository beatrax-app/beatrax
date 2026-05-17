---
status: partial
phase: 06-email-receipt-ingestion-infrastructure
source: [06-VERIFICATION.md]
started: 2026-05-17T10:00:00Z
updated: 2026-05-17T10:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Complete Gmail OAuth round trip end-to-end
expected: User completes Google Cloud Project setup, OAuth consent screen pushed to 'In production', client_id/secret pasted into wizard, consent flow completes, `/inboxes` shows Gmail inbox with email + idle status
why_human: Requires a real GCP project and live Google OAuth consent dance; cannot mock the IdP redirect from localhost
result: [pending]

### 2. Complete Microsoft 365 OAuth round trip end-to-end
expected: User completes Azure App Registration, client_id (UUID) and secret pasted into wizard, consent flow completes, `/inboxes` shows Microsoft inbox with email + idle status
why_human: Requires a real Azure App Registration and live Microsoft consent dance
result: [pending]

### 3. Kill-and-restart resume (SC#3)
expected: After kill + restart, `IncrementalScanJob` reads `last_history_id` / `last_delta_link` from `inbox_scan_state` and resumes without re-fetching already-indexed messages
why_human: Requires a live Horizon worker, an actual connected inbox, and a kill signal; cannot replicate purely in unit tests
result: [pending]

### 4. Backfill progress strip renders and disappears (SC#2)
expected: After connecting an inbox, the backfill window modal opens, user picks 1-12 months, progress strip appears on `/inboxes` with `wire:poll` counting up, disappears when backfill completes; N `inbox_messages` rows + N `.eml` blobs exist on disk
why_human: Requires real Gmail/Graph credentials and a running Horizon worker to observe progress strip behavior
result: [pending]

### 5. macOS launchd auto-start (SC#5)
expected: Running `php artisan diederik:install --launchd` copies plists to `~/Library/LaunchAgents/` and background workers start on macOS login; Horizon + scheduler visible in `launchctl list`
why_human: Requires running on the user's actual macOS machine with launchctl; cannot test in CI
result: [pending]

### 6. Health view 'last scan: X hours ago' per inbox (SC#4)
expected: After a successful incremental scan, the `/inboxes` page and dashboard tile show the correct "last scanned N hours ago" text per inbox
why_human: Requires a live scan run; `diffForHumans()` output depends on actual scan timing
result: [pending]

### 7. Rate-limit exponential backoff visible in health view (SC#4)
expected: When Gmail/Graph returns a rate-limit error, inbox transitions to `rate_limited` status with `retry_attempts` incrementing; `/inboxes` shows the `rate_limited` badge; Horizon honours `[60, 300, 900]` backoff envelope
why_human: Requires triggering a real rate-limit event; quota exhaustion cannot be safely faked end-to-end in CI
result: [pending]

### 8. Discovery loop — reviewed senders panel + promote/dismiss
expected: After `DiscoveryScanJob` runs daily, candidate senders appear on `/inboxes` discovery panel (above promotion threshold of 2 occurrences in 90 days); Add inserts `known_senders` row; Dismiss marks dismissed
why_human: Requires a real inbox with receipt-like emails and a day-scale observation window
result: [pending]

## Summary

total: 8
passed: 0
issues: 0
pending: 8
skipped: 0
blocked: 0

## Gaps
