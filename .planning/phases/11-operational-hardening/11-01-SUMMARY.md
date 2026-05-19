---
phase: 11-operational-hardening
plan: 01
subsystem: infra
tags: [sqlite, eloquent, system-alerts, pest, larastan, arch-test, pdo, wal]

# Dependency graph
requires:
  - phase: 09-drift-alerts
    provides: DriftAlerts migration / Eloquent / Public service+action shape that the system_alerts surface mirrors verbatim
  - phase: 01-foundation
    provides: Modules\Core\Public\Concerns\BelongsToUser trait, Modules\Core\Models\User, Modules\Core\Public\Contracts\Clock
provides:
  - system_alerts persistence surface (migration + Eloquent model with severity trigger pair)
  - SystemAlertQuery Public read service (per-user + system-wide carve-out, severity-tier ordering)
  - AcknowledgeSystemAlert Public action (transactional, idempotent, cross-user 404)
  - systemAlertsTableNotJoinedToTransactions BoundaryArchTest invariant
  - tests/Helpers/RealSqliteFixture (on-disk SQLite + WAL fixture builder for Wave 1+ VACUUM INTO tests)
affects: [11-02-backup-command, 11-03-restore-command, 11-04-doctor-probes-failed-jobs, 11-05-banner-readme]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Raw DatabaseManager::table() + SystemAlert::hydrate() round-trip in Public read services to satisfy larastan-strict-rules' staticMethod.dynamicCall on Eloquent\\Builder"
    - "Operational persistence surface (kind/severity/message/metadata/acknowledged_at) mirroring drift_alerts shape but free-form to accept any module's failure event"
    - "Reusable tests/Helpers/RealSqliteFixture for on-disk SQLite + WAL tests outside the Modules/ tree"

key-files:
  created:
    - Modules/Core/Database/Migrations/2026_05_20_010001_create_system_alerts_table.php
    - Modules/Core/Models/SystemAlert.php
    - Modules/Core/Public/Services/SystemAlertQuery.php
    - Modules/Core/Public/Actions/AcknowledgeSystemAlert.php
    - Modules/Core/tests/Unit/SystemAlertsMigrationTest.php
    - Modules/Core/tests/Unit/SystemAlertModelTest.php
    - Modules/Core/tests/Unit/SystemAlertQueryTest.php
    - Modules/Core/tests/Unit/AcknowledgeSystemAlertTest.php
    - tests/Helpers/RealSqliteFixture.php
    - tests/Helpers/RealSqliteFixtureTest.php
  modified:
    - Modules/Core/Providers/CoreServiceProvider.php
    - tests/Contracts/BoundaryArchTest.php
    - phpunit.xml

key-decisions:
  - "Drop the planner-suggested scopeActive / scopeByKind methods on SystemAlert — larastan-strict-rules' noLocalQueryScope rejects them. Callers use whereNull('acknowledged_at') / where('kind', …) inline at the service layer, mirroring DriftAlertQuery's pattern."
  - "Run SystemAlertQuery::active() through DatabaseManager::table() + SystemAlert::hydrate() instead of SystemAlert::query()->…->get(). The Eloquent path triggers staticMethod.dynamicCall on whereNull / orWhereNull / orderByRaw / orderBy after Model::query(); the raw-builder + hydrate round-trip preserves Collection<SystemAlert> while staying strict-rules clean."
  - "Drop the Clock dependency from SystemAlertQuery v1. The plan asked for parity with DriftAlertQuery but phpstan property.onlyWritten rejects the unused readonly param. Clock will be re-added when the first time-windowed filter (e.g. 'last 24h') ships."
  - "RealSqliteFixture::DEFAULT_SCHEMAS is a public const array (PHP 8.5 typed constants). Extension hook is [...RealSqliteFixture::DEFAULT_SCHEMAS, 'CREATE TABLE …']; one Pest scenario locks the seam against future regressions."

