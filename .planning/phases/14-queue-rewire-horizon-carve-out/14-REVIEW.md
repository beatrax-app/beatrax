---
phase: 14-queue-rewire-horizon-carve-out
reviewed: 2026-05-22T00:00:00Z
depth: standard
files_reviewed: 33
files_reviewed_list:
  - .env.example
  - Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php
  - Modules/Chains/tests/Feature/DatabaseQueueConcurrencyTest.php
  - Modules/Chains/tests/Feature/ResolveChainLinksJobTest.php
  - Modules/Core/Public/Support/LockStore.php
  - Modules/Core/tests/Unit/LockStoreTest.php
  - Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php
  - Modules/DriftAlerts/tests/Unit/DetectDriftAlertsJobUniqueTest.php
  - Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php
  - Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php
  - Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php
  - Modules/EmailScan/tests/Integration/BackfillPerInboxJobTest.php
  - Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php
  - Modules/Forecasting/tests/Unit/ProjectForecastJobUniqueTest.php
  - Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php
  - Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php
  - Modules/Receipts/tests/Feature/ScanInboxDropFolderJobTest.php
  - Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php
  - Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php
  - app/Providers/HorizonServiceProvider.php
  - bootstrap/providers.php
  - composer.json
  - config/app.php
  - config/cache.php
  - config/queue.php
  - database/migrations/2026_05_21_001844_create_cache_table.php
  - database/migrations/2026_05_21_001844_create_job_batches_table.php
  - database/migrations/2026_05_21_001844_create_jobs_table.php
  - phpstan.neon
  - tests/Contracts/BoundaryArchTest.php
  - tests/Feature/HorizonGatingTest.php
  - tests/Feature/ShippedDependencyTreeTest.php
  - tests/TestCase.php
findings:
  critical: 1
  warning: 6
  info: 4
  total: 11
status: issues_found
---

# Phase 14: Code Review Report

**Reviewed:** 2026-05-22T00:00:00Z
**Depth:** standard
**Files Reviewed:** 33
**Status:** issues_found

## Summary

Phase 14 moves the queue default to the `database` driver, introduces a shared
`LockStore` helper to resolve the cache lock store for nine
`ShouldBeUnique*` jobs, and carves Horizon out to `require-dev` behind a
`config('app.dev_mode')` dev-mode gate. The carve-out architecture is sound:
the `class_exists()` guard in `bootstrap/providers.php`, the `dont-discover`
manifest entry, and the dev-mode early-return in `HorizonServiceProvider::boot()`
are all consistent and well-tested.

However the queue rewire introduces one **BLOCKER**: dispatch correctness
under the `database` driver depends on `after_commit`, which is explicitly set
to `false` for the `database` connection — directly contradicting the
documented post-commit dispatch contract that several jobs rely on for
correctness against SQLite's single-writer transaction frame. There are also
several maintainability and test-integrity warnings, most notably that the new
`DatabaseQueueConcurrencyTest` does not run migrations for the `jobs` /
`cache_locks` tables it asserts against, and a stated stack constraint
(`brick/money ^0.13`) is violated by `composer.json` pinning `^0.11`.

## Critical Issues

### CR-01: `database` queue connection has `after_commit => false`, contradicting the documented post-commit dispatch contract

**File:** `config/queue.php:36` (and `config/queue.php:44` for `redis`)
**Issue:**
`ResolveChainLinksJob`'s class docblock states the queue contract explicitly:

> "Dispatched from `ConfirmImport` AFTER the import's outer DB transaction
> commits — never inside the transaction closure. The queue driver does not
> share the SQLite transaction frame, so an in-transaction dispatch would let
> the worker see stale state."

Phase 14 makes `database` the default queue driver. The `database` connection
in `config/queue.php` sets `'after_commit' => false`. With the *previous*
`sync`/`redis` setup the in-test driver executed inline so ordering was moot,
but on the shipped `database` driver a job dispatched *inside* any wrapping
transaction is inserted into the `jobs` table as part of that transaction.
A worker on a separate connection can then pick the row up. Worse, because
`jobs` is itself a SQLite table, an enqueue inside a transaction participates
in the same single-writer frame, and a dispatch that races a not-yet-committed
write is exactly the stale-state hazard the docblock warns against.

