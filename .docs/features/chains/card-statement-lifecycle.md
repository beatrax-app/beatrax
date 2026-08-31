# Card statement lifecycle

An ICS credit card is billed once a month: one bulk-iDEAL debit on the
bank account pays off everything the card spent in a statement period.
Answering "is this statement paid off?" therefore cannot be a boolean.
A settlement can land short (the user paid part of it), land long (the
user overpaid), or land after a refund has already moved the number the
card actually expects. A `statement_summaries` join computed on the fly
cannot express any of that either, because there is nothing to mutate
between the two events that matter — the statement arriving and the
settlement arriving, weeks apart and in either order.

`card_statements` is therefore a real row with a persistent balance,
and `Modules\Chains\Internal\CardStatementStateMachine` is the only
code allowed to move it.

## The row

One row models one statement period on one ICS-kind account.

| Column | Meaning |
|---|---|
| `total_amount_minor` | The statement total, **negative** — money owed. Carries `statement_summaries.closing_balance_minor`'s sign verbatim. |
| `open_balance_minor` | What is still to settle, **positive**. Starts at `abs(total_amount_minor)`. |
| `currency` | What the two amounts above count. Taken from `statement_summaries.closing_balance_currency`; `EUR` when the summary states none, which is every row the ICS reader wrote. |
| `state` | `open`, `partially_settled`, `settled`, `overpaid`. |
| `period_start` / `period_end` | The statement window: the min and max `posted_at` of the rows the statement bills, copied off the summary the ICS reader wrote. Both are required. |
| `import_run_id` | Nullable, `nullOnDelete` — a back-populated row outlives the import that produced it. |