patterns-established:
  - "system_alerts severity trigger pair: BEFORE INSERT + BEFORE UPDATE OF severity raising ABORT on values outside 'info','warning','critical' — a schema-level rail that survives Eloquent layer bugs."
  - "Per-user-OR-system-wide read scope on SystemAlerts: where(user_id = X OR user_id IS NULL) for authenticated reads; whereNull(user_id) only when called with NULL user."
  - "Public read services use raw Query Builder + SystemAlert::hydrate to return Eloquent Collection<Model> instances without tripping larastan-strict-rules on chained Eloquent\\Builder calls."
  - "tests/Helpers/ for cross-Modules test infrastructure (added to phpunit.xml Unit testsuite); helpers live outside the Modules/ tree so the arch invariants against module-facade-usage do not apply."

requirements-completed: [FND-05]

# Metrics
duration: 95min
completed: 2026-05-19
---

# Phase 11 Plan 01: System Alerts Foundation Summary

**system_alerts persistence surface (migration + severity trigger pair + Eloquent model) plus the SystemAlertQuery + AcknowledgeSystemAlert Public API and a reusable on-disk SQLite fixture helper that subsequent Wave 1+ backup/restore plans depend on.**

## Performance

- **Duration:** ~95 min
- **Started:** 2026-05-19 (Wave 0 of Phase 11)
- **Completed:** 2026-05-19
- **Tasks:** 3 (all autonomous, all TDD: RED → GREEN per task)
- **Files created:** 10
- **Files modified:** 3

## Accomplishments

- Migrated the `system_alerts` table on the SQLite testing connection, including the eight load-bearing columns, the two read indexes (`user_id+acknowledged_at`, `kind+acknowledged_at`), and the BEFORE INSERT + BEFORE UPDATE OF severity trigger pair that rejects out-of-band severity values at the schema rail.
- Shipped a `SystemAlert` Eloquent model with the BelongsToUser trait, the immutable_datetime cast pipeline, and JSON metadata array casts — `$timestamps = false` because the migration intentionally omits an `updated_at` column.
- Shipped `SystemAlertQuery::active(?User)` returning an Eloquent `Collection<SystemAlert>` of un-acknowledged rows scoped to the caller + system-wide (`user_id IS NULL`) rows, ordered critical → warning → info with chronological tie-break inside each severity tier.
- Shipped `AcknowledgeSystemAlert::__invoke(int, User)` that stamps `acknowledged_at = Clock::now()` inside `connection()->transaction(...)`, is idempotent on already-acknowledged rows, and throws `NotFoundHttpException` on cross-user lookups without touching the original row.
- Extended `BoundaryArchTest` with the `systemAlertsTableNotJoinedToTransactions` invariant that walks the entire `Modules/` tree (excluding `tests/`), strips comments, and fails if any non-test file co-references a `->join('system_alerts')` call alongside the `transactions` table literal.
- Shipped `tests/Helpers/RealSqliteFixture` (`create()` + `cleanup()` + `DEFAULT_SCHEMAS` public const array) for the Wave 1+ backup/restore tests that need a real on-disk `.sqlite` file with WAL journal mode active — the default sqlite_testing `:memory:` connection cannot back `VACUUM INTO` against a path.

## Task Commits

Each task was committed atomically (TDD: test → feat per task):

1. **Task 1 — RED:** `091face` (test: add failing migration + model tests for system_alerts)
2. **Task 1 — GREEN:** `d99b2fb` (feat: add system_alerts migration + SystemAlert model)
3. **Task 2 — RED:** `2394d3c` (test: add failing tests for SystemAlertQuery + AcknowledgeSystemAlert)
4. **Task 2 — GREEN:** `ebc7b81` (feat: ship SystemAlertQuery + AcknowledgeSystemAlert Public surface)
5. **Task 3 — RED:** `989fd56` (test: add failing RealSqliteFixture helper test + arch invariant)
6. **Task 3 — GREEN:** `06da959` (feat: ship RealSqliteFixture helper for on-disk SQLite tests)

## Files Created/Modified

### Created

