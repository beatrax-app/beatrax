---
phase: 09-unusual-charge-anomaly-alerts
plan: 04
subsystem: api
tags: [anomaly, jobs, queue, listener, scheduler, backfill, safety-net, snooze-revival, should-be-unique, laravel-modules, pest]

# Dependency graph
requires:
  - phase: 09-02 (Anomaly evaluator + detectors)
    provides: "AnomalyEvaluator::evaluate(int transactionId, User) — the single detection entry point all four jobs route through (no duplicate detection logic)"
  - phase: 09-03 (Anomaly read/write Public surface)
    provides: "AnomalyAlertQuery::openForUser revival-aware open filter (state=open OR snoozed-but-expired) — surfaces a revived snooze before the sweep runs; AnomalyAlertStateMachine snoozed->open edge"
  - phase: 05-recurring-drift-alerts (DriftAlerts module)
    provides: "DetectDriftAlertsJob (ShouldBeUniqueUntilProcessing + LockStore uniqueVia) + RevivedExpiredDriftSnoozesJob + EvaluateDriftOnMetricsRefreshed + drift-alerts.revive-snoozes scheduler block — cloned + re-keyed to the per-transaction anomaly shape"
  - phase: import
    provides: "TransactionImported Public event (Transaction + User, per-row synchronous) — the reactive detection hook"
  - phase: 09-01 (Anomaly module scaffold)
    provides: "users.anomaly_backfilled_at first-activation guard column, AnomalyAlertStateMachine (sole mutator), anomaly_alerts UNIQUE(transaction_id) idempotency seam"
provides:
  - "DetectAnomaliesJob — (userId, transactionId)-unique queued detection job (D-12), runs the shared evaluate() path off the import transaction"
  - "EvaluateAnomaliesOnTransactionImport — synchronous listener that QUEUES DetectAnomaliesJob, never detects inline (T-09-14)"
  - "BackfillAnomaliesJob — chunked (lazyById 500) full-history backfill, userId-unique, anomaly_backfilled_at wholesale no-op guard, idempotent + resumable (D-13/D-14)"
  - "ReviveExpiredAnomalySnoozesJob — global hourly snoozed->open revival with audit row + per-row InvalidStateTransition skip (Pattern 4)"
  - "SafetyNetAnomalySweepJob — per-user, userId-unique, re-evaluates recently-imported (30d) NOT-EXISTS-alerted transactions"
  - "TransactionImported listener registration + two scheduler entries (anomaly.revive-snoozes, anomaly.safety-net-sweep) in routes/console.php"
affects: [09-05-ui]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Reactive detection: synchronous listener QUEUES a (userId,txnId)-unique job; baseline math never runs inline in the import transaction (D-12/T-09-14)"
    - "One-shot full-history backfill: ShouldBeUniqueUntilProcessing keyed userId + a persisted anomaly_backfilled_at timestamp short-circuit the WHOLE job on re-dispatch; per-row idempotency on UNIQUE(transaction_id) makes it resumable"
    - "Recent-window safety-net sweep: NOT EXISTS against anomaly_alerts + a 30-day created_at window keeps the catch-up cheap (history is the backfill's job), per-user fan-out via lazyById(100)"
    - "Hybrid snooze revival (cloned from drift): global hourly sweep writes the audit transition; the query-time open filter surfaces expired snoozes immediately between sweeps"
    - "All four jobs route through the single AnomalyEvaluator::evaluate() path — zero duplicated detection logic across reactive / backfill / sweep"

key-files:
  created:
    - Modules/Anomaly/Internal/Jobs/DetectAnomaliesJob.php
    - Modules/Anomaly/Internal/Jobs/BackfillAnomaliesJob.php
    - Modules/Anomaly/Internal/Jobs/ReviveExpiredAnomalySnoozesJob.php
    - Modules/Anomaly/Internal/Jobs/SafetyNetAnomalySweepJob.php
    - Modules/Anomaly/Internal/Listeners/EvaluateAnomaliesOnTransactionImport.php
    - Modules/Anomaly/tests/Unit/DetectAnomaliesJobUniqueTest.php
    - Modules/Anomaly/tests/Unit/EvaluateAnomaliesOnImportTest.php
    - Modules/Anomaly/tests/Feature/BackfillAnomaliesJobTest.php
    - Modules/Anomaly/tests/Feature/AnomalySnoozeRevivalTest.php
    - Modules/Anomaly/tests/Feature/SafetyNetAnomalySweepTest.php
  modified:
    - Modules/Anomaly/Providers/AnomalyServiceProvider.php
    - routes/console.php

