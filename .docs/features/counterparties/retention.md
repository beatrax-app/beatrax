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

One thing, and it is a decision the reader makes by hand: merging two
merchant aliases. The alias's friendly name is what the resolver slugs a
counterparty from, so renaming it leaves the names that used to own a row
owning none, and the next import mints a second row beside the history.
`Counterparties\Internal\Actions\MergeCounterparties` folds them instead
— it repoints the absorbed row's transactions onto the survivor, renames
the survivor to the merged name so the next import lands on it, and then
removes the row the reader merged away. Nothing on a timer still prunes,
and nothing deletes a transaction.

`Categorization`'s `DeactivateRulesOnReferentDelete` keeps its
`EntityMutated` arm for the same delete, and for a peer running an older
build that still prunes: both arrive as an op-log row which fires no
Eloquent model event. A rule whose action names the arriving id is
switched off rather than repointed — a rule is an instruction, not
history, and a merge is not evidence the reader meant it to follow the
survivor; see
[categorization architecture](../categorization/architecture.md#app-level-referential-integrity).

### What travels with a merge, and what does not

`counterparty_id` lives in three tables plus one scratch one, and none of
them is reached by a cascade — `transactions.counterparty_id` carries no
foreign key at all, deliberately, so history is never cascaded away.

| Column | Travels | Why |
|---|---|---|
| `transactions.counterparty_id` | yes | the history itself; one `Set` op per row, or the move never reaches the paired device |
| `anomaly_suppression_rules.counterparty_id` | yes | the foreign key is `nullOnDelete`, and a null there matches *every* merchant, so leaving it would widen one merchant's mute over the whole ledger |
| `migration_source_map.beatrax_id` (rows whose `beatrax_entity_type` is `counterparty`) | yes | `SourceMapWriter` reads an existing mapping back rather than resolving again, so a stale row hands a removed id to the next re-import |
| `rule_actions.payload.counterparty_id` | no | the delete announcement already reaches `DeactivateRulesOnReferentDelete`, which switches the rule off |
| `migration_staging_payees.resolved_counterparty_id` | no | per-run scratch, not synced, and rewritten from the freshly resolved id by the next promotion |

`transactions.counterparty_name` is **not** rewritten: it is what the bank
called the row, not what the app calls the counterparty. That is what lets
a reader see which lines moved after a merge, and it is why the fold owes
the search index nothing — `transaction_search_docs.search_body` is
composed from `counterparty_name` and `description`, and the fold changes
neither.

## Related

- [Module architecture](architecture.md) — the module surface map.
- [Resolution chain](resolution-chain.md) — how these rows are created.
- [Triage suggestions](triage-suggestions.md) — accepting a suggestion
  writes `merchant_name`.