- `Modules/Core/Database/Migrations/2026_05_20_010001_create_system_alerts_table.php` — anonymous-class migration with cached DatabaseManager resolver, severity trigger pair, two read indexes; down() drops triggers + table.
- `Modules/Core/Models/SystemAlert.php` — `final class` extending `Model`, uses `BelongsToUser`, casts `metadata=>array`, `acknowledged_at`/`created_at=>immutable_datetime`, `$timestamps=false`.
- `Modules/Core/Public/Services/SystemAlertQuery.php` — `final readonly class` with `DatabaseManager` constructor DI; `active(?User)` + `count(?User)` use raw Query Builder + `SystemAlert::hydrate()`.
- `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` — `final class` with `DatabaseManager` + `Clock` constructor DI; cross-user 404, idempotent, transactional.
- `Modules/Core/tests/Unit/SystemAlertsMigrationTest.php` — 12 tests covering columns, indexes, severity triggers, system-wide rows, FK cascade.
- `Modules/Core/tests/Unit/SystemAlertModelTest.php` — 4 tests covering casts, BelongsTo relation, inline whereNull/where('kind') predicates.
- `Modules/Core/tests/Unit/SystemAlertQueryTest.php` — 7 tests covering per-user scope, system-wide carve-out, severity ordering, count(), null-user mode.
- `Modules/Core/tests/Unit/AcknowledgeSystemAlertTest.php` — 6 tests covering happy path with frozen Clock, system-wide ack, cross-user 404 + row-untouched, idempotent, missing-id 404, singleton resolution.
- `tests/Helpers/RealSqliteFixture.php` — `final class` with `create(string $name, array $schemas)`, `cleanup(string $path)`, and `DEFAULT_SCHEMAS` public const; raw PDO, mkdir/unlink/rmdir, no facades.
- `tests/Helpers/RealSqliteFixtureTest.php` — 6 tests covering file existence, cleanup round-trip, default schemas, WAL mode, custom-schemas extension hook, per-call uniqueness.

### Modified

- `Modules/Core/Providers/CoreServiceProvider.php` — added `SystemAlertQuery::class` + `AcknowledgeSystemAlert::class` singleton bindings (two lines in `register()`).
- `tests/Contracts/BoundaryArchTest.php` — appended `systemAlertsTableNotJoinedToTransactions` invariant (verbatim shape from RESEARCH §Code Examples + matching the Phase 10 `noScenarioMutationsJoinedToTransactionQueries` template).
- `phpunit.xml` — added `<directory>tests/Helpers</directory>` to the `Unit` testsuite so the helper's sanity tests are discovered alongside the rest of the unit suite.

## Decisions Made

See frontmatter `key-decisions`. The four substantive deviations from the planner's letter (no model scopes; raw-builder + hydrate in the query; drop the unused Clock from SystemAlertQuery; tests/Helpers added to phpunit.xml) are all driven by the project's CI-enforced larastan-strict-rules profile. The plan's `<read_first>` references and `<action>` blocks describe the intent precisely; the implementation paths chosen here satisfy the same intent while passing the static-analysis bar.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Dropped `scopeActive` + `scopeByKind` from SystemAlert model**
- **Found during:** Task 1 GREEN (after running phpstan on the new files)
- **Issue:** The plan's `<behavior>` block on Task 1 asks for `scopeActive(Builder $q)` and `scopeByKind(Builder $q, string $kind)` on the SystemAlert model. Larastan with `larastan-strict-rules` rejects local query scopes via the `larastanStrictRules.noLocalQueryScope` identifier — the same rule the rest of the project's models comply with (DriftAlert never defines scopes; filters are applied inline at the service layer).
- **Fix:** Removed the two scope methods. Updated the model docblock to document the inline-predicate pattern. Updated `SystemAlertModelTest.php` to assert the inline `whereNull('acknowledged_at')` / `where('kind', …)` predicates instead of `->active()` / `->byKind()`.
- **Files modified:** Modules/Core/Models/SystemAlert.php, Modules/Core/tests/Unit/SystemAlertModelTest.php
- **Verification:** phpstan exits 0; the 4 model-test scenarios still cover both filters.
- **Committed in:** d99b2fb (Task 1 GREEN commit)