key-decisions:
  - "SafetyNetAnomalySweepJob recency window = 30 days by transactions.created_at (import time): the sweep catches charges the reactive listener missed during import, while the one-shot BackfillAnomaliesJob owns full history — keeping the hourly sweep cheap"
  - "Candidate filter is a NOT EXISTS against anomaly_alerts (not a left-join scan) so already-alerted rows are never re-evaluated; UNIQUE(transaction_id) is the final backstop on any race"
  - "BackfillAnomaliesJob + SafetyNetAnomalySweepJob inject Clock for the backfilled-at timestamp / window cutoff (not the now() global helper) to satisfy larastanStrictRules.noGlobalLaravelFunction and stay deterministic under setTestNow()"
  - "anomaly.safety-net-sweep fans out per-user via User::query()->lazyById(100) (mirrors forecasting/fx) and dispatches a userId-unique job; anomaly.revive-snoozes stays global (the state machine resolves the user from the row)"

patterns-established:
  - "Reactive listener stays synchronous (no ShouldQueue on the listener) and only DISPATCHES the queued job, so the unique-job key on (userId,txnId) is not defeated by a double-queued listener"
  - "Backfill/sweep enumerate via ->select('id')->lazyById(CHUNK) so a multi-year ledger never loads into memory; per-row evaluate() reuse means one detection code path"
  - "Scheduler entries follow the file-wide method order .name() BEFORE .hourly() BEFORE .withoutOverlapping(30) so CallbackEvent's description-required guard is satisfied (schedule:list exits 0)"

requirements-completed: [ANOM-01]

# Metrics
duration: ~22 min
completed: 2026-06-13
---

# Phase 9 Plan 04: Anomaly Detection Orchestration Summary

**The runtime that makes ANOM-01 fire: a synchronous TransactionImported listener that QUEUES a (userId,txnId)-unique DetectAnomaliesJob off the import transaction (D-12), a chunked full-history BackfillAnomaliesJob guarded wholesale by anomaly_backfilled_at (D-13/D-14), an hourly global ReviveExpiredAnomalySnoozesJob (snoozed->open + audit row), and a per-user SafetyNetAnomalySweepJob over recently-imported-but-unalerted charges — all four routed through the single AnomalyEvaluator::evaluate() path, plus the listener registration + two scheduler entries.**

## Performance

- **Duration:** ~22 min
- **Started:** 2026-06-13T18:35:00Z
- **Completed:** 2026-06-13
- **Tasks:** 3 (all TDD: RED -> GREEN -> verify)
- **Files modified:** 12 (10 created, 2 modified)

## Accomplishments
- `DetectAnomaliesJob` clones `DetectDriftAlertsJob` re-keyed to `(userId, transactionId)`: `ShouldBeUniqueUntilProcessing`, `uniqueId "{userId}:{transactionId}"`, `tries=3`, `backoff=[60,300,900]`, `uniqueFor=600`, `uniqueVia=>LockStore::forUniqueJobs()`; `handle(AnomalyEvaluator)` resolves the user via `firstOrFail` and runs the shared `evaluate()` path. `EvaluateAnomaliesOnTransactionImport` is a synchronous listener that only DISPATCHES the job (no `AnomalyEvaluator` reference at all, reads `$event->transaction->id` + `$event->user->id`), registered in `AnomalyServiceProvider::boot()` via the `class_exists`-guarded `$events->listen` shape (T-09-14).
- `BackfillAnomaliesJob` (userId-unique): a non-null `users.anomaly_backfilled_at` short-circuits the WHOLE job; otherwise it walks the user's full history via `->select('id')->lazyById(500)`, runs `evaluate()` per row (lands alerts in Open, no muting — D-14), and stamps `anomaly_backfilled_at` on completion via the injected Clock. Idempotent + resumable on `UNIQUE(transaction_id)`, explicit `where('user_id')` (T-09-16).
- `ReviveExpiredAnomalySnoozesJob` clones the drift revival re-keyed to `anomaly_alerts` + `AnomalyAlertStateMachine`: global `SELECT state='snoozed' AND snoozed_until<=now()`, per-row `try/catch(InvalidStateTransitionException){continue;}`, transitions via the sole-mutator state machine (reason `detector_revived_snooze`, actor `detector`, T-09-17). The companion `AnomalyAlertQuery::openForUser` already surfaces the expired snooze before the sweep runs (Pattern 4, verified by test).
- `SafetyNetAnomalySweepJob` (per-user, userId-unique): re-evaluates the user's transactions imported within 30 days (`created_at`) that have no `anomaly_alerts` row (NOT EXISTS) through `evaluate()`; already-alerted rows are skipped and any race collapses on `UNIQUE(transaction_id)`.
- `routes/console.php`: `anomaly.revive-snoozes` (hourly, global dispatch) + `anomaly.safety-net-sweep` (hourly, `User::query()->lazyById(100)` per-user fan-out), both with method order `.name()` -> `.hourly()` -> `.withoutOverlapping(30)`. `php artisan schedule:list` exits 0 with both entries present.
- All gates green: 135 Pest tests in the Anomaly suite (193 with `BoundaryArchTest`), Pint clean on every touched file, PHPStan L10 strict clean on all six touched in-`Modules` source files.

