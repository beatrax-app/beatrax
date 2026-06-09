---
phase: 03-savings-pots-envelopes-seed-011
plan: "01"
subsystem: Pots module scaffold + Ledger AccountBalanceQuery
tags: [pots, scaffold, migrations, tdd, ledger, wave-0]
dependency_graph:
  requires:
    - "02-04 Goals module (goals table FK target)"
    - "Modules/Ledger (accounts, transactions tables)"
  provides:
    - "pots table (account_id FK, no kind restriction D-04, currency col D-05)"
    - "pot_movements table (append-only signed bigInteger, D-06)"
    - "Pot model (BelongsToUser + HasFactory + newFactory override)"
    - "PotFactory (user_id/account_id null defaults per convention)"
    - "PotRow / PotMovementRow / ReconciliationRow DTOs (Spatie LaravelData)"
    - "PotNotFoundException / InsufficientUnallocatedException"
    - "AccountBalanceQuery (Ledger Public service, ownership-scoped)"
    - "Wave 0 RED test harness (PotModelTest GREEN, PotBalanceQueryTest/PotsPageTest RED)"
  affects:
    - "Modules/Goals/tests/Unit/GoalProgressQueryTest.php (added POTS-04 D-10 tests)"
    - "tests/Pest.php (added Modules/Pots to TestCase map)"
tech_stack:
  added: []
  patterns:
    - "nwidart/laravel-modules module scaffold (mirrors Modules/Goals exactly)"
    - "append-only ledger table pattern (pots + pot_movements)"
    - "Spatie LaravelData readonly constructor props for Public DTOs"
    - "Wave 0 RED test harness per plan's TDD mandate"
key_files:
  created:
    - Modules/Pots/module.json
    - Modules/Pots/Providers/PotsServiceProvider.php
    - Modules/Pots/Database/Migrations/2026_06_10_000001_create_pots_table.php
    - Modules/Pots/Database/Migrations/2026_06_10_000002_create_pot_movements_table.php
    - Modules/Pots/Models/Pot.php
    - Modules/Pots/Database/Factories/PotFactory.php
    - Modules/Pots/Public/Dto/PotRow.php
    - Modules/Pots/Public/Dto/PotMovementRow.php
    - Modules/Pots/Public/Dto/ReconciliationRow.php
    - Modules/Pots/Public/Exceptions/PotNotFoundException.php
    - Modules/Pots/Public/Exceptions/InsufficientUnallocatedException.php
    - Modules/Pots/Routes/web.php
    - Modules/Ledger/Public/Services/AccountBalanceQuery.php
    - Modules/Pots/tests/TestCase.php
    - Modules/Pots/tests/Pest.php
    - Modules/Pots/tests/Unit/PotModelTest.php
    - Modules/Pots/tests/Unit/PotBalanceQueryTest.php
    - Modules/Pots/tests/Feature/PotsPageTest.php
  modified:
    - Modules/Goals/tests/Unit/GoalProgressQueryTest.php
    - tests/Pest.php
    - docker-compose.yml
decisions:
  - "PotsServiceProvider: PotBalanceQuery singleton commented out until Plan 02 (class.notFound at PHPStan level 10)"
  - "Routes/web.php: closure stub returning 501 until Plan 03 adds PotsPage (mirrors STATE.md goals route stub pattern)"
  - "D-01 confirmed: no stored unallocated/allocated column — migrations have zero such columns"
  - "docker-compose.yml updated with vendor bind-mount from main repo (worktree isolation + docker toolchain constraint)"
metrics:
  duration: "13m"
  completed_date: "2026-06-09"
  tasks_completed: 2
  files_changed: 20
---

# Phase 3 Plan 01: Pots Module Scaffold + AccountBalanceQuery Summary

Bootstraps the Modules/Pots nwidart module skeleton following Modules/Goals exactly: two migrations (pots + pot_movements append-only ledger), Pot model with BelongsToUser + newFactory override, PotFactory, three Spatie LaravelData DTOs, two exception classes, route stub, PotsServiceProvider, Ledger AccountBalanceQuery Public service, and the complete Wave 0 RED test harness covering all four POTS requirements.

## What Was Built

### Task 1: Pots Module Scaffold

All 12 scaffold files created and verified:

