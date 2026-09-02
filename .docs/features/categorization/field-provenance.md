# Field provenance — what stops a rule from undoing the user's work

A user categorises a transaction by hand. Later they edit a rule, or an
import triggers a re-apply pass, and that rule matches the transaction
they just fixed. Without something to prevent it, the rule wins and the
correction is silently reverted. The user has no way to tell it happened
except by noticing the wrong category again weeks later.

Field provenance is the record of *who last set each field*, and it is
what makes a hand-made correction stick.

## The shape

`transactions.field_provenance` holds a JSON object mapping a field name
to the source that last wrote it:

    {"category_id": "manual", "note": "rule"}

The two sources in use are:

- `manual` — a person set this field directly. `AssignCategory`,
  `TransactionDetail` and the default argument of `TagTransaction::execute()`
  all stamp this. **Agreeing with a rule counts.** `AssignCategory` used to
  gate the stamp on rows affected, and `UpdateTransactionCategory` returns
  zero when the value it would write is already in the column — so a reader
  who opened the picker, saw the rule had chosen Groceries, and chose
  Groceries got no protection at all, while one who disagreed did. The
  action now separates a zero that means "the column already says this"
  from one that means "the write was refused": a missing or foreign row and
  a row locked against edits are refusals and stamp nothing, and a
  confirmation stamps `manual` and teaches merchant memory exactly as an
  override does. It raises no `TransactionMutated`, because no column moved
  and there is no value for a peer to converge on.
- `rule` — a categorisation rule set it. `RuleApplier` stamps it for the
  `category_id`, `counterparty_id` and `note` actions, and passes it
  explicitly to `TagTransaction::execute()` for the `tax_tag` action.

A field absent from the map has never been stamped. A row that has never
been stamped at all stores NULL rather than `{}`.

`FieldProvenanceWriter` (which lives in `Ledger`, because the column is
on `transactions`) is the only reader and writer.

## The rule that matters

`RuleApplier::applyAtReapply()` checks provenance once per action type,
before attempting any write:

    if (($provenance[self::PROVENANCE_KEY[$type] ?? ''] ?? null) === 'manual') {
        continue;
    }

Only the exact string `manual` blocks. A field stamped `rule` is
overwritten freely — that is what lets an edited rule correct its own
earlier output. An unstamped field is overwritten freely too.

`PROVENANCE_KEY` exists because the action type and the provenance key
are not the same vocabulary: the action types are `category`,
`counterparty`, `note` and `tax_tag`, while the stored keys are
`category_id`, `counterparty_id`, `note` and `tax_tag`. The map is the
translation, and the `?? ''` fallback means an unrecognised action type
looks up a key that cannot exist, reads as unstamped, and is allowed
through to the `match` below — which then has no arm for it and returns
null. An unknown action type is inert rather than dangerous.

Note the check is per action type, not per rule and not per row. One
`manual` field does not freeze the rest of the transaction: a
hand-corrected category with an untouched note still lets a rule write
the note.

## Stamping is additive, never a replace

    $expression = "COALESCE(field_provenance, '{}')";
    foreach ($fieldToSource as $field => $source) {
        $expression = "json_set({$expression}, ?, ?)";
        ...
    }

Each field wraps another `json_set` around the previous expression, so
the final statement sets every requested key in one UPDATE while
preserving every key already in the map. The `COALESCE(..., '{}')`
initialises a never-stamped NULL to an empty object so the first
`json_set` has something to write into.

Writing the whole map instead would be the obvious implementation and it
would be wrong: stamping `category_id` would drop an existing
`note: manual` stamp, and the next re-apply would overwrite a note the
user wrote by hand.

The UPDATE is guarded by `where id = ? and user_id = ?`. A foreign or
missing transaction id matches zero rows and the call is a silent no-op,
so provenance can never be stamped across a user boundary.

## Reads never throw

`provenanceFor()` returns `[]` for a never-stamped row, a foreign or
missing transaction id, corrupt JSON, or a payload that decodes to
something other than an object. `decodeProvenance()` catches
`JsonException` and returns `[]`.

That is deliberate and worth preserving. Provenance is best-effort audit
metadata; it is not the ledger. A corrupt map degrades to "nothing is
protected" — rules apply as if the row were fresh — rather than taking
down a re-apply run over a JSON parse error. If it were made a crash
surface, one bad row would stop the whole batch.

The trade to be aware of: corrupt provenance loses the user's manual
protection silently. That is accepted because the alternative loses the
entire run loudly, and the map is reconstructed on the next manual edit.

## It reaches the other devices

`stamp()` announces the map on a `TransactionMutated` of its own, so a field
the reader set by hand is protected on every device rather than only on the one
they typed it on. B3-R18 states the guarantee of the person, not of a device.

Three things about the announcement are deliberate:

- **It carries the merged map, read back after the UPDATE — never the delta.**
  The statement wraps `json_set` around whatever was stored, so the argument to
  `stamp()` is only the keys that moved. A peer handed those alone would drop
  every key it had not just been told about, and with it the protection the
  reader had already earned on another field.
- **It is silent when nothing moved.** The map is read before and after; a
  stamp that rewrites the value already stored announces nothing. A re-apply
  run is expected to be idempotent, and this keeps that true of the op log as
  well as of the columns.
- **It is silent when the UPDATE matched no row.** A foreign or missing
  transaction id is already a no-op through the `user_id` predicate, and
  announcing a stamp that did not happen would hand a peer a map for a row this
  device never wrote.

The column merges last-writer-wins like any other, which is worth knowing
because the value is a map: two devices stamping *different* keys of the same
transaction at the same time resolve to one of the two maps, not to their
union. That is the same best-effort posture as the corruption case above — the
loss is a protection, reconstructed on the next manual edit, not a ledger
value.

## The tax-tag exception

Every action type in `RuleApplier` stamps its own provenance and
dispatches its own `TransactionMutated` event — except `tax_tag`, which
deliberately does neither.

`TagTransaction::execute()` already dispatches an event and stamps
provenance itself. If `RuleApplier` did it too, the same change would be
reported into the op log twice, and a sync peer would see two mutations
for one user-visible edit. The `'rule'` actor string passed into
`execute()` is what tells `TagTransaction` to stamp `rule` rather than
`user`.

This is the kind of asymmetry that looks like an oversight and gets
"fixed" into a bug. It is load-bearing.

## Related pages

- [Rule evaluation order](rule-evaluation-order.md) — which rule's action
  reaches this check in the first place.
- [Re-applying rules to history](reapply-to-history.md) — the batch pass
  where provenance does most of its work.
- [`Ledger` architecture](../ledger/architecture.md) — the `transactions`
  table the column lives on.
