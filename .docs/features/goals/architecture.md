# `Goals` — architecture

The `Goals` module lets a user track a savings goal (a target amount by a
target date), funded either by a linked savings pot or by the transactions
the user explicitly attributed to it, and projects when it will finish based
on recent contribution behaviour.

## Module boundary

- **Public/Services/GoalProgressQuery** — the read model for `/goals` and the
  dashboard summary card: active/completed goals (`forUser()`) and archived
  goals (`archivedForUser()`), each with contribution progress and a
  projected finish date.
- **Public/Services/GoalProjectionService** — the run-rate projection
  algorithm, consumed only by `GoalProgressQuery`.
- **Public/Services/GoalWriter** — the sole write path: create, update, and
  the three lifecycle transitions (`markComplete`, `archive`, `restore`).
- **Public/Services/GoalContributionWriter** — the sole write path for the
  `goal_contributions` pivot: `attribute()` and `detach()`.
- **Public/Services/GoalContributionQuery** — the picker list
  (`attributableGoals()`) and the per-transaction attribution list
  (`forTransaction()`) the Ledger transaction detail screen renders.
- **Public/Dto/GoalProgressRow** — one goal's progress row.
- **Public/Dto/GoalAttributionRow** — one (goal id, goal name) pair for the
  attribution picker.
- **Public/Exceptions** — `GoalNotFoundException` (missing/cross-user) and
  `InvalidGoalAmountException` (bad amount), distinct types so callers drive
  control flow on exception identity rather than message text.
- **Internal/Http/Livewire** — `GoalsPage` (`/goals`) and `GoalsSummaryCard`
  (dashboard tile).

## Contribution progress

A goal's `contributedMinor` comes from exactly one of two sources, both of
which belong to a single goal:

- **Linked pot** (preferred when present): the pot's current balance,
  FX-converted into the goal's `target_currency` when the pot's currency
  differs.
- **Otherwise**: the sum of the transactions the user attributed to this goal
  through the `goal_contributions` pivot, each converted into
  `target_currency`. A goal nothing is attributed to reports 0 contributed.

Both sources are batch-loaded once per render — never a per-goal follow-up
query.

There is deliberately no third source. Goals used to sum every credit on a
linked `goals.account_id`, which belonged to no goal in particular: two goals
over one account showed the same figure, and any target below a month's income
read as reached the moment the salary landed. The column and its sum are gone.

An attribution counts whenever the transaction posted. `start_date` bounds the
projection's observation window only — an explicit attribution is the user's
own statement that the money funds the goal, so a backdated one is not silently
dropped.

`goal_contributions` is append-only and holds no amount of its own: the funded
figure is always read back through the joined transaction, so an edited or
FX-restated amount cannot drift from its attribution. `unique(goal_id,
transaction_id)` makes attributing twice — by double-submit or by a replayed
peer op — a no-op. The pivot shape (rather than a `goal_id` column on
`transactions`) keeps the hot, heavily-synced transactions table untouched and
already allows one transaction to fund several goals.

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

1. A goal already at or past its target gets no projected date.
2. The contribution sum over the trailing 90 days (clamped to the goal's own
   `start_date` if younger) is divided by the *actual elapsed observation
   window* — not the constant 90 — so a goal younger than the trailing
   window doesn't have its rate systematically understated. The sum is read
   from whichever source the goal's progress comes from: pot movements for a
   pot-linked goal, attributed transactions otherwise. Rate and level always
   measure the same money.
3. A goal with fewer than 7 days of observation history is suppressed
   entirely (no projected date) — dividing a single early deposit by a
   1–2 day window would extrapolate a misleadingly-soon finish.
4. A zero-or-negative daily rate (no history, or net outflow) also
   suppresses the projection.
5. The projected date is `today + ceil(remaining / dailyRate)`; beyond 90 days
   it is reported as extrapolated / lower-confidence (`beyondHorizon: true`).

The trailing-window length and the horizon limit are both 90 days by design.
Forecasting is not consulted: a `ForecastDto` point is an account's overall
balance trajectory, and a goal no longer has an account.

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

`GoalContributionWriter` re-asserts ownership of **both** the goal and the
transaction before it writes, and treats a foreign or missing id as a silent
no-op — the attribution actions are invokable straight from the browser, so
confirming that a row exists would itself leak.

## Pot linking

A goal may link a savings pot (from the Pots module), which then supplies both
its progress figure and its run-rate. Every create/update that touches the
linked pot runs the goal write and the pot link/unlink/relink as **one DB
transaction**, so a failed link (one-pot-per-goal violation, a cross-user or
category-linked pot id) rolls back the goal mutation too — there is never an
orphan goal or a silently-lost previous pot link. A pot already linked to a
category is rejected as a goal link target (linking it would blank the
category link as a side effect of the pot write).

The linked-pot picker on `/goals` excludes pots already linked to a goal or a
category, except — while editing — the pot currently linked to the goal being
edited, via a single shared base query reused (via `UNION`) by both branches so
they cannot drift apart.

## Assigning a transaction to a goal

Attribution happens on the Ledger transaction detail screen, next to the
category, split and counterparty pickers — the one screen where a transaction
is assigned to anything. `TransactionDetail::attributeToGoal()` and
`removeGoalAttribution()` are thin wrappers over `GoalContributionWriter`.

Attribution is deliberately **not** behind the reconciled lock the sibling
mutators on that screen enforce: it writes a separate row and leaves the
reconciled transaction untouched, and a reconciled row is exactly the confirmed
money a goal wants to count.

Every write raises `Sync\Public\Events\GoalContributionMutated`, which the
Sync capture listener records as a `create_row` or `delete_tombstone` op — an
attribution made on the desktop reaches the phone.