## Task Commits

Each task committed atomically (TDD):

1. **Task 1: DetectAnomaliesJob + reactive listener + TransactionImported registration** - `25ee487` (feat)
2. **Task 2: BackfillAnomaliesJob (chunked, idempotent, anomaly_backfilled_at guard)** - `fd71b27` (feat)
3. **Task 3: ReviveExpiredAnomalySnoozesJob + SafetyNetAnomalySweepJob + scheduler wiring** - `be106b7` (feat)

**Plan metadata:** _(this commit)_ (docs: complete plan)

## Files Created/Modified
See `key-files` frontmatter. Highlights:
- `Modules/Anomaly/Internal/Jobs/DetectAnomaliesJob.php` — the reactive (userId,txnId)-unique detection job.
- `Modules/Anomaly/Internal/Jobs/BackfillAnomaliesJob.php` — the one-shot full-history backfill with the wholesale `anomaly_backfilled_at` guard.
- `Modules/Anomaly/Internal/Jobs/SafetyNetAnomalySweepJob.php` — the recent-window NOT-EXISTS catch-up sweep.
- `Modules/Anomaly/Internal/Jobs/ReviveExpiredAnomalySnoozesJob.php` — the global hourly snooze revival (drift clone).
- `Modules/Anomaly/Internal/Listeners/EvaluateAnomaliesOnTransactionImport.php` — synchronous queue-only listener.
- `Modules/Anomaly/Providers/AnomalyServiceProvider.php` — `boot(Dispatcher)` now registers the `class_exists`-guarded `TransactionImported` listener.
- `routes/console.php` — two new scheduler entries.

## Decisions Made
- **Safety-net window = 30 days by `created_at` (import time):** the sweep is the catch-up for charges the reactive listener missed *during import*; the one-shot backfill owns full history. A 30-day window keeps the hourly per-user sweep cheap and bounded.
- **`NOT EXISTS` candidate filter (not a left-join):** already-alerted rows are excluded at the query layer, so the sweep evaluates only genuinely-unalerted recent rows; `UNIQUE(transaction_id)` is the final backstop on a concurrent reactive insert.
- **Inject `Clock`, not `now()`:** both new per-user jobs take `Clock` in `handle()` for the backfilled-at stamp and the window cutoff — satisfies `larastanStrictRules.noGlobalLaravelFunction` and keeps the jobs deterministic under `CarbonImmutable::setTestNow()`.
- **Revival stays global, sweep fans out per-user:** the revival only flips `snoozed->open` (no cross-user data exposure, T-09-16) and the state machine reads the owning user from the row, so a global sweep is correct; the safety-net sweep reads per-user transaction history and therefore fans out one userId-scoped job per user.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Inject Clock into BackfillAnomaliesJob (and SafetyNetAnomalySweepJob) instead of the now() global helper**
- **Found during:** Task 2 (per-task PHPStan gate), repeated in Task 3
- **Issue:** L10 strict (`larastanStrictRules.noGlobalLaravelFunction`) rejects the `now()` global helper for the `anomaly_backfilled_at` write. The state machine + evaluator both inject `Clock`, so the global helper also broke the deterministic-time convention.
- **Fix:** Added `Modules\Core\Public\Contracts\Clock` to both jobs' `handle()` signatures and used `$clock->now()->...`; updated the test job-runner helpers to pass `app(Clock::class)`.
- **Files modified:** BackfillAnomaliesJob.php, SafetyNetAnomalySweepJob.php, BackfillAnomaliesJobTest.php, SafetyNetAnomalySweepTest.php
- **Verification:** PHPStan L10 strict clean on both jobs; all backfill + sweep tests green.
- **Committed in:** `fd71b27` / `be106b7` (respective task commits)

