---
phase: 03-savings-pots-envelopes-seed-011
plan: "02"
subsystem: Pots domain services — PotBalanceQuery + PotWriter
tags: [pots, domain-services, tdd, reconciliation, ledger, wave-2]
dependency_graph:
  requires:
    - "03-01 Pots module scaffold (pots + pot_movements tables, Pot model, DTOs, exceptions)"
    - "Modules/Ledger AccountBalanceQuery (real-balance reads)"
    - "Modules/Ledger PeriodQuery (current-period dates for categorySpentMinor)"
  provides:
    - "PotBalanceQuery Public service: forUser, archivedForUser, reconciliationForAccount, balanceForPot, currentUnallocatedForAccount, linkedPotBalancesForUser, currencyForLinkedPot"
    - "PotWriter Public service: save, update, fund, withdraw, transfer, archive, restore, parseAmount"
    - "PotsServiceProvider: PotBalanceQuery singleton registered (deferred from Plan 01)"
    - "Pots module: enabled via artisan module:enable"
  affects:
    - "Plan 03 (PotsPage Livewire component) consumes PotBalanceQuery + PotWriter"
    - "Plan 04 (Goals D-10 override) consumes linkedPotBalancesForUser + currencyForLinkedPot"
tech_stack:
  added: []
  patterns:
    - "Raw DatabaseManager read path (no Eloquent static calls in PotBalanceQuery — Larastan staticMethod.dynamicCall safe)"
    - "append-only movement ledger: balance = signed SUM(amount_minor)"
    - "DB::transaction wrapping check+insert inside fund/withdraw/transfer/archive (Pitfall 2 race condition guard)"
    - "withoutGlobalScope(UserScope)+where(user_id) for ownership-explicit writes (IDOR mitigation)"
    - "allocatedForAccount() private helper shared by reconciliationForAccount + currentUnallocatedForAccount"
key_files:
  created:
    - Modules/Pots/Public/Services/PotBalanceQuery.php
    - Modules/Pots/Public/Services/PotWriter.php
  modified:
    - Modules/Pots/Providers/PotsServiceProvider.php
decisions:
  - "PotBalanceQuery constructor takes PeriodQuery for categorySpentMinor (D-12) — adds one dep vs plan spec but avoids re-implementing period calculation"
  - "Pots module enable: added artisan module:enable call before running tests (module was disabled after Plan 01 scaffold)"
  - "accountCurrency() reads from DB after assertOwnedAccount (not from a passed currency param) so the currency is always the account's current default_currency (D-05)"
metrics:
  duration: "25m"
  completed_date: "2026-06-10"
  tasks_completed: 2
  files_changed: 3
---

# Phase 3 Plan 02: PotBalanceQuery + PotWriter Summary

Implements the two Pots domain services behind the clean Public API: `PotBalanceQuery` (read model computing balances, reconciliation, and the Goals D-10 override surface) and `PotWriter` (single write path enforcing all invariants atomically). Turns the Wave 0 RED tests for POTS-02 and POTS-03 GREEN.

## What Was Built

### Task 1: PotBalanceQuery (POTS-02 + POTS-03 GREEN)

`Modules/Pots/Public/Services/PotBalanceQuery.php` — final class, `declare(strict_types=1)`, no Eloquent static calls.

Constructor: `DatabaseManager $db` + `AccountBalanceQuery $accountBalance` + `PeriodQuery $periods`.

**Public API delivered:**

- `balanceForPot(int $potId): int` — signed SUM(amount_minor) from pot_movements (D-06). The ledger sum IS the balance.
- `reconciliationForAccount(int $accountId, User $user): ReconciliationRow` — real (from AccountBalanceQuery) + allocated (SUM of ACTIVE pot balances via `allocatedForAccount()` private helper) + unallocated = real − allocated (D-01 derived, never stored). `isOverAllocated = unallocated < 0` (D-02).
- `currentUnallocatedForAccount(int $accountId, User $user): int` — reuses same `allocatedForAccount()` helper so PotWriter can re-read this value inside transactions (D-03).
- `forUser(User $user): list<PotRow>` — active pots, left-joined to accounts/goals/categories, ordered account name → pot name. Each PotRow carries `balanceMinor` (via balanceForPot), `recentMovements` (latest 10 as PotMovementRow with counterpart name resolved), and `categorySpentMinor` (current-period SUM(-settled_amount_minor) for category-linked pots, D-12).
- `archivedForUser(User $user): list<PotRow>` — same shape, status='archived'.
- `linkedPotBalancesForUser(User $user): array<int, int>` — single-pass batch load of goal_id→balanceMinor for all active goal-linked pots (D-10, avoids N+1).
- `currencyForLinkedPot(int $goalId, User $user): ?string` — currency of the active pot linked to the goal (for FX in GoalProgressQuery Plan 04).

Every raw read carries `->where('user_id', $user->id)` (T-03-07).
Archived pots excluded from `allocatedForAccount()` (D-09).

**PotsServiceProvider updated:** PotBalanceQuery singleton registered (was deferred in Plan 01 comment).

**Wave 0 tests:** 5 PotBalanceQueryTest tests now GREEN (13 assertions, exit 0).
**PHPStan level 10:** 0 errors.
**Pint:** 18 files clean.

### Task 2: PotWriter (single write path)

`Modules/Pots/Public/Services/PotWriter.php` — final class, `declare(strict_types=1)`.

Constructor: `DatabaseManager $db` + `PotBalanceQuery $balance`.

