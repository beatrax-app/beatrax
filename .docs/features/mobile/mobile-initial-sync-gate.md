# The blocking initial-sync gate on a joined phone

A phone that has just paired with a desktop holds an account, a device
identity and an empty ledger. Everything the user expects to see — balances,
transactions, budgets, the shape of their month — lives on the other device
and has to cross the wire before any of it is true here.

The obvious approach is to let the app open and fill in behind the user.
That is wrong for a finance app in a way that is not merely cosmetic. A
half-populated balance is not "loading"; it is a *wrong number*, rendered
with the same confidence as a right one. There is no spinner that makes
"€ 1,240" honest when the remaining half of the month has not arrived yet.
So the phone does not open. `SetupProgressScreen` stands in front of the app
with no cancel, no dismiss, no back and no skip, and releases only when the
device can render the truth.

The cost of that decision is that the gate now owns every way a first sync
can go wrong, and on a phone there are a lot of them.

## Why it has to be resumable

Mobile operating systems kill backgrounded processes routinely and without
warning. A first sync that pulls a year of history is exactly the kind of
long operation that gets killed: the user starts it, switches to check
something, and comes back to a process that no longer exists.

If progress lived in memory — or in a Livewire property, which is the same
thing with extra steps — every kill would restart the transfer from zero.
Worse, a naive restart would re-apply what it had already applied.

So progress is durable. `mobile_sync_progress` holds one row per
(user, peer device) carrying `records_applied`, `records_expected`, the
hybrid-logical-clock watermark `last_hlc_l` / `last_hlc_c`, the `phase`, and
a `reprojected_at` stamp. `InitialSyncPuller` is stateless between calls: a
freshly constructed instance in a cold-started process reads that row and
carries on from it.

That `phase` is a `SyncPhase` — `pending`, `pulling`, `rebuilding`,
`complete` — and its backing values *are* the column values, so they are a
storage format rather than an internal spelling: renaming one silently
strands every row already written. A stored value no case can represent
hydrates as `pending`, so an unreadable cursor resumes the gate from the
start rather than throwing on the read.

Two consequences worth keeping:

- `SetupProgressScreen::mount()` reads the durable cursor via
  `InitialSyncPuller::progress()`, which does *not* drive a sync step. The
  very first paint after a relaunch therefore shows the true resumed
  percentage rather than flashing 0 and jumping. `isResuming` is derived
  there too, so the headline says "Resuming setup" instead of claiming this
  is a fresh start.
