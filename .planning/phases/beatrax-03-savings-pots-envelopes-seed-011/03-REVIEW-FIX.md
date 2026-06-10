---
phase: beatrax-03-savings-pots-envelopes-seed-011
fixed_at: 2026-06-10T17:55:00Z
review_path: .planning/phases/beatrax-03-savings-pots-envelopes-seed-011/03-REVIEW.md
iteration: 1
findings_in_scope: 18
fixed: 18
skipped: 0
status: all_fixed
---

# Phase 3: Code Review Fix Report (Savings Pots / Envelopes)

**Fixed at:** 2026-06-10T17:55:00Z
**Source review:** .planning/phases/beatrax-03-savings-pots-envelopes-seed-011/03-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 18 (10 Warning, 8 Info — fix scope `all`)
- Fixed: 18
- Skipped: 0

**Verification (all gates green after fixes):**
- `vendor/bin/pest Modules/Pots Modules/Goals Modules/Ledger` — 218 passed, 1 skipped (pre-existing skip), 0 failed
- `vendor/bin/phpstan analyse -c phpstan-docker.neon --memory-limit=1G` — level 10, 0 errors
- `vendor/bin/pint --test` — PASS (1564 files)

## Fixed Issues

### WR-01: Pot row persists when initial funding fails

**Files modified:** `Modules/Pots/Public/Services/PotWriter.php`
**Commit:** 5fa0311
**Applied fix:** The optional initial amount is parsed BEFORE any write, and pot creation + the initial-funding check/insert now run in one DB transaction. A failed funding check (`InsufficientUnallocatedException`) or an invalid amount string rolls back the pot row — no orphan zero-balance pot, no duplicate on resubmit.

### WR-02: Goal persists when pot-link step fails

**Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** 0a66749
**Applied fix:** `createGoal()` wraps `GoalWriter::save()` and the D-11 pot link in a single `$db->connection()->transaction()`. A failed link (one-pot-per-goal violation, cross-user pot id) rolls the goal back; the catch blocks (most-specific first: `InvalidGoalAmountException`, then `\InvalidArgumentException`) keep the existing inline-error UX.

### WR-03: Goal-edit relink is non-atomic — previous pot link silently lost

**Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** 4acaf78
**Applied fix:** `updateGoal()` now runs the goal update, the clear of the previous pot link, and the new link inside one transaction. A throw from the relink rolls back both the cleared link and the goal update (also closing the residual non-atomicity the review noted). Catch order: `GoalNotFoundException` → `InvalidGoalAmountException` → `\InvalidArgumentException`.

### WR-04: restore() can violate one-pot-per-goal (D-11)

**Files modified:** `Modules/Pots/Public/Services/PotWriter.php`
**Commit:** 314100d
**Applied fix:** `restore()` re-checks the invariant: if the archived pot carries a `goal_id` that another ACTIVE pot now holds, the restored pot comes back unlinked (`goal_id = null`) instead of producing two active pots on one goal. Complemented by the IN-08 DB-level index.

### WR-05: Linking a category-linked pot to a goal silently destroys its category link

**Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** 63ae2fe
**Applied fix:** Both layers from the review's suggestion: the goal-modal pot picker now adds `->whereNull('category_id')` so category-linked pots are not offered, and `linkPotToGoal()` rejects a category-linked pot id (stale/tampered input) with an inline `InvalidArgumentException` ("This pot is linked to a category. Remove that link on the Pots page first.") instead of letting `PotWriter::update()` wipe the category link.

### WR-06: pots lookup for goal-picker exclusion missing user_id guard

**Files modified:** `Modules/Pots/Internal/Http/Livewire/PotsPage.php`
**Commit:** a930b36
**Applied fix:** The `editPotId`-keyed `pots` read in `render()` now carries `->where('user_id', $user->id)`, restoring the module's T-03-07 convention that every read on user-owned tables is explicitly user-scoped.

### WR-07: pot_movements reads carry no user_id guard; balanceForPot() unscoped public API

**Files modified:** `Modules/Pots/Public/Services/PotBalanceQuery.php`, `Modules/Pots/Public/Services/PotWriter.php`
**Commit:** 56d07fb
**Applied fix:** `balanceForPot()` now takes `User $user` and scopes by `user_id` (uses the existing `['user_id','pot_id']` index); the `allocatedForAccount()` sum and the movement-history fetch in `loadPotRows()` gained the same explicit guard. All five call sites (three in `PotWriter`, two internal) updated.

### WR-08: Category spend sums mixed currencies

**Files modified:** `Modules/Pots/Public/Services/PotBalanceQuery.php`
**Commit:** a8026a5
**Applied fix:** The D-12 category-spend sum is now filtered with `->where('settled_currency', <pot currency>)` so the figure the view labels with the pot's currency is honest for that unit (review's "minimum" option; FX-converting per-currency groups noted as a possible future upgrade).