The codebase relies on *callers* manually dispatching post-commit
(`ResolveChainLinksJobTest` even asserts this via line-number arithmetic on
`ConfirmImport`). That discipline is fragile: any future dispatch site inside a
transaction (e.g. a Livewire action that wraps work in `DB::transaction`) will
silently enqueue mid-transaction with no safety net, because the framework-level
`after_commit` guard that would catch it is disabled.

The migration to `database` is precisely the moment `after_commit => true`
becomes the correct default — it is the framework mechanism that enforces the
contract the docblocks describe. Leaving it `false` ships a correctness gap.

**Fix:**
```php
// config/queue.php — database connection
'database' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => true, // enforce the post-commit dispatch contract
],
```
Apply the same to the `redis` connection for parity. If a deliberate per-job
opt-out is ever needed, jobs can call `->beforeCommit()`. If the team decides
to keep `after_commit => false` intentionally, that decision must be documented
in `config/queue.php` and the `ResolveChainLinksJob` docblock's claim that
"an in-transaction dispatch would let the worker see stale state" must be
reconciled — right now the config and the docblock contradict each other.

## Warnings

### WR-01: `composer.json` pins `brick/money: ^0.11` — violates the CLAUDE.md stack constraint of `^0.13`

**File:** `composer.json:9`
**Issue:** The project stack table in CLAUDE.md specifies `brick/money ^0.13`
(0.13.0, Mar 2026, "PHP 8.2+"). `composer.json` pins `"brick/money": "^0.11"`.
`^0.11` will *not* resolve 0.13.x (Composer treats `0.x` minor bumps as
breaking), so the shipped lock file is on an older line than the documented and
intended one. Phase 14 touches `composer.json` (Horizon/predis carve-out) so the
manifest is in scope. This is a quietly drifting dependency that the
`ShippedDependencyTreeTest` does not catch because it only asserts Horizon/predis
placement.

**Fix:** Bump to `"brick/money": "^0.13"` and run `composer update brick/money`,
or — if `^0.11` is deliberate — update CLAUDE.md so the documented constraint
matches reality. Do not leave the manifest and the stack doc disagreeing.

### WR-02: `DatabaseQueueConcurrencyTest` asserts against `jobs` / `cache_locks` tables without guaranteeing the migrations run

**File:** `Modules/Chains/tests/Feature/DatabaseQueueConcurrencyTest.php:180-208`
**Issue:** The test `database queue admits one job row...` does
`$db->connection()->table('jobs')->count()` and `Cache::store('database')->lock(...)`,
and the file carries no `uses(RefreshDatabase::class)` or equivalent. The
suite's stated purpose (header comment lines 20-34) is to exercise "the SQLite
`jobs` table" as "the surface under test." If the harness does not migrate the
`jobs` and `cache_locks` tables, every assertion either fatals with
`no such table: jobs` or — depending on the module `TestCase` — silently runs
against a leftover table from another test, making the "exactly one row" count
non-deterministic across parallel runs (`pest --parallel` is the configured
default). The root `tests/TestCase.php` explicitly notes "Unit tests do not run
migrations" — a feature test relying on real tables must opt in explicitly.

