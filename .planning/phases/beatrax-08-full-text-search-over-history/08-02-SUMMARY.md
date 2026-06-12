---
phase: "08"
plan: "02"
subsystem: Search
tags: [fts5, indexing, sqlite, search, doctor, artisan]
dependency_graph:
  requires: ["08-01"]
  provides: ["FTS5 synchronous write path", "search:reindex command", "FTS doctor probe"]
  affects: ["Modules/Search", "Modules/Tax", "Modules/Core"]
tech_stack:
  added: []
  patterns:
    - "FTS5 external-content table (transaction_search_docs + transaction_search_fts)"
    - "chr(12) form-feed field separator in search_body (prevents cross-field false positives)"
    - "FTS5 sync protocol: delete-then-insert on upsert, delete-all + rebuild on reindex"
    - "Plain-value public facade (FtsHealthCheck) lets Core\Internal avoid importing Search\Internal types"
    - "class_exists()-guarded artisan command registration in SearchServiceProvider"
    - "Optional nullable constructor injection for cross-module health facades"
key_files:
  created:
    - Modules/Search/Internal/Services/SearchIndexWriter.php
    - Modules/Search/Internal/Listeners/IndexTransactionOnImport.php
    - Modules/Search/Internal/Console/ReindexSearchCommand.php
    - Modules/Search/Public/Services/FtsHealthCheck.php
  modified:
    - Modules/Tax/Public/Actions/TagTransaction.php
    - Modules/Tax/Public/Actions/UntagTransaction.php
    - Modules/Core/Internal/Console/DoctorCommand.php
    - Modules/Search/Providers/SearchServiceProvider.php
  deleted:
    - Modules/Search/Internal/Console/Probes/FtsIndexProbe.php
decisions:
  - "FtsHealthCheck returns plain PHP values only — no Core\\Internal type imports (boundary-safe). DoctorCommand creates ProbeResult inline from these values."
  - "ReindexSearchCommand uses DELETE (not TRUNCATE) — SQLite tables without AUTOINCREMENT lack sqlite_sequence, causing TRUNCATE to fail."
  - "IndexTransactionOnImport accepts Transaction object directly from event — no is_numeric guard needed since Transaction::$id is typed int."
  - "FtsIndexProbe deleted — implementing Core\\Internal\\Probe interface inside Search\\Internal violates BoundaryArchTest rule."
metrics:
  duration: "~3 hours (across context sessions)"
  completed: "2026-06-12T22:46:58Z"
  tasks_completed: 3
  tasks_total: 3
  files_created: 4
  files_modified: 4
  files_deleted: 1
---

# Phase 08 Plan 02: FTS Index Write Path Summary

Synchronous FTS5 index-maintenance path: upsert/delete writer, import listener, Tax re-index hooks, search:reindex artisan command, and FTS health probe wired into DoctorCommand.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | SearchIndexWriter + IndexTransactionOnImport | `82437f1` | SearchIndexWriter.php, IndexTransactionOnImport.php |
| 2 | Tax note re-index hook (TagTransaction/UntagTransaction) | `ce0f02a` | TagTransaction.php, UntagTransaction.php |
| 3 | ReindexSearchCommand + FtsHealthCheck + DoctorCommand wiring | `3cc7359` | ReindexSearchCommand.php, FtsHealthCheck.php, DoctorCommand.php, SearchServiceProvider.php |

## What Was Built

**SearchIndexWriter** (`Modules/Search/Internal/Services/`): Implements `SearchIndexWriterContract`. On `upsertForTransaction`:
1. Fetches `transactions` row (counterparty_name, description, user_id)
2. Fetches `tax_transaction_tags` note for that transaction
3. Builds `search_body = counterparty + chr(12) + description + chr(12) + note`
4. Reads existing doc body (for stale FTS delete)
5. Upserts `transaction_search_docs`
6. FTS5 delete (old body) then insert (new body)

No try/catch swallow — failures bubble so the outer import transaction rolls back (D-23 / Pitfall-2).

**IndexTransactionOnImport**: Listens to `TransactionImported` event. Calls `writer->upsertForTransaction($event->transaction->id)`. Registered via `class_exists()` guard in SearchServiceProvider.

**TagTransaction + UntagTransaction** (Tax module): Nullable `?SearchIndexWriterContract` injected as 4th constructor param. Calls `$this->searchIndex?->upsertForTransaction($transactionId)` after tag write/delete so tax notes become searchable immediately.

**ReindexSearchCommand** (`search:reindex`): Clears docs with DELETE + FTS `delete-all`, chunks through transactions (500/batch), batch-fetches tax notes per chunk, inserts denormalized docs, runs FTS `rebuild`. Outputs `"FTS index rebuilt. {N} transactions indexed."` (UI-SPEC copywriting contract).

**FtsHealthCheck** (`Modules/Search/Public/Services/`): Returns `label()`, `severity()` (`'ok'|'warning'`), `message()` as plain PHP values. Counts `transactions` vs `transaction_search_docs`. Reports in-sync or delta. Catches `Throwable` for absent table.

