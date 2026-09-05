# How a replay decides what the row should say

Two devices edit the same budget while offline. Both come back online. The op-log holds every
field-level write either of them made, in no particular order, signed by whichever device
wrote it. `OpLogReplayer::replay()` has to turn that into one row that both devices agree
on — and agree on again after a full rebuild, months later, from the same entries.

Wall-clock timestamps cannot do this. Two devices' clocks disagree, so the "later" write is
whichever device's clock happened to be ahead, and a clock correction changes the answer
retroactively. A per-device counter cannot do it either: counters from different devices are
incomparable.

## The total order

Every entry carries a hybrid logical clock: `hlc_l` (the highest physical millisecond this
device has seen, its own or a peer's) and `hlc_c` (a counter that breaks ties within the same
millisecond). `HybridLogicalClock::compare()` orders by `l`, then `c`, then `strcmp` on the
device id.

The device-id tiebreak is what makes the order *total* rather than merely partial. Without
it, two entries with identical `l` and `c` sort arbitrarily, and `usort` is not stable — so
the same input could produce different output on two devices, or on the same device twice.

`receive()` implements the four-branch Kulkarni–Demirbas update, taking
`l = max(wall clock, local l, message l)` and then choosing the counter according to which of
the three won. The practical effect is that hearing from a peer with a fast clock drags this
device's `l` forward, so a subsequent local write sorts after the peer's — not before it,
which is what a naive wall clock would have produced.

Clock state is persisted per `(user_id, device_id)` in `hlc_clock_state` and restored in the
`OpLogWriter` constructor, so a restart cannot rewind the clock: the next `tick()` starts
from `max(wall clock, last_l)`.

`receive()` runs in `Internal\Clock\RemoteClockAdvance`, called by
`OpLogReplayer::replay()` with every verified entry and persisting the result to the same
row. That persistence IS the wiring: `OpLogWriter` is bound transient, so it restores the
advanced clock on the next resolve. Feeding `receive()` only this device's own stored state
— the constructor call above, and nothing else — made the whole mechanism dead, and the fast
peer above then won every subsequent merge instead of only the first.

## Values are always JSON, and `NULL` is a sentinel

Every op-log `value` is `json_encode`d by the producer, without exception. The decoder is an
unconditional `json_decode` with no raw-string fallback.

The single carve-out is PHP `null`, which is written as SQL `NULL` and means *tombstone or
clear*. It is never `json_encode(null)` — that would store the four-character string `"null"`,
and the decoder would hand back the PHP string `"null"` where the sentinel was meant. The
asymmetry is deliberate and it is the reason the encoder has an explicit `!== null` branch on
every write path.

The consequence for anything touching the wire: a value that decodes to the string `"null"`
is a legitimate string value, not a cleared field. Only SQL `NULL` clears.

## A batch is not the set a strategy resolves over

`SyncSession::receiveOps()` is called once per *frame*, and `TransportFramer` caps a frame at
1024 ops or 64 KB — so a real history arrives as dozens of separate `replay()` calls. Nothing
about that boundary is semantic. It falls wherever the packer ran out of bytes.

Every strategy resolves over the list it is handed, so for as long as that list was the frame,
the frame *was* the truth. A laptop edit at HLC 2000 already applied and already in the log,
followed by a phone's offline edit at HLC 1000 arriving in a frame of its own, resolved to the
phone's: the batch held no other candidate, so the oldest op in the log won. The same shape
reset a G-Counter to the newest frame's single contribution, dropped every OR-Set element added
in an earlier frame, and let a bare tombstone from HLC 1000 delete a row a HLC 3000 edit had
kept — `tombstoneWins()` had nothing in the frame to lose to.

`RowHistoryRehydration::augment()` closes it. `OpLogEntryVerifier` persists every accepted entry
to `op_log_entries` **before** the merge runs, so by the time the applier is reached the durable
log already holds both the arriving ops and every op this device ever accepted for the same
rows. The rehydration reads them back for the `(table, pk)` pairs the batch names, de-duplicates
against the batch on the log's own unique key, and hands the applier a set that is complete per
row. The frame decides *which rows* are re-resolved; it no longer decides *what they say*.

