# A mutation a keyless process cannot sign

Sync was the first thing found behind the app-lock wall
([background-sync-cannot-hold-the-key](../mobile/background-sync-cannot-hold-the-key.md)),
and change capture was the second. This page is about the second.

## What was measured

`storage/logs/laravel.log` on a paired desktop held **4,925** lines reading
`no writer available; skipped` — 3,484 from `EntityMutated`, 718 notification,
163 goal, 138 split, 132 saved report, 88 goal contribution, 33 envelope
assignment, 5 notification preference. Every one of those is a mutation that was
never written to `op_log_entries` and therefore reached no peer, ever.

One of them, followed end to end across two real devices:

- `routes/console.php` schedules `recurring:detect` daily. It fired at
  **2026-09-04 00:00:15**.
- `Modules\Recurring\Internal\Detectors\SeriesRefresher::refresh()` moved
  `recurring_series.billing_day` on **six** rows, and dispatched `EntityMutated`
  for each. The capture site is correct.
- `op_log_entries` held **zero** `set` ops for those primary keys.
- The paired iPhone still read `billing_day = NULL` with the stale `updated_at`.
  The desktop read 7 / 11 / 15 / 1 / 3 / 5.

So the capture sites were right and the writer was the thing that was missing.

## Why the writer is missing, and why that stays true

`OpLogWriterFactory::forCurrentUser()` throws `BindingResolutionException` when
there is no authenticated user, or when `DeviceIdentityLoader::load()` hands back
null. That loader opens a **sealed** key-file using the app-lock KEK, and the KEK
lives in the session. A scheduled command, `sync:serve`, and a queue worker are
each a cold-started process with a session of its own and nothing in it. None of
them can ever open that file, and the whole point of sealing it is that they
cannot: a device that can sign while locked is a device whose ledger is readable
while locked.

