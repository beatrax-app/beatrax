# Moving the budget month — what happens to the plan already on disk

A reader who sets their budget month to payday is changing
`users.period_start_day`. Every envelope row is keyed by a literal
`period_start` date, and `CarryoverQuery` matches that key exactly, so
the boundary move strands every row unless something re-keys them.
`EnvelopePeriodRekeyer` is that pass, run from `SettingsPage::save()`
only when the day actually changed.

## The mapping: keep the distance from the month the reader is in

The rekeyer is handed the day being replaced, because a stored key means
nothing without it: `2026-06-01` is a period start under day 1 and a
mid-period date under day 15. Every stored key is first normalised into
the old period that *contains* it, on the old day.

The new key is then the period the same distance away from the period
the reader is in now:

    target = containing(now)          // on the day just saved
             + periodsBetween(containingForDay(previousDay, now),
                              oldPeriodOf(storedKey))

So the month the reader is living in stays the month their plan is on,
whichever day they moved to, and the months either side of it keep their
order and their spacing.

The mapping it replaced took `containingDate(storedKey)` — the old
period's **first instant**, read on the new day. Under any later start
day that instant falls into the *previous* new period, so the whole plan
slid a month backwards while genesis, anchored on
`users.envelope_activated_at`, slid forwards. `batchAssignments` and
`batchMoves` filter on `period_start >= genesis` and month-back nav stops
at genesis (`BudgetsPage`), so for an upgrader the entire plan became
unreadable and unreachable in one save, and moving the day back re-keyed
it further into the past rather than recovering it. On a fresh install
(`envelope_activated_at` null) genesis follows the earliest assignment
row, so nothing was permanently lost — but the current month read
unbudgeted, which is the same screen as "your plan is gone".

## The invariant: no row lands below genesis

The index shift preserves order, so a row at or after genesis before the
move is at or after it afterwards — but that is a property of *this*
mapping, not of the rekeyer. `targetFor()` therefore floors the result:
a row whose old period was at or after the old genesis is never keyed
below the new one. Two old keys landing on one new period are already
handled — the surviving assignment row carries their sum, which the
`(user_id, category_id, period_start)` UNIQUE requires.

The floor is deliberately not applied to a row that was *already* below
genesis before the move. Such a row was unreadable before and stays
unreadable: lifting it would add money to a month the reader never
assigned it to.

Two merging rows need not share a currency — `envelope_assignments.currency`
records what the row was written in, and the reader's base currency can have
moved between the two months. Adding their minor units invented the difference:
a EUR 100.00 month and a USD 100.00 month came out one EUR 200.00 envelope.
A bucket holding more than one currency is converted into the owner's base
currency first, the same conversion `EnvelopeWriter::copyFromPeriod()` applies
to a copied month. A bucket the rate table cannot price *whole* is left summed
as it was: dropping the part with no rate would delete stored money that comes
back the day a rate arrives, and the fold already reads such a row as zero
wherever it sits.

## Repairing an install the old mapping already damaged

`2026_08_28_000002_lift_envelope_rows_stranded_below_genesis` is the
one-off repair. The stored key and the activation anchor always sat in
the same old period, so the two new windows they fall in are at most one
period apart: the damage could only ever strand a plan **exactly one
period** below genesis. The migration lifts assignment and move rows in
that one window onto genesis, merging an assignment into the genesis row
it would otherwise collide with, and leaves anything further down alone.
That merge is the rekeyer's merge: the currency rule above governs it too,
because the two rows it joins are the same two rows, written under whatever
base currency the reader held each month. It read `assigned_minor` alone and
added the minor units, so USD 100.00 merged into EUR 100.00 came out one
EUR 200.00 envelope — the exact arithmetic the paragraph above exists to
refuse.

An install that already ran the migration cannot be repaired. The merge
deleted the stranded row and wrote a plain sum, so nothing on disk says
which rows were merged or what the two halves were; the surviving row is
indistinguishable from one the reader assigned by hand, and there is no
predicate to name it and nothing to recompute from. The fix protects an
install upgrading from before 2026-08-28 and nobody else, which is the
same forward-only limit `down()` states for its own reason.

What it cannot restore is the *month attribution* of a multi-month plan.
The day the rows were written under was never recorded anywhere, so a
plan that spanned several months comes back with its first month merged
into genesis and the rest still one period early. The amounts are whole;
the calendar is approximate. A fresh install needs no repair at all — its
genesis moved with its rows, so nothing was ever below it.

Each device runs the migration against its own copy of the same synced
rows and derives the same answer, so no op-log traffic is needed to make
the repair converge.

### The key is inside the id, so the id has to move with it

`EnvelopeMoveId::for()` folds `(move_group_id, kind, period_start)` into
an `envelope_moves` primary key, so `period_start` is not an ordinary
column on that table — it is a third of the row's identity. The first
version of the lift moved it with a bare `UPDATE`, and every move row it
touched came out of the upgrade under an id its own columns no longer
derive. That is the worse half of both kinds of id: it looks stable and
reproducible, and it is a number a second device folding the same three
values does not arrive at.

The lift now re-derives the id in the statement that moves the key, and
`2026_09_12_000002_give_a_lifted_move_back_the_id_its_own_columns_derive`
repairs an install that already ran the earlier version. That repair is
possible where the assignment merge above is not: the move row still
carries all three inputs, so the right id is a pure function of columns
still on it, and the rows to fix are named by evidence rather than
guessed at — a row sitting on genesis whose stored id is the derivation
at a key inside the window the lift emptied was put there by that
`UPDATE` and by nothing else.

Two kinds of row are deliberately left alone. A move written before the
id was derived from the move at all carries whatever the table's sequence
handed it; nothing in the tree has ever re-derived those, and one with no
`move_group_id` has no identity to fold. And a row whose corrected id is
already taken keeps its stale one: two rows folding onto a single id are
one `(move_group_id, kind, period_start)` carrying two amounts, which is
a worse problem than a stale id and not one a migration may pick a loser
in.

Neither the lift nor the repair emits an op, and neither needs to.
`envelope_moves` is captured from `EnvelopeMoveMutated`, which a
query-builder write does not raise, and `period_start` carries no
last-writer-wins rule in `MergeRulesRegistry` — it travels as
delete-and-create or not at all, which is why `EnvelopePeriodRekeyer`
re-creates a row rather than updating it. Convergence rests on every
device running the same arithmetic over the same synced rows, and the id
is part of that arithmetic.

## Related pages

- [`Budgets` architecture](architecture.md) — the fold the keys are read by.
