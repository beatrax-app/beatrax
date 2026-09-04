# Counterparty retention

Counterparties are kept for good, like every other row the reader
authored. There is no sweep that prunes them, and nothing on a timer
writes to `transactions`.

That is a change. A daily `counterparties.gc` job used to delete rows
on a 365-day window and NULL `transactions.counterparty_id` on every
ledger row that named one. This page is why it is gone.

## Why the sweep could not be made safe

The [resolution chain](resolution-chain.md) creates a counterparty for
almost every transaction it sees, including a `type = 'unknown'` row
for anything it cannot place. The sweep existed because that
accumulates one-off payees: a holiday car-hire firm, a one-time private
transfer, a mistyped IBAN.

Its orphan test asked whether any transaction had pointed at the row
within the last 365 days. That question has no safe answer on a
local-first device, because **each device holds a partial replica**.
"No transaction points at this" and "the transactions that point at
this have not arrived yet" are the same observation. The sweep could
not tell them apart, and resolved both towards deleting.

It is not hypothetical. On a paired Mac and iPhone sharing one
household ledger:

| | Mac | iPhone |
|---|---|---|
| Counterparties | 31 | 48 |
| Transactions | 35 | 140 |

The Mac had received 35 of the household's 140 transactions. Measured
against that quarter of the ledger, 17 counterparties looked
unreferenced, and the sweep deleted all 17. On the phone, 16 of those
17 were referenced — by 52 transactions between them, one payee
carrying 10. The phone still holds every row the Mac dropped.

Widening the window would not have helped, and neither would narrowing
the predicate to "no transaction references it at all": on the Mac,
none did. The defect is not the window. It is that a delete decided on
one replica is a delete of what another replica is still using.

## What the removal covered

- `Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob`
  and `CollectCounterpartyGarbageCommand`, deleted.
- The `counterparties.gc` entry in `routes/console.php` and in
  `MobileBackgroundSchedule::requiredOnDevice()`, removed. The phone ran
  this one too.
- `tests/Contracts/NoScheduledTaskPrunesUserDataArchTest.php` now walks
  every scheduled command into the jobs it dispatches and fails on a
  `->delete()` against a table of user data, or an `->update()` that sets
  a column of one back to `null`. `notifications` is the single declared
  exception, and it carries the requirement its window is written down
  in.

  The exception is asserted to be load-bearing rather than decorative: a
  second case checks that every exempted table is one the guard would
  otherwise have looked at, and that some scheduled task really does
  prune it. Deleting the exemption fails the suite instead of quietly
  widening the rule. That check exists because the first version of this
  guard exempted `notifications` from a list that never contained it —
  an exemption that read as a decision and did nothing.

  The scan reads every `->table('x')` in a file, not the first: the
  notification sweep plucks the ids in one chain and deletes them in the
  next, so a detector that stopped at the first occurrence reported it
  clean.

## What still deletes a counterparty

Nothing on this device. `Categorization`'s
`DeactivateRulesOnReferentDelete` keeps its `EntityMutated` arm because
a peer running an older build still prunes, and that delete arrives as
an op-log row which fires no Eloquent model event. A rule whose action
names the arriving id is switched off; see
[categorization architecture](../categorization/architecture.md#app-level-referential-integrity).

## Related

- [Module architecture](architecture.md) — the module surface map.
- [Resolution chain](resolution-chain.md) — how these rows are created.
- [Triage suggestions](triage-suggestions.md) — accepting a suggestion
  writes `merchant_name`.
