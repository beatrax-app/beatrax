---
phase: 02-savings-goals-seed-003
plan: "02"
subsystem: Goals
tags: [read-model, dto, fx, projection, forecasting, wave-2]
dependency_graph:
  requires:
    - goals table + Goal model + GoalFactory (Plan 01)
    - Modules\FX\Public\Services\ExchangeRateService::convertToBase
    - Modules\Forecasting\Public\Services\ForecastQuery::forUser
    - Modules\Ledger\Public\ValueObjects\Money::ofMinor
  provides:
    - GoalProgressRow DTO (extends Spatie Data, projectionBeyondHorizon field)
    - GoalProgressQuery::forUser(User): list<GoalProgressRow>
    - GoalProgressQuery::archivedForUser(User): list<GoalProgressRow>
    - GoalProjectionService::project(Goal, int, User): array{date, beyondHorizon}
    - GoalProgressQuery singleton registered in GoalsServiceProvider
  affects:
    - Modules/Goals/Providers/GoalsServiceProvider.php
tech_stack:
  added: []
  patterns:
    - Raw DatabaseManager read-model (staticMethod.dynamicCall compliant — mirrors BudgetProgressQuery/UncategorizedTriageQuery)
    - Left-join accounts onto goals query for account_name (single query, no N+1)
    - Goal hydration via forceFill() to get immutable_date casts for GoalProjectionService
    - Run-rate / forecast-validation projection algorithm (GOAL-04)
    - TRAILING_WINDOW_DAYS=90 / HORIZON_LIMIT_DAYS=90 named constants (D-07 tunables)
    - FX boundary: ExchangeRateService::convertToBase per contribution row
    - fractionComplete as integer ratio (not Money float — T-02-06 / NoFloatMoney)
    - CarbonImmutable::today() for projection date arithmetic
key_files:
  created:
    - Modules/Goals/Public/Dto/GoalProgressRow.php
    - Modules/Goals/Public/Services/GoalProgressQuery.php
    - Modules/Goals/Public/Services/GoalProjectionService.php
  modified:
    - Modules/Goals/Providers/GoalsServiceProvider.php (added singleton binding)
decisions:
  - GoalProgressQuery uses raw DatabaseManager (not Eloquent) for goals loading to stay clean under phpstan-strict-rules staticMethod.dynamicCall — the whereIn call on an Eloquent builder triggers a Larastan level 10 error; DatabaseManager.whereIn on the query builder does not.
  - GoalProjectionService extracts account_id into a local variable after the null guard so PHPStan sees it as int (not int|null) and the cast.useless error is avoided.
  - TRAILING_WINDOW_DAYS = HORIZON_LIMIT_DAYS = 90 (D-07 — aligns the run-rate window with the maximum forecast horizon; both declared as named constants for easy adjustment).
  - archivedForUser() is owned and created in Plan 02 (not Plan 04) — it shares the same row-mapping machinery and Plan 04 only consumes it.
metrics:
  duration: ~35 minutes
  completed: 2026-06-08
  tasks_completed: 3
  files_created: 3
  files_modified: 1
---

# Phase 2 Plan 02: Goals Read-Model (GoalProgressQuery + GoalProjectionService) Summary

Goals analytical core built: FX-correct contribution tracking, progress/status derivation, and a run-rate + forecast-validated projected finish date with a beyond-90d confidence flag; GoalProgressQueryTest and GoalProjectionTest both GREEN.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | GoalProgressRow DTO + GoalProgressQuery (contribution sum + FX + progress + archivedForUser) | 6cf471e | 2 created |
| 2 | GoalProjectionService — run-rate + forecast validation + confidence flag (GOAL-04) | ec380ec | 1 created |
| 3 | Register GoalProgressQuery singleton + full module gate | f936157 | 3 modified |

## Verification Results

