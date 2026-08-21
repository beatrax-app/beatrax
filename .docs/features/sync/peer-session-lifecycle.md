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

Both peers keep a hybrid logical clock watermark, `(hlc_l, hlc_c)`, recording
how far they have consumed. Catch-up is each side telling the other its
watermark and receiving everything newer. From the responder's side the
choreography is:

1. Read the peer's `CATCH_UP_REQUEST`, carrying its watermark.
2. Send a `CATCH_UP_RESPONSE` announcing a frame count, then that many frames.
3. Send this side's own `CATCH_UP_REQUEST`.
4. Read the peer's `CATCH_UP_RESPONSE` and then its frames.
5. Send `CATCH_UP_COMPLETE`, read the peer's.

Everything after the handshake is Noise-encrypted; control messages are JSON
inside the session, op batches are `TransportFramer` frames inside the session.

### Both integers arriving on the wire are clamped

The peer's watermark is clamped to non-negative. A negative `hlc_l` would make
`opsAfterWatermark()`'s `> $peerHlcL` predicate match the *entire* op log,
turning every reconnect into a full-history dump.

The peer's declared `frame_count` is clamped to `[0, MAX_CATCHUP_FRAMES]`
(100,000). A negative value yields zero and the loop never runs; a huge one is
capped, so a peer cannot stream frames indefinitely and grow the local op log
without bound. Nothing else on this path bounds attacker-declared counts.

### What gets sent

`PeerCatchUpExchanger::opsAfterWatermark()` restricts the query to entries
signed by a device the registry still holds as confirmed. An entry signed by a
retired identity can be verified by nobody, so sending it only wastes the wire
and fills the peer's log with drops — one import shipped 12,948 entries and the
phone refused 12,476 of them.

Entries are packed into frames against **two** limits, `BATCH_OPS` (1024) and
`MAX_FRAME_BYTES` (65536), because entries with long signatures or values hit
the byte ceiling well before the op count. The byte budget accounts for the
JSON array brackets and the comma between entries so it matches what the framer
will actually emit.

Rows whose `op_type` is not a known `OpType` are dropped: an unknown op cannot
be safely replayed by a peer.

### Ed25519 verification is additive, not redundant

Noise authenticates the *socket*. It says nothing about who originally signed a
given op, and ops legitimately arrive having been forwarded through the relay
or replayed from disk — so the transport channel is not the signing boundary.
`SyncSession::receiveOps()` verifies each entry's Ed25519 signature against the
snapshot key map and drops anything unverifiable before handing the rest to the
replayer.

Two dropped-entry cases are logged very differently on purpose. An invalid
signature is one warning per entry. An entry whose author key is not in the map
at all is **counted per author and reported once, at error level**: a peer whose
history was signed by a retired identity wrote the same warning six thousand
times, which is why nobody read it, and an exchange that delivered thousands of
entries and applied none of them otherwise looks like an ordinary clean sync
from every surface above it.

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

## Every read is bounded

| Phase | Timeout | Why |
| --- | --- | --- |
| Waiting for handshake msg1 | 10s | Bounds the pre-auth slow-loris window: a peer that connects and never sends msg1 is dropped rather than parking a fiber forever. |
| Catch-up and live stream | 60s | Generous enough for slow links and large replay batches; a peer that stalls mid-stream is dropped rather than pinning the fiber. |

On timeout the connection is closed and the exception is rethrown, so the
caller's `try`/`catch` tears the session down.

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
  connected. When unwrapping fails — the daemon may hold no unlocked app-lock
  session — the row is left unconfirmed for a request-scoped drain to retry and
  the failure is never rethrown. Letting it escape aborted the whole exchange
  *after* catch-up had already succeeded.

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
- [Sync architecture](architecture.md) — the surrounding module.