**Fix:** Add the migration trait to the test file:
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('Phase14');
```
Confirm the framework `jobs` / `cache` / `cache_locks` migrations are within the
suite's migration path so the `database` lock store and queue table genuinely
exist before the assertions run.

### WR-03: `LockStore` swallows a misconfigured `cache.locks_store` into an opaque downstream failure

**File:** `Modules/Core/Public/Support/LockStore.php:31-37`
**Issue:** `forUniqueJobs()` does `$store = config('cache.locks_store')` with a
`/** @var string */` annotation and passes it straight to `Cache::store($store)`.
If `cache.locks_store` is null/unset (e.g. a partial `.env`, or a typo in the
env key), `config()` returns `null`, the `@var string` hint is a lie, and
`Cache::store(null)` resolves the *default* store rather than the lock store —
silently. Because this helper is the single chokepoint for nine
`ShouldBeUnique*` jobs, a misconfiguration here degrades queue-uniqueness
guarantees for the whole system with no error. The DI-only carve-out
justification makes this file load-bearing; it should fail loud on bad config.

**Fix:**
```php
public static function forUniqueJobs(): Repository
{
    $store = config('cache.locks_store');
    if (! is_string($store) || $store === '') {
        throw new \RuntimeException(
            'cache.locks_store must be a non-empty store name; got: '.var_export($store, true),
        );
    }

    return Cache::store($store);
}
```

### WR-04: `.env.example` keeps Redis defaults despite the Redis-free shipped-build goal

**File:** `.env.example:38-44`
**Issue:** Phase 14's stated objective is a Redis-free shipped build
(`ShippedDependencyTreeTest` exists to prove predis is `require-dev` only).
Yet `.env.example` — the template a new install copies — ships
`REDIS_CLIENT=predis`, `REDIS_HOST`, `REDIS_PORT`, and `REDIS_PASSWORD=null`
as active (uncommented) keys, alongside a comment claiming "Redis runs as a
loopback-bound Docker container." A shipped (`--no-dev`) build has no predis
package; `REDIS_CLIENT=predis` in that environment is misleading at best and,
if any code path touches the redis connection, fatals on a missing client
class. The default `.env` for a shipped build should describe the Redis-free
reality.

**Fix:** Comment out the `REDIS_*` block in `.env.example` (mirroring the
already-commented `OAUTH_LOOPBACK_PORT` pattern) with a note that it is only
relevant on the developer's Herd box where `CACHE_LOCK_STORE=redis` /
`QUEUE_CONNECTION=redis` are set. The shipped defaults (`database` for both)
already make Redis unnecessary.

### WR-05: `BackfillInboxJob` re-clamp docblock contradicts the implementation; `failed()` does not surface terminal failure on `database` driver

**File:** `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php:99-101, 658-670`
**Issue:** Two related quality defects surface now that the driver changed:

1. The class docblock (lines 99-101) says window clamping is done "before any
   further work — the constructor is too early to read the property in a
   deserialised job." But `runGmailBackfill` / `runMicrosoftBackfill` are
   reached via `handle()`, which *does* clamp at line 191
   (`max(1, min(12, $this->windowMonths))`). The docblock's claim that "the
   constructor is too early" is stale reasoning — the clamp is in `handle()`,
   not deferred for any deserialisation reason. Per the project rule that docs
   describe current state and never rationale/history, this comment should be
   trimmed to state what the code does.

2. `failed()` (lines 658-670) catches every `Throwable` from
   `$sm->applyStatus(...)` and swallows it. With the `database` queue driver,
   `failed()` runs in a separate worker invocation; if the state-machine write
   itself throws (e.g. `SQLITE_BUSY` with no `busy_timeout` set on *that*
   connection — note `handle()` sets `PRAGMA busy_timeout` only inside the
   per-message transaction closure, not for the `failed()` path), the inbox is
   left in a non-terminal state with the failure invisible to the UI. This is a
   silent-failure path that the driver change makes reachable.

**Fix:** (1) Reduce the lines 95-101 docblock to a plain statement: "The
handler clamps `windowMonths` to `[1, 12]` before use." (2) In `failed()`,
either set `PRAGMA busy_timeout` before the `applyStatus` call or, at minimum,
do not swallow unconditionally — a failed terminal-state write on the
failure-handling path deserves a logged warning so the inbox does not strand
silently.

### WR-06: `ScanInboxDropFolderJob::topLevelCandidates()` is now a pure passthrough — dead abstraction with a misleading docblock

**File:** `Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php:188-198`
**Issue:** The method's docblock says it "Skips any path that lives under the
`processed/` or `failed/` subdirectories so re-runs never touch quarantined or
completed files." The body does no such filtering — it iterates
`$files->files($baseDir)` (which is already top-level only, non-recursive) and
appends every pathname unchanged. The skip behaviour described is incidental to
`Filesystem::files()` being non-recursive, not to anything this method does.
The method now adds nothing over a direct `$files->files($baseDir)` call but
carries a docblock that overstates its behaviour, which is exactly the kind of
"comment lies about the code" defect the project guidelines flag. If a future
change makes `$files` recursive or the scan structure changes, the docblock's
promised invariant silently breaks.

**Fix:** Either inline the call and delete the method, or make the docblock
truthful: "Returns the top-level files in `inbox-drop/{userId}/`;
`Filesystem::files()` is non-recursive so the `processed/` and `failed/`
subdirectories are not descended into." Do not claim active filtering the code
does not perform.

## Info

### IN-01: `BackfillInboxJob::handle()` accepts a `$secrets` argument only to `unset()` it

**File:** `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php:161, 170` (also `DiscoveryScanJob.php:151, 160` and `IncrementalScanJob.php:151, 161`)
**Issue:** Three jobs inject `OAuthSecretsRepository $secrets` into `handle()`
and immediately `unset($secrets)` with a comment that it is pinned to the DI
surface "for future re-baselining flows." Injecting a dependency purely to
discard it is dead wiring — it forces the container to resolve a collaborator
no code path uses, and the `unset()` is a workaround for the static analyser.
A future reader cannot tell whether the argument is load-bearing.
**Fix:** Remove the unused parameter from all three `handle()` signatures. When
a re-baselining flow genuinely needs it, add it back at that time. YAGNI applies
to DI surfaces too.

### IN-02: `phpstan.neon` dropped to `level: max` while CLAUDE.md and the constraint doc say "level 10"

**File:** `phpstan.neon:8`
**Issue:** CLAUDE.md's quality-gate constraint says "Larastan at level 10 (max)
with strict mode." `phpstan.neon` uses `level: max`. In current PHPStan `max`
is an alias that tracks the highest available level, so today this is
equivalent to 10 — but it is not pinned. A PHPStan upgrade that adds level 11
would silently raise the bar (or, if `max` ever changes semantics, lower it).
The constraint doc explicitly names a number.
**Fix:** Either pin `level: 10` to match the documented constraint exactly, or
accept `max` and note in CLAUDE.md that the project deliberately tracks the
ceiling. Minor, but the manifest and the doc should agree.

### IN-03: `ProcessFetchedInboxMessagesJob` per-day `ImportRun` anchor uses two different time granularities

**File:** `Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php:189, 197-201`
**Issue:** The `raw_file_path` sentinel uses `format('Y-m-d')` (day
granularity) while the `sha256` run anchor uses `format('Y-m-d-H')` (hour
granularity). The inline comment for `sha256` says it mixes a "wall-clock day
plus userId" so "two hourly runs on the same day collapse to one ImportRun" —
but the anchor is keyed per *hour*, so two runs in the same hour collapse and
runs in different hours of the same day diverge. The comment describes day
collapsing; the code does hour collapsing. The two `format()` calls and the
comment are mutually inconsistent.
**Fix:** Pick one granularity for both the sentinel path and the sha256 anchor,
and correct the comment to match. If hourly collapsing is intended (matching the
hourly cadence), say "two runs in the same hour collapse to one ImportRun."

### IN-04: `LockStoreTest` data-provider case relies on `redis` being remapped to `array`, weakening the `DatabaseStore` assertion

**File:** `Modules/Core/tests/Unit/LockStoreTest.php:70-92`
**Issue:** The `routes every ShouldBeUnique job uniqueVia...` dataset sets
`cache.locks_store => 'database'` and asserts the resolved store
`toBeInstanceOf(DatabaseStore::class)`. This is fine, but the sibling test
`resolves the redis-named store...` (lines 38-45) only asserts identity
against `Cache::store('redis')` — and `tests/TestCase.php::setUp()` remaps the
`redis` store to the `array` driver. So the "redis" test never proves a real
Redis repository is resolved; it proves `LockStore` returns whatever
`cache.stores.redis` points at, which the harness has overridden. The test is
self-consistent but its name implies a stronger guarantee than it delivers.
**Fix:** Rename the test to make the harness substitution explicit (e.g.
"resolves the store named `redis` — remapped to array under test") or assert
on store *name* resolution rather than driver class so the intent is honest.

---

_Reviewed: 2026-05-22T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