- De-duplication is not invented here. The puller recounts applied records
  on every step by asking the *same* watermark-scoped question the wire
  protocol itself asks (`PeerCatchUpExchanger::tallyFromAuthorAfter`), read
  against the cursor's persisted watermark rather than the device's own
  write-side clock. A watermark only ever advances, so calling `pull()`
  again over an unchanged one counts zero and advances nothing.

  The *tally*, not the delta. The count used to be taken by asking for the
  frames — `opsFromAuthorAfter`, an unbounded `get()`, every row hydrated
  into an `OpLogEntry` and packed into 64 KB frames — and then decoding all
  of them again to run `$count++`. That is O(the peer's whole history) in
  memory for a number and a watermark, and the phone's ceiling is the
  interpreter's compiled default of 128 MB, because NativePHP's Android
  shell writes a `php.ini` with no `memory_limit` in it at all. Fifty
  thousand entries — roughly 3,100 transactions, since
  `MergeRulesRegistry` lists sixteen create-required fields for
  `transactions` and `writeCreateRow()` emits one op per field — exhausted
  it. Memory exhaustion is `E_ERROR`, not a `Throwable`, so
  `SetupProgressScreen::poll()`'s `catch` could not see it; and the fatal
  landed at step 4, *before* `persistCursor()`, so the watermark never
  moved and the next tick redid the same doomed work. A permanent stall,
  every two seconds, with nothing on screen and nothing in the log.
  `COUNT(*)` and the highest `(hlc_l, hlc_c)` are both O(1) memory, and the
  same 50k delta now counts in 0.4 s at 42.5 MB — the same figure 200k
  entries costs.

One subtlety in that count: the watermark covers *this* device's writes as
well as the peer's. Counting everything after the watermark made a phone
report its own locally seeded rows as records received from a desktop it had
never reached. Only entries the resolved peer *authored* count as progress,
which is why the puller asks the single-author form of the query rather than
the general one — that one treats an author it holds no cursor for as fully
owed, and would frame every other device's history only to discard it.

## What one step actually does

`SetupProgressScreen::poll()` calls `InitialSyncPuller::pull()` once per
tick. Each call is bounded — it opens, does one thing, and returns. The
phone never runs a listener or a daemon; see
[the module architecture page](architecture.md) for why that is a platform
constraint rather than a preference.

A step, in order:

1. Load the device identity. A null identity means the step does nothing at
   all and mutates no cursor — but *which* null it is decides what the
   screen says, and folding them together is a defect this made twice.
   `DeviceIdentityLoader::load()` answers null for a locked app-lock and for
   an install where sync was never enabled alike, and the gate read both as
   "no peer". On a locked phone that is a healthy, confirmed desktop being
   reported as missing; and if the peer happened to be mid-pairing —
   `paired_at` set, `confirmed_at` not yet — `peerRevokedUs()` matched and
   the screen said *"This device was removed from your other device. Pair
   again to resume syncing."* Terminal copy, about another machine, caused
   by nothing but a lock screen. It never cleared on its own either:
   `AppLockMiddleware` allow-lists `mobile.setup`, so the gate is never
   bounced to the PIN pad and `GET /mobile/setup` keeps answering 200 with
   `wire:poll` live. The step now asks `state()` — only once `load()` has
   already answered null, so an unlocked tick pays nothing extra — and a
   `Locked` state reports `SyncBlockedReason::Locked`, whose copy already
   existed and had no producer on this path.
2. Resolve the peer: the single confirmed non-self device in
   `device_registry`. Household pairing is one-to-one, so there is exactly
   one, and multi-peer selection is deliberately out of scope.
3. Run one `MobileSyncTriggerService::syncOnce()` burst — LAN first, relay
   as fallback.
4. Recount from the watermark, advance the cursor, and decide whether the
   gate can release.

The LAN leg needs an address, and this is a real trap: the puller only
attempts LAN when handed a host and port, and at one point *every* caller
passed neither. The relay leg alone drains a mailbox without applying rows,
so the device sat at "0 of 0 records" indefinitely while looking busy.
`PeerLanAddress` closes that by deriving the host from the relay endpoint
the pairing QR carried — the machine that issued the QR is the machine
`sync:serve` is listening on, and it is the only address this device is ever
told.

## Keys before the data they decrypt

The single most important ordering constraint in the whole flow lives in
`LanSyncClient::runExchange()`.

The desktop's history is encrypted under group data keys the phone does not
have yet. Those keys arrive as epoch wrap frames — see
[GDK epoch wrap delivery](../sync/gdk-epoch-wrap-delivery.md) for the wrap
format and the sender-signature rule.

Draining those wraps *after* the catch-up exchange looks harmless and is
not. The first sync then applies the desktop's entire encrypted history
against an empty keyring, every entry quarantines as undecryptable, and
there is no replay path built into the catch-up protocol to send it again.
The device ends up permanently holding rows it can never read.

So the epoch phase runs **before** catch-up, inside the same still-open,
already-authenticated Noise session. Two details follow from that placement:

- **The phase announces its own length.** A trailing phase can end on a read
  timeout; a phase with catch-up queued behind it cannot, because the
  timeout read would swallow the catch-up request. The desktop therefore
  sends a `GDK_EPOCH_PUSH` header carrying a count, and the client reads
  exactly that many frames (clamped to 100 per connect — a larger backlog is
  picked up on the next connect rather than pinning the fiber).
- **An unrecognised frame is skipped, not fatal.** Ending the loop on the
  first frame the client did not understand discarded every wrap queued
  behind it, and the desktop had already marked all of them delivered. The
  keys were simply gone.

The security property that must survive any refactor: a wrap reaches
`GdkEpochDeliveryGateway` **only** from inside this authenticated session.
Nothing unauthenticated may ever be wired into that path. The handshake that
establishes the session is described in
[the Noise handshake state machine](../sync/noise-handshake-state-machine.md).

## What the gate releases on

Finishing the sync leg is necessary and not sufficient. `pull()` reports
`complete` only when all three hold:

1. `syncOnce()` returned `true` — the full bidirectional catch-up exchange
   finished in this step. This is also what fixes `records_expected`: the
   phone has no way to learn the peer's total ahead of a completed exchange,
   so whatever is locally applied at that moment *becomes* the expected
   total.
2. The keyring is non-empty (`sync_encryption_state.current_epoch` is set).
   Without this a relay-only or not-yet-delivered import reports complete and
   lands the user on a dashboard of rows that failed to decrypt.
3. The op log has been re-projected, stamped by `reprojected_at`.

Point 3 exists because of the ordering above being imperfect in practice.
Entries can arrive and quarantine *before* the keyring is populated — over
the relay, for instance, where there is no single session imposing an order.
The first `pull()` step to observe a non-empty keyring therefore re-projects
what quarantined, exactly once per cursor, so anything held back earlier now
decrypts and projects.

*What* quarantined, not the whole log. That pass used to be
`OpLogRebuilder::rebuild()` — snapshot the triggers, drop them, delete every
row the log can recreate, replay the entire persisted op log, restore, reindex
— and it is measured below as the third of four costs this gate could not
afford. `HistoryReprojector::replayQuarantined()` is the same question asked of
the rows that actually failed, and it is already the desktop's answer to the
identical problem: `SealedLedgerRecovery` calls it for "peer entries a locked
drain persisted but never projected".

Re-projection blocks the request it runs in, which produced a UI bug worth
knowing about. Running it in the same tick that finished the transfer made
the screen jump straight from "transferring" to "done", skipping the step
that actually takes the time — the user watched a frozen bar and then a
completed one. There is now an explicit `rebuilding` phase: one tick
persists the phase and returns, so the screen renders the rebuild step, and
the *next* tick performs it.

A re-projection that throws is logged and leaves `reprojected_at` null.
Completion stays gated on it, so the next tick retries rather than crashing
the poll.

## Reading a stall

A blocking screen with nothing to say is indistinguishable from a hung one.
Every non-working outcome is therefore named by `SyncBlockedReason`, whose
backing values are `mobile::setup.blocked.*` translation keys —
`SetupBlockedReasonsHaveCopyTest` walks `cases()` and fails on a case added
without copy, so a new reason cannot ship as a blank line on screen.

| Reason | Meaning |
|---|---|
| `NoPeer` | No confirmed peer yet, or no usable identity. |
| `NoKeys` | Peer reached, keyring still empty. |
| `Unreachable` | The sync burst ran and did not complete. |
| `Reprojecting` | History rebuild announced or in progress. |
| `Locked` | No app-lock key: engaged before the step, or gone mid-flow. |
| `Revoked` | The peer says it no longer knows this device. |
| `Retrying` | The poll tick itself threw. |

Two of those are there because of specific failures:

- **`Revoked` is terminal, not retryable.** When a peer answers
  `PEER_REVOKED`, `LanSyncClient` clears the local `confirmed_at`. Every
  later tick then found no confirmed peer and reported "waiting for the
  other device" — which is what a device that had *never* paired reports.
  The screen span forever on a pairing that could not come back.
  `peerRevokedUs()` distinguishes the two by looking for a row that is
  paired but no longer confirmed: "no longer" has a row, "not yet" does not.
- **`Retrying` exists because this tick *is* the screen.** Letting an
  exception out of `poll()` answered 500, which Livewire discards. The view
  kept its last frame and looked perfectly alive while nothing ran again.

`SetupStep` maps each reason onto one of four ordered stages — connect,
keys, transfer, rebuild — so a long stage reads as "step 3 of 4" rather than
a hang. The progress bar reports how far the *current* step has got, not the
ceremony as a whole; a ceremony-wide number sat at 100% throughout the
rebuild because the transfer it measured had already finished. And the bar
is only determinate during transfer when `records_expected` genuinely
exceeds `records_applied` — since expected is derived from applied, treating
it as a total renders a full bar the instant the first row lands.
Everything else reports indeterminate, which is honest about not knowing.

The settings screen's own progress block (`SyncScreen::hydrateProgress()`)
reads the same cursor and answers the same two questions the same way: a total
that merely equals what has landed is indeterminate, and the block stays on
screen for `rebuilding` as well as `pulling` — `SyncPhase::isInitialSyncInFlight()`
is where that pair is spelled once. Asking only about `pulling` made the block
vanish for the whole of the slowest step.

## The one silent case left

An import exists because another device *has* data. Completing one with
`records_applied === 0` is an upstream defect, not a quiet success — but
"0 of 0" on a finished screen is indistinguishable from a sync that had
nothing to carry. The puller logs a warning on exactly that shape. Nothing
else in the system would say so.

## Before the epoch: the gate that returns you to pairing

`SetupProgressScreen` is the second half of this gate. The first is
`MobileEnsureImportCompleted`, which reads the durable `mobile_import_intent`
marker and, while the device still has no epoch, redirects every route that is
not pair, setup, lock, import, welcome or logout back to
`mobile.pair?mode=import`.

That is right while the ceremony is live and was a trap when it was not. The
pairing screen renders in `layouts.lock`, which draws no navigation at all, and
its camera arm offered exactly two controls: open the camera, and enter a code
instead. Choosing "Import from another device" on a phone whose pairing could
not complete therefore left the reader on a screen with nothing behind it and
no way off — dashboard, transactions, budgets and settings all returned there —
and the only recovery was reinstalling the app.

`MobilePairingScan::abandonImport()` is the way out. It expires any in-flight
token, retires the intent marker, and lands on the dashboard. Nothing is added
to the exempt list: retiring the marker is the gate's *own* convergence move,
the one it already makes when an import genuinely converges, so a device that
has taken the exit is held no differently from one that never chose to import.

Two pieces of state make that honest rather than merely quiet:

- **No epoch is not a broken device.** A plain signup has no
  `sync_encryption_state.current_epoch` either — encryption is minted when sync
  is enabled, not at signup — so the abandoned device sits in exactly the state
  a local-only account normally sits in.
- **The withheld starter data is seeded.** The import path signs up with
  `seedsStarterData: false`, because those categorization rules were to arrive
  from the peer. Abandoning means nothing ever will, so the exit re-dispatches
  `UserInstalled` — the same idempotent heal `InstallCommand` performs for an
  existing user.

Taking the exit does not spend the import: `/mobile/import` stays exempt and
still leads back into `mobile.pair?mode=import`.

## Four measured costs on the sync path

The phone's ceiling is 128 MB — the interpreter's compiled default, because
NativePHP's Android shell writes a `php.ini` with no `memory_limit` in it at
all (see [the module architecture page](architecture.md)). Everything below was
measured in a bare harness whose framework baseline is 40.5 MB; a routed `web`
request costs about 10 MB more, so on the device each threshold arrives sooner
than the number given.

Scale, so the entry counts mean something: `OpLogBackfiller::columnsOf()` selects
every column but `id` — 34 of them for `transactions` — and
`OpLogWriter::writeCreateRow()` emits one op per field. Fifty thousand entries is
roughly 1,500 transactions. One real device sync in this project moved 8,365
records.

| Where | Before | After |
|---|---|---|
| `PeerCatchUpExchanger::opsAfterWatermark()` | 80.5 MB at 20k, 98.5 MB at 30k, **fatal at 50k** | 40.5 MB flat to 630,360, at 60% more wall time |
| the gate's re-projection | 124.5 MB at 130k, **fatal at 200k** — and that is the load step alone, before the replay | one query, 0.007 s, no growth |
| `SyncStatusService::overallStatus()` | 106.5 MB at 130k, **fatal at 200k** | 40.5 MB flat, 0.11 s at 630,360 |
| `OpLogWriter::writeIncrement()` | 6.2 ms and 2 MB per call after 5,000 increments, rising with every one | 0.10 ms, no growth |

**The catch-up delta** is [counted, then streamed](../sync/peer-session-lifecycle.md#the-delta-is-counted-then-streamed).
It reaches the phone through `LanSyncClient::runExchange()`, which
`SyncScreen::syncNow()` — the "Sync now" button — drives through
`MobileSyncTriggerService`, and which `InitialSyncPuller` drives on every tick of
this gate.

**The re-projection** is the quarantine-scoped replay described above. In the
ordering the protocol actually imposes — epoch phase before catch-up — nothing
quarantines, so the pass is one `exists()` and costs nothing. Its worst case is
the relay-first arrival, where the sensitive columns of a whole ledger land
before their keys: it is then bounded by the rows that quarantined rather than by
the log, which is a smaller number and never a larger one. It is deliberately
still a single `replay()` call rather than one per 400-pk chunk, because one call
is what lets `parentsFirst()` and `childrenFirst()` order creates and tombstones
across tables; chunked, a parent's tombstone can be applied before a child chunk
that a foreign key still holds.

**The status service** asked "has this device written anything since the last
session closed" by plucking every `recorded_at` it had ever written and running
`CarbonImmutable::parse()` on each. It is one `MAX()` — 0.12 s over 630,360 rows
warm, 0.6 s on the first touch of a 242 MB file, against 8.6 s and 322 MB for the
pluck given a 1 GB ceiling to survive in. The index is deliberately not widened:
`op_log_entries_replay_idx` reaches only `user_id` here, so the aggregate scans
that user's rows, and a tenth of a second at 630,360 is not what was wrong. The
two timestamp formats still meet as parsed instants, never as strings —
`sync_sessions` writes ISO8601 with an offset and this table writes
`Y-m-d H:i:s`, and `' '` sorts before `'T'`.

**The G-Counter increment** read back every op this device had ever written for
`(table, pk, field)` and `json_decode`d each to find its own running maximum, so
every increment cost the ones before it. `GCounterStrategy` merges as the sum of
each device's maximum, so a total that does not rise is merged away; the newest
op is therefore the maximum, and `writeIncrement()` now refuses a non-positive
delta rather than letting that stop being true. One row, read backwards along
`op_log_entries_table_pk_idx`, `limit 1`.

### The op log has no floor, and should not grow one here

Nothing anywhere deletes from `op_log_entries` outside account deletion and a
rebuild's own replay. That is correct for a CRDT log — an op that is pruned on
one device and not another is a divergence no later exchange can resolve, and
per-device watermarks say what a *peer* has consumed, never what every peer ever
will. So the log grows for the life of the install, and each of the four costs
above grows with it.

The answer is not a prune. It is that no reader of the log may be proportional to
it, which is what the four fixes make true: three are now flat in the size of the
log, and the fourth is flat in the size of the delta actually owed. A compaction
scheme would need a device-set-wide floor nothing in the protocol currently
carries, and inventing one to protect readers that no longer need protecting
would trade a bounded cost for an unbounded correctness risk.

One consequence worth writing down: `OpLogRebuilder::rebuild()` now has no
production caller. It is exercised by `OpLogRebuilderTest`,
`RebuildDeletionOrderTest` and `EncryptedRebuildConvergenceTest`, and it remains
the only thing that can rebuild a projection from nothing — but nothing triggers
it. Either it wants a deliberate entry point, or it wants deleting; it should not
sit between the two.

## Related

- [Mobile module architecture](architecture.md) — cold start, pairing, and
  why every native crossing is a single bounded operation.
- [Pairing handshake](../sync/pairing-handshake.md) — how the phone got a
  confirmed peer in the first place.
- [GDK epoch wrap delivery](../sync/gdk-epoch-wrap-delivery.md) — the wrap
  format, sender signatures, and idempotency on re-delivery.
- [Op log merge rules](../sync/op-log-merge-rules.md) — what re-projection
  replays.
- [Device removal and epoch rotation](../sync/device-removal-and-epoch-rotation.md)
  — the other side of `PEER_REVOKED`.
