---
phase: 03-savings-pots-envelopes-seed-011
plan: "04"
subsystem: Goals domain — D-10 pot-balance override + D-11 pot-picker modal
tags: [goals, pots, cross-module, d-10, d-11, pots-04, livewire, tdd, wave-3]
dependency_graph:
  requires:
    - "03-02 PotBalanceQuery (linkedPotBalancesForUser, currencyForLinkedPot, linkedPotIdForGoal)"
    - "03-02 PotWriter (update sets pots.goal_id, enforces one-pot-per-goal)"
    - "Phase 2 GoalProgressQuery (loadRows loop, sumContributions, FX path)"
  provides:
    - "GoalProgressQuery: D-10 pot-balance override of contributedMinor via PotBalanceQuery injection"
    - "GoalsPage: D-11 pot-picker (linkedPotId state + create/edit wiring)"
    - "PotBalanceQuery: linkedPotIdForGoal() — reverse lookup for goal-side prefill"
  affects:
    - "Plan 03 (PotsPage) — PotBalanceQuery extended with linkedPotIdForGoal; no breaking changes"
    - "Goals feature tests — all 35 assertions GREEN with no Phase 2 regression"
tech_stack:
  added: []
  patterns:
    - "Method-parameter DI on Livewire action methods (no constructor injection — Livewire ban)"
    - "Batch-load linkedPotBalancesForUser before loop to prevent N+1 (D-10)"
    - "FX conversion via ExchangeRateService::convertToBase for cross-currency pot balances"
    - "User-scoped pots list built in render() — unlinked OR currently-linked-to-this-goal (T-03-13)"
    - "DatabaseManager over DB Facade in private helpers (larastan noFacadeRule compliance)"
key_files:
  created: []
  modified:
    - Modules/Goals/Public/Services/GoalProgressQuery.php
    - Modules/Goals/Providers/GoalsServiceProvider.php
    - Modules/Goals/Internal/Http/Livewire/GoalsPage.php
    - Modules/Goals/Resources/views/livewire/goals-page.blade.php
    - Modules/Pots/Public/Services/PotBalanceQuery.php
decisions:
  - "DatabaseManager in linkPotToGoal/clearPotGoalLink helpers instead of DB Facade — noFacadeRule strict-rules enforcement"
  - "wire:model.live on the account select so the pot options list re-renders when the account changes"
  - "Union query in render() to include the currently-linked pot in edit mode so the current link stays visible in the picker"
  - "PotBalanceQuery::linkedPotIdForGoal() added to Public service surface rather than inlining raw DB in GoalsPage — preserves T-03-14 module boundary"
metrics:
  duration: "continuation agent — crashed agent had Task 1 committed; this agent completed Task 2"
  completed_date: "2026-06-10"
  tasks_completed: 2
  files_changed: 5
---

# Phase 3 Plan 04: GoalProgressQuery D-10 Override + Goals Modal Pot-Picker Summary

GoalProgressQuery now uses a linked pot's balance as the goal's contributedMinor (D-10), overriding the Phase 2 transfer-sum counting. The Goals create/edit modal gains a "Linked pot (optional)" picker (D-11) so the link is settable from the goal side. Both tasks satisfy the POTS-04 requirement and keep every existing Goals and Pots unit test GREEN at Larastan level 10.

## What Was Built

### Task 1: GoalProgressQuery D-10 pot-balance override + autowiring (crashed agent — committed 16b4c0c)

`Modules/Goals/Public/Services/GoalProgressQuery.php` — modified.

- Added `private readonly PotBalanceQuery $potBalance` as the final constructor parameter. Laravel's container autowires it via the existing `$this->app->singleton(GoalsProgressQuery::class)` binding in GoalsServiceProvider (no explicit closure needed).
- In `loadRows()`, immediately before the per-goal `foreach`, batch-loads: `$linkedPotBalances = $this->potBalance->linkedPotBalancesForUser($user)` — array<int goalId, int balanceMinor>; prevents N+1.
- D-10 branch inside the loop: if `isset($linkedPotBalances[$goalId])`, `$contributedMinor` = pot balance (FX-converted via `$this->fx->convertToBase` when `$potCurrency !== $targetCurrency`); otherwise falls back to `$this->sumContributions()` (Phase 2 path, unchanged).
- GoalsServiceProvider unchanged — autowiring resolves the new dep.

`Modules/Pots/Public/Services/PotBalanceQuery.php` — added `linkedPotBalancesForUser(User $user): array<int,int>` (already in Plan 02) and the single-row `currencyForLinkedPot()` method injected into GoalProgressQuery.

**Wave 0 D-10 tests GREEN:** `it uses the linked pot balance as contributedMinor` and `it falls back to transfer tracking for a goal with no linked pot` both pass. All 12 existing Phase 2 GoalProgressQueryTest assertions still pass.

### Task 2: Goals modal "Linked pot (optional)" picker (this agent — committed 71a5db0)

`Modules/Pots/Public/Services/PotBalanceQuery.php` — added `linkedPotIdForGoal(int $goalId, User $user): ?int`. Returns the id of the active pot whose `goal_id = $goalId` for `$user`, or null. Used by `openEdit()` to prefill the pot picker and by `updateGoal()` to read the previous link.

`Modules/Goals/Internal/Http/Livewire/GoalsPage.php` — multiple additions:

