---
phase: beatrax-02-savings-goals-seed-003
fixed_at: 2026-06-08T00:00:00Z
review_path: .planning/phases/beatrax-02-savings-goals-seed-003/02-REVIEW.md
iteration: 1
findings_in_scope: 12
fixed: 12
skipped: 0
status: all_fixed
---

# Phase 2 (Savings Goals): Code Review Fix Report

**Fixed at:** 2026-06-08T00:00:00Z
**Source review:** .planning/phases/beatrax-02-savings-goals-seed-003/02-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 12 (all critical, warning, and info — fix_scope=all)
- Fixed: 12
- Skipped: 0

**Verification:** Goals module test suite GREEN (31 passed, 61 assertions),
Larastan level 10 clean on all changed files, Pint clean on all changed files.
Two new tests added to cover previously-uncovered paths (CR-02 currency
divergence, WR-01 edit→save). All work performed in an isolated git worktree;
the user's `release/v1.3` branch is fast-forwarded to capture the commits.

## Fixed Issues

### CR-01: `hydrateGoal` writes the goal's `id` into `user_id`

**Files modified:** `Modules/Goals/Public/Services/GoalProgressQuery.php`
**Commit:** a632e90
**Applied fix:** Changed the force-filled `'user_id' => self::toInt($row->id)`
to `'user_id' => null`. The read path takes `$user` explicitly into the
projection, so the hydrated model never needs (and must not fabricate) an
identity. The migration makes `user_id` nullable, so `null` is the correct
"not loaded" sentinel and removes the data-integrity landmine.

### CR-02: Progress math mixes base currency with `target_currency`