**2. [Rule 1 — Bug] Switched SystemAlertQuery + AcknowledgeSystemAlert to raw Query Builder + SystemAlert::hydrate**
- **Found during:** Task 2 GREEN (after running phpstan)
- **Issue:** The plan's `<behavior>` block on Task 2 specifies an Eloquent path: `SystemAlert::query()->whereNull('acknowledged_at')->where(function (Builder $q) use ($user) { … })->orderByRaw(…)->orderBy(…)->get()`. Each chained call on the Eloquent `Builder` object after `Model::query()` triggers `staticMethod.dynamicCall` under `phpstan-strict-rules` — `whereNull` / `orWhereNull` / `orderByRaw` / `orderBy` are all static methods on `Illuminate\Database\Eloquent\Model`/`Builder` per PHPStan's introspection, and the rules profile rejects calling them dynamically. `findOrFail` is also affected. The KnownSenderQuery and TopCategoriesByPeriodQuery in other modules use the raw `DatabaseManager::table()` Query Builder for exactly this reason.
- **Fix:** Rewrote `SystemAlertQuery::active()` to use `$this->db->connection()->table('system_alerts')` + a typed `function (Builder $q)` closure for the per-user-OR-system-wide predicate, then `SystemAlert::hydrate((array) $row[])` to hand callers an Eloquent `Collection<SystemAlert>`. `count()` uses the same raw-builder shape. `AcknowledgeSystemAlert::__invoke()` does the cross-user 404 lookup via raw `table()`, then re-hydrates via `SystemAlert::query()->findOrFail($id)` for the row mutation.
- **Files modified:** Modules/Core/Public/Services/SystemAlertQuery.php, Modules/Core/Public/Actions/AcknowledgeSystemAlert.php
- **Verification:** phpstan exits 0; all 13 service+action unit tests still pass (per-user scope, system-wide carve-out, severity ordering, count, happy-path ack, cross-user 404, idempotent, missing-id 404, singleton resolution).
- **Committed in:** ebc7b81 (Task 2 GREEN commit)

**3. [Rule 1 — Bug] Dropped the unused Clock dependency from SystemAlertQuery v1**
- **Found during:** Task 2 GREEN (after running phpstan)
- **Issue:** The plan's `<behavior>` block on Task 2 asks SystemAlertQuery to constructor-inject `Clock $clock` "for parity with DriftAlertQuery and for future 'alerts created in last 24h' filters — keep it injected, do not call now() for v1." Phpstan with strict rules surfaces `property.onlyWritten` on the unused `readonly` constructor-promoted `Clock` property because v1 never calls `$this->clock->now()`. A no-op `touchClock()` method would lie about intent.
- **Fix:** Removed the Clock parameter from SystemAlertQuery's constructor for v1. The future "last 24h" filter will re-introduce it at the point it has a real read. `AcknowledgeSystemAlert` keeps its Clock dependency because it actively writes `acknowledged_at = Clock::now()`.
- **Files modified:** Modules/Core/Public/Services/SystemAlertQuery.php
- **Verification:** phpstan exits 0; SystemAlertQueryTest singleton-resolution + per-user scope tests still pass without the Clock binding.
- **Committed in:** ebc7b81 (Task 2 GREEN commit)

**4. [Rule 3 — Blocking] Added tests/Helpers to the phpunit.xml Unit testsuite**
- **Found during:** Task 3 RED (the RealSqliteFixtureTest was not discovered by Pest)
- **Issue:** The plan locates the helper test at `tests/Helpers/RealSqliteFixtureTest.php`, but no phpunit testsuite entry covered that directory. Pest exited with `INFO No tests found.` and the `pest --filter='RealSqliteFixtureTest'` acceptance criterion in the plan would never pass without the registration.
- **Fix:** Added `<directory>tests/Helpers</directory>` to the `Unit` testsuite in `phpunit.xml`. This is the same level of infrastructure change the planner asked for in the plan's `<files_modified>` block implicitly (the helper test file is part of the plan's deliverables).
- **Files modified:** phpunit.xml
- **Verification:** Pest discovers and runs all 6 RealSqliteFixtureTest scenarios; no other testsuite changes were made.
- **Committed in:** 989fd56 (Task 3 RED commit, alongside the test file)