Making credentials reachable from a console is therefore not on the table. Nor is
holding the refused write until a key turns up — a rendered value parked in a
pending table is the plaintext the seal exists to prevent, one table over. That is
the same argument
[the notification passes](../mobile/background-sync-cannot-hold-the-key.md#re-deriving-not-buffering)
settled, and it settles the same way here.

## Coordinates, not values

`deferred_op_captures` records **where** a change landed and never what it was:

| Column | What it holds |
|---|---|
| `user_id`, `table_name`, `pk`, `field` | the coordinate |
| `op_kind` | `create`, `set`, `increment` or `delete` |
| `delta` | the g_counter case only, argued below |
| `captured_at` | when the keyless process asked |

Nothing sealed is copied. `op_log_entries` already carries `table_name`, `pk` and
`field` in the clear — only `value` is encrypted — so this table discloses
nothing the op log does not disclose already, and it is
`SensitiveFieldRegistry::columns()` that decides which of those values is sealed.

The `delta` is the one exception and it is a real one: a `g_counter` column stores
the total merged across **every** device, so this device's own contribution is
unrecoverable from the row the moment it lands there. `merchant_memories.
occurrence_count` is the single field in that class today, it is not on the
sensitive list, and what is kept is a count of occurrences. Deferred increments
accumulate rather than coalescing, or a locked week of categorising counts once.

### One entry per coordinate

The unique index is `(user_id, table_name, pk, field, op_kind)`. A locked device
that touches one field a thousand times owes its peer one op, and the drain's
cost has to follow the number of rows changed rather than the number of writes.
Insertion order is kept, because it is capture order: a row's create has to reach
a peer before the sets that followed it.

### The queue is bounded

At `DeferredOpCaptures::MAX_PENDING_ENTRIES` coordinates the queue stops paying
for itself, and further mutations are **not** dropped: `BackfillProgress::open()`
is called instead, so the device owes a whole-database walk that
[the pre-sync capture](pre-sync-history-capture.md) already knows how to run in
slices off a request tail. Past that size the walk is the cheaper description of
what this device owes, and it reaches the same peer state.

## The drain

`DeferredOpCaptureDrain` runs on the first request that **can** build a writer. For
each coordinate it re-reads the row's current value and emits the op normally, so
signing and field encryption happen exactly where they always did.

- **The HLC is stamped at drain time, not capture time.** A drained op therefore
  means "this device's current truth, announced late" rather than a replay of a
  value that has since moved on. If the reader edited the row between the locked
  capture and the unlock, the op carries what they typed — announcing the captured
  value would undo their own edit.
- **A sealed column is decrypted on the way out.** Columns on the sensitive list
  are ciphertext at rest and `OpLogWriter` seals what it is handed under
  associated data of its own; passing the stored bytes through would wrap a second
  layer round the first, and the peer that unwrapped the outer one would project
  the inner base64 as a note. `StoredRowPlaintext` is the one reader both this and
  `OpLogBackfiller` use, so there is no second copy of that rule to rot.
- **Columns the registry keeps off the wire are dropped**, not read. `users` mixes
  the reader's settings with this device's own password and theme.
- **A create is emitted before the sets for its row**, whatever order the
  coordinates come back in.
- **A create is dropped where this device has already announced one for the
  row.** The pre-sync walk reads the table and does not know a coordinate is
  pending, so a row created while locked can be announced by the walk before the
  drain ever gets a key. Only a column that earlier create did not carry is
  still owed, and it goes as a `Set`: [announced once, whichever announcer
  arrives first](pre-sync-history-capture.md#announced-once-whichever-announcer-arrives-first).
- **A create or set whose row is gone is dropped.** A later delete superseded it,
  and announcing it would resurrect on the peer a row this device no longer has.
  A `delete` is the one kind that needs no row: the tombstone *is* the fact.
- **A coordinate is retired in the same transaction as the op it produced**, so
  the two cannot disagree about what was announced.

### The driver is a request, because nothing else holds the key

`Modules\Sync\Internal\Http\Middleware\DrainsDeferredOpCaptures` is an
`AfterResponseMiddleware` on the `web` group of **both** roots. After the
response, never in front of it: the unlock is the first request that can run any
of this and also the one interaction that has to feel instant.

The cost of a request with nothing owed is one covered index read — which is the
resting state of every device that is not locked for long. Nothing is written, no
writer is built, and no row is touched. This is the same shape and the same
argument as [`ResumesPreSyncCapture`](pre-sync-history-capture.md#the-driver-is-a-request-because-nothing-else-holds-the-key)
and `CarriesPendingPairingFrames`, which sit beside it in the same stack.

"After the response" is true of the desktop and self-hosted roots and **false of
the phone**, where the runtime terminates before it returns the response and the
shell has nothing to draw until it does. Measured: 12,563 ms for `/data-devices`
on a Galaxy A51 against 249 ms with the queue empty. So the tick is bounded by
`ResponseTailBudget` as well as by `BATCH`: one row's coordinates are always
replayed, then the budget decides whether a second row's are. A queue too large
for one request is finished by the requests after it, in the same capture order,
and [the argument for why nothing is lost or doubled](../mobile/a-tail-the-reader-waits-for.md)
is on its own page.

## Why a reader who never enabled sync defers nothing

`OpCaptureSinkFactory` asks `DeviceSyncStanding`, which reads the key-file and
the `device_registry` self row together. Neither alone. `NeverEnabled` — no
key-file and no self row — means this reader has never switched sync on: they
owe no peer anything, and switching sync on captures their whole database in one
walk. Those mutations are discarded exactly as before, and `SyncOffOpSink` says
so at debug level. Deferring them instead would fill a table on every install
that only ever runs on one machine.

**The file alone was the wrong question, and it cost a device everything it
wrote.** A restored database brings the old machine's self row and can never
bring its key-file, so `exists()` answered false on a device a peer was already
syncing with. Every write went to `SyncOffOpSink` — at debug, forever — while
the settings screen read the same registry row and said sync was enabled.
Nothing rescues those: a peer already holds the rows, so the backfill that
repairs a never-synced device fills only columns the receiver never had, and an
edit or a delete made in that window reaches nobody. See [a self row and no
key-file is a restored
database](device-identity-key-files.md#a-self-row-and-no-key-file-is-a-restored-database)
for the repair the reader is offered.

That leaves the four states that DO defer: no authenticated user (a console),
`Locked`, `Unreadable`, and a self row whose key-file never travelled.

### The standing is the reader's, not the install's

Both halves of the standing are keyed on a user: the key-file is
`sync/identity/{user_id}.enc` and `DeviceRegistryService::localDeviceId()` takes
a user id. So two members of one household on one machine can hold two different
standings, and on a shared desktop they usually do — one has paired a phone, the
other has never opened the sync screen.

Every write the second member makes therefore goes to `SyncOffOpSink` on a
device that is, by any other reading, fully synced. That is correct, and it is
also the single most convincing false positive this seam produces. Measured on a
live desktop: `recurring_series` held 399 ops and `notifications` 275, and
alongside them six series rows, fourteen occurrence rows and five notifications
with **no op at all** — every one of them the second reader's, written by the
same midnight pass that captured the first reader's rows one second earlier.

Three reads tell it apart from a lost write, in this order:

1. **Whose row is it?** `select user_id from <table> where id = …`. A row owned
   by a reader other than the one whose ops you counted is not missing anything.
2. **Has that reader ever paired?** `select count(*) from device_registry where
   user_id = …`, plus `sync/identity/{that id}.enc` under the app data path.
   Neither present is `NeverEnabled`, and `NeverEnabled` owes nothing.
3. **What did the sink say?** The `SyncOffOpSink` debug line carries `user_id`
   beside `table` and `pk`. It used to carry only the last two and to blame
   "this device", which is the wrong subject for a per-reader decision and sent
   one investigation looking for a capture defect that was not there.

Nothing is lost by any of this. When that reader does enable sync, the pre-sync
walk covers every table in `MergeRulesRegistry` — these three among them — and
announces the rows that were written while they owed nobody.

### A `system_alerts` row with no owner is a fourth answer again

`system_alerts` is two tables wearing one name, and only one of them travels.
`SystemAlertWriter::raiseOnceSystemWide()` writes `user_id` null and dispatches
nothing: the row is about the machine that noticed — a corrupt backup is that
laptop's — and the peer raises its own copy from its own probes under its own
id. So a machine-local alert has no op for the same reason it has no owner, and
counting ops per pk over that table finds a hole in it on every healthy install.
`raiseForUser()` and `raiseDerivedForUser()` are the two that do announce.

## A fourth state: signed, and not sealable

The three above are all "the writer could not be built". There is a fourth where
it can: the identity key-file opens and the **keyring** does not, which is what a
re-wrap under a new KEK leaves behind if it stops part-way. `OpLogWriter` is
built, signs happily, and reaches a column the registry calls sensitive with no
epoch key in reach.

It used to write the plaintext, with a null epoch and no log line — the one
thing this page's own argument forbids: *a rendered value parked in a pending
table is the plaintext the seal exists to prevent, one table over*. An
`op_log_entries` row is that, and then it goes on the wire.

`SensitiveColumnCodec` already refuses the same write at the column, on the same
question (`GdkKeyringService::hasCurrentEpoch()` — a plain integer readable with
no key at all, which says the rows are **supposed** to be sealed). The writer now
asks it too, and defers the coordinate instead:

- Enabled and out of reach → deferred, and logged at warning.
- Never enabled → the clear value is the right one, and always was. Nothing is
  supposed to be sealed, so nothing is deferred.

A `create` defers **whole**, never column by column. A create announced without
some of its columns is a row the peer has to be told about twice, and the second
telling arrives as a `set` stamped later than the create it belongs to.

`Modules/Sync/tests/Feature/AKeylessWriterNeverPutsASealedColumnOnTheWireTest.php`
holds both halves, so a refusal cannot quietly become a refusal to write at all.

## One sink, so a path cannot be forgotten

The eleven handlers on `SyncCaptureListener` each resolved the writer themselves
and each swallowed the failure themselves. That is why fixing the one somebody
was looking at would have left ten behind.

They now write through `OpCaptureSink`, and ask `OpCaptureSinkFactory::forUser()`
which implementation they get: `OpLogWriter` when a key is in reach, a
`DeferredOpCaptureSink` when one is not, `SyncOffOpSink` when there is no peer to
owe. A handler cannot tell them apart. `report()` has lost its quiet arm along
with them: sync being off and the app being locked no longer arrive there, so
anything that still does is a mutation nobody will send, and it is logged at
error.

`Modules/Sync/tests/Feature/EveryCapturePathDefersWhatItCannotSignTest.php`
enumerates the handlers **by reflection** and fails when the live class has one
the file does not name, so a twelfth handler cannot join in silence.

## A data migration is the same wall, with a delete on the other side

A migration is a fourth keyless process, and it was the one nothing covered. It
holds no session, so `OpLogWriterFactory::forCurrentUser()` refuses it for the
reason it refuses the scheduler — and a migration that *deletes* replicated rows
has a failure the others do not: `OpLogRebuilder::deleteReplayableRows()` drops
every row the log names a create for and then replays that create, so a row
removed without a tombstone is handed straight back. `verifyRestored()` counts
what went missing and has no word for what came back, which is why the
resurrection passed its own verification.

Deferring the coordinate alone does not answer it. The peer's copy is repaid by
the drain, but this device rebuilds from its own log, and until the drain runs
that log still says "create". So `KeylessTombstone::announce()` does both: it
writes the `delete_tombstone` entry locally **and** queues the coordinate.

The entry is authored by `OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID`, not by this
device. That is the only author `OpLogEntryVerifier` admits without a signature,
and the unsigned row under this device's own id is not a shortcut — it
quarantines as `forged_signature` on the very replay it was written for.
`TransferPairCascade` writes under the same author for the same reason.

Two properties that are easy to get wrong and are pinned by test:

- **The stamp has to outrank every op on the row.** A tombstone that loses the
  delete-wins comparison to the create it answers brings the row back, so the
  clock is advanced past the row's own highest entry rather than merely read.
- **The key is the coordinate, not the coordinate plus the clock.** A migration
  is re-runnable, and keying on the HLC too would leave one tombstone per run.

## What this does not repair

`ImportSyncCapture` sits behind the same wall and is fixed differently. It
captures rows by id in a dependency order, and re-deriving that order later is
the walk — so a keyless import now opens a backfill rather than queueing
coordinates. It asks `DeviceSyncStanding` which devices are owed one: asked of
the key-file alone it answered "never enabled" for a restored database, and an
import committed there opened no walk and reached no peer at all. Its old comment said the rows "travel on the next backfill"; there
was no next backfill, because one is only opened at sync-enable and at pairing.

The same debt is owed by its other arm, and for a while only one of the two
paid it. `captureInOrder()` stops at the first table that throws — the order is
a dependency order, and children announced after their parent failed name rows
the peer's foreign keys drop — so a failure part-way leaves the parents
announced and the transactions not. That arm wrote a warning and nothing else,
which is not a channel any peer reads: an import that committed here reached a
peer never. It opens a backfill too now. `oweABackfill()` swallows its own
failure for the same reason every capture does — the reader's import is already
committed and confirmed, and a throw on the tail of that would fail a write that
landed — but it says so at error rather than passing in silence.

A coordinate whose replay keeps failing — a sealed column no epoch in this
keyring opens — is left standing rather than retired, and retried on each drain.
The rest of the batch still drains around it, so the queue shrinks to the poison
and stops there. That is deliberate: dropping it would be the silent loss this
page exists to end. `HistoryReprojector` is the surface that recovers such a row
once its epoch wrap lands.