**DoctorCommand**: Accepts optional `?FtsHealthCheck` injection. Creates `ProbeResult` inline from FtsHealthCheck's plain values. FtsHealthCheck never imports `Core\Internal` types — boundary preserved.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] is_numeric guard removed from IndexTransactionOnImport**
- **Found during:** Task 1 (PHPStan)
- **Issue:** `is_numeric($transactionId)` where `$transactionId` is already `int` (from `Transaction::$id` `@property` annotation)
- **Fix:** Removed the guard — simplified to `$this->writer->upsertForTransaction($event->transaction->id)`
- **Files modified:** `Modules/Search/Internal/Listeners/IndexTransactionOnImport.php`
- **Commit:** `82437f1`

**2. [Rule 3 - Blocking] FtsIndexProbe architecture deleted**
- **Found during:** Task 3
- **Issue:** `FtsIndexProbe` in `Search\Internal` imported `Modules\Core\Internal\Console\Probes\Probe` and `ProbeResult`, violating the BoundaryArchTest rule `Modules\Core\Internal is only used inside Modules\Core`
- **Fix:** Deleted `FtsIndexProbe.php` entirely. Redesigned: `FtsHealthCheck` (Search\Public) returns plain PHP values; `DoctorCommand` (Core\Internal) constructs `ProbeResult` inline from those values
- **Files modified:** DoctorCommand.php, FtsHealthCheck.php (new design)
- **Commit:** `3cc7359`

**3. [Rule 1 - Bug] TRUNCATE fails on SQLite without AUTOINCREMENT**
- **Found during:** Task 3
- **Issue:** `->truncate()` on `transaction_search_docs` throws `SQLSTATE[HY000]: no such table: sqlite_sequence` — SQLite only has `sqlite_sequence` for tables with `AUTOINCREMENT`
- **Fix:** Changed to `->delete()` which is equivalent for reindex purposes
- **Files modified:** `Modules/Search/Internal/Console/ReindexSearchCommand.php`
- **Commit:** `3cc7359`

**4. [Rule 2 - Missing critical functionality] PHPStan narrow type on FtsHealthCheck::severity()**
- **Found during:** Task 3 (PHPStan)
- **Issue:** `ProbeResult` constructor expects `'ok'|'warning'|'critical'` literal, but `severity()` returned `string`. PHPStan `argument.type` error.
- **Fix:** Added `@return 'ok'|'warning'` annotation to `severity()` and `@return array{severity: 'ok'|'warning', message: string}` to `result()`
- **Files modified:** `Modules/Search/Public/Services/FtsHealthCheck.php`
- **Commit:** `3cc7359`

**5. [Rule 3 - Blocking] SearchServiceProvider command registration not in plan**
- **Found during:** Task 3
- **Issue:** `ReindexSearchCommand` had no artisan registration path — it would never be discoverable
- **Fix:** Added `class_exists(ReindexSearchCommand::class) && $this->app->runningInConsole()` guarded `$this->commands([ReindexSearchCommand::class])` in `SearchServiceProvider::boot()`
- **Files modified:** `Modules/Search/Providers/SearchServiceProvider.php`
- **Commit:** `3cc7359`

## Cross-Plan Dependency

**SearchIndexFreshnessTest** (`Modules/Search/tests/Feature/SearchIndexFreshnessTest.php`) cannot fully pass in this worktree because it calls `app(SearchQuery::class)` — `SearchQuery.php` belongs to Plan 08-03 (parallel execution constraint prohibits creating it here). The freshness tests will pass after wave merge when Plan 03's `SearchQuery` exists.

**ReindexCommandTest** (4 assertions) passes fully. The `.env` missing warning is expected worktree behavior and does not indicate test failure.

## Verification Results

- `vendor/bin/pint --test` on all Task 3 files: PASSED
- `php -d memory_limit=512M vendor/bin/phpstan analyse ... --level=8`: PASSED (no errors)
- `php artisan test --filter=BoundaryArchTest`: PASSED (55 warnings = expected arch rules, no failures)
- `php artisan test --filter=ReindexCommandTest`: PASSED (4 assertions, WARN = missing .env only)

## Known Stubs

None. All wiring is functional — the writer, listener, reindex command, and health check operate against real DB tables from the Wave 1 migration.

## Self-Check: PASSED

Files exist:
- FOUND: Modules/Search/Internal/Services/SearchIndexWriter.php
- FOUND: Modules/Search/Internal/Listeners/IndexTransactionOnImport.php
- FOUND: Modules/Search/Internal/Console/ReindexSearchCommand.php
- FOUND: Modules/Search/Public/Services/FtsHealthCheck.php

Commits exist:
- FOUND: 82437f1 (SearchIndexWriter + IndexTransactionOnImport)
- FOUND: ce0f02a (Tax note re-index hooks)
- FOUND: 3cc7359 (ReindexSearchCommand + FtsHealthCheck + DoctorCommand)
