# The peer session, from connect to close

`SyncWebSocketHandler` drives one whole sync exchange as the responder. It is a
strict sequence, and each step exists because doing it later, earlier, or not
at all broke something specific.

```
Noise handshake  →  auth gate  →  epoch push  →  catch-up  →  live loop
```

## Why the order is what it is

**Keys before the data they decrypt.** The group-data-key epoch push runs
*before* catch-up, not after. Delivered afterwards, a joining device applied the
peer's entire encrypted history against an empty keyring, and every sensitive op
landed in quarantine — an audit table with no replay path back into the app.

**A counted phase, not an inferred one.** The epoch push opens with a header
naming the phase and the number of wrap frames that follow. Because it runs
before catch-up rather than as the last thing on the wire, it cannot end by
letting a read time out — that would swallow the catch-up request already
queued behind it. The explicit count makes the boundary exact.

The header is sent unconditionally, even when there is nothing to push. The
peer reads it before catch-up and would otherwise block waiting for a phase
this side silently skipped; an unbound mailbox is simply a zero-wrap push.

## The auth gate, and the snapshot it takes

`SyncSession::authenticate()` compares the X25519 static key the handshake
revealed against `DeviceRegistryService::deviceX25519Keys()` and refuses on no
match. On success it writes a `sync_sessions` row and touches
`device_registry.last_seen_at`.

Both of those writes are **best-effort bookkeeping and nothing more**. Losing a
race for SQLite's single writer used to throw straight out of the handshake and
close the connection, so a catch-up that was about to run never did and no
epoch ever reached the peer. Status tracking must never be a precondition for
the exchange it is tracking. `last_seen_at` is additionally throttled to one
write per 60 seconds, because a peer reconnecting every couple of seconds does
not need a database write every couple of seconds.

The session row is written with `updateOrInsert` keyed on the table's own
`(user_id, local_device_id, peer_device_id)` unique index rather than a plain
insert. A `SyncSession` lives for exactly one connection, so its cached row id
is null again on every reconnect, and a plain insert died on that constraint the
second time a peer connected.

Immediately after the gate, the handler reads the confirmed-device key map
**once** and reuses it for the whole session — the replayer, the catch-up loop
and the live loop all share that one snapshot. Revoking a device therefore does
not invalidate an open session's key map; see the revocation loop below for
what does.

## Catch-up: an HLC watermark exchange

Each side keeps a watermark per **(peer, author)** — `(hlc_l, hlc_c)` in
`sync_peer_catch_up_state`, keyed by
`(user_id, peer_device_id, author_device_id)` — recording how far it has
consumed of what THAT peer delivered of THAT author's ops.
`PeerCatchUpWatermarks::advance()` moves each one, forwards only, from what the
peer actually delivered, after the replay rather than before: a cursor advanced
over ops that failed to apply would ask the peer to skip them next time and
nothing else would ever send them again. An author never heard of through this
peer reads `(0, 0)` — "send me everything you have of theirs".

It is deliberately NOT this device's own `hlc_clock_state`. That is the last
LOCAL write, which is not a statement about the peer at all: A writes at 10:00
and 10:10, B writes at 10:01, and A's request for "everything after 10:10" left
B's op below the watermark forever. The same design already existed two files
away in `InitialSyncPuller`'s `mobile_sync_progress.last_hlc_l`.

### Why one scalar per peer is a claim the stream cannot make

`opsAfterWatermark()` deliberately serves ops authored by **every** device, not
only the peer's own — that is how a relay forwards a third install's history at
all. So "delivered by" and "authored by" are different questions, and a single
`(l, c)` per peer only answers the first.

Device D hears dev-A's op at `(1000, 0)` through relay B and moves its cursor for
B to `(1000, 0)`. Device C, offline for months, then pushes its `(900, 0)` op
into B. D asks B for everything after `(1000, 0)`, C's op sits below it, and no
later exchange ever raises the subject again — the ops are not late, they are
*gone*, and nothing reports it. Walking the cursor backwards is not the fix
either: that re-asks for everything already consumed of every other author.

