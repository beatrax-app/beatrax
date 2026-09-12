# A check another writer can invalidate

A read that decides whether to insert, and an insert that acts on that decision,
are two statements. Between them the row can appear. Nothing in

```php
if ($query->exists()) {
    return;
}

$connection->table('t')->insert($row);
```

holds the answer still, so the guard is a statement about the past. On SQLite
the loser does not write a duplicate — the unique index refuses it — it raises,
and the raise lands wherever the caller's error handling happens to send it.
That is the whole shape: **the defect is never the duplicate row, it is what the
loser does instead.**

Second writers are not hypothetical here. The desktop app, the sync daemon and
a queue worker are three processes over one database file; a queue job declared
`ShouldBeUniqueUntilProcessing` releases its lock the moment `handle()` begins,
so a second dispatch for the same user starts while the first is still running;
and a double-submit is one impatient reader.

## What the connection already gives you

`config/database.php` sets `transaction_mode` to `IMMEDIATE`. Laravel issues
`BEGIN IMMEDIATE TRANSACTION`, which takes the write lock at `BEGIN` rather than
at the first write, so a second writer blocks there and — once the first
commits — reads the committed row. A transaction is therefore a real fix in this
tree and not a decoration. `DEFERRED` would not be: the lock would arrive after
the read, which is exactly the window being closed.

The read-only connection beside it is deliberately `DEFERRED`. It never writes,
so taking a write lock on every read would serialise the whole application
against itself.

## Three patterns, and they are not interchangeable

| Pattern | Use it when | Why not the others |
|---|---|---|
| **Catch the constraint violation and reroute** | A unique index is the real arbiter, and the loser has a correct thing to do — converge on the winner's row, or report the clash | A transaction serialises work that did not need serialising, and costs the write lock for the whole body |
| **A transaction** | Several reads must agree with each other and with the write that follows — a maximum, a count, an existence test | A catch answers for one index; it cannot make two reads consistent with one another |
| **A conditional-update mutex** | The work is idempotent but expensive, and the point is that only one runner does it | Neither of the others stops the second runner from doing the work; they only stop it writing twice |

A fourth thing is not a pattern but an observation: where the write itself
reports what it did, there is nothing to check first. `insertOrIgnore()` returns
the number of rows it inserted — `0` when the index refused it — and that count
is taken under the same lock as the write. A pre-read asking the same question
is strictly worse than reading the return value.

## Where each one is in the tree

- **Catch and reroute** — `Modules/Tax/Public/Actions/TagTransaction.php`, which
  catches `UniqueConstraintViolationException` on `tax_transaction_tags` and
  retries as the guarded update. `ChainLinkInsertHelper::insertIfNotExists()`
  and `DeviceIdentityService` do the same against `chain_links_pair_uq` and
  `device_registry_user_device_idx`.
- **A transaction** — `TaxCategoryStore::add()` and `::seedFromCorpus()`, where
  `max(sort_order)` and an existence test both feed one insert.
  `IcsSettlementResolver` already wraps its links, statement move and credits
  together for the same reason.
- **A conditional-update mutex** — `BackfillAnomaliesJob`, which claims the
  backfill with `whereNull('anomaly_backfilled_at')->update([...])` and returns
  when the update affects no rows.
- **The write's own report** — `MobileImportIntentGate::markImporting()` and
  `SavingsInsightsQuery::dismiss()`. `ReceiptLedgerBridge` reads the same report
  for a harder question: a refused insert there is ambiguous, so it recounts and
  retries only while the recount moved — see
  [a refused receipt insert](../architecture/ingestion-pipeline.md#a-refused-receipt-insert).

## The sibling nothing refuses

Everything above turns on an index that says no. Its sibling is a read that
decides an **update** rather than an insert, and there the shape is worse in the
one way that matters: nothing refuses the second write. A value read, computed
on, and written back has no arbiter, so the loser does not raise — it wins, and
the winner's work disappears. The insert race is loud and lands in error
handling; this one is silent and lands in the data.

```php
$attempts = $cursor->reproject_attempts;          // another process reads the same
$cursor->update(['reproject_attempts' => $attempts + 1]);
```

Four of these were live in the tree at once, and none of them announced itself:
`ResolveChainLinksJob` took a count before and after and wrote the delta, so a
concurrent pass, the sync applier and a cascade delete all landed inside someone
else's arithmetic — measured at `-5`. `InitialSyncPuller` under-counted
`reproject_attempts`, which is the only record a killed re-projection leaves,
because an OOM raises a fatal rather than a `Throwable` and no `catch` runs.
`RateProviderRegistry` had two callers both read `0` and both write `1`, opening
the circuit a failure late. `StagedExportHandover` let a one-shot export token be
claimed twice.

The same three patterns apply, read for an update instead of an insert, and the
fourth observation generalises further than it first appears:

| Pattern | Reads as |
|---|---|
| **A transaction** | Claim the value inside the transaction that writes it — the deciding read and the write share one lock |
| **An atomic primitive** | `Cache::add()` then `increment()` where a lock would be too heavy; an atomic lock where the claim *is* the spend |
| **A conditional-update mutex** | Unchanged — it was always a read-then-update pattern |
| **The write's own report** | Sum what each write seam reported inserting, rather than counting the table before and after. A delta between two counts is a measurement of the whole table; a returned count is a measurement of this pass |

That last row is the one worth carrying. `ResolveChainLinksJob`'s seams already
returned their counts and the resolvers discarded them, so the honest number was
one line away at each seam while the code was measuring the table instead.

## The tell when reading a diff

A comment promising idempotence directly above a read-then-write is the reliable
signal. `MobileImportIntentGate::markImporting()` carried "a second call for the
same user is a no-op, never a duplicate row" over exactly the shape that raises
instead, and `SavingsInsightsQuery::dismiss()` explained that its pre-read
existed because `insertOrIgnore` "reports nothing" — it returns an `int`.

## Related

- [Invariants written after a shipped failure](invariants-from-shipped-failures.md)
- [Chain resolution](../features/chains/architecture.md) — both `chain_links`
  write paths and the tuple index under them
- [Device identity key files](../features/sync/device-identity-key-files.md) —
  why a second self-row is a second identity
