---
phase: 14-queue-rewire-horizon-carve-out
plan: 02
subsystem: queue-rewire
tags: [queue, cache, locks, jobs, arch-test, tdd]
requires:
  - "config('cache.locks_store') project-defined key (Plan 14-01)"
  - "jobs / cache_locks tables on the default SQLite store (Plan 14-01)"
provides:
  - "QUEUE_CONNECTION=database as the shipped queue default"
  - "Modules\\Core\\Public\\Support\\LockStore — single sanctioned uniqueVia() lock-store resolver"
  - "All 9 ShouldBeUnique* jobs resolve their unique lock via LockStore::forUniqueJobs()"
  - "Single-file Cache/config() facade carve-out (BoundaryArchTest + phpstan.neon)"
affects:
  - "Plan 14-03 (Horizon carve-out) — the database queue + lock path is now the shipped default it gates dev-mode against"
tech-stack:
  added: []
  patterns:
    - "Shared static lock-store resolver as the single module-code Cache facade / config() consumer"
    - "uniqueVia() delegates to a Public/Support helper because Laravel calls it before constructor DI"
key-files:
  created:
    - Modules/Core/Public/Support/LockStore.php
    - Modules/Core/tests/Unit/LockStoreTest.php
    - Modules/Chains/tests/Feature/DatabaseQueueConcurrencyTest.php
  modified:
    - config/queue.php
    - phpstan.neon
    - tests/Contracts/BoundaryArchTest.php
    - Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php
    - Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php
    - Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php
    - Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php
    - Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php
    - Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php
    - Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php
    - Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php
    - Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php
decisions:
  - "after_commit kept false on the database queue connection — chain-resolution dispatch already happens strictly post-commit (ResolveChainLinksJobTest enforces it), so flipping to true is redundant and out of scope"
  - "phpstan.neon carve-out moved together with the BoundaryArchTest carve-out — both shrink from 10 per-job entries to one LockStore entry, since they enforce the same invariant from two angles"
metrics:
  duration: ~25m
  completed: 2026-05-21
  tasks: 3
  files: 15
---

# Phase 14 Plan 02: Queue Rewire Summary

Flipped the shipped queue default to `database`, created the single shared `LockStore` helper that resolves the configured cache lock store, migrated all 9 `ShouldBeUnique*` jobs' identical `uniqueVia()` bodies off the hard-coded `Cache::driver('redis')` onto the helper, collapsed the facade carve-out from 10 per-job entries to one helper file, and proved the slice end-to-end with an SC1 unit test and an SC2 database-lock concurrency test.

## What Was Built

- **Task 1 — LockStore helper + queue default.** Created `Modules/Core/Public/Support/LockStore.php`, a `final class` with one static method `forUniqueJobs(): Repository` returning `Cache::store(config('cache.locks_store'))`. Flipped `config/queue.php` line 20 to `env('QUEUE_CONNECTION', 'database')` and rewrote the stale header comment (which falsely claimed redis was the project default) to describe the `database` driver as the shipped, Redis-free default. `'after_commit' => false` left unchanged on the `database` queue connection.
- **Task 2 — 9-job migration + carve-out collapse (TDD).** RED: added `LockStoreTest` asserting `forUniqueJobs()` resolves the store named by `config('cache.locks_store')` and that every `ShouldBeUnique*` job routes `uniqueVia()` through it. GREEN: replaced the byte-identical `Cache::driver('redis')` body in all 9 jobs with `LockStore::forUniqueJobs()`, dropped the `Cache` facade import, added the `LockStore` import, and rewrote each job's docblock paragraph to describe delegation (no history phrasing, decision-ID tokens dropped from the rewritten paragraphs). Collapsed the `BoundaryArchTest` "no Laravel facade usage" carve-out and the matching `phpstan.neon` `ignoreErrors` list from 10 per-job FQNs to the single `LockStore` FQN.
- **Task 3 — SC2 concurrency test (TDD).** Created `Modules/Chains/tests/Feature/DatabaseQueueConcurrencyTest.php`. With `cache.locks_store=database` and `queue.default=database`, it proves: (1) a duplicate concurrent unique-lock acquire on the SQLite `database` lock store is rejected; (2) running `ResolveChainLinksJob::handle()` once writes exactly one `chain_resolution_runs` row; (3) the database queue admits one `jobs` row and a duplicate dispatch is dropped by the held unique lock. The unique-lock key is derived via `Illuminate\Bus\UniqueLock::getKey()`, never hard-coded.

## Verification