### WR-09: transferTargetPotId (int) bound to a select with '' placeholder

**Files modified:** `Modules/Pots/Internal/Http/Livewire/PotsPage.php`
**Commit:** 6eeb389
**Applied fix:** Property changed to `public string $transferTargetPotId = ''` (matching the established `$accountId` pattern), cast with `(int)` in `movePot()` — `''` casts to `0`, which `PotWriter::transfer()` rejects via `PotNotFoundException` → inline error instead of a Livewire property-type 500. `resetOperationModal()` resets to `''`.

### WR-10: Stale linkedPotId survives account switch in the goal modal

**Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** ef9a234
**Applied fix:** Added the `updatedAccountId()` Livewire hook resetting `$this->linkedPotId = ''`, so a pot selected under account A can no longer be submitted after switching the goal to account B.

### IN-01: Dead code — $potCreatedAt computed and never used

**Files modified:** `Modules/Pots/Public/Services/PotBalanceQuery.php`
**Commit:** a31f73d
**Applied fix:** Deleted the dead parse/format block and the now-unneeded `pots.created_at` select column.

### IN-02: Stale/contradictory docblocks in GoalProgressQuery

**Files modified:** `Modules/Goals/Public/Services/GoalProgressQuery.php`
**Commit:** 1eaccfb
**Applied fix:** Class docblock now states contributions convert into the goal's immutable `target_currency` (D-05), and that `fractionComplete` is a float ratio of two integer minor amounts (a display fraction, not float money).

### IN-03: currencyForLinkedPot() re-queries per goal, defeating the batched D-10 load

**Files modified:** `Modules/Pots/Public/Services/PotBalanceQuery.php`, `Modules/Goals/Public/Services/GoalProgressQuery.php`
**Commit:** e464b2f
**Applied fix:** `linkedPotBalancesForUser()` now returns `goal_id => ['balance' => int, 'currency' => string]` (the source query already touched the pots row holding `currency`); `GoalProgressQuery` consumes both from the single batched load — the per-goal `currencyForLinkedPot()` call inside the loop is gone. `currencyForLinkedPot()` remains as a single-goal convenience lookup with an updated docblock.

### IN-04: Duplicate structures and duplicated query construction

**Files modified:** `Modules/Pots/Internal/Http/Livewire/PotsPage.php`, `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** ef392a5
**Applied fix:** (a) The byte-identical `$potsForMove` rebuild loop is removed — the view receives `$groups` for both variables. (b) `GoalsPage::render()` extracts a `$basePotsQuery` closure used by both the plain and the edit-mode union branch, so the base conditions (including WR-05's `whereNull('category_id')`) cannot drift.

### IN-05: Float division on minor units in edit-prefill

**Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** deb35ca
**Applied fix:** `number_format($row->targetMinor / 100, …)` replaced with `sprintf('%d.%02d', intdiv($row->targetMinor, 100), $row->targetMinor % 100)` — integer-only money formatting, exactly as the review suggested.

### IN-06: "Target amount ({{ $baseCurrency }})" label wrong when editing a diverged-currency goal

**Files modified:** `Modules/Goals/Resources/views/livewire/goals-page.blade.php`
**Commit:** 48adeda
**Applied fix:** When `$editGoalId !== 0`, the label resolves the edited goal's own `currency` from the rows already passed to the view; create mode keeps `$baseCurrency`.

### IN-07: AccountBalanceQuery sums amount_minor across currencies

**Files modified:** `Modules/Ledger/Public/Services/AccountBalanceQuery.php`
**Commit:** 2917b2a
**Applied fix:** Took the review's documentation option: an explicit SINGLE-CURRENCY ASSUMPTION docblock on `currentBalance()` explaining the mixed-unit behaviour, why it deliberately mirrors the `BalanceAnchorResolver` fallback (reconciliation header and net worth must agree), and that an FX-aware balance must change both call paths together. Filtering by `default_currency` was NOT applied to avoid the two figures diverging.

### IN-08: One-pot-per-goal (D-11) has no DB-level backstop

**Files modified:** `Modules/Pots/Database/Migrations/2026_06_10_000003_add_active_goal_unique_index_to_pots.php` (new)
**Commit:** bf0f308
**Applied fix:** New migration (rather than editing the already-shipped create migration, so existing local DBs upgrade cleanly) adding the partial unique index: `CREATE UNIQUE INDEX pots_active_goal_unique ON pots (goal_id) WHERE goal_id IS NOT NULL AND status = 'active'`. Supported by SQLite and PostgreSQL (the stated multi-user migration target). Archived pots keep `goal_id` freely; two ACTIVE pots on one goal are now impossible at the schema level.

## Skipped Issues

None — all 18 findings were fixed.

---

_Fixed: 2026-06-10T17:55:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