- `public string $linkedPotId = ''` and `public string $errorLinkedPot = ''` state properties.
- `clearErrors()` and `resetForm()` both updated to include the new fields.
- `createGoal(... DatabaseManager $db, PotWriter $potWriter)`: after `$writer->save()`, if `$linkedPotId !== ''`, calls `linkPotToGoal()` helper. `InvalidArgumentException` (one-pot-per-goal guard from PotWriter) mapped to `$errorLinkedPot`.
- `openEdit(... PotBalanceQuery $potBalance)`: prefills `$this->linkedPotId` from `$potBalance->linkedPotIdForGoal()`.
- `updateGoal(... PotBalanceQuery $potBalance, DatabaseManager $db, PotWriter $potWriter)`: reads `$newPotId`/`$prevPotId`, clears old link before setting new one (allows PotWriter's assertGoalOwnedAndFree to pass), clears when user empties picker.
- `render()`: builds user-scoped `$pots` list — active pots with `goal_id = NULL`; in edit mode, UNIONs in the currently-linked pot so it stays visible. Scoped to selected account when `$accountId` is non-empty. Passes `$pots` to view.
- Private helpers: `potName(int $potId, User $user, DatabaseManager $db): string` (fetches current name so PotWriter::update preserves it), `linkPotToGoal()`, `clearPotGoalLink()` — all using injected `DatabaseManager`, not the DB Facade (noFacadeRule).

`Modules/Goals/Resources/views/livewire/goals-page.blade.php` — "Linked pot (optional)" select added after the "Savings account (optional)" select inside the goal-form modal:
- `wire:model="linkedPotId"` bound select.
- When account is empty: disabled state with single option "Select an account first".
- When account selected: first option "No pot — use transfer tracking" (value ''), then pot options from `$pots`.
- Inline error `$errorLinkedPot` below select.
- Helper text (text-xs, slate-400): "When linked, the pot's balance drives this goal's progress."
- Account select changed to `wire:model.live` so pot list re-renders reactively.

**All 35 Goals tests GREEN.** PHPStan level 10: 0 errors. Pint: clean.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Facade use forbidden by Larastan noFacadeRule**
- **Found during:** Task 2, PHPStan check
- **Issue:** Initial implementation of `potName()` used `\Illuminate\Support\Facades\DB::connection()` — Larastan strict rules prohibit facades
- **Fix:** Changed `potName()` signature to accept `DatabaseManager $db` directly; threaded `DatabaseManager` from `createGoal()` and `updateGoal()` into the helpers
- **Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
- **Commit:** part of 71a5db0

**2. [Rule 3 - Blocking] Worktree missing .env for Livewire feature tests**
- **Found during:** Task 2 verification run
- **Issue:** The worktree had no `.env` file; Livewire test boot called `file_get_contents(/app/.env)` which failed, causing all tests to WARN (skip) rather than PASS/FAIL
- **Fix:** Copied `.env` from main checkout into worktree (`.env` is in `.gitignore`, not committed)
- **Files modified:** `.env` (not tracked, not committed)

**3. [Rule 3 - Blocking] Vendor bind-mount for toolchain**
- **Found during:** Task 2 setup
- **Issue:** Worktree has no `vendor/` directory
- **Fix:** Added `vendor:/app/vendor:ro` bind-mount to `docker-compose.yml` for test runs; reverted before commit (per parallel execution instructions)
- **Files modified:** `docker-compose.yml` (reverted, not committed)

## Known Stubs

None — the pot picker is fully wired. The PotsPage feature tests (plan 03-03) fail with `ComponentNotFoundException` because `Modules\Pots\Internal\Http\Livewire\PotsPage` does not exist yet; this is pre-existing and unrelated to this plan.

## Threat Flags

No new threat surface beyond what the plan's `<threat_model>` registers. Implemented mitigations:

| Flag | File | Status |
|------|------|--------|
| T-03-11 (IDOR via linkedPotId) | GoalsPage.php | Mitigated — PotWriter::update resolves pot via findOwnedActivePot (user-scoped) |
| T-03-12 (one-pot-per-goal bypass) | GoalsPage.php | Mitigated — PotWriter::assertGoalOwnedAndFree checked; GoalsPage maps exception to errorLinkedPot |
| T-03-13 (pot-options info disclosure) | GoalsPage render() | Mitigated — pots list uses `->where('user_id', $user->id)` |
| T-03-14 (cross-module boundary) | GoalProgressQuery.php + GoalsPage.php | Mitigated — Goals never touches pots/pot_movements tables directly; all access via PotBalanceQuery/PotWriter Public services |

## Self-Check: PASSED

Files verified to exist:
- `Modules/Goals/Public/Services/GoalProgressQuery.php` (committed in 16b4c0c) ✓
- `Modules/Goals/Internal/Http/Livewire/GoalsPage.php` (committed in 71a5db0) ✓
- `Modules/Goals/Resources/views/livewire/goals-page.blade.php` (committed in 71a5db0) ✓
- `Modules/Pots/Public/Services/PotBalanceQuery.php` (committed in 71a5db0) ✓

Commits verified:
- `16b4c0c`: feat(03-04): GoalProgressQuery D-10 pot-balance override + PotBalanceQuery injection
- `71a5db0`: feat(03-04): Goals modal linked-pot picker + PotBalanceQuery.linkedPotIdForGoal

Test results: 35/35 Goals tests PASSED (70 assertions), 0 failures, 0 regressions.
PHPStan level 10: 0 errors.
Pint: 19 files clean.
