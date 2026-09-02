# `Budgets` — architecture

The `Budgets` module owns zero-based envelope budgeting: assigning every
euro of income to a category ("envelope") for the current period, tracking
what each envelope has spent, and carrying its leftover or shortfall
forward into the next period. It replaced an earlier flat, period-agnostic
`category_budgets` ceiling list, which went write-dead at the cutover and
whose table was dropped once the last reader moved off it. Every read and
write in this module now goes through the envelope tables
(`envelope_assignments`, `envelope_moves`, `envelope_settings`).

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
`envelope_activated_at` is null, `genesisAnchorFor()` falls back to the
earliest period the user has an `envelope_assignments` row for — a device
that joined by pairing never receives that column, and reporting every
synced assignment as zero was the alternative. Only when that fallback is
empty too is there no anchor to walk from, and what comes back then is
not zeroes. `unstartedRows()` reads this period's real spend per category,
so a category already spending against nothing assigned returns a negative
`availableMinor`, counts toward `overspentCount`, and carries the same
`unconvertedSpentMinor` split the fold applies; "Ready to assign" is the
period's own income, because carry and assigned are both nought
pre-genesis and income is all the fold would have had to add. An
all-zero result told a reader with a month's pay banked that they had
nothing to assign and nothing overspent, on a screen listing two dozen
categories. The walk
is bounded `genesis..min(target, current+12)` — it never walks further
into the future than 12 periods past "now", closing an unbounded
past/future-walk resource-exhaustion surface. Its *length* is capped too,
at `MAX_WALK_PERIODS`, and the walk is built **backwards from the target**
so that cap can only ever cost carry-in from beyond it. Built forwards
from genesis, a cap reached first left the target period unfolded and the
whole read fell through to `rows => []` — which is the "no expense
categories yet" empty state, drawn at two dozen of them, for a reader
whose only fault was an activation anchor decades in the past. Every read
carries an explicit `WHERE user_id = ?` — no reliance on a global
`UserScope` for this cross-module Public service.

Per-envelope availability is `available = assigned + carriedIn + netMoved

- spent`. When an envelope's availability goes negative, its
`overspend_mode` decides what happens to the shortfall at period-end:
`reduce_to_budget` (the default) debits the pool once for the shortfall
and resets that envelope's carry to zero next period; `carry_negative`
lets the negative balance itself roll forward untouched, never touching
the pool. Envelopes are held in the user's base currency, and settled
spend the ledger holds in another currency is converted into it at the
rate table's rate and folded into `spentMinor` like any other spend — a
USD Google Play charge counts against its envelope. What does not fold in
is spend in a currency the rate table cannot *reach* at all: that is
summed separately into `EnvelopeRow::$unconvertedSpentMinor` so the grid
can surface it rather than silently drop it or count it at one to one.
Because that residue may mix several unreachable currencies it is only
ever a "there is spend not shown here" signal, never an authoritative
amount.

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

Runs hourly per user and reads the envelope model via
`CarryoverQuery::forUserAndPeriod()`. A row is over its threshold
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
`CurrentUser` contract, the job binds the loaded user onto the default
auth guard for the *duration* of the period read and the
`forUserAndPeriod()` call, then always restores whatever guard state
existed beforehand in a `finally` block — so the job never leaves a queue
worker process with another user's identity bound to the guard after it
returns. Inside that window it calls `PeriodQuery::current()` itself
rather than reproducing the window algorithm: `CarryoverQuery` calls
`PeriodQuery` internally for its own genesis/current-period bounds anyway
and that dependency cannot be bypassed from outside the class, so a second
inline copy of the algorithm would have been one more thing to keep in
step for nothing.

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

### The id of a move is derived, not minted (`EnvelopeMoveId`)

`envelope_moves` declares no unique index but its primary key, so an
autoincrement is the row's only identity and nothing downstream can tell two
rows apart by content. Two devices writing while apart therefore both took the
next id: a desktop move of &minus;777 and a phone move of &minus;888 were both
id 9, and once they synced the arriving create was refused by the primary key
and discarded as an idempotent replay. The two devices held different money at
one id, with an empty quarantine on both.

`EnvelopeMoveId::for()` computes the id from `DerivedRowId` over
`(move_group_id, kind, period_start)` instead:

- `move_group_id` is minted once by the device making the move and travels with
  both rows, so the arithmetic is the same wherever it is run.
- `kind` keeps the two halves of one move off a single id.
- `period_start` is in the tuple because `EnvelopePeriodRekeyer` re-creates each
  row against a new period, and both devices rekey independently when the start
  day changes — two autoincrements there are the same collision again.

The demo seeder derives its `move_group_id` from the demo move itself (owner
username, the two category slugs, the memo key, the period start) rather than
minting a uuid per run. Every device seeds the same demo moves, and a fresh
uuid on each made one demo move two the moment they synced.

Move ids run past 2<sup>53</sup>, so the blade sends them **quoted** and
`BudgetsPage::undoMove()` reads them back through `DerivedRowId::fromWire()`. A
bare number literal is rounded by the browser before the server sees it, and
the action then matches no row —
`ADerivedIdNeverReachesTheBrowserAsANumberArchTest` guards that.

Rows written before this keep the small ids they were given; nothing is
rewritten, and the two kinds of id sit side by side.

`copyFromPeriod()` ("Copy last month") applies every row of the source
period inside **one** transaction and dispatches its collected events
after that single commit, rather than opening a transaction and an event
per category: a copy that stopped half way left a partially-assigned month
indistinguishable from a deliberate one. Each source row is **converted**
out of the currency it was written in and into the reader's base currency
on the way, because the reader's base currency can have changed since the
source month: handing the raw minor units on and stamping the new code
beside them turned USD 500.00 into EUR 500.00 on every envelope of the
month, from one click. A row in a currency the rate table cannot reach is
carried across in its own currency instead — the fold surfaces it exactly
as it surfaced the source, and relabelling is the one thing that must not
happen.

