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

## Why a device that never enabled sync defers nothing

`OpCaptureSinkFactory` asks `DeviceIdentityLoader::exists()` — a bare
`file_exists` on the key-file, needing no KEK, no session and no authenticated
user. False means this install has never switched sync on: it owes no peer
anything, and switching sync on captures the whole database in one walk. Those
mutations are discarded exactly as before, and `SyncOffOpSink` says so at debug
level. Deferring them instead would fill a table on every install that only ever
runs on one machine.

That leaves the three states that DO defer, which are the three the loader
already distinguishes: no authenticated user (a console), `Locked`, and
`Unreadable`.

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

## What this does not repair

`ImportSyncCapture` sits behind the same wall and is fixed differently. It
captures rows by id in a dependency order, and re-deriving that order later is
the walk — so a keyless import now opens a backfill rather than queueing
coordinates. Its old comment said the rows "travel on the next backfill"; there
was no next backfill, because one is only opened at sync-enable and at pairing.

A coordinate whose replay keeps failing — a sealed column no epoch in this
keyring opens — is left standing rather than retired, and retried on each drain.
The rest of the batch still drains around it, so the queue shrinks to the poison
and stops there. That is deliberate: dropping it would be the silent loss this
page exists to end. `HistoryReprojector` is the surface that recovers such a row
once its epoch wrap lands.
