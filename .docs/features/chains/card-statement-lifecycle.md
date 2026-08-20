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
| `state` | `open`, `partially_settled`, `settled`, `overpaid`. |
| `period_start` / `period_end` | The statement window. Both are required. |
| `import_run_id` | Nullable, `nullOnDelete` — a back-populated row outlives the import that produced it. |

`UNIQUE (user_id, account_id, period_start, period_end)` is what makes
creation idempotent. A `BEFORE INSERT` / `BEFORE UPDATE` trigger pair
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
`Modules\Chains\Public\Exceptions\CardStatementNotFoundException`
rather than returning a null settlement — a settlement against a
statement that is not there is a resolver bug, not a normal outcome.
The call returns a `StatementSettlement` DTO carrying the statement id,
the previous and new open balances, and the new state.

## Who calls it

`Modules\Chains\Internal\Resolvers\IcsSettlementResolver` is the only
caller. When an ASN-side bulk settlement matches a statement within
tolerance, the resolver writes the per-expense `chain_links` rows and
then hands the transfer's magnitude to `applySettlement()`. If the
resulting state is `overpaid`, it writes a `card_statement_credits` row
for the surplus. The matching algorithm — the tolerance arms, the
period window and the sign convention of the delta — is described in
[chain resolution](../../architecture/chain-resolution.md).

The `noCardStatementStateWritesOutsideMachine` arch invariant keeps
this the only mutator: no other file may write `card_statements.state`.

## Credits between statements

`card_statement_credits` is the carry-forward ledger. A row is a
`(from_statement_id, to_statement_id, amount_minor, reason)` tuple, and
`reason` is restricted by its own trigger pair to exactly two values:

- **`overpayment`** — the surplus left when a settlement overshoots.
  Written by the resolver's main pass at the moment the state machine
  returns `overpaid`.
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

The consumer is `IcsSettlementResolver::priorCreditsMinor()`, which
sums `amount_minor` over the credits whose `to_statement_id` is the
statement being settled, and subtracts that sum when computing the
unaccounted delta. A credit whose `to_statement_id` is still NULL is
therefore invisible to that sum: `overpayment` rows are written with a
NULL destination and nothing currently fills it in, so an overpayment
surplus is recorded for audit but does not yet reduce the next
statement's expected settlement.

## Where to look in the code

- `Modules/Chains/Internal/CardStatementStateMachine.php` — the transitions.
- `Modules/Chains/Internal/Services/CardStatementUpserter.php` — row creation.
- `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` — the only caller.
- `Modules/Chains/Models/CardStatement.php`,
  `Modules/Chains/Models/CardStatementCredit.php` — the Eloquent surface.
- `Modules/Chains/Public/Services/CardStatementQuery.php` — the
  next-settlement and forecast-tile reads.
- [`chains` module architecture](architecture.md) — where this sits in the module.
