---
phase: beatrax-02-savings-goals-seed-003
reviewed: 2026-06-08T00:00:00Z
depth: standard
files_reviewed: 19
files_reviewed_list:
  - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Goals/Database/Factories/GoalFactory.php
  - Modules/Goals/Database/Migrations/2026_06_08_000001_create_goals_table.php
  - Modules/Goals/Internal/Http/Livewire/GoalsPage.php
  - Modules/Goals/Internal/Http/Livewire/GoalsSummaryCard.php
  - Modules/Goals/Models/Goal.php
  - Modules/Goals/Providers/GoalsServiceProvider.php
  - Modules/Goals/Public/Dto/GoalProgressRow.php
  - Modules/Goals/Public/Services/GoalProgressQuery.php
  - Modules/Goals/Public/Services/GoalProjectionService.php
  - Modules/Goals/Public/Services/GoalWriter.php
  - Modules/Goals/Resources/views/livewire/goals-page.blade.php
  - Modules/Goals/Resources/views/livewire/goals-summary-card.blade.php
  - Modules/Goals/Routes/web.php
  - Modules/Goals/module.json
  - bootstrap/providers.php
  - composer.json
  - resources/css/app.css
findings:
  critical: 2
  warning: 6
  info: 4
  total: 12
status: issues_found
---

# Phase 2 (Savings Goals): Code Review Report

**Reviewed:** 2026-06-08T00:00:00Z
**Depth:** standard
**Files Reviewed:** 19
**Status:** issues_found

## Summary

The Goals module is well-structured and the user-scoping surface is mostly sound:
`GoalProgressQuery` and `GoalProjectionService` carry explicit `->where('user_id', $user->id)`
guards on every raw read; `GoalWriter` validates `account_id` ownership before persisting
and resolves goals through the `BelongsToUser` global scope; `CurrentUser` is used everywhere
instead of `auth()`/`session()`; money flows through `brick/money` via the Ledger `Money` VO
and `ExchangeRateService`. The Flux-modal CSS override is correctly element-scoped and does not
over-reach to flyouts.

However, two correctness defects undermine the feature: a wrong-field hydration bug that writes
the goal's own id into `user_id`, and a base-currency/target-currency mismatch in the progress
math that silently corrupts displayed progress for any goal whose `target_currency` differs from
the user's current `base_currency` (exactly the multi-currency case the project mandates).
The edit flow is also broken: `openEdit` always blanks `targetDate`, and `updateGoal` then
refuses to save because the date is empty.

## Critical Issues

### CR-01: `hydrateGoal` writes the goal's `id` into `user_id`

**File:** `Modules/Goals/Public/Services/GoalProgressQuery.php:155`
**Issue:** The hydrated `Goal` model is force-filled with
`'user_id' => self::toInt($row->id)` — the row's primary key, not the user's id. The inline
comment ("placeholder; not used by projection") asserts it is harmless because
`GoalProjectionService` takes `$user` separately. That is true *today*, but this is a
data-integrity landmine: any future reader of `$goal->user_id` on a hydrated row (a new
projection rule, a logging line, an event payload) silently gets the goal id and can leak or
mis-scope another user's data. A hydration helper that deliberately stuffs a wrong identity
into a security-relevant field is a defect, not a placeholder. The migration even makes
`user_id` nullable, so the correct "not loaded" value is `null`.
**Fix:**
```php
// Either select goals.user_id in loadRows() and pass it through, or set null:
'user_id' => null, // not loaded on the read path; projection takes $user explicitly
```

### CR-02: Progress math mixes base currency with `target_currency`

**File:** `Modules/Goals/Public/Services/GoalProgressQuery.php:114, 132, 189-191`
**Issue:** `sumContributions()` converts every credit via
`$this->fx->convertToBase($money, $user->base_currency)` — i.e. it produces a sum in the user's
*current* `base_currency`. But `targetMinor` is read straight from `goals.target_minor` (stored
in `target_currency`), and the row's `currency` is set to `target_currency` (line 132). The
migration/D-05 explicitly make `target_currency` immutable so it can diverge from `base_currency`
when the user later changes their default. When they diverge:
- `fractionComplete = contributedMinor / targetMinor` (line 114) divides a base-currency
  numerator by a target-currency denominator — a meaningless ratio.
