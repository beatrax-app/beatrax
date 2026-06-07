---
phase: 02-savings-goals-seed-003
plan: "01"
subsystem: Goals
tags: [scaffold, module, migration, tests, wave-0]
dependency_graph:
  requires: []
  provides:
    - goals table (nullable user_id, nullable account_id, BIGINT target_minor, status)
    - Goal Eloquent model with BelongsToUser scope + GoalFactory
    - GoalsServiceProvider registered in bootstrap/providers.php
    - Goals test namespace in composer.json
    - Wave 0 RED test stubs for GOAL-01..05 and cross-cutting cases
  affects:
    - bootstrap/providers.php
    - composer.json
    - tests/Pest.php
tech_stack:
  added: []
  patterns:
    - Module skeleton mirroring Modules/Budgets structure verbatim
    - Anonymous-class migration with DatabaseManager schema/db() private helpers
    - HasFactory with newFactory() override pointing to module-local GoalFactory
    - Wave 0 RED stubs pattern (stubs reference Plan 02/03/04 classes; RED until those land)
key_files:
  created:
    - Modules/Goals/module.json
    - Modules/Goals/Models/Goal.php
    - Modules/Goals/Database/Migrations/2026_06_08_000001_create_goals_table.php
    - Modules/Goals/Database/Factories/GoalFactory.php
    - Modules/Goals/Providers/GoalsServiceProvider.php
    - Modules/Goals/Routes/web.php
    - Modules/Goals/tests/TestCase.php
    - Modules/Goals/tests/Pest.php
    - Modules/Goals/tests/Unit/GoalModelTest.php
    - Modules/Goals/tests/Unit/GoalProgressQueryTest.php
    - Modules/Goals/tests/Unit/GoalProjectionTest.php
    - Modules/Goals/tests/Feature/GoalsPageTest.php
  modified:
    - bootstrap/providers.php (added GoalsServiceProvider use + array entry)
    - composer.json (added Modules\Goals\Tests\ autoload-dev PSR-4 entry)
    - tests/Pest.php (added Modules/Goals => Modules\Goals\Tests\TestCase::class)
decisions:
  - goals route registered as a closure stub (returns 501) instead of GoalsPage::class to avoid
    UnexpectedValueException on package:discover; Plan 04 will swap the closure for the real component.
  - newFactory() override added to Goal model to bypass Laravel's default resolver which derives
    Database\Factories\...\GoalFactory (wrong path for module-local factories).
  - GoalsServiceProvider register() left intentionally empty; GoalProgressQuery singleton added
    in Plan 02 to avoid referencing a class that does not yet exist.
  - Goals test namespace added to root tests/Pest.php (per-module Pest.php is inert by convention).
metrics:
  duration: ~25 minutes
  completed: 2026-06-08
  tasks_completed: 3
  files_created: 12
  files_modified: 3
---

# Phase 2 Plan 01: Goals Module Scaffold Summary

Goals module skeleton created as a verbatim structural copy of Modules/Budgets, with the goals table migrated, provider registered, test namespace wired, and Wave 0 RED test stubs ready for Plans 02–04.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Module skeleton: module.json, Goal model, migration, factory, provider, route | 94bd4b4 | 6 created |
| 2 | Register provider + test namespace; dump-autoload; migrate | 17748d4, 96a4908 | 2 modified |
| 3 | Wave 0 test harness + RED stubs (GOAL-01..05) | f952c2e | 8 created/modified |

## Verification Results

- `docker compose run --rm php ./vendor/bin/pest --filter=GoalModelTest` — **PASS** (3 tests, 9 assertions)
- `docker compose run --rm php php artisan migrate:status --database=sqlite` — `create_goals_table [3] Ran`
- `docker compose run --rm php ./vendor/bin/phpstan analyse Modules/Goals` — **No errors** (level 10)
- `docker compose run --rm php ./vendor/bin/pint --test Modules/Goals` — **PASS** (11 files, no style issues)
- GoalProgressQueryTest — **RED** as expected (GoalProgressQuery class not yet built, Plan 02)
- GoalProjectionTest — **RED** as expected (GoalProgressQuery class not yet built, Plan 02)
- GoalsPageTest — **RED** as expected (GoalsPage class not yet built, Plan 04)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] GoalFactory not found via Laravel's default resolver**
- **Found during:** Task 3 (GoalModelTest run)
- **Issue:** Laravel derives `Database\Factories\Modules\Goals\Models\GoalFactory` as the default factory name, but module factories live at `Modules\Goals\Database\Factories\GoalFactory`.
- **Fix:** Added `protected static function newFactory(): Factory` override to Goal model, returning `GoalFactory::new()` directly (same pattern as ForecastShortfallWindow).
- **Files modified:** `Modules/Goals/Models/Goal.php`
- **Commit:** f952c2e

**2. [Rule 3 - Blocking] Goals module missing from root tests/Pest.php module map**
- **Found during:** Task 3 (GoalModelTest not discovered)
- **Issue:** Per-module test suites are only discovered if the module path → TestCase class mapping is registered in `tests/Pest.php`; the per-module `Pest.php` is inert by convention.
- **Fix:** Added `'Modules/Goals' => Modules\Goals\Tests\TestCase::class` to the foreach loop in root `tests/Pest.php`.
- **Files modified:** `tests/Pest.php`
- **Commit:** f952c2e

**3. [Rule 3 - Blocking] Goals route file referenced GoalsPage::class which does not exist yet**
- **Found during:** Task 2 (composer dump-autoload post-autoload-dump hook crashed with UnexpectedValueException)
- **Issue:** `package:discover` runs as part of the Composer post-autoload-dump hook and tries to register the route, failing when GoalsPage::class is absent.
- **Fix:** Changed `GoalsPage::class` to a closure that `abort(501)` — route name `goals.index` is preserved; Plan 04 will replace the closure with the real component.
- **Files modified:** `Modules/Goals/Routes/web.php`
- **Commit:** 96a4908

## Known Stubs

- `Modules/Goals/Routes/web.php` — route handler is a `abort(501)` closure placeholder; GoalsPage::class is wired in Plan 04.
- `GoalsServiceProvider::register()` — intentionally empty; GoalProgressQuery singleton binding lands in Plan 02 when the class exists.

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes beyond what is documented in the plan's threat model (T-02-01 through T-02-SC). `goals.index` route is protected by `['web', 'auth']` middleware stub. No new threat flags.

## Self-Check

**Created files exist:**
- [x] Modules/Goals/module.json
- [x] Modules/Goals/Models/Goal.php
- [x] Modules/Goals/Database/Migrations/2026_06_08_000001_create_goals_table.php
- [x] Modules/Goals/Database/Factories/GoalFactory.php
- [x] Modules/Goals/Providers/GoalsServiceProvider.php
- [x] Modules/Goals/Routes/web.php
- [x] Modules/Goals/tests/TestCase.php
- [x] Modules/Goals/tests/Pest.php
- [x] Modules/Goals/tests/Unit/GoalModelTest.php
- [x] Modules/Goals/tests/Unit/GoalProgressQueryTest.php
- [x] Modules/Goals/tests/Unit/GoalProjectionTest.php
- [x] Modules/Goals/tests/Feature/GoalsPageTest.php

**Commits exist:**
- [x] 94bd4b4 feat(02-01): scaffold Goals module skeleton
- [x] 17748d4 chore(02-01): register GoalsServiceProvider and Goals test namespace
- [x] 96a4908 fix(02-01): stub goals route as closure to avoid class-not-found on boot
- [x] f952c2e test(02-01): Wave 0 test harness and RED stubs for Goals module

## Self-Check: PASSED
