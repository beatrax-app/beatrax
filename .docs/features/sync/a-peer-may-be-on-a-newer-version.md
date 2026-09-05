# A peer may be on a newer version

Two devices in one household are not upgraded at the same instant. A phone
that auto-updates and a desktop that has not been reopened for a fortnight are
the normal case, not the edge, and the op log carries whatever the newer one
wrote. So every value that crosses is a value from a build this one has never
seen, and forward compatibility is a standing requirement rather than a
migration concern.

## What actually crosses

The applier writes a create-row op's fields **verbatim**. It validates
ownership, completeness against `_create_required` and the tombstone order —
it does not validate a value against a PHP enum, and it cannot: the enum it
would validate against is this build's, which is precisely the thing that is
out of date.

The only thing that refuses an unknown value is the schema. SQLite cannot add
a `CHECK` after the fact, so the repo's constraint shape is a paired
`BEFORE INSERT` / `BEFORE UPDATE OF <column>` trigger that `RAISE(ABORT)`s —
`transactions_type_check_insert`, `recurring_series_cadence_check_insert`,
`drift_alerts_state_check_insert` and about thirty more. A column with one of
those pairs cannot hold a spelling this build does not know, so reading it
back with `Enum::from()` is honest.

A column **without** one can, and two of them were being read with `from()`:

| Column | Constraint | Read |
|---|---|---|
| `envelope_moves.kind` | none — `string(32)` | `EnvelopeMoveKind::tryFrom()` |
| `pot_movements.kind` | none — `string(32)` | `PotMovementKind::tryFrom()` |

Both are create-only synced ledgers with `kind` in `_create_required`. A peer
adding a third kind therefore landed a row whose `from()` raised a `ValueError`
mid-render, and the reader's whole `/budgets` or `/pots` page went with it —
on the *older* device, which is the one that did nothing wrong.

## Why the fallback is not a guess

`tryFrom()` alone is not the fix; what is done with the null is. The obvious
patch is to fold the unknown kind into the side it most resembles, which is
what the code before the enum did (`=== 'move_in' ? 'in' : 'out'`). That is
worse than the crash, quietly: both screens draw the *direction* of a history
line from the kind — the wording, the `+`, the colour — so a guess shows the
reader the wrong side of a real move, in money, with nothing to say it was
guessed.

So the null is carried into the DTO (`EnvelopeMoveRow::$kind` and
`PotMovementRow::$kind` are nullable) and the screen has copy for it:
`budgets::messages.history.moved_unreadable` and
`pots::messages.movement.unreadable` both say the row was written by a newer
version of Beatrax. The row keeps its date, its memo, its counterpart and its
amount — the amount with the sign it was stored with, which is a fact and not
a derivation — and loses only the direction word and the `+` that would have
claimed one.

Nothing is dropped, so nothing is silently missing from a total either.
`CarryoverQuery::batchMoves()` reaches `EnvelopeRow::$netMovedMinor` through a
`SUM(amount_minor) AS net_minor` over `envelope_moves`, and
`PotAllocationLedger` sums `pot_movements.amount_minor` the same way. Neither
consults `kind`.

## The rule

A synced column read into a PHP enum needs one of two things, and the choice
is not a preference:

1. **A trigger pair in SQL.** Then `from()` is right, and a peer's unknown
   value is refused at the insert and quarantined by the replayer rather than
   reaching a reader.
2. **`tryFrom()` plus copy for the null.** Then the row survives, and the
   screen says which part of it this build cannot read.

What is never right is `from()` over an unconstrained synced column, and
neither is `tryFrom()` with a fallback case picked for looking plausible.