**Public API delivered:**

- `parseAmount(string $value): ?int` — verbatim copy from GoalWriter (Dutch "1.234,56" + plain forms; rejects zero/negative; 12-digit cap). T-03-05.
- `save(User $user, ..., ?string $rawInitialAmount, ...): Pot` — validates blank name, asserts account ownership (T-03-03), assertXorLink (D-13), assertGoalOwnedAndFree (D-11 one-pot-per-goal), assertCategoryAccessible. Creates via `withoutGlobalScope()->create()` with currency from `accountCurrency()` (D-05). Optional initial funding validated against currentUnallocated INSIDE a transaction (D-08 / D-03).
- `update(User $user, int $potId, string $name, ?int $goalId, ?int $categoryId): Pot` — findOwnedActivePot (throws PotNotFoundException on null), blank-name guard, assertXorLink, assertGoalOwnedAndFree (only when goal_id changes), assertCategoryAccessible. Never touches account_id or status.
- `fund(User $user, int $potId, string $rawAmount, ?string $memo): void` — parseAmount, findOwnedActivePot (throw on null), DB::transaction: re-read currentUnallocatedForAccount INSIDE transaction (D-03 / T-03-04 / Pitfall 2), insert +amount 'fund' row.
- `withdraw(User $user, int $potId, string $rawAmount, ?string $memo): void` — parseAmount, findOwnedActivePot, DB::transaction: check balanceForPot, insert −amount 'withdraw' row. Throws InsufficientUnallocatedException when amount > balance.
- `transfer(User $user, int $fromPotId, int $toPotId, string $rawAmount, ?string $memo): void` — parseAmount, both pots resolved, same-account guard, self-transfer guard, DB::transaction: check source balance, insert transfer_out (−amount) + transfer_in (+amount) row pair (D-07 / Pattern 2).
- `archive(User $user, int $potId): void` — findOwnedActivePot (silent no-op on null), DB::transaction: insert final 'withdraw' −balance movement with memo 'Released on archive' if balance > 0 (D-09 / Pitfall 4), set status='archived'.
- `restore(User $user, int $potId): void` — resolve archived pot (withoutGlobalScope+user_id+status=archived), silent no-op on null. Sets status='active', no movements inserted (D-09 restores empty).

**PHPStan level 10:** 0 errors.
**Pint:** clean.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Pots module was disabled**
- **Found during:** Task 1 test run
- **Issue:** `artisan module:list` showed Pots as `[Disabled]` — nwidart/laravel-modules does not auto-enable modules; running `Pest Modules/Pots/tests/Unit/PotBalanceQueryTest.php` gave `no such table: pots` because migrations never ran
- **Fix:** `docker compose run --rm php php artisan module:enable Pots`
- **Files modified:** `modules_statuses.json` (module registry file managed by nwidart)
- **Commit:** part of Task 1 commit (59f6c8c)

**2. [Rule 2 - Missing critical functionality] PeriodQuery constructor dep added for categorySpentMinor**
- **Found during:** Task 1 implementation
- **Issue:** `categorySpentMinor` (D-12) requires knowing the user's current period start/end to compute current-month category spend — the same calculation `BudgetProgressQuery` uses via `PeriodQuery`. The plan's interface spec omits `PeriodQuery` from the constructor.
- **Fix:** Added `private readonly PeriodQuery $periods` to `PotBalanceQuery` constructor and invoked `$this->periods->current()` once per `loadPotRows()` call — one instance, not per-pot. Larastan and Pint both pass; the service container auto-wires it.
- **Files modified:** `Modules/Pots/Public/Services/PotBalanceQuery.php`

**3. [Rule 3 - Blocking] Docker vendor bind-mount (worktree isolation)**
- **Found during:** Task 1 verification
- **Issue:** Worktree has no vendor/ directory; added bind-mount to docker-compose.yml to use main repo's vendor
- **Fix:** Added bind-mount for test runs; reverted before final commit (per parallel execution instructions)
- **Files modified:** `docker-compose.yml` (reverted, not committed)

## Known Stubs

None — both services are fully wired. The PotsPage (Plan 03) route stub from Plan 01 (`Routes/web.php → abort(501)`) remains, but is out of scope for this plan.

## Threat Flags

No new threat surface beyond what the plan's `<threat_model>` already registers:
- T-03-03 (IDOR): `findOwnedActivePot` uses `withoutGlobalScope(UserScope)+where('user_id')` — implemented.
- T-03-04 (over-allocation replay): `currentUnallocatedForAccount` re-read INSIDE the fund transaction — implemented (line 190 of PotWriter).
- T-03-05 (negative-amount injection): `parseAmount` rejects zero/negative/garbage — implemented.
- T-03-06 (XOR bypass): `assertXorLink` throws when both goal_id and category_id non-null — implemented.
- T-03-07 (Information Disclosure): every PotBalanceQuery read carries `->where('user_id', $user->id)` — implemented.

## Self-Check: PASSED

Files verified to exist:
- `Modules/Pots/Public/Services/PotBalanceQuery.php` ✓
- `Modules/Pots/Public/Services/PotWriter.php` ✓
- `Modules/Pots/Providers/PotsServiceProvider.php` (modified) ✓

Commits verified:
- `59f6c8c`: feat(03-02): implement PotBalanceQuery read model (POTS-02 + POTS-03 GREEN)
- `41f24c8`: feat(03-02): implement PotWriter single write path (POTS-01 + POTS-02 writer)