Entries read back this way are decrypted and nothing else: `op_log_entries` only ever holds
entries that already passed the signature and cross-user gates, and re-verifying a row's whole
history on every frame would pay for the same Ed25519 check thousands of times. One that will
not decrypt is skipped rather than quarantined — it already has its audit row from the pass that
first refused it, and quarantine writes are plain inserts, so recording it again on every frame
that touched the row would grow the table without bound.

`RowHistoryPolicy::AsGiven` opts out, and exactly two callers may use it: `OpLogRebuilder`,
which passes the whole log, and `HistoryReprojector::replayQuarantined()`, whose
`PersistedOpLogEntries::forRows()` has already fetched every op of every row it names. For
anything else the boundary is arbitrary, and an arbitrary boundary is not a merge boundary.

Buffering frames until `CATCH_UP_COMPLETE` would not have fixed this. The live loop has no such
marker, and two ops of one row can land in two separate *sessions* days apart — the durable log
is the only set with no boundary in it at all.

## One pass, three buckets

After verification and HLC sorting, `OpLogEntryApplier::partitionByOpType()` makes a single
pass into three maps:

| Bucket | Keyed by | Holds |
| --- | --- | --- |
| `tombstones` | `[table][pk]` | the winning `DELETE_TOMBSTONE` |
| `creates` | `[table][pk][field]` | `CREATE_ROW` ops |
| `candidatesByField` | `[table][pk][field]` | `SET` ops, still HLC-sorted |

They are then applied in that order — creates, field merges, then the deletions both of the
later passes collected — because a tombstone for a row that also has field ops is decided by
the field-merge pass, and applying it twice would delete a row a later create legitimately
re-established.

Creates are re-ordered parents-first before insertion. HLC order says nothing about
referential order: a transaction can be written before the account it points at, and SQLite
rejects that outright. `CoveredTableOrder::insertionOrder()` supplies the topological order;
tables it does not know about keep their original position rather than being dropped.

### Deletions are one pass, children first

Neither the field-merge pass nor the bare-tombstone pass deletes anything itself: each records
what it decided into one `pendingDeletes` map, and `applyDeletions()` runs it in
`CoveredTableOrder::deletionOrder()` — `insertionOrder()` reversed, so the same live foreign
keys that order the inserts order the deletes.

Deleting in whatever order HLC left the tables in is not a cosmetic problem. `import_runs` and
`categories` hold `ON DELETE NO ACTION` children, so a parent tombstone that happens to sort
first is refused outright by SQLite. Ordering within each pass would not have been enough
either: the two passes ran one after the other, so a parent decided in the first and its child
in the second were still parent-first.

A delete the database still refuses after the whole pass is retried once — a cycle the
topological order had to break can leave a child the first attempt had not reached. Refused
again, it is recorded as `delete_blocked_by_reference` and logged, because the only rows that
reach that point are ones this device holds and no op deletes: the two devices now disagree
about a row, and that has to be visible on the sync-health screen rather than swallowed.

## Delete wins ties

`tombstoneWins()` compares the tombstone's HLC against the **highest** field HLC for that row
and returns true on `>=`. The `=` is the interesting half: an exact tie resolves in favour of
the delete.

"The highest field HLC for that row" means every field op the durable log holds for it, which
is what the rehydration above supplies. Compared against one frame's worth, a bare tombstone
from HLC 1000 arriving after an HLC 3000 edit had nothing left to lose to, and deleted a row
the total order says survives.

Any rule works as long as both devices apply the same one, but `>=` avoids the shape where a
row is resurrected by a concurrent field write it never semantically survived. Both the
create-shadow check and the field-merge delete path call this same function, so the two
cannot drift.

## The three merge strategies

`MergeRulesRegistry` maps `(table, field)` to a strategy; anything unmapped is last-write-wins.