- `docker compose run --rm php ./vendor/bin/pest Modules/Goals/tests/Unit/GoalProgressQueryTest.php` — **PASS** (8 tests, GOAL-02, GOAL-03)
- `docker compose run --rm php ./vendor/bin/pest Modules/Goals/tests/Unit/GoalProjectionTest.php` — **PASS** (6 tests, GOAL-04)
- `docker compose run --rm php ./vendor/bin/pest Modules/Goals/tests/Unit/` — **PASS** (17 tests, 32 assertions)
- `docker compose run --rm php ./vendor/bin/phpstan analyse Modules/Goals/Public --memory-limit=512M` — **No errors** (level 10)
- `docker compose run --rm php ./vendor/bin/pint --test Modules/Goals/Public Modules/Goals/Providers` — **PASS** (4 files, no style issues)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] GoalProgressQuery::whereIn on Eloquent builder triggers staticMethod.dynamicCall at Larastan level 10**
- **Found during:** Task 3 (Larastan module gate)
- **Issue:** `Goal::query()->whereIn('status', ['active', 'completed'])` triggers PHPStan strict-rules `staticMethod.dynamicCall` error — calling `->whereIn()` (an instance method) on the result of a static `::query()` call is flagged at level 10.
- **Fix:** Replaced Eloquent-based goals loading with a raw `DatabaseManager` query (left-joining `accounts` for the account name in a single query). This matches the established project pattern used by `BudgetProgressQuery` and `UncategorizedTriageQuery`. Goal model objects are hydrated via `forceFill()` to preserve the `immutable_date` casts needed by `GoalProjectionService`.
- **Files modified:** `Modules/Goals/Public/Services/GoalProgressQuery.php`
- **Commit:** f936157

**2. [Rule 1 - Bug] GoalProjectionService::project cast.useless at Larastan level 10**
- **Found during:** Task 3 (Larastan module gate)
- **Issue:** `(int) $goal->account_id` on line 115 — PHPStan sees the `@property int|null $account_id` PHPDoc and, after the null guard, knows `$account_id` is `int`; the explicit `(int)` cast is flagged as `cast.useless`.
- **Fix:** Extracted `$accountId = $goal->account_id;` immediately after the null guard so PHPStan narrows the type to `int` for all subsequent uses, eliminating the need for a cast.
- **Files modified:** `Modules/Goals/Public/Services/GoalProjectionService.php`
- **Commit:** f936157

**3. [Rule 1 - Bug] Pint braces_position style violation in GoalProjectionService**
- **Found during:** Task 3 (Pint module gate)
- **Issue:** Minor brace-placement style issue introduced during implementation.
- **Fix:** `docker compose run --rm php ./vendor/bin/pint Modules/Goals/Public` auto-fixed it.
- **Files modified:** `Modules/Goals/Public/Services/GoalProjectionService.php`
- **Commit:** f936157

## Known Stubs

None — all analytical logic is fully implemented.

## Threat Surface Scan

No new network endpoints or auth paths introduced. The following mitigations from the plan's threat register are implemented:

| Threat ID | Mitigation in code |
|-----------|-------------------|
| T-02-04 | Every raw `transactions` read carries `->where('user_id', $user->id)`; goals are loaded with `->where('goals.user_id', $user->id)` |
| T-02-05 | `NotFoundHttpException` from `ForecastQuery::forUser` is caught and handled defensively in `GoalProjectionService::project` |
| T-02-06 | `fractionComplete` is `contributedMinor / targetMinor` (int ratio); no `->float()` call anywhere; Larastan + grep gate passes |
| T-02-07 | ForecastQuery only called with horizons in {30,60,90}; beyond-90d extrapolation is local arithmetic |

## Self-Check

**Created files exist:**
- [x] Modules/Goals/Public/Dto/GoalProgressRow.php
- [x] Modules/Goals/Public/Services/GoalProgressQuery.php
- [x] Modules/Goals/Public/Services/GoalProjectionService.php

**Modified files updated:**
- [x] Modules/Goals/Providers/GoalsServiceProvider.php — contains `singleton(GoalProgressQuery::class)`

**Acceptance criteria grep checks:**
- [x] GoalProgressRow.php contains `extends Data`
- [x] GoalProgressRow.php contains `projectionBeyondHorizon`
- [x] GoalProgressQuery.php contains `whereIn('type', ['transfer_in', 'income'])`
- [x] GoalProgressQuery.php contains `convertToBase(`
- [x] GoalProgressQuery.php contains `function archivedForUser(`
- [x] GoalProgressQuery.php contains no `->float(`
- [x] GoalProjectionService.php contains `TRAILING_WINDOW_DAYS`
- [x] GoalProjectionService.php contains `HORIZON_LIMIT_DAYS`
- [x] GoalProjectionService.php does not use a horizon other than {30,60,90}
- [x] GoalProjectionService.php does not read `pointMinor` as goal progress

**Commits exist:**
- [x] 6cf471e feat(02-02): add GoalProgressRow DTO and GoalProgressQuery read model
- [x] ec380ec feat(02-02): add GoalProjectionService — run-rate + forecast validation
- [x] f936157 chore(02-02): register GoalProgressQuery singleton + Larastan/Pint module gate

## Self-Check: PASSED
