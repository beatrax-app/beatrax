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

## One pass, three buckets

After verification and HLC sorting, `OpLogEntryApplier::partitionByOpType()` makes a single
pass into three maps:

| Bucket | Keyed by | Holds |
| --- | --- | --- |
| `tombstones` | `[table][pk]` | the winning `DELETE_TOMBSTONE` |
| `creates` | `[table][pk][field]` | `CREATE_ROW` ops |
| `candidatesByField` | `[table][pk][field]` | `SET` ops, still HLC-sorted |

They are then applied in that order — creates, field merges, then bare tombstones — because a
tombstone for a row that also has field ops is already handled by the field-merge pass, and
applying it twice would delete a row a later create legitimately re-established.

Creates are re-ordered parents-first before insertion. HLC order says nothing about
referential order: a transaction can be written before the account it points at, and SQLite
rejects that outright. `CoveredTableOrder::insertionOrder()` supplies the topological order;
tables it does not know about keep their original position rather than being dropped.

## Delete wins ties

`tombstoneWins()` compares the tombstone's HLC against the **highest** field HLC for that row
and returns true on `>=`. The `=` is the interesting half: an exact tie resolves in favour of
the delete.

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
  ops therefore yields the same sum. `OpLogWriter::writeIncrement()` upholds that by reading
  back the highest total *this* device has already published and adding the delta to it —
  emitting the merged column value instead would re-count every other device's contribution
  as this device's own.
- **`or_set`** — for set-valued fields such as `merchant_aliases.merged_from`. Each entry
  carries `{added: [{v, tag}], removed: [tag]}`; an element is live when its tag was added and
  never removed. Remove wins on tag identity, so a concurrent add and remove of the *same*
  element (different tags) keeps the add.

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
3. Is there a key for the device id — in the confirmed map, or failing that in
   `DeviceRegistryService::retainedDeviceKeys()`, which also covers a device the user has
   REMOVED? If not, is this exact entry (identity **and** signature) already in
   `op_log_entries`? Only then is it `missing_device_key`.
4. Does the Ed25519 signature verify? (`forged_signature`)
5. Is the field a real column of that table? (`unknown_column`)

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

`OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID` (`'system-cascade'`) and the FTS equivalent are
device ids the replayer produces itself. They carry no Ed25519 key and no signature, because
they are deterministically re-derived on every replay — incremental and rebuild alike — from
entries that were themselves verified. They are trusted by construction, and they keep the
cross-user check that the Ed25519 gate would otherwise have covered.

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
  swallowed. The tombstone is simply not applied yet; it re-applies once the children are
  gone, which beats aborting every other op.

## What runs inside the transaction, and what cannot

The three apply passes run inside one `transaction()` scoped to `$userId`. Two things are
*collected* inside it by reference and *consumed* after it commits:

- **Transfer-pair cascades**, because the reclassification has to see the pair link already
  nulled by the committed delete.
- **Full-text index refreshes**, because FTS5 shadow-table writes cannot run inside a
  transaction that also touches the base table.

Search freshness can therefore never fail a replay. Each index call is individually guarded
and routed to quarantine on failure — a stale index recovers on the next write, a
half-applied replay does not.

`OpLogRebuilder` runs the same production replayer, injected without a search writer so FTS
writes are suppressed inside its transaction, and re-indexes afterwards. Skipping that second
step left every rebuilt row without a search document, and search quietly stopped finding
real transactions.

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