Per-author cursors answer both questions. `PeerCatchUpCursors` is the value
object that carries them, owns the wire shape, and owns the clamp — `hlc_l` and
`hlc_c` are clamped to non-negative in exactly one place instead of being
re-derived in each of the two protocol halves, which had already drifted once
over a different number.

Catch-up is each side telling the other its watermark and receiving everything
newer. From the responder's side the choreography is:

1. Read the peer's `CATCH_UP_REQUEST`, carrying its `cursors` list.
2. Send a `CATCH_UP_RESPONSE` announcing a frame count, then that many frames.
3. Send this side's own `CATCH_UP_REQUEST`.
4. Read the peer's `CATCH_UP_RESPONSE` and then its frames.
5. Send `CATCH_UP_COMPLETE`, read the peer's.

Everything after the handshake is Noise-encrypted; control messages are JSON
inside the session, op batches are `TransportFramer` frames inside the session.

### Every number arriving on the wire is clamped

Each cursor the peer declares is clamped to non-negative by
`PeerCatchUpCursors::fromWire()`. A negative `hlc_l` would make the `> hlc_l`
predicate match that author's *entire* history, turning every reconnect into a
full-history dump. A cursor entry with no usable `device_id` is dropped rather
than guessed at, which reads as "no cursor for that author" — over-asking, the
safe direction.

The peer's declared `frame_count` is clamped to `[0, MAX_CATCHUP_FRAMES]`
(100,000). A negative value yields zero and the loop never runs; a huge one is
capped, so a peer cannot stream frames indefinitely and grow the local op log
without bound. Nothing else on this path bounds attacker-declared counts.

### What gets sent

`PeerCatchUpExchanger::opsAfterWatermark()` restricts the query to entries
signed by a device the registry still holds a ROW for. An entry signed by an
identity nobody can name is verifiable by nobody, so sending it only wastes the
wire and fills the peer's log with drops — one import shipped 12,948 entries and
the phone refused 12,476 of them.

Confirmed is deliberately not the test. A REMOVED device keeps its registry row
(`DeviceRegistryService::purge()` deletes its sessions, mailbox and tokens, never
the row) precisely so its key survives to verify what it wrote. Filtering on
`confirmed_at` instead withheld every transaction, goal, envelope move and pot
movement the old phone ever created from the new phone replacing it.

Entries are packed into frames against **two** limits, `MAX_OPS_PER_FRAME`
(1024) and `MAX_PAYLOAD_BYTES` (65536), because entries with long signatures or
values hit the byte ceiling well before the op count. Both belong to
`TransportFramer`, which is the class that *throws* when a batch exceeds them,
and `PeerCatchUpExchanger` asks it — `wouldOverflow($batch, $next)` — rather
than predicting the answer.

That is not tidiness. The packer used to hold its own copy of the entry
encoder to size a batch, and the copy wrote `user_id` as this device's local
scope while the framer writes the id the entry was *signed* under. An entry
relayed from another install carries a longer origin id, so every one of them
was under-counted, and a batch packed to just inside the cap came back out of
`encode()` as an `OverflowException` — thrown mid-catch-up, where nothing
catches it. A prediction that lives anywhere but beside the throw is a
prediction that can disagree with it.

Rows whose `op_type` is not a known `OpType` are dropped: an unknown op cannot
be safely replayed by a peer.

### The delta is counted, then streamed