`UNIQUE (user_id, account_id, period_start, period_end)` is what makes
creation idempotent — which is also why the pair is derived from `posted_at`
and not `booked_at`, and why changing that derivation needed a migration to
move the stored pair with it. See [a period derived from one column and tested
on another](../../conventions/invariants-from-shipped-failures.md#a-period-derived-from-one-column-and-tested-on-another). A `BEFORE INSERT` / `BEFORE UPDATE` trigger pair
rejects any `state` outside the four values above, so an out-of-band
write fails at the database rather than silently corrupting the
lifecycle.

## Creation

`Modules\Chains\Internal\Services\CardStatementUpserter` (bound to the
`UpsertsCardStatements` contract) promotes every ICS-kind
`statement_summaries` row into a `card_statements` row using
`insertOrIgnore` against that UNIQUE constraint. The ignore is the
point: a statement whose state has since moved to `settled` is never
reset back to `open` by a re-import. Rows missing either period
boundary are skipped, because the constraint needs both.

The candidate summaries are walked with `chunkById` in
`statement_summaries.id` order and promoted a chunk at a time, one
`insertOrIgnore` per chunk. `statement_summaries` gains a row per
imported statement and never loses one, so neither the read nor the
write may be sized by how many the user has.

Two entry points exist for the same query. `upsertForImportRun()`
narrows to one import and runs from `ConfirmImport` after the import
transaction commits. `upsertForUser()` drops that predicate and runs as
the healing pass at the top of `ResolveChainLinksJob`, catching up
installs whose per-import upsert never happened.

## The transitions

`applySettlement(int $statementId, int $deltaMinor, User $user)` takes
a **positive** settlement magnitude and subtracts it:

```
newOpen = prevOpen - deltaMinor
```

The new state is the first of these that matches, in order:

1. `abs(newOpen) <= 1` → `settled`
2. `newOpen < -1` → `overpaid`
3. `newOpen > 0` and `prevOpen > newOpen` → `partially_settled`
4. otherwise → the previous state, unchanged

The one-minor-unit tolerance in arms 1 and 2 is not cosmetic. SQLite's
decimal rounding plus the EUR-only rounding step in the ICS PDF adapter
can leave a one-cent residual on a statement that is, in every sense
the user cares about, fully paid. Without the tolerance those
statements would sit at `partially_settled` forever.

Arm 4 is the guard against going backwards: a delta that leaves
`newOpen` unchanged or larger than `prevOpen` does not demote a
statement, so a replayed or negative settlement is inert rather than
destructive.

The read, the arithmetic and the write all happen inside one
`$connection->transaction()`, which opens with
`PRAGMA busy_timeout = 5000`. Laravel opens SQLite transactions in
DEFERRED mode, so the write fence is not taken at `BEGIN`; the pragma
asks SQLite to wait up to five seconds for a competing writer instead
of raising `SQLITE_BUSY` on first contention.

An unknown statement id raises
`Modules\Chains\Internal\Exceptions\CardStatementNotFoundException`
rather than returning a null settlement — a settlement against a
statement that is not there is a resolver bug, not a normal outcome.
The call returns a `StatementSettlement` DTO carrying the statement id,
the previous and new open balances, and the new state.

## Who calls it

`Modules\Chains\Internal\Resolvers\IcsSettlementResolver` is the only
caller. When an ASN-side bulk settlement matches a statement within
tolerance and covers at least one expense, the resolver writes the
per-expense `chain_links` rows and then hands `applySettlement()` the
transfer's magnitude **plus whatever credits were carried into that
statement** — the tolerance test above has already spent them, so told
the payment alone the machine left a statement nobody owed anything on
reading `partially_settled` forever. A settlement that covers no expense is left
alone entirely — there would be no link recording that it was applied,
and the next pass would apply it again. If the
resulting state is `overpaid`, it writes a `card_statement_credits` row
for the surplus. The matching algorithm — the tolerance arms, the
period window and the sign convention of the delta — is described in
[chain resolution](../../architecture/chain-resolution.md).

The `noCardStatementStateWritesOutsideMachine` arch invariant keeps
this the only mutator: no other file may write `card_statements.state`.

## Credits between statements

`card_statement_credits` is the carry-forward ledger. A row is a
`(from_statement_id, to_statement_id, amount_minor, currency, reason)`
tuple whose `currency` is the one the source statement is denominated
in, and `reason` is restricted by its own trigger pair to exactly two
values:

- **`overpayment`** — the surplus left when a settlement overshoots.
  Written by the resolver's main pass at the moment the state machine
  returns `overpaid`, with a NULL `to_statement_id`: the statement that
  will absorb the surplus has not been imported yet.
  `IcsSettlementResolver::attachDanglingCredits()` runs ahead of the main
  pass on every later run and closes that pointer onto the earliest
  `open`/`partially_settled` statement on the same account, in the
  credit's own currency, whose period starts after the source period
  ended.
- **`refund_after_close`** — a refund that posted inside a statement
  period that had already reached `settled` or `overpaid`. Written by
  the resolver's second pass, which also chains the refund back to the
  original purchase. This row's `to_statement_id` points at the next
  `open`/`partially_settled` statement on the same account, so the
  credit lands where the money actually reduces what the user owes.

`from_statement_id` cascades on delete — a credit that lost its source
statement has no meaning. `to_statement_id` is nullable and
`nullOnDelete`, because a surplus can exist before the next statement
period rolls in.

There are two consumers. `IcsSettlementResolver::priorCreditsMinor()`, which
sums `amount_minor` over the credits whose `to_statement_id` is the
statement being settled **and whose `currency` is the statement's own**,
and adds that sum to what the payment covered when computing the
unaccounted delta. The currency predicate is load-bearing: a USD 20
credit summed into a EUR 500 statement pushed a fully-paid statement
back out of tolerance and left it `open`. A credit whose `to_statement_id`
is still NULL is invisible to that sum, which is why the carry-forward
pass picks a destination in the credit's own currency: a surplus pointed
at a statement denominated in another money would be spent on nothing
and never reconsidered.

The second is `CardStatementQuery::payableMinor()`, which states what
will actually leave the bank account. It draws the same two lines — the
statement's own currency, and a destination already chosen — and
subtracts the sum from `open_balance_minor`, floored at zero. Reading the
open balance raw contradicted the resolver on the same row: the machine
is handed payment **plus** credits and lands on zero, so a statement of
EUR 500.00 carrying a EUR 75.00 credit is settled by a EUR 425.00
payment, while the projection deducted EUR 500.00 from the account and
waited for a settlement no pass would ever match.

Every reader of that figure goes through it. `nextSettlementForUser()`
and `forecastTileForUser()` are one row read and one amount, differing
only in that the first also names the funder account and answers null
where there is none. `ThisPeriodAtAGlanceQuery::nextIcsSettlement()`
composed the tile from a query of its own and deducted nothing, so the
position tile and the forecast highlights quoted one statement at two
amounts on the same due date; it now calls `forecastTileForUser()`. The
`Public/` seam is what makes that reachable from Ledger without touching
this module's `Internal/`.

Which account it names is read from `chain_links` first — the payer of the
last settlement on that card. With no settlement yet linked it falls back to
the reader's first account that is not itself an `ics_card`, the same line
`IcsSettlementResolver::candidateTransferIds()` draws on the write side.
Pinned to `kind = 'bank'` instead, it returned nothing at all for a reader
paying their card from a PayPal balance or a cash account — no tile, no
overdue banner, on a statement that was open.

## Where to look in the code

- `Modules/Chains/Internal/CardStatementStateMachine.php` — the transitions.
- `Modules/Chains/Internal/Services/CardStatementUpserter.php` — row creation.
- `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` — the only caller.
- `Modules/Chains/Models/CardStatement.php`,
  `Modules/Chains/Models/CardStatementCredit.php` — the Eloquent surface.
- `Modules/Chains/Public/Services/CardStatementQuery.php` — the
  next-settlement and forecast-tile reads.
- [`chains` module architecture](architecture.md) — where this sits in the module.