**2. [Rule 3 - Blocking] Backfill no-op test re-parents a fresh transaction instead of re-seeding the same fixture**
- **Found during:** Task 2 (the "second run is a no-op" test)
- **Issue:** Re-seeding the `large-above` fixture for the SAME user a second time collided on `UNIQUE(counterparties.user_id, counterparties.slug)` — a test-data artifact, not a job bug (the other three backfill tests passed).
- **Fix:** The no-op test now seeds the second anomalous fixture as a fresh user, then re-parents that brand-new transaction onto the backfilled user and asserts the `anomaly_backfilled_at` guard short-circuits it (the new row is never alerted).
- **Files modified:** BackfillAnomaliesJobTest.php
- **Verification:** All four backfill tests green; the guard short-circuit is now asserted directly.
- **Committed in:** `fd71b27` (Task 2 commit)

**3. [Rule 1 - Bug] Listener-source assertion stripped comment lines before the `$event->transactionId` check**
- **Found during:** Task 1 (the "queues only" listener test)
- **Issue:** The acceptance criterion's `$event->transactionId`-absence assertion tripped on the listener's *docblock*, which deliberately warns "NOT a flat `$event->transactionId`". The production code uses `$event->transaction->id` correctly.
- **Fix:** The test strips comment lines (`preg_replace` of `*`/`//` lines) before asserting the flat-property access is absent from the CODE, keeping the intent (no `$event->transactionId` access) without false-positiving on documentation.
- **Files modified:** EvaluateAnomaliesOnImportTest.php
- **Verification:** Listener test green; the code-vs-comment distinction is now explicit.
- **Committed in:** `25ee487` (Task 1 commit)

---

**Total deviations:** 3 auto-fixed (2 blocking static-analysis/test-harness, 1 test-assertion bug)
**Impact on plan:** All necessary for the quality gates / correct test semantics. The Clock injection is the one production change and aligns the jobs with the module-wide deterministic-time convention. No scope creep; the four jobs + listener + scheduler entries match the plan one-for-one.

## Issues Encountered
- **PHPStan scope for `routes/console.php`:** passing the routes file explicitly to PHPStan surfaces pre-existing `Schedule` facade + mixed-cast errors that exist on every scheduler entry in the file. The project's `phpstan.neon` `paths:` only includes `Modules`, `app`, and `bootstrap/app.php` — `routes/` is out of analysis scope by design, so the new entries (which follow the identical sanctioned pattern) are not gated by PHPStan. The two new in-`Modules` jobs are PHPStan-clean.
- PHPStan was scoped to the touched paths with `php -d memory_limit=3G ./vendor/bin/phpstan analyse <files>` per the project memory note about host fd/memory limits on whole-repo runs.

## Known Stubs
None — all four jobs + the listener are fully wired against real tables, the shared evaluator, and the state machine. The `AnomalyServiceProvider::boot()` retains an explicit `TODO(Plan 05)` note for the not-yet-built Livewire surface + nav badge composer — the planned wave boundary, not a stub in this plan's deliverables. `BackfillAnomaliesJob` has no dispatch site yet; per the plan it is dispatched on first activation by the Plan 05 settings toggle (documented intent, not a stub).

## Threat Flags
None — no new network endpoint, auth path, or trust-boundary schema change beyond the plan's threat model. The implementation applies the prescribed mitigations: T-09-14 (listener queues, never inline — asserted), T-09-15 (userId-unique + `anomaly_backfilled_at` guard + lazyById chunking), T-09-16 (explicit `where('user_id')` on the per-user backfill/sweep; revival global-by-design only flips snoozed->open), T-09-17 (revival mutates state only via the sole-mutator state machine).

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Detection now runs end-to-end: reactively on import (D-12), as an hourly per-user safety net, with a one-shot full-history backfill ready to dispatch, and an hourly snooze revival. Plan 05's `/drift` type switch + settings toggle can consume `AnomalyAlertQuery` for display and dispatch `BackfillAnomaliesJob($user->id)` when the user first enables anomaly detection.
- The recency window (30d), backfill chunk (500), and sweep chunk (500) are all one-line tunables should production volume warrant.
- The two scheduler entries are live (`schedule:list` exits 0); they will fire once the queue worker + scheduler daemons run (existing launchd wiring).

## Self-Check: PASSED
- All 10 created files verified present on disk.
- Task commits `25ee487`, `fd71b27`, `be106b7` present in `git log`.
- Plan verification re-run: `./vendor/bin/pest Modules/Anomaly tests/Contracts/BoundaryArchTest.php` -> 193 passed (627 assertions). `php artisan schedule:list` exits 0 with `anomaly.revive-snoozes` + `anomaly.safety-net-sweep`. Pint clean (`--test`) on every touched file; PHPStan L10 strict clean on all six touched in-`Modules` source files.

---
*Phase: 09-unusual-charge-anomaly-alerts*
*Completed: 2026-06-13*