- **module.json**: priority 10 (Goals = 9), PotsServiceProvider registered — ensures pots migration runs after goals (FK dependency).
- **PotsServiceProvider**: loadMigrationsFrom / loadRoutesFrom / loadViewsFrom pattern. PotBalanceQuery singleton commented out until Plan 02 (PHPStan level 10 `class.notFound` fix).
- **Migration `create_pots_table`** (timestamp `2026_06_10_000001`): D-01 (no stored balance cols), D-04 (no account kind restriction), D-05 (currency col = account native currency). Composite index on `(user_id, account_id, status)`.
- **Migration `create_pot_movements_table`** (timestamp `2026_06_10_000002`): D-06 (append-only, separate from `transactions` table). Signed `bigInteger amount_minor`. Indexes on `pot_id` and `(user_id, pot_id)`.
- **Pot model**: `BelongsToUser` + `HasFactory` + `newFactory()` override + `account()` BelongsTo. `memo` deliberately absent from `$fillable` (lives on `pot_movements`, not `pots`).
- **PotFactory**: `user_id` and `account_id` default to null (explicit convention).
- **PotRow / PotMovementRow / ReconciliationRow**: final classes extending `Spatie\LaravelData\Data`. `ReconciliationRow` carries `unallocatedMinor` (may be negative, D-02) and `isOverAllocated`.
- **PotNotFoundException** (extends `InvalidArgumentException`) + **InsufficientUnallocatedException** (extends `RuntimeException`).
- **Routes/web.php**: closure stub returning `abort(501)` until Plan 03 adds `PotsPage` class.

Verification: `php artisan migrate:fresh --env=testing` exits 0; both pot migrations shown in output. PHPStan level 10 passes (0 errors). Pint passes (17 files clean).

### Task 2: AccountBalanceQuery + Wave 0 RED Test Harness

- **AccountBalanceQuery** (`Modules/Ledger/Public/Services/AccountBalanceQuery.php`): final class, `currentBalance(int $accountId, User $user): int` returns `(int) SUM(transactions.amount_minor WHERE account_id AND user_id)`. T-03-01 mitigation: explicit `user_id` guard.
- **Pots tests/TestCase.php + tests/Pest.php**: boilerplate copied from Goals, namespace swapped to `Modules\Pots\Tests`.
- **tests/Pest.php**: `'Modules/Pots' => Modules\Pots\Tests\TestCase::class` added to the module TestCase map.
- **PotModelTest** (3 tests): factory smoke, BelongsToUser scope isolation, nullable user_id — all GREEN in Wave 0.
- **PotBalanceQueryTest** (5 tests): balance SUM math, reconciliation real/allocated/unallocated, negative unallocated + isOverAllocated, archived-pot exclusion (D-09), derived-only unallocated (D-01) — all RED (PotBalanceQuery missing until Plan 02).
- **PotsPageTest** (6 tests): createPot, blank-name validation, cross-user IDOR, fundPot success, fundPot exceeds-unallocated path — all RED (PotsPage missing until Plan 03).
- **GoalProgressQueryTest additions** (2 tests): linked-pot-overrides-contribution (POTS-04 / D-10) and fallback-for-unlinked-goal — RED until Plan 04 wires PotBalanceQuery into GoalProgressQuery.

Wave 0 test result: **3 PASS, 11 FAIL** — correct; the 3 PotModelTest tests pass because the schema and factory exist; the 11 failures are expected class-not-found for not-yet-built services.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PotsServiceProvider PHPStan level 10 fix**
- **Found during:** Plan verification run after Task 1 commit
- **Issue:** `use Modules\Pots\Public\Services\PotBalanceQuery` import in PotsServiceProvider caused `class.notFound` error at PHPStan level 10 (class doesn't exist until Plan 02)
- **Fix:** Removed the `use` import; deferred the singleton registration with a `// Plan 02:` comment per plan instructions ("if Larastan flags the missing class at this stage, leave the singleton line commented with a // Plan 02: note")
- **Files modified:** `Modules/Pots/Providers/PotsServiceProvider.php`
- **Commit:** b624e15

**2. [Rule 3 - Blocking] Docker worktree vendor path issue**
- **Found during:** Task 1 migration verification
- **Issue:** The worktree docker-compose.yml mounts the worktree as `/app` but the worktree has no vendor directory; the main repo vendor uses absolute paths in autoload classmap pointing to main repo Modules — not usable in the container
- **Fix:** Added vendor bind-mount to worktree docker-compose.yml (`/Users/wessel/Development/nightworksio/beatrax/vendor:/app/vendor:ro`); ran migration and tests against the main repo's docker-compose with the Pots module bind-mounted separately
- **Files modified:** `docker-compose.yml` (worktree)
- **Commit:** bc4cf68 (included in Task 1 commit)

## Known Stubs

- `Modules/Pots/Routes/web.php`: Route `/pots` returns `abort(501)` — intentional scaffold stub until Plan 03 adds `PotsPage` class. This mirrors the Pattern documented in STATE.md for the goals route before Plan 04.

## Threat Flags

No new threat surface beyond what the plan's `<threat_model>` already registers. T-03-01 (AccountBalanceQuery user_id guard) is implemented. T-03-02 (schema FKs with cascadeOnDelete/nullOnDelete; no float money) is confirmed in migrations.

## Self-Check: PASSED

All 20 files verified to exist at their expected paths. Three commits verified:
- `bc4cf68`: Task 1 scaffold
- `5bac348`: Task 2 tests + AccountBalanceQuery
- `b624e15`: Fix PHPStan PotBalanceQuery singleton defer