- **`lww`** — take the last entry in HLC order and decode it. The default.
- **`g_counter`** — for fields like `merchant_memories.occurrence_count` where each device
  counts independently. The result is `sum(max(value) per device_id)`. This converges only
  because each device publishes its own **running total**, not a delta: re-replaying the same
  ops therefore yields the same sum. It converges over the **whole** set and no subset of it —
  resolve a frame holding one device's ops and the answer is that device's total alone, which
  is what the row-history rehydration above exists to prevent. `OpLogWriter::writeIncrement()` upholds that by reading
  back the highest total *this* device has already published and adding the delta to it —
  emitting the merged column value instead would re-count every other device's contribution
  as this device's own.
- **`or_set`** — for set-valued fields such as `merchant_aliases.merged_from`. Each entry
  carries `{added: [{v, tag}], removed: [tag]}`; an element is live when its tag was added and
  never removed. Remove wins on tag identity, so a concurrent add and remove of the *same*
  element (different tags) keeps the add. Like the G-Counter it is a whole-set fold: a frame
  carrying only the newest add resolves to a set of exactly one element.

A malformed value in either non-LWW strategy throws a typed `UnexpectedValueException` rather
than coercing. A non-integer G-Counter value silently coerced to `0` would *lower* a device's
contribution and break convergence with no signal at all.

An OR-Set resolves to a `list<array{v, tag}>`, which a query-builder bind cannot accept
("Array to string conversion"). `OpLogValueProjector::encodeColumnValue()` therefore
JSON-encodes any non-scalar, non-null result before it reaches a column.

## Nothing rejected reaches `op_log_entries`

`OpLogEntryVerifier` runs before any merge, and its ordering matters:

1. Is the table registered in the merge rules? (`unknown_table`)
2. Is this a system op? (See [System ops](#system-ops-bypass-the-signature-gate).)
3. Is there a key for the device id in the **confirmed** map? If so, step 4 decides it.
4. Otherwise: is this exact entry (identity **and** signature) already in `op_log_entries`?
   Only an entry the durable log already holds may be verified against the key
   `DeviceRegistryService::retainedDeviceKeys()` keeps for a device the user has REMOVED, and
   only such an entry is accepted when no key remains at all. An entry the log does not hold
   is `unconfirmed_device` when the registry still has the author's key and
   `missing_device_key` when it does not.
5. Does the Ed25519 signature verify, against whichever of those two keys applied?
   (`forged_signature`)
6. Is the field a real column of that table? (`unknown_column`)

Only entries that pass are written to `op_log_entries` — and they are written **before** any
decryption, so the durable log keeps the ciphertext exactly as the peer sent it. Everything
else goes to `op_log_quarantine` and nowhere else. Quarantine writes are best-effort and
never propagate: a replay must continue whether or not the audit row lands.

Membership is proved by the *device*, not by the entry's `user_id`. `user_id` is a per-device
autoincrement surrogate — the same household account is user 3 on the desktop and user 1 on
the phone — so comparing it rejected every op a paired peer ever sent. Verified entries are
re-scoped onto the local user id via `withUserId()`, which records the original in
`origin_user_id` so a signature produced under the older v1 payload shape (which covered
`user_id`) can still be re-verified afterwards.

Before that column existed, the re-scope destroyed the signature of every entry it touched,
and the failure was invisible for as long as it mattered least. Live sync verifies *before*
re-scoping, so it passed. Only the later re-verification — the rebuild that re-projection
runs — saw a payload that no longer matched what was signed, and it quarantined the entire
log as forged. The device came back up with an empty app and a full op log. Entries written
by a device signing v2 (which excludes `user_id`) need nothing here, so the column is
nullable and old rows keep working through the null fallback.

Decryption failures fail closed to `gdk_decrypt_failed`: an unavailable keyring, a missing
epoch key and an AEAD rejection are all the same null, and all quarantine.

### System ops bypass the signature gate

`OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID` (`'system-cascade'`) is a device id the replayer
produces itself. It carries no Ed25519 key and no signature, because the op is
deterministically re-derived on every replay — incremental and rebuild alike — from entries
that were themselves verified. It is trusted by construction, and it keeps the cross-user
check that the Ed25519 gate would otherwise have covered.

There was a second one, `'system-fts'`, and it was not an op at all: it named the *author* of
a quarantine row written when the search index could not be refreshed. Nothing was refused
there, so the row is gone and the id with it — see
[an index that missed a row refused nothing](an-index-that-missed-a-row-refused-nothing.md).

The transfer-pair cascade is the reason this exists. When one leg of a transfer is
tombstoned, the surviving leg stops being a transfer and becomes plain income or expense.
That reclassification has to be recorded as a **real op**, or a rebuild would replay the
original entries and quietly revert it. The cascade op is stored at
`(tombstone hlc_l, tombstone hlc_c + 1)` — a real monotonic HLC that sorts deterministically
*after* the tombstone. An HLC of `[0, 0]` would sort first, and the rebuild would undo the
reclassification every time.

## Ownership is checked twice, on purpose

The ids a row *names* are minted per device, so an op can legitimately arrive naming a row id
that belongs to a different household member entirely.

`RowOwnership::referencesBelongToUser()` is checked once on the create payload and again on
every `SET`. Checking only the create is not enough: create a transaction against your own
account, then `SET account_id` to another member's, and the row is scoped to you while
reading their balance.

Only a referenced row that **exists and belongs to someone else** is refused. An absent
target is an ordering problem, not a cross-user one — children legitimately arrive before
their parents — and the deferral and foreign-key paths already handle it.

`parentBelongsToUser()` covers the other direction. Child tables (`rule_conditions`,
`rule_actions`) carry no `user_id` column at all, so nothing on the row itself proves
ownership; without the parent check an op could attach a condition to another user's rule
simply by naming its id. A covered table with neither a `user_id` column nor a known parent
is refused outright by `scopeToUser()` (`WHERE 1 = 0`) rather than written user-wide.

The reference list is **derived from the live foreign keys**, not hand-maintained: the
hand-written version named eleven tables while the schema had twenty-three, and nothing
failed to say so. Only two shapes cannot be derived and are listed explicitly — owner-scoped
references with no foreign key (`transactions.counterparty_id`,
`forecast_scenario_mutations.target_series_id`), and the polymorphic
`migration_source_map.beatrax_id`, whose target table is named by a sibling column. For a
single-field `SET` that carries no sibling, the type is read back from the row; with neither,
the write is refused, because a target that cannot be resolved cannot be cleared either.

`user_id` in a create payload is **ignored, not compared**. It is the origin device's
autoincrement, so rejecting a mismatch quarantined every peer row. The payload's `user_id` is
overwritten from the session after the field loop — the insert has no `WHERE` clause, so that
forced re-seed is what stops a device supplying somebody else's id from planting a row in
their namespace.

## One bad op is isolated, never fatal

Every failure mode is deliberately scoped to a single op:

- A strategy that throws, or a value the driver refuses to bind, is caught around **both** the
  encode and the write. Computing the value inside the try but running `->update()` outside it
  let a non-scalar OR-Set throw during binding and roll back the entire merge transaction.
- An insert the database refuses is caught and classified by `CreateRowInsertFailure`: a
  duplicate is the idempotent re-apply and stays silent, a `NOT NULL` violation is
  `incomplete_create_row`, an unsatisfiable foreign key is `missing_reference`. Uncaught, one
  unsatisfiable reference discarded every op replayed beside it, and the poll driving the UI
  answered 500 instead of advancing. The insert is a plain `insert()`, never `insertOrIgnore`:
  OR IGNORE silences NOT NULL as readily as it silences a duplicate, so a create whose ops
  straddled a frame boundary wrote no row, raised no quarantine and reported success.
- A delete refused by an `ON DELETE NO ACTION` foreign key (`import_runs`, `categories`) is
  isolated rather than fatal, but never silent: it quarantines as
  `delete_blocked_by_reference`. For as long as the catch block was empty, a parent survived a
  delete both devices had agreed on and nothing anywhere said so.

## What runs inside the transaction, and what cannot

The three apply passes run inside one `transaction()` scoped to `$userId`. Two things are
*collected* inside it by reference and *consumed* after it commits:

- **Transfer-pair cascades**, because the reclassification has to see the pair link already
  nulled by the committed delete.
- **Full-text index refreshes**, because FTS5 shadow-table writes cannot run inside a
  transaction that also touches the base table.

Search freshness can therefore never fail a replay. Each index call is individually guarded,
and a failure is reported as a warning naming the row, the operation and `search:reindex` —
a stale index recovers on the next write or on that rebuild, a half-applied replay does not.
It is **not** quarantined: the op it belongs to was applied, and the quarantine records what
the replayer refused.

`OpLogRebuilder` runs the same production replayer, injected without a search writer so FTS
writes are suppressed inside its transaction, and re-indexes afterwards. Skipping that second
step left every rebuilt row without a search document, and search quietly stopped finding
real transactions.

### What a rebuild reindexes

That second step then reindexed the wrong set: it plucked *every* transaction the reader
owns and upserted them one at a time. A hundred-thousand-row ledger carrying a fifty-thousand
op delta that named three thousand transactions therefore did a hundred thousand index
rebuilds, at roughly five queries each — half a million queries, **91% of everything the
whole re-projection ran**, and around a minute of it, inside a request a mobile setup screen
drives on a two-second poll.

The set it wants is the set the incremental path already uses: the rows the replay could
have changed. An op names its row, and a row no op names was not touched, so its document is
still true. `reindex()` reads the distinct `pk`s the op log names for `transactions` and
works only those, chunked so one `id IN (…)` stays inside SQLite's bind ceiling.

That chunk lookup answers a second question at the same time. A row the replay tombstoned is
gone from `transactions`, and `upsertForTransaction()` returns silently on a row it cannot
find — so a deleted transaction kept its search document and went on answering queries. Each
chunk now learns which of its ids survived, and the ones that did not are `deleteForTransaction`
rather than a silent no-op.

The other half of a rebuild's cost is the log itself. `PersistedOpLogEntries::forUser()`
streams the rows through a cursor instead of fetching them: the fetched form and the hydrated
entries used to stand at the same time, which put twice the log in memory for the length of
one query, on the device with the smallest ceiling of any that runs this.

## The tables a search document is built from

`SearchIndexWriter::upsertForTransaction()` composes one row of
`transaction_search_docs` from **two** tables, not one:

| table | what it contributes |
|---|---|
| `transactions` | `counterparty_name`, `description` |
| `tax_transaction_tags` | `note` |

A replay that marked only `transactions` rows dirty therefore left the index
stale whenever a tag arrived on its own. Measured across two paired devices:
17 of 147 bodies on the receiving device were missing their tax-note segment,
while `tax_transaction_tags` itself had synced perfectly — same 17 rows, same
ids, every note present. Search found the note on one device and silently
found nothing on the other.

`SearchDocumentRows` holds the mapping from a changed row to the document(s)
it belongs to, and two rules that are easy to get backwards:

- **Resolve before deleting.** A row that is not the transaction itself names
  one in a column; once it is gone there is nothing left to resolve it by.
- **A child row's delete rebuilds, it does not tombstone.** Deleting the
  transaction takes its document with it. Deleting a tag leaves the
  transaction behind, so its document is rebuilt without the note. Treating
  the two the same way drops a live transaction out of search entirely.

`ASearchDocumentIsRebuiltForEveryTableItReadsTest` derives the expected source
list from what `SearchIndexWriter` actually queries, so a third source table
breaks the test rather than going silently unindexed.

## Self-referential columns are written last

`transactions.pair_transaction_id` and `categories.parent_id` point at their own table.
Ordering cannot resolve them — a transfer pair references both ways, so whichever row lands
first names a row that does not exist yet. `SelfReferenceDeferral` strips those columns from
the insert, nulls them in place, and writes them back once the whole batch is in, scoped to
the same user. A target that still does not exist (its create was quarantined, or it arrives
in a later batch) leaves the column null rather than failing the row that points at it — the
link is optional by construction.

## See also

- [Replaying the op-log against live SQLite triggers](oplog-replay-under-live-triggers.md) —
  how the schema's own constraints interact with a replay.
- [Getting a group data key epoch onto every device](gdk-epoch-wrap-delivery.md) — where the
  key that decrypts a `gdk_epoch`-tagged entry comes from.
- [Sensitive columns at rest](sensitive-columns-at-rest.md).
- [Sync architecture](architecture.md).