The delta used to be a `list<string>`. Every matching row was fetched, every row
hydrated into an `OpLogEntry`, and every finished frame held until the caller
asked for it — three copies of the peer's whole history alive at once, and the
frames alone are a third of the raw log again. Twenty thousand entries cost
80.5 MB resident. Thirty thousand cost 98.5 MB. Fifty thousand — roughly 1,500
transactions, since `OpLogBackfiller::columnsOf()` names 34 columns for that
table and `writeCreateRow()` emits one op per column — **exhausted the phone's
128 MB ceiling inside `TransportFramer`**, and memory exhaustion is `E_ERROR`,
not a `Throwable`, so the `catch` at both call sites never ran. This sits behind
the phone's "Sync now" button, and it is the same shape the tally fix removed
from [the initial-sync gate](../mobile/mobile-initial-sync-gate.md#four-measured-costs-on-the-sync-path)
one method over.

`opsAfterWatermark()` now answers a `CatchUpDelta`: a frame count and a stream.
Rows are read through a cursor, one entry is hydrated at a time, and a frame is
encoded and handed to the caller the moment its batch fills. Peak is one batch
plus one frame — 40.5 MB flat, whether the delta is 20,000 entries or 630,360.

It has to be counted *and* streamed because the protocol declares `frame_count`
in the `CATCH_UP_RESPONSE` before the frames follow, and the receiver reads
exactly that many. Frames are byte-bounded, so their number cannot be known
without measuring the bytes: the count pass packs the same batches without
encoding them, and the send pass encodes them.

The cost is real and is reading the delta twice. At 200,000 entries that is
5.6 s to count and 4.8 s to send; at 630,360 the two passes take 32.8 s against
20.2 s for the list — which the list only ever reached by being handed a 4 GB
ceiling and 1,058 MB of it. Sixty per cent more wall time on a leg that is
already seconds long, in exchange for a delta that finishes at all.

The two passes must agree, and this device keeps writing while a peer drains it.
So the count pass records the highest `op_log_entries.id` it saw and the send
pass is bounded by it. Ids are monotonic and nothing deletes from this table, so
that bound admits exactly the rows the count was taken over and excludes every
row written since. Without it a local write between the two passes adds a frame
the peer was never told to read, and the next control message is read as op data.

An unframable entry is reported by the count pass only. Both passes see it, and
logging from both would double every such report the moment a caller iterated.

### One entry that can never be framed

Packing starts a new frame whenever the next entry would not fit in the current
one. That works for every entry *except* the one that does not fit in an empty
frame either, and the packer used to hand exactly that entry to
`encode()` — the throw it exists to avoid, reached by the path that was meant to
avoid it.

The cost was not one row. `opsAfterWatermark()` built **all** the frames for
the whole owed delta before a single one went out, so the `OverflowException`
aborted the entire exchange; both call sites catch `Throwable` and close the
session. The per-author cursor never advanced, nothing re-sent, and every
reconnect rebuilt the same frames and failed identically. One row a device could
not put on the wire cost it every row behind that row, to every peer, forever.

So an entry the framer says exceeds the budget **on its own** is skipped and the
rest of the delta is sent. Skipped, not deferred: the ceiling is a property of
the entry, so a later attempt reaches the same answer, and a device that keeps
retrying it is a device that never syncs anything else. It is reported at
`error` level with the table, pk, field, author and byte count — never the value,
which is ledger content — because an exchange that quietly withheld a row looks
like a clean sync from every surface above it.

`TransportFramer::exceedsFrameBudget()` is what is asked, for the same reason
`wouldOverflow()` is: the class that throws is the class that answers.

#### What makes an entry that large

A sensitive column is sealed before it is framed. The value on the wire is
`base64(nonce ‖ XChaCha20 ‖ tag)` over the **JSON-encoded** plaintext, and
`json_encode` escapes every non-ASCII character to `\uXXXX`. So one character of
note text costs 1 byte if it is ASCII, 6 if it is Cyrillic or Greek, and 12 if it
is an emoji — and then all of that grows by a further ~1.334× through base64.
A note of 48,096 ASCII characters was the first that could not be framed.

`Modules\Sync\Public\Transport\SensitiveTextBudget::MAX_PLAINTEXT_CHARACTERS`
is the other half of the pair: the plaintext length a screen editing a sealed
column may accept, chosen so that even an all-emoji value at the cap still frames.
`TheTextBudgetStaysInsideTheFrameBudgetTest` is the only place the two numbers
meet, so it is the only place that answers an edit to either one. The `note`
textarea on the transaction detail screen carries it as `maxlength` and
`saveNote()` refuses past it — because a note the transport will silently
withhold is worse than a note the screen declines to save.

`tallyFromAuthorAfter()` is the same question narrowed to one author and
*measured* rather than carried, which is what a progress counter asks —
`InitialSyncPuller` counts how much of the peer's own stream has landed. Two
things had to be narrowed for it. The general cursor form treats an author with
no cursor as fully owed, so passing it a single cursor would frame every other
device's whole history only to throw it away. And framing at all was the wrong
shape: a count and a watermark are both O(1) in SQL, and building the frames to
arrive at them loaded the peer's entire delta into a phone whose PHP ceiling is
128 MB. It is the one caller that wants the answer without the bytes, so it is
the one caller that does not go through `framesFor()` — and the `op_type`
allow-list above is re-stated as SQL there, or the tally would credit the reader
with rows the wire never carried.

### Ed25519 verification is additive, not redundant

Noise authenticates the *socket*. It says nothing about who originally signed a
given op, and ops legitimately arrive having been forwarded through the relay
or replayed from disk — so the transport channel is not the signing boundary.
`SyncSession::receiveOps()` verifies each entry's Ed25519 signature against the
key map its caller hands it, and that map is the whole gate — nothing inside the
method widens it. Every path that reaches the wire passes
`DeviceRegistryService::signatureVerificationKeys()`, read once as a connect-time
snapshot so a revocation takes effect on the peer's next reconnect. A key the
registry merely *retains* is deliberately not in it: it belongs to a device
nothing confirms, the replayer refuses that device's new work anyway, and
admitting it here would spend this peer's cursor on an entry no later
confirmation could bring back.

An entry the map cannot verify is therefore **held, not dropped**. Held means it
reaches no merge strategy and writes nothing, and — the load-bearing half — that
it is left out of the watermark advance, which runs over the verified entries
alone. The cursor is kept per (peer, author), so an author with a single held
entry gets no cursor movement at all and the peer offers that author's whole
delta again on the next exchange. What releases the hold is the key arriving:
the device finishing the pairing ceremony, or a `device_introductions` row this
reader confirms. The next exchange re-offers the same entries, they verify, and
they replay. `AHeldEntryKeepsItsPlaceInThePeerCursorTest` is where that is
pinned.

The two refusals are logged very differently on purpose. An entry that fails
verification against a key this device does hold is one warning per entry. An
entry whose author is in no map at all is **counted per author and reported
once, at error level**: a peer whose history was signed by a retired identity
wrote the same warning six thousand times, which is why nobody read it, and an
exchange that delivered thousands of entries and applied none of them otherwise
looks like an ordinary clean sync from every surface above it. That one line
carries `known_to_registry`, read from `retainedDeviceKeys()` — the only use the
retained map has here, and only to separate an author this install once trusted,
whom an introduction can restore, from one it has never heard of.

The replay itself is synchronous. That is safe only because the pacing bounds
live out of band — the per-receive timeout and `MAX_CATCHUP_FRAMES` — rather
than inside the replayer.

## The live loop and mid-session revocation

After catch-up the handler reads messages until the peer goes away, each read
bounded by a 60-second idle timeout.

Trust is re-checked **on the live connection**, not only at connect. Removing a
device cleared its registry row while its open session carried on syncing, so
"removed" only took effect whenever the peer next happened to reconnect. The
check is throttled to once every 5 seconds: the answer only changes when the
user removes a device, so asking per message would buy nothing and cost a query
on every op. Five seconds is short enough that a removal takes hold while the
user is still looking at the screen.

When the gate refuses a peer, the handler sends `PEER_REVOKED` over the
completed Noise session before hanging up. Closing silently is
indistinguishable from a flaky network, and a device removed that way kept
describing itself as connected and synced indefinitely. The message is
trustworthy because the Noise IK handshake has already proved to the dialling
peer that this responder holds the static key it dialled. It is best-effort and
never allowed to throw — the connection is closing either way.

**Both ends say it and both ends hear it.** The initiator has the same gate:
`LanSyncClient` authenticates the responder against its own registry, and a
desktop the phone has stopped confirming is told so before the phone hangs up.
On the receiving side, a `PEER_REVOKED` read anywhere in the epoch phase clears
this device's confirmation of that peer and raises `PeerRevokedException`, so
the session tears down instead of carrying on with a peer that will refuse
everything it sends. For a while only one direction existed in each half — the
responder could say it and only the initiator could hear it — which left the
phone's own refusal indistinguishable from a dropped connection.

## Every read is bounded, by numbers neither half owns alone

`Modules\Sync\Public\Transport\ProtocolTimings` holds every bound this
protocol waits on, because the initiator and the responder are separate classes
in separate modules and a bound written twice is a phase one half abandons
while the other is still working.

| Phase | Timeout | Why |
| --- | --- | --- |
| Waiting for handshake msg1 / msg2 | 10s | Bounds the pre-auth slow-loris window: a peer that connects and never sends its handshake message is dropped rather than parking a fiber forever. |
| Catch-up and live stream | 60s | Generous enough for slow links and large replay batches; a peer that stalls mid-stream is dropped rather than pinning the fiber. |
| Dialling a peer to sync | 5s | The first LAN connect per install can sit behind the iOS Local Network Privacy prompt, which a shorter bound turns into a hard failure. |
| Dialling a peer while pairing | 1s | Up to four candidates in sequence while a reader watches a spinner — a different question from the one above, and deliberately not the same number. |

**The initiator's read bound is derived from the responder's rather than
restated.** They had drifted to fifteen seconds against sixty, so any replay
batch the responder needed longer than fifteen seconds to produce was abandoned
by the only device asking for it. `initiatorReadSeconds()` returns
`responderReadSeconds()`, and an arch test pins the ordering.

On timeout the connection is closed and the exception is rethrown, so the
caller's `try`/`catch` tears the session down. The exception is
`Amp\CancelledException`: an expired `TimeoutCancellation` carries
`Amp\TimeoutException` only as its *previous*, so a catch naming the latter
never fires — which is what made the close-on-stall above dead code on both
halves for as long as it existed.

## The epoch-wrap drain, as this session sees it

The wrap format, the two delivery directions and the shared-mailbox hazard are
covered in [Getting a group data key epoch onto every device](gdk-epoch-wrap-delivery.md).
Three things belong to this session specifically:

- Wraps for the connected peer are collected first and sent second, because the
  count has to be known before the first frame goes out — the phase header
  carries it.
- Each mailbox row is confirmed only after its blob has been handed to the
  transport, so an interrupted drain is retried on the next connect.
- The inbound drain touches no wire, so it runs whether or not a peer is
  connected. `GdkEpochControlHandler::handle()` reports a `GdkWrapOutcome`
  rather than throwing, and `InboundGdkWrapDrain` asks that outcome
  `consumesCarrier()` before it retires the mailbox row: `Applied` and
  `Refused` are terminal and retire it, `Deferred` and `Retained` leave it for
  a later pass from a context that holds the app-lock key — which the listener
  daemon never does. A blob that is not an epoch wrap is skipped without
  spending the pass's budget, because pairing shares the same mailbox.

The whole epoch step degrades to a no-op when its dependencies are unbound,
rather than tearing down a sync session over one skippable delivery.

## See also

- [The Noise handshake state machine](noise-handshake-state-machine.md) — the
  first step of the sequence above.
- [The relay endpoints](relay-endpoint-authorization.md) — the store-and-forward
  path this one optimises around.
- [Getting a group data key epoch onto every device](gdk-epoch-wrap-delivery.md)
- [How a replay decides what the row should say](op-log-merge-rules.md) — what
  happens to the ops that arrive here.
- [Device removal and epoch rotation](device-removal-and-epoch-rotation.md) —
  what the mid-session revocation check is reacting to.
- [Introducing a device nobody can pair with](introducing-a-device-nobody-can-pair-with.md)
  — why the request names the authors it can verify, and what the answer
  withholds and offers when it cannot.
- [Sync architecture](architecture.md) — the surrounding module.
