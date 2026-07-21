# `Goals` — architecture

The `Goals` module lets a user track a savings goal (a target amount by a
target date), optionally linked to a real account for automatic contribution
tracking, and projects when it will finish based on recent contribution
behaviour.

## Module boundary

- **Public/Services/GoalProgressQuery** — the read model for `/goals` and the
  dashboard summary card: active/completed goals (`forUser()`) and archived
  goals (`archivedForUser()`), each with contribution progress and a
  projected finish date.
- **Public/Services/GoalProjectionService** — the run-rate projection
  algorithm, consumed only by `GoalProgressQuery`.
- **Public/Services/GoalWriter** — the sole write path: create, update, and
  the three lifecycle transitions (`markComplete`, `archive`, `restore`).
- **Public/Dto/GoalProgressRow** — one goal's progress row.
- **Public/Exceptions** — `GoalNotFoundException` (missing/cross-user) and
  `InvalidGoalAmountException` (bad amount), distinct types so callers drive
  control flow on exception identity rather than message text.
- **Internal/Http/Livewire** — `GoalsPage` (`/goals`) and `GoalsSummaryCard`
  (dashboard tile).

## Contribution progress

A goal's `contributedMinor` comes from one of two sources:

- **Linked pot** (preferred when present): the pot's current balance,
  FX-converted into the goal's `target_currency` when the pot's currency
  differs. Pot balances for all the user's goals are batch-loaded once per
  render — never a per-goal follow-up query.
- **Fallback (no linked pot, or unlinked goal)**: the sum of credits
  (`transfer_in`, `income`) on the goal's linked account, posted on or after
  the goal's `start_date`, each converted into `target_currency`. An
  unlinked goal (no `account_id`) always reports 0 contributed.

`target_currency` is fixed at goal creation and never changes — it is
intentionally NOT the user's current base currency, since a user may change
their base currency after a goal exists. Both the contribution sum and the
target figure are always expressed in this same fixed currency, so
`fractionComplete` and the "reached" transition are internally consistent
even if the user's base currency later diverges.

`fractionComplete` is a plain float ratio of two integer minor-unit amounts
— never a `Money::toFloat()` call on a monetary value.

## Projection algorithm

`GoalProjectionService::project()` estimates a finish date from a
**trailing-window contribution run-rate**:

1. An unlinked goal, or a goal already at or past its target, gets no
   projected date.
2. The contribution sum over the trailing 90 days (clamped to the goal's own
   `start_date` if younger) is divided by the *actual elapsed observation
   window* — not the constant 90 — so a goal younger than the trailing
   window doesn't have its rate systematically understated.
3. A goal with fewer than 7 days of observation history is suppressed
   entirely (no projected date) — dividing a single early deposit by a
   1–2 day window would extrapolate a misleadingly-soon finish.
4. A zero-or-negative daily rate (no history, or net outflow) also
   suppresses the projection.
5. The projected date is `today + ceil(remaining / dailyRate)`. When that
   falls within the 90-day forecast horizon, the smallest covering
   Forecasting horizon ({30, 60, 90}) is queried as a sanity signal only —
   never as the source of the contribution figure itself, since a
   `ForecastDto` point represents the account's overall balance
   trajectory, not goal-specific contributions. Beyond 90 days the
   projection is reported as extrapolated / lower-confidence
   (`beyondHorizon: true`) without consulting Forecasting at all.

The trailing-window length and the horizon limit are both 90 days by
design, so the run-rate window aligns with the maximum forecast horizon.

## Ownership and lifecycle safety

Every `GoalWriter` mutation re-asserts ownership against the **explicitly
passed** `$user`, bypassing the `BelongsToUser` global scope rather than
relying on it alone — the global scope reads the ambient `CurrentUser` and
is a no-op in an unauthenticated context (e.g. a queued job), so trusting it
alone for an ownership check is a latent cross-user access path. A missing
or foreign goal id resolves to `null` and the caller either throws
(`update()`) or silently no-ops (the three lifecycle methods, matching the
project's read-or-no-op convention for background-safe mutations).

`status` has exactly one write surface: the three dedicated lifecycle
methods (`markComplete`, `archive`, `restore`). `save()` and `update()`
never accept or touch `status` from caller input, so a form field can never
smuggle an arbitrary status value. `save()` always creates a fresh row
rather than upserting on a natural key — a same-name, same-day goal
(e.g. two "Holiday" goals) must never silently collapse into one.

A client-supplied `account_id` is validated as either null or owned by the
user before every write; an unowned account id throws.

`GoalWriter` deliberately injects only `DatabaseManager` and runs its own
inline account-ownership query rather than depending on `GoalProgressQuery` —
this keeps the read and write paths independently testable and avoids a
read-model dependency the write path does not otherwise need.

## Pot linking

A goal may optionally link a savings pot (from the Pots module) instead of
tracking a real account directly. Every create/update that touches the
linked pot runs the goal write and the pot link/unlink/relink as **one DB
transaction**, so a failed link (one-pot-per-goal violation, a cross-user or
category-linked pot id) rolls back the goal mutation too — there is never an
orphan goal or a silently-lost previous pot link. A pot already linked to a
category is rejected as a goal link target (linking it would blank the
category link as a side effect of the pot write).

The linked-pot picker on `/goals` is scoped to the selected account and
excludes pots already linked to a goal or a category, except — while
editing — the pot currently linked to the goal being edited, via a single
shared base query reused (via `UNION`) by both branches so they cannot
drift apart.