---

**Total deviations:** 4 auto-fixed (3 Rule 1 — Bug, 1 Rule 3 — Blocking)
**Impact on plan:** All four deviations are driven by the project's CI-enforced larastan-strict-rules and phpunit-discovery requirements. They preserve the plan's intent verbatim (per-user-OR-system-wide scoping, severity-tier ordering, transactional + idempotent acknowledge, on-disk SQLite fixture helper) while satisfying the static-analysis bar. No scope creep; no architectural changes; no new packages.

## Issues Encountered

**Worktree CWD vs. PHPUnit testsuite-path discovery.** When pest is invoked from the worktree CWD, `tests/Pest.php`'s per-module `__DIR__.'/../Modules/<X>/tests/{Unit,Feature}'` extend calls do not match the discovered test files (the realpath includes the `.claude/worktrees/<id>/` prefix, breaking the glob). Resolved by running every verification pass from the main-repo CWD with composer's autoload regenerated against the worktree paths — the test files were copied into the main repo for each verification cycle, then removed afterward so the main repo stayed clean. This is an artifact of git-worktree + composer-autoload interaction and does not affect production behaviour. All commits are in the worktree branch; the main repo was only used as a verification harness.

## User Setup Required

None — no new dependencies, no new environment variables, no external service configuration. The system_alerts migration is additive and runs cleanly on the existing sqlite_testing in-memory connection. The plan's local `php artisan migrate --force` step was deferred (the worktree branch should not modify the user's local on-disk dev DB); Pest's `:memory:` migration via `RefreshDatabase` exercises the same code path and exits 0.

## Next Phase Readiness

- **Wave 1 (Plan 11-02 BackupDatabaseCommand):** Ready to consume `SystemAlert::create([…])` for the corrupt-backup path and `RealSqliteFixture::create(…)` for the on-disk VACUUM INTO + integrity_check tests.
- **Wave 1 (Plan 11-03 RestoreDatabaseCommand):** Ready to consume the same SystemAlert write path for the post-swap critical-failure case and RealSqliteFixture for the swap-target fixture.
- **Wave 2 (Plan 11-04 doctor probes):** Ready to consume `SystemAlertQuery` for the BackupFreshnessProbe's duplicate-suppression read.
- **Wave 2 (Plan 11-05 banner SFC):** Ready to consume `SystemAlertQuery::active($currentUser)` for the mount-time read and `AcknowledgeSystemAlert::__invoke($id, $user)` for the wire:click handler.

## Self-Check: PASSED

- File `Modules/Core/Database/Migrations/2026_05_20_010001_create_system_alerts_table.php` exists in worktree.
- File `Modules/Core/Models/SystemAlert.php` exists in worktree.
- File `Modules/Core/Public/Services/SystemAlertQuery.php` exists in worktree.
- File `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` exists in worktree.
- File `tests/Helpers/RealSqliteFixture.php` exists in worktree.
- Commits 091face, d99b2fb, 2394d3c, ebc7b81, 989fd56, 06da959 all reachable from HEAD.
- `pest --testsuite=Unit --filter='SystemAlert|RealSqliteFixture'` → 35 passed (80 assertions).
- `pest --testsuite=Contracts --filter='systemAlertsTableNotJoinedToTransactions'` → 1 passed.
- `pest --testsuite=Contracts` (full suite) → 102 passed (no regression).
- `phpstan analyse` (whole tree) → No errors.
- `pint --test` (all touched files) → passed.

---
*Phase: 11-operational-hardening*
*Completed: 2026-05-19*