`setAssigned()` normalises the date it is handed to the start of the period
that *contains* it, resolved against the **owner's** own `period_start_day`
via `PeriodQuery::containingForUser()` — never the browsing session's.
`CarryoverQuery` looks assignments up by an exact `period_start` string
match, so a row keyed to anything but a real period boundary matches no
period the fold walks: written, on disk, and read as zero forever. The
grid and the onboarding step already passed `$period->start`, so for them
this is the identity; the migration promoter did not, and a YNAB/Actual
export's `startOfMonth()` budget month landed off-boundary for every
reader whose month does not start on the 1st. Normalising in the writer
rather than at each caller is what makes a fourth caller safe by default.
[`EnvelopePeriodRekeyer`](moving-the-budget-month.md) repairs rows
already on disk when the reader *moves* their boundary; this stops new
ones being written off it.
`move()` keys its paired rows the same way, for the same reason.

`envelope_assignments.currency` and `envelope_moves.currency` are stamped
from `BaseCurrency::forUser($user)`, never `code()`: `code()` answers for
whoever the guard carries, and falls through to the install default in a
console or queued context — two callers of "the user's base currency"
giving two answers for a writer that was handed the owner explicitly. The
currency is part of what an edit compares, not only part of what it
writes: the grid seeds its cell with the figure it printed, so a reader
re-typing that figure after a reporting-currency switch sends the same
minor units under a different sign, and a guard reading only
`assigned_minor` dropped the write as unchanged and left the row
denominated in the currency they had just left.

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
fold's exact `where('period_start', 'Y-m-d')` string match. There is one
writer and this is it — `EnvelopeAssignment` carries no cast and no
accessor for the column, and the assertion that the stored value is a bare
date is made against `EnvelopeWriter` itself. It used to be made against
an `Attribute` on the model that no production caller reached, which meant
the fold's own tests seeded their rows through the model and would have
stayed green had the writer started storing the datetime form.

## Public seam

- **Actions/writes** — `EnvelopeWriter` (the sole write path for
  assignments, moves, overspend mode, notify thresholds, copy-last-month),
  `EnvelopeActivationService` (the cutover).
- **Reads** — `CarryoverQuery` (the genesis-to-target fold),
  `EnvelopeBalanceQuery` (per-envelope recent moves, batched across many
  categories in one query to avoid an N+1 on every grid render, each row
  converted into the reader's base currency the way the fold nets it —
  a line left in its stored units read `+EUR 500.00` beside a moved column
  reading `EUR 440.18`, off one rate the fold had already applied),
  `EnvelopeProgressQuery` (the fold reduced to one progress row per
  envelope that has something to report, which is what `Position`
  composes its budget status from), `BudgetProgressQuery` (the
  `canBudget()`/`expenseCategories()` category-authorization surface every
  write path re-validates against).
- **DTOs** — `EnvelopeRow` (one envelope's per-period figures),
  `EnvelopeMoveRow` (one recent-moves history entry, carrying the currency
  its `amountMinor` is denominated in — the reader's, or the row's own when
  the rate table cannot reach it), `BudgetProgressRow`
  (one envelope reduced to budget/spent/status).
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
per-row move-money modal with a recent-moves + undo list.

A row prints **every term of its own availability** — assigned, carried
in, moved, spent, available — because that is the arithmetic
`CarryoverQuery` did, and printing three of the five terms left the fifth
underivable. On a demo install the utilities envelope read `Assigned
EUR 150.00 · Spent EUR 0.00 · Available EUR 425.00`, and nothing on the
page said where the other EUR 275.00 had come from. The phone card prints
the two middle terms only when they carry something, since a row where
both are nought has nothing to explain. Each history line carries the
move's note as well, which the modal has always asked for and stored. A line
whose `kind` this build has no case for keeps its date, note, counterpart and
signed amount and says so instead of picking a direction —
`envelope_moves.kind` has no CHECK and a peer on a newer version writes its
own spelling straight through the op log
([a peer may be on a newer version](../sync/a-peer-may-be-on-a-newer-version.md)).
All service
collaborators arrive as method parameters (no constructor injection,
project-wide rule for Livewire `Component` subclasses); every action
re-checks `CurrentUser` before any write. The `periodStartStr` client
property is always re-validated through `resolvePeriod()` against a strict
`Y-m-d` round-trip before use, so a malformed value can never reach
`CarbonImmutable::parse()` uncaught.

The page renders the period `CarryoverQuery::boundedPeriodFor()` hands back,
never the one its anchor resolved to. The fold clamps a target outside
`[genesis, current + 12]` and answers for the clamped month, and a page that
went on drawing the resolved month put two months on one screen: heading,
moves list and copy banner from one, grid figures from the other. It needs no
tampered anchor to happen — zeroing the earliest month deletes its rows, and
with `envelope_activated_at` null genesis follows the earliest row forward,
straight past the month the reader has open. The clamp lives on
`CarryoverQuery` beside the fold that applies it, for the same reason
`genesisPeriodFor()` does: a second copy on the page is how the two came to
disagree about which months exist.

`EnvelopeGlanceCard` is the dashboard's compact analog: it sources its
"Ready to assign" figure from the same `CarryoverQuery` fold and renders
one of three states —
unauthenticated (card chrome with a null figure, never a blank gap),
authenticated with zero expense categories (renders nothing at all, since
envelopes are implicit and a "no envelopes yet" state doesn't exist once
any expense category exists), or the populated figure with an amber
"N over budget" pill.
