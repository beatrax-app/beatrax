# `Budgets` — architecture

The `Budgets` module owns zero-based envelope budgeting: assigning every
euro of income to a category ("envelope") for the current period, tracking
what each envelope has spent, and carrying its leftover or shortfall
forward into the next period. It replaced an earlier flat, period-agnostic
`category_budgets` ceiling list, which is now write-dead — every write path
in this module goes through the envelope tables
(`envelope_assignments`, `envelope_moves`, `envelope_settings`) instead.
`CategoryBudget`/`BudgetProgressQuery`/`BudgetWriter` remain only as the
legacy read/write path for the retired `/budgets` predecessor and are not
exercised by the current `/budgets` grid.

## Envelope activation (the hard cutover)

`EnvelopeActivationService::activate()` makes "cutover to envelopes" and
"envelope activation" the same atomic event. For every user not yet
activated, it archives every active category-linked pot via
`PotWriter::archive()` (which already releases the pot's balance to
unallocated) and stamps `users.envelope_activated_at` — the genesis anchor
`CarryoverQuery` folds forward from. No balance is separately
converted/seeded into any envelope: since archiving a pot already releases
its balance to the account's unallocated pool, genesis `carried_in` stays
zero for every envelope everywhere. Goal-linked pots (`category_id IS
NULL`) are entirely untouched — only `status='active' AND category_id IS
NOT NULL` pots are ever selected.

Per-user idempotency is enforced by an atomic `whereNull('envelope_activated_at')
->update(...)` claim *before* the pot walk (the same claim-then-walk mutex
shape `BackfillAnomaliesJob` uses): a user whose claim update affects zero
rows was already activated and is skipped entirely. The claim + pot-archive
walk is deliberately *not* wrapped in a single spanning transaction —
`PotWriter::archive()` opens its own inner transaction and dispatches its
events only after that inner commit, and an outer transaction would defer
those events until the outer commit and break that contract. Instead, a
walk that throws part-way unclaims the user (resets `envelope_activated_at`
back to null) so a subsequent `activate()` re-runs the full cutover for
that user; this is safe because `PotWriter::archive()` already skips
non-active pots, so pots archived before the failure are never
double-archived. Every pot is archived individually via
`PotWriter::archive($user, $potId)`, which re-verifies ownership
internally — never via a single unscoped bulk update across all users.

## The genesis-to-target fold (`CarryoverQuery`)

`CarryoverQuery::forUserAndPeriod()` is the one genuinely new algorithm in
this module: an iterative fold that walks every period from the user's
envelope-activation genesis anchor forward to the requested target period,
carrying forward exactly two pieces of running state — a single
unassigned pool (`poolCarry`) and a per-category `carriedIn` map. Every
input (assignments, moves, settings, spend, income) is read fresh from
current ledger state on every call; nothing is persisted or incrementally
mutated, which is what makes re-running an unchanged import (or
editing/splitting a past transaction) idempotent by construction.

Every live expense category is iterated every period — via
`BudgetProgressQuery::expenseCategories()` — never just the categories
that happen to have an `envelope_assignments`/`envelope_settings` row, so
an unassigned-but-spending category still surfaces as an overspent
€0-assigned envelope instead of silently vanishing. When
`envelope_activated_at` is null (pre-cutover), the fold short-circuits to
an all-zero result rather than walking from account inception. The walk
is bounded `genesis..min(target, current+12)` — it never walks further
into the future than 12 periods past "now", closing an unbounded
past/future-walk resource-exhaustion surface. Every read carries an
explicit `WHERE user_id = ?` — no reliance on a global `UserScope` for
this cross-module Public service.

Per-envelope availability is `available = assigned + carriedIn + netMoved
- spent`. When an envelope's availability goes negative, its
`overspend_mode` decides what happens to the shortfall at period-end:
`reduce_to_budget` (the default) debits the pool once for the shortfall
and resets that envelope's carry to zero next period; `carry_negative`
lets the negative balance itself roll forward untouched, never touching
the pool. Envelopes are EUR-only; settled spend the ledger holds in other
currencies (a USD Google Play charge, a non-EUR ICS merchant charge) is
summed separately into `nonEurSpentMinor` so the grid can *surface* that
spend rather than silently drop it or collapse it into a EUR total — that
figure is deliberately never folded into `spentMinor`/`availableMinor`,
and because it may mix several non-EUR currencies it is only ever a
"there is spend not shown here" signal, never an authoritative amount.

`CarryoverQuery` is bound as a request-lifetime singleton so the grid, the
sticky "Ready to assign" header, and the dashboard glance card share one
container instance per HTTP request. It deliberately does *not* cache fold
results across separate calls keyed only by `(userId, targetPeriodStart)`:
tests interleave writes (assign/unassign/toggle-overspend-mode) with
repeated reads of the same `(user, period)` via the same container
instance, and an instance-property cache would silently serve stale
figures after those writes. Every call is a fresh, pure, request-scoped
fold over batch-loaded data (one query per table across the whole walk
range, not one query per period per table) — the "memoized per request"
property is satisfied by the fold being inexpensive and bounded, not by an
unsafe result cache.

## The over-budget nudge job (`EmitBudgetNudgesJob`)