| Check | Result |
|-------|--------|
| `php artisan test --group=Phase14` (13 LockStore SC1 + 3 DatabaseQueueConcurrency SC2) | PASS — 16 passed, 42 assertions |
| `php artisan test --filter=DatabaseQueueConcurrency` | PASS — 3 passed |
| `php artisan test --filter=BoundaryArchTest` | PASS — 38 passed (facade invariant green with the single-file carve-out) |
| `php artisan test --filter="ResolveChainLinksJob\|DiscoveryScan\|IncrementalScan\|BackfillInbox"` | PASS — 21 passed (migrated jobs' own suites still green) |
| `composer analyse` (Larastan L10 strict, `reportUnmatchedIgnoredErrors: true`) | PASS — 0 errors |
| `composer format:check` (Pint) | PASS |
| No `Cache::driver('redis')` literal under `Modules/*/Internal/Jobs/` | PASS — none remain |
| `config('queue.default')` resolves to `database` | PASS |

## Decisions Made

- **`after_commit` kept `false`.** The `database` queue connection's `'after_commit' => false` was left unchanged. Chain-resolution dispatch already happens strictly after the import's outer transaction commits — `ResolveChainLinksJobTest` has a static post-commit-dispatch assertion enforcing this. Flipping to `true` would be redundant defense-in-depth and a behavior change outside this phase's scope.
- **`phpstan.neon` carve-out moved with the arch test.** The plan's Task 2 named `BoundaryArchTest` explicitly; `phpstan.neon` carried the same 10 per-job `Cache` facade ignores enforcing the identical invariant from the static-analysis side. Both were collapsed to one `LockStore` entry together — leaving them out of sync would have failed `composer analyse` under `reportUnmatchedIgnoredErrors: true`. A `config()` global-helper ignore for `LockStore.php` was also added (the helper is the one sanctioned `config()` caller in module code).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree shipped no `vendor/` directory or `.env`**
- **Found during:** Task 1
- **Issue:** The fresh worktree had no `vendor/` and no `.env`, so `php artisan` and all test/analysis commands fatally failed on the missing autoloader.
- **Fix:** Ran `composer install --no-interaction` against the committed `composer.lock` (an install from a pinned lockfile — not a new package add), copied `.env` from `.env.example`, ran `php artisan key:generate`, created `database/database.sqlite`, and ran `php artisan migrate`.
- **Files modified:** None tracked — `vendor/`, `.env`, and `database/database.sqlite` are all gitignored. No commit needed.
- **Commit:** n/a (environment setup only)

**2. [Rule 3 - Blocking] `LockStore.php` tripped Larastan strict rules; `phpstan.neon` carve-out moved into Task 1**
- **Found during:** Task 1
- **Issue:** `LockStore.php`'s `Cache` facade use, `config()` call, and the `mixed`-typed `config()` return tripped three Larastan errors, so `composer analyse` (a Task 1 acceptance gate) failed.
- **Fix:** Added a `/** @var string */` annotation on the `config('cache.locks_store')` read to satisfy `Cache::store()`'s parameter type, and added the `LockStore.php` facade + `config()` ignore entries to `phpstan.neon`. The 10 per-job ignores stayed in place through Task 1 (jobs still used `Cache::driver` then) and were removed in Task 2.
- **Files modified:** `phpstan.neon` (committed with Task 1), `Modules/Core/Public/Support/LockStore.php`
- **Commit:** `11ab024`

### Verification Method Adjustment

- **SC1 `redis` assertion adapted to the test harness.** The plan's `<behavior>` for SC1 asks to assert `forUniqueJobs()` resolves the `redis` store when `config('cache.locks_store')` is `redis`. `tests/TestCase.php::setUp()` deliberately remaps `cache.stores.redis` onto the `array` driver so no test opens a TCP socket to a Redis daemon. The test therefore proves configured-store resolution by comparing `forUniqueJobs()->getStore()` against `Cache::store('redis')->getStore()` directly (driver-agnostic) and by asserting that switching the config key yields a *different* store than `database`. The `database` leg keeps the concrete `DatabaseStore` assertion. The intent — "resolves the configured store, not a hard-coded one" — is fully proven; only the concrete `RedisStore` class assertion was unsuitable for this codebase's test environment.
- **`--group` vs `--filter` for the Phase14 group.** The plan's verify snippet uses `php artisan test --filter=Phase14`. Pest's `--filter` matches test *names*, not group tags; the canonical mechanism is `uses()->group('Phase14')` + `php artisan test --group=Phase14`, which runs all 16 Phase 14 tests. `--filter=DatabaseQueueConcurrency` and `--filter=LockStore` (class-name matches) both work as written. `--group=Phase14` is the correct invocation and is green.
- **SC2 seeds the scenario-1 data shape rather than running the full ingestion pipeline.** The scenario-1 fixtures (`asn-camt053.xml`, `ics-statement.pdf`, `paypal-activity.csv`) are raw source files; persisting them to the `transactions` table end-to-end needs the full Ingestion persistence layer. Consistent with the proven `ResolveChainLinksJobTest::handle()` pattern, `DatabaseQueueConcurrencyTest` seeds transactions directly in the scenario-1 *empirical contract* shape (23 ICS rows totalling 84732 cents + the matching ASN bulk-iDEAL `transfer_in` + the open `card_statement`) so the resolver has real cross-source data to fold. This exercises the same resolver path and produces a genuine `chain_resolution_runs` row; it does not invoke the CSV/PDF/CAMT adapters.

## Self-Check: PASSED

- FOUND: Modules/Core/Public/Support/LockStore.php
- FOUND: Modules/Core/tests/Unit/LockStoreTest.php
- FOUND: Modules/Chains/tests/Feature/DatabaseQueueConcurrencyTest.php
- FOUND: config/queue.php (modified)
- FOUND: phpstan.neon (modified)
- FOUND: tests/Contracts/BoundaryArchTest.php (modified)
- FOUND commit: 11ab024 (Task 1 — LockStore + queue default)
- FOUND commit: 0b548c6 (Task 2 RED — failing LockStore/uniqueVia test)
- FOUND commit: 53b35de (Task 2 GREEN — 9 jobs routed through LockStore)
- FOUND commit: 0f4219f (Task 3 — SC2 database-lock concurrency test)