**Files modified:** `Modules/Goals/Public/Services/GoalProgressQuery.php`,
`Modules/Goals/Public/Services/GoalProjectionService.php`
**Commit:** a632e90 (query), c4f5f03 (projection)
**Applied fix:** Both contribution sums now convert credits via
`$this->fx->convertToBase($money, $goal->target_currency)` instead of
`$user->base_currency`. The `ExchangeRateService::convertToBase()` second
parameter is a generic target-currency argument, so this lands the numerator in
the same unit as `target_minor` (the goal's immutable `target_currency`, D-05).
This makes `fractionComplete`, the "reached" flip, and the rendered
amount/currency all consistent for goals whose target currency diverges from the
user's current base currency, while preserving the EUR-only passthrough path.
A new test (`GoalProgressQueryTest`) asserts a EUR contribution against a USD
goal is converted to USD (110000 minor, fraction 1.0, reached) using a seeded
EUR→USD rate. **Requires human verification:** the currency-conversion direction
and rounding is correctness-sensitive; the added test covers the happy path but
the developer should confirm the FX semantics match intent for cross-rate goals.

### WR-01: Edit flow can never save — `targetDate` is blanked then required

**Files modified:** `Modules/Goals/Public/Dto/GoalProgressRow.php`,
`Modules/Goals/Public/Services/GoalProgressQuery.php`,
`Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** 5cdf24e (DTO), a632e90 (query), 9087d34 (page)
**Applied fix:** Added a `targetDate` field to `GoalProgressRow`; `loadRows`
populates it from the already-selected `goals.target_date` via a new
`toDateStr()` helper that normalises to `Y-m-d` for the date input regardless of
driver format. `openEdit` now sets `$this->targetDate = $row->targetDate` instead
of blanking it, so the edit modal prefills the stored date and a save succeeds
without re-entry. A new feature test exercises the full
`openEdit` → `updateGoal` path and asserts the save dispatches a toast with no
date error.

### WR-02: `save()` upsert silently overwrites a same-name same-day goal

**Files modified:** `Modules/Goals/Public/Services/GoalWriter.php`
**Commit:** 236f916
**Applied fix:** Replaced the `updateOrCreate(['user_id','name','start_date'], …)`
with an explicit `Goal::query()->withoutGlobalScope(UserScope::class)->create([…])`.
`save()` is the dedicated create path; the lifecycle methods own all subsequent
mutation, so a same-name same-day goal now creates a distinct row instead of
silently overwriting the original. The `withoutGlobalScope` + authoritative
`user_id` is preserved (T-02-12).

### WR-03: Dead/confusing comparator preamble in summary-card sort

**Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsSummaryCard.php`
**Commit:** b0f27e7
**Applied fix:** Deleted the two unused `$aDate`/`$bDate` assignments at the top
of the `usort` comparator. The null checks plus `strcmp` already implement the
intended "nulls sort last" ordering.

### WR-04: lifecycle methods ignore their `$user` argument

**Files modified:** `Modules/Goals/Public/Services/GoalWriter.php`
**Commit:** 236f916
**Applied fix:** Added a `findOwnedGoal(User $user, int $goalId)` helper that
resolves goals via `withoutGlobalScope(UserScope::class)->where('user_id',
$user->id)->find($goalId)`, and routed `update`, `markComplete`, `archive`, and
`restore` through it. Ownership now depends on the explicitly-passed `$user`
rather than the ambient guard state, closing the latent IDOR where an
unauthenticated `UserScope` applies no filter.

### WR-05: `updateGoal` distinguishes cross-user via brittle string match

**Files modified:** `Modules/Goals/Public/Services/GoalWriter.php`,
`Modules/Goals/Public/Exceptions/GoalNotFoundException.php` (new),
`Modules/Goals/Public/Exceptions/InvalidGoalAmountException.php` (new),
`Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** 236f916 (writer + exceptions), 9087d34 (page)
**Applied fix:** Introduced two dedicated exception types (both extend
`InvalidArgumentException` for backward compatibility). `GoalWriter` now throws
`GoalNotFoundException` for missing/cross-user goals and
`InvalidGoalAmountException` for bad amounts. `GoalsPage::updateGoal` and
`createGoal` now `catch` each type by identity instead of `str_contains($msg,
'not found')`, so re-wording or localising the writer message can no longer
misroute control flow.

### WR-06: Projection run-rate denominator fixed at 90 days

**Files modified:** `Modules/Goals/Public/Services/GoalProjectionService.php`
**Commit:** c4f5f03
**Applied fix:** Replaced the constant `/ self::TRAILING_WINDOW_DAYS` divisor
with the actual elapsed observation window
`max(1, CarbonImmutable::parse($effectiveStart)->diffInDays(today))`. A young
goal's run-rate now reflects its real recent pace rather than being systematically
understated by dividing by 90, fixing the over-stated projected finish date.
The existing projection tests remain GREEN. **Requires human verification:** the
projected-date arithmetic is the headline value of the feature; confirm the
elapsed-window rate produces sensible dates across the early-life and full-window
boundaries.

### IN-01: `GoalProgressRow` docblock contradicts the clamp it describes

**Files modified:** `Modules/Goals/Public/Dto/GoalProgressRow.php`
**Commit:** 5cdf24e
**Applied fix:** Reworded the docblock to state `fractionComplete` is a float
ratio, lower-bounded at 0.0, NOT upper-clamped (values above 1.0 flow through;
the view clamps the rendered bar to 100%). Also corrected the "user's base
currency" note to "the goal's immutable target_currency".

### IN-02: `target_minor` is a signed `bigInteger` with no DB-level positivity guard

**Files modified:**
`Modules/Goals/Database/Migrations/2026_06_08_000001_create_goals_table.php`
**Commit:** f7f9867
**Applied fix:** Added an inline comment documenting that positivity is a PHP-only
invariant enforced in `GoalWriter::parseAmount` (the sole write path), that a
DB-level CHECK is intentionally omitted (SQLite-first), and that any future
alternate write path must re-assert it. No schema change (per the review's "leave
as-is is defensible").

### IN-03: `confirmArchive`/`cancelArchive`/`render` lack `isAuthenticated` guard

**Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
**Commit:** 9087d34
**Applied fix:** Added `CurrentUser $currentUser` parameter + `isAuthenticated()`
guard to `confirmArchive` and `cancelArchive`, and a guard at the top of `render`
that returns an empty-state page (rather than throwing
`NotAuthenticatedException`) when unauthenticated. Defence-in-depth consistency
with every other action.

### IN-04: Summary-card empty-state branch passes `[]` but never reaches the page query

**Files modified:** _(none)_
**Commit:** _(no code change — documentation-only finding)_
**Applied fix:** The review explicitly states "no action required beyond noting
the pattern is uniformly defensive." The existing defensive `[]` branch in
`GoalsSummaryCard::render` is consistent with the page component's own redundant
guards (now reinforced by IN-03), so it is intentionally retained. Recorded as
addressed-by-acknowledgement.

## Skipped Issues

None — all 12 findings were addressed.

---

_Fixed: 2026-06-08T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