Runs hourly per user and reads the *live* envelope model via
`CarryoverQuery::forUserAndPeriod()` — never the write-dead
`category_budgets`/`BudgetProgressQuery` path. A row is over its threshold
when `spentMinor * 100 >= notifyThresholdPercent * (availableMinor +
spentMinor)`, guarding the case where the budget base
(`availableMinor + spentMinor`) is zero or negative (no positive budget
base means nothing can be "over"). All of this is integer math on
minor-unit fields — never a float. Budgets never imports the
notifications module's namespace; a listener living in that other module
subscribes to the dispatched `BudgetThresholdCrossed` event on its own,
keeping the module boundary one-directional.

Because a queue job has no authenticated web-guard user, but
`PeriodQuery::current()`/`containing()` are bound to the request-scoped
`CurrentUser` contract, the job reproduces `PeriodQuery::containing()`'s
exact period-window algorithm inline, scoped explicitly to the loaded
user's own `period_start_day`. `CarryoverQuery` also calls `PeriodQuery`
internally for its own genesis/current-period bounds, and that internal
dependency can't be bypassed from outside the class — so for the
*duration* of the `forUserAndPeriod()` call only, the job binds the loaded
user onto the default auth guard (making every transitive `CurrentUser`
read inside that call resolve correctly) and always restores whatever
guard state existed beforehand in a `finally` block, so the job never
leaves a queue worker process with another user's identity bound to the
guard after it returns.

## Moving money between envelopes (`EnvelopeWriter::move()`)

Writes a paired debit/credit row (`kind: 'move_out'` / `'move_in'`) in one
transaction, sharing one correlation id (`move_group_id`) so `undoMove()`
can match the counterpart deterministically rather than by
second-precision timestamp (legacy rows written before `move_group_id`
existed fall back to that original timestamp-based match). Moves never
touch `Σassigned`/the pool — "Ready to assign" is unchanged by a move.
Deliberately carries **no balance guard**: a move that takes the source
envelope negative succeeds outright, which is the correct behavior for
zero-based budgeting (over-allocation is a normal, expected state here,
not an error). `undoMove()` hard-deletes both paired rows and dispatches a
`delete` mutation event for each row's own primary key.

`setAssigned()` upserts one `(user, category, period)` row,
preserving its primary key on edit; setting the amount to zero *deletes*
the row rather than storing a zero, and dispatches a `delete` mutation
event rather than an `edit` with value 0 — the two converge differently
under the per-`(table, pk, field)` last-write-wins sync merge. Every
client-supplied category id is re-validated server-side via
`BudgetProgressQuery::canBudget()` before any write; the rendered
`<select>` on the grid is never treated as pre-authorization. Every
`Envelope*Mutated` event is dispatched only after its DB transaction
commits, never from inside it.

`envelope_assignments.period_start` is deliberately written as a plain
`Y-m-d` string via the raw query builder rather than through the Eloquent
model's `'date'` cast: that cast serializes on save using the connection
grammar's full datetime format (`"Y-m-d 00:00:00"`), which would break the
fold's exact `where('period_start', 'Y-m-d')` string match. The model's
own `periodStart()` `Attribute` accessor mirrors this by storing a bare
date string on write while still returning a `CarbonImmutable` on read, so
the model and the writer always agree on storage format.

## Public seam

- **Actions/writes** — `EnvelopeWriter` (the sole write path for
  assignments, moves, overspend mode, notify thresholds, copy-last-month),
  `EnvelopeActivationService` (the cutover), `BudgetWriter` (legacy
  `category_budgets` path).
- **Reads** — `CarryoverQuery` (the genesis-to-target fold),
  `EnvelopeBalanceQuery` (per-envelope recent moves, batched across many
  categories in one query to avoid an N+1 on every grid render),
  `BudgetProgressQuery` (legacy progress read model; also the
  `canBudget()`/`expenseCategories()` category-authorization surface every
  write path re-validates against).
- **DTOs** — `EnvelopeRow` (one envelope's per-period figures),
  `EnvelopeMoveRow` (one recent-moves history entry), `BudgetProgressRow`
  (legacy progress row).
- **Events** — `BudgetThresholdCrossed`, dispatched once per envelope that
  crosses its own configured notify threshold; its `$period` field is a
  canonical `Y-m-d` period-start string that must be computed identically
  on every device (derived from `PeriodQuery`'s window algorithm applied to
  the current instant, never from a locale-formatted label) or the sync
  layer's convergence breaks.

## `/budgets` page

The Livewire `BudgetsPage` renders the assign-every-euro grid: a sticky
"Ready to assign" header (never blocking, even negative), month navigation
bounded at the user's genesis and `current + 12` periods, a per-row
overspend-mode toggle, a "Copy last month" auto-fill offered only when the
selected period has zero assignments and the prior period has some, and a
per-row move-money modal with a recent-moves + undo list. All service
collaborators arrive as method parameters (no constructor injection,
project-wide rule for Livewire `Component` subclasses); every action
re-checks `CurrentUser` before any write. The `periodStartStr` client
property is always re-validated through `resolvePeriod()` against a strict
`Y-m-d` round-trip before use, so a malformed value can never reach
`CarbonImmutable::parse()` uncaught.

`EnvelopeGlanceCard` is the dashboard's compact analog: it sources its
"Ready to assign" figure from the same `CarryoverQuery` fold (never the
retired `category_budgets` table) and renders one of three states —
unauthenticated (card chrome with a null figure, never a blank gap),
authenticated with zero expense categories (renders nothing at all, since
envelopes are implicit and a "no envelopes yet" state doesn't exist once
any expense category exists), or the populated figure with an amber
"N over budget" pill.