- The view (`goals-page.blade.php:111`) formats `contributedMinor` *as* `$row->currency`
  (= `target_currency`), so a EUR-converted figure is rendered with a USD label — wrong amount,
  wrong currency symbol.
- `progressState` "reached" (line 117) compares the two mismatched figures, so a goal can flip
  to "reached" early or never.

This is the exact multi-currency correctness failure the project constraints call out
("Multi-currency tracking required from v1 … preserving both currencies prevents losing FX
information"). For a single-currency (EUR-only) user it happens to work because
`convertToBase` passes through, which likely masked it in tests.
**Fix:** Convert contributions to the goal's `target_currency`, not the user's `base_currency`,
so numerator and denominator share a unit:
```php
$result = $this->fx->convertToBase($money, $goal->target_currency);
```
Apply the same change in `GoalProjectionService::project()` (line 90), which has the identical
`convertToBase($money, $user->base_currency)` against a `target_currency` denominator
(`$goal->target_minor`).

## Warnings

### WR-01: Edit flow can never save — `targetDate` is blanked then required

**File:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php:132, 160-163`
**Issue:** `openEdit()` hard-sets `$this->targetDate = ''` with the comment "no target_date on
GoalProgressRow; will be left blank for now". `updateGoal()` then early-returns with
`errorDate = 'Choose a target date.'` whenever `trim($this->targetDate) === ''`. Result: opening
the edit modal and pressing Save always fails the date validation unless the user manually
re-enters a date they cannot see. The edit affordance is effectively non-functional for the
common "rename / change amount" case. `GoalProgressRow` has no `target_date` field to prefill it,
so the root cause is a missing DTO field.
**Fix:** Add `targetDate` to `GoalProgressRow` (select `goals.target_date` in `loadRows`), then
in `openEdit` set `$this->targetDate = $row->targetDate;`.

### WR-02: `save()` upsert silently overwrites a same-name same-day goal

**File:** `Modules/Goals/Public/Services/GoalWriter.php:66-79`
**Issue:** `updateOrCreate` is keyed on `['user_id', 'name', 'start_date']`. Creating a second
goal with the same name on the same day (e.g. two "Holiday" goals, or a different
currency/account) silently *updates* the first goal instead of creating a new row — target
amount, account link, and date of the original are overwritten with no warning. The migration
has no unique constraint enforcing this, so the upsert key is an undocumented behavioural
constraint that contradicts the "create a new goal" contract in the method's own docblock.
**Fix:** Use `Goal::create([...])` for `save()` (the dedicated lifecycle methods already own
mutation), or include a discriminator that genuinely makes the create unique. If de-dup is truly
intended, document it and surface a "goal already exists" message instead of a silent overwrite.

### WR-03: Dead/confusing comparator preamble in summary-card sort

**File:** `Modules/Goals/Internal/Http/Livewire/GoalsSummaryCard.php:42-43`
**Issue:** `$aDate` and `$bDate` are computed at the top of the `usort` comparator and then never
used — every branch below returns based on `$a->projectedFinishDate` / `$b->projectedFinishDate`
directly. The two lines are dead code that also misleadingly imply a null-coalescing fallback
that does not happen. Harmless at runtime but a maintenance trap (and Larastan strict may flag the
unused assignment).
**Fix:** Delete lines 42-43; the null checks plus `strcmp` already implement the intended
"nulls sort last" ordering.

### WR-04: `markComplete`/`archive`/`restore`/`update` ignore their `$user` argument

**File:** `Modules/Goals/Public/Services/GoalWriter.php:96-171`
**Issue:** All four methods accept `User $user` but never use it for goal resolution — they rely
entirely on the ambient `BelongsToUser` global scope (which reads `CurrentUser`, not the passed
`$user`). The signature therefore advertises an ownership guarantee the body does not enforce: if
any caller ever invokes these with a `$user` that is not the authenticated `CurrentUser` (a queued
job, a future admin path, a test), the call silently operates on whichever user the guard resolves
— or, in an unauthenticated context, `UserScope` applies no filter at all (see `UserScope::apply`
catching `NotAuthenticatedException`), so `Goal::find($goalId)` would resolve *any* user's goal.
Today every call site is an authenticated Livewire action, so it is not exploitable, but the
mismatch between the parameter and the actual scope source is a latent IDOR.
**Fix:** Either drop the unused `$user` parameter (and document the global-scope reliance), or
explicitly re-assert `->where('user_id', $user->id)` (via `withoutGlobalScope(UserScope::class)`)
so ownership does not depend on guard state. The latter is the safer, intention-revealing choice.

### WR-05: `updateGoal` distinguishes cross-user via brittle string match

**File:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php:177-188`
**Issue:** The catch block decides "cross-user attempt" vs "invalid amount" by
`str_contains($msg, 'not found')` against `InvalidArgumentException::getMessage()`. This couples
UI control flow to an exact substring of a writer message; changing the writer copy (or
localising it) silently reroutes a "not found" into the "invalid amount" branch and shows the
wrong inline error. Exception *type/identity* should drive control flow, not message text.
**Fix:** Throw distinct exception types from `GoalWriter` (e.g. `GoalNotFoundException` vs
`InvalidGoalAmountException`) and `catch` each, or have the writer no-op silently on not-found
(as the lifecycle methods already do) so the page never needs to sniff the message.

### WR-06: Projection run-rate denominator is fixed at 90 days even early in a goal's life

**File:** `Modules/Goals/Public/Services/GoalProjectionService.php:74-95`
**Issue:** `$dailyRateMinor = $windowSum / TRAILING_WINDOW_DAYS` always divides by 90, but
`$effectiveStart` is clamped to `max(goal.start_date, today-90d)` (line 76). A goal created 5
days ago with one 500-unit contribution yields a daily rate of `500/90 ≈ 5.5/day`, projecting a
finish hundreds of days out even though the real recent pace is `500/5 = 100/day`. The fixed
denominator systematically understates the rate (and overstates the finish date) for any goal
younger than the window. Not a crash, but the projected date — the headline value of this
feature — is materially wrong for new goals.
**Fix:** Divide by the actual elapsed observation window:
`max(1, daysBetween($effectiveStart, today))`, not the constant 90.

## Info

### IN-01: `GoalProgressRow` docblock contradicts the clamp it describes

**File:** `Modules/Goals/Public/Dto/GoalProgressRow.php:14, 113-114 (query)`
**Issue:** The DTO docblock says `fractionComplete` is "clamped to 0.0 when targetMinor <= 0",
but the producer (`GoalProgressQuery::loadRows` line 114) does not clamp the upper bound — values
above 1.0 flow through (the view clamps to 100% for display). The comment also says "int ratio,
not money" while the field is a `float`. Cosmetic, but the documented contract and the code differ.
**Fix:** Align the docblock with the actual behaviour (float ratio, lower-bounded at 0.0, may
exceed 1.0).

### IN-02: `target_minor` is a signed `bigInteger` with no DB-level positivity guard

**File:** `Modules/Goals/Database/Migrations/2026_06_08_000001_create_goals_table.php:51`
**Issue:** The column is documented as "always positive (a target amount, not a signed flow)" but
is a plain signed `bigInteger`. Positivity is enforced only in `GoalWriter::parseAmount`. Since
the writer is the sole write path this is acceptable, but the invariant lives entirely in PHP; a
future direct insert or seeder could store a non-positive target. Worth a note (a CHECK constraint
is overkill for SQLite-first, so leaving as-is is defensible).

### IN-03: `confirmArchive`/`cancelArchive`/`render` lack `isAuthenticated` guard

**File:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php:218-228, 272-302`
**Issue:** Every other action guards on `$currentUser->isAuthenticated()`, but `confirmArchive`,
`cancelArchive`, and `render` call `$currentUser->user()` (in render) without it. The `auth`
route middleware makes this unreachable in practice, so it is defence-in-depth only — but the
inconsistency is a readability snag and `render` would throw `NotAuthenticatedException` rather
than degrade gracefully if the guard ever lapsed.

### IN-04: Summary-card empty-state branch passes `[]` but never reaches the page query

**File:** `Modules/Goals/Internal/Http/Livewire/GoalsSummaryCard.php:33-35`
**Issue:** Minor: the unauthenticated branch is dead under the `auth` middleware that fronts the
dashboard. Consistent with the page component's own redundant guards; no action required beyond
noting the pattern is uniformly defensive.

---

_Reviewed: 2026-06-08T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
