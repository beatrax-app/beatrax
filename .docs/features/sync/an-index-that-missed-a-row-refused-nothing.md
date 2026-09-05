# An index that missed a row refused nothing

The full-text index is refreshed as part of merge, so a row that arrives from a
peer is findable. When that refresh throws, the op has already been applied: the
row is in `transactions`, correct and complete, and the only thing that missed
it is a derived index this device keeps for itself.

## What was recorded instead

`SearchIndexRefresher` wrote a row into `op_log_quarantine`:

| Column | What it held | What that says |
|---|---|---|
| `device_id` | `system-fts` | a device in no registry, that never signed anything |
| `reason` | `strategy_error` | a merge strategy failed — none ran |
| `hlc_l`, `hlc_c` | `0`, `0` | an entry from before any clock |
| `pk` | the transaction id | a row that was applied, not refused |

Every column is a statement about something that did not happen. The
quarantine is the record of an entry the replayer **refused**; the reasons it
may carry are a closed set, and none of them is "the index missed a row",
because refusing an entry and failing to index one are different events.

## What the fabricated row then caused

`strategy_error` is one of the two reasons `QuarantineReason::keyRecoverable()`
names, and it is inside `QuarantineReason::recoverable()`. Both of those drive
`HistoryReprojector`, so one FTS hiccup produced:

- **A backlog notice with the wrong cause.** `HistoryReprojector::backlogState()`
  answers `Deferred` while any recoverable hold is worth replaying, and the
  devices screen renders that as *"This device has received data from another
  device and has not added it to your ledger yet … applied automatically,
  normally within a moment."* Nothing had been received, nothing was waiting,
  and no amount of waiting would clear it.
- **A hold nothing retires.** The insert names no `gdk_epoch`, so the column is
  null. `HistoryReprojector::replayQuarantined()` retires a key-recoverable hold
  only once the device holds the epoch that hold names; its settled sweep clears
  the two create-refusals when the row turns up and the delete-refusal when the
  row goes away; and it deletes outright the `gdk_decrypt_failed` holds this
  device authored itself. A `strategy_error` row with a null epoch, a `pk` whose
  row is sitting right there and a `device_id` of `system-fts` answers to none
  of them, so it stands for the life of the database.
- **A replay of a whole row's history, repeatedly.** A null epoch also passes the
  openable-rows filter, so every pass inside the window re-fetched and re-applied
  every op that transaction ever had, to fix an index.
- **A count in the developer console that names a device the reader cannot find.**

## What is recorded now

A `warning` on the log, naming the transaction, whether the upsert or the delete
failed, the exception class, and `search:reindex` — which is the designed
recovery tool for any disagreement between `transactions` and the index, and
therefore the answer to what gets this out.

Nothing is written to the quarantine, because nothing was refused.

## What is still missing

A stale index is not visible to anybody who does not read the log. The condition
is computable — `transaction_search_docs` holds one row per transaction, so a
transaction with no document is exactly a gap — but no surface computes it. A
requirement for that surface does not exist yet; when one does, the honest
record is a count of rows the index is missing, not an entry in the quarantine.
