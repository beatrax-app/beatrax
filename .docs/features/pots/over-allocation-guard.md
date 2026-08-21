# The over-allocation guard — transaction boundaries and ordering

A pot has no balance column. Its balance is the signed
`SUM(amount_minor)` of its `pot_movements` rows, and an account's
unallocated figure is `real − allocated`, derived the same way at read
time. Nothing about "you cannot put €100 into a pot when only €40 is
unallocated" is expressible as a database constraint: the quantity being
constrained does not exist as a column, and the rule spans every active
pot on the account rather than the row being inserted.

So the rule lives in application code, and the only thing standing
between it and a lost update is where the transaction boundary sits.

## Why the obvious shape is wrong

The natural way to write it is:

```
$unallocated = $balance->currentUnallocatedForAccount(...);   // read
if ($amount > $unallocated) { throw; }                        // check
$db->table('pot_movements')->insert([...]);                   // write
```

Two funding actions arriving together — a double-submit, a desktop and
a phone acting on the same account, a replayed sync op — both read the
same €40 unallocated, both pass the check, and both insert. The account
is now over-allocated by design, and because balances are derived there
is no corrupted column to notice: the books simply say the user has
allocated more than they have. Recomputing afterwards cannot tell you
which of the two inserts was the illegitimate one.

## The shape `PotWriter` actually uses

Every mutation splits into two phases with the transaction boundary
between them.

**Before the transaction opens** — everything that can fail cheaply, and
everything that only needs data the transaction will not race on:

1. Parse the amount. `MoneyInput::tryToPositiveMinor()` returns `null`
   for blank, malformed, zero or negative input, and the writer throws
   before touching the database. On `save()` this ordering is
   load-bearing beyond tidiness: parsing after the pot row was created
   would leave an orphan pot behind for a typo.
2. Resolve and own the target. `findOwnedActivePot()` queries with
   `withoutGlobalScope(UserScope::class)` plus an explicit
   `where('user_id', $user->id)`, so ownership does not depend on the
   ambient `CurrentUser` — the global scope is a no-op in an
   unauthenticated context such as a queued job, which would make
   relying on it alone a cross-user access path. A missing or foreign id
   throws `PotNotFoundException`.
3. Read the immutable facts off the resolved row: `account_id`,
   `currency`, and for a transfer, that both pots are active, owned, and
   share an `account_id` (transfers are intra-account only).

**Inside the transaction** — the read the decision depends on, the
decision, and the write, with nothing between them:

```php
$this->db->connection()->transaction(function () { 
    $unallocated = $this->balance->currentUnallocatedForAccount($accountId, $user);
    if ($minor > $unallocated) {
        throw new InsufficientUnallocatedException(...);
    }
    $this->insertMovement([...]);
});
```

The figure is **re-read inside** the transaction rather than passed in
from the phase above. That is the whole guard: the SELECT and the INSERT
are in the same write transaction, so a second writer serialises behind
the first and its own re-read already sees the first insert. Throwing
inside the closure rolls the transaction back, so a rejected fund leaves
no trace.

`withdraw()` and `transfer()` use the identical shape against
`balanceForPot()` instead of `currentUnallocatedForAccount()` — the
constraint is the pot's own balance rather than the account's headroom,
but the ordering is the same.

## The compound transactions

Three operations wrap more than one write, and in each case the point is
that a partial result is worse than no result:

- **`save()` with an initial amount** puts the `pots` row creation, the
  unallocated check and the funding movement in one transaction. An
  over-limit initial amount therefore takes the pot row with it — the
  user sees an error, not an empty pot they now have to clean up, and a
  resubmit cannot create a duplicate.
- **`transfer()`** performs the source-balance check and **both**
  movements — a negative `transfer_out` on the source, a positive
  `transfer_in` on the target — in one transaction, and stamps both with
  a single shared `$now`. Half a transfer would invent or destroy money
  in the reconciliation, and the shared timestamp keeps the pair
  adjacent in the movement history rather than letting them straddle
  another movement.
- **`archive()`** reads the remaining balance, inserts one releasing
  `withdraw` movement for it (memo `Released on archive`), and flips
  `status` to `archived`, all in one transaction. An archived pot always
  reads back as balance 0 and its money is back in unallocated; a
  half-applied archive would strand the balance in a pot the reconciler
  no longer counts.

`restore()` is deliberately *not* one of these. It inserts no movements
at all — a restored pot comes back empty rather than re-funded, because
the money was released on archive and may since have been spent or
allocated elsewhere.

## What the guard does not cover

The guard is a property of `PotWriter`, not of the schema. Any code that
inserts into `pot_movements` directly bypasses it entirely, and no
constraint will stop it.

`PotWriter::insertMovement()` is the single insert path for that reason,
and it carries a second responsibility: it raises
`Sync\Public\Events\EntityMutated` for the row it just wrote. A movement
inserted anywhere else would be both unguarded and uncaptured, and an
uncaptured movement means a fund made on the desktop never reaches the
phone, whose pot balance then silently freezes at whatever the last
backfill handed it. `PotWriter::capture()` plays the same role for the
`pots` table itself.

Finally, the guard only constrains *allocation*. It cannot stop the
account balance from falling below what pots have already claimed — that
is real-world spending, and no pot rule applies to it. When it happens,
`ReconciliationRow::$isOverAllocated` flags it and the view styles the
warning. The system never rewrites a pot's balance to make the books
look tidy.

## See also

- [`architecture.md`](architecture.md) — the reconciliation model, the
  goal-only link rule, and the Public surface.
- [`../goals/architecture.md`](../goals/architecture.md) — the consumer
  of `linkedPotBalancesForUser()`, which reads a linked pot's balance as
  a goal's contributed figure.
- [`../goals/run-rate-projection.md`](../goals/run-rate-projection.md) —
  why `netMovementForPotSince()` exists.
