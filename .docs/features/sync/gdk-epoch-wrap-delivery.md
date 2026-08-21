# Getting a group data key epoch onto every device

Sensitive op-log columns are encrypted under a per-user Group Data Key (GDK). The key is
versioned: each version is an *epoch*, identified by a small integer, and every encrypted row
records the `gdk_epoch` it was written under. A row is readable only by a device that holds
that epoch's key.

Epochs are minted on one device — when a device is removed, when a key is rotated, when a new
device joins — and every other confirmed device has to end up holding the same bytes for the
same epoch id. That is the whole problem this mechanism solves, and the obvious approaches
both fail:

- **Broadcast the key through the relay.** The relay is zero-knowledge by design; it must
  never be able to read anything it forwards.
- **Seal it to each recipient and be done.** A sealed box (`crypto_box_seal`) is confidential
  but *anonymous*. It proves nothing about who sealed it. Anyone who learns a device's public
  key — it travels in the pairing payload — can seal a key of their own choosing to it.

## The wrap

`GdkRotationService::buildGdkEpochWrap()` produces a `GDK_EPOCH_WRAP` message carrying five
things that matter:

| Field | Purpose |
| --- | --- |
| `epoch_id` | Which epoch these bytes are the key for. A placeholder `0` when the wrap is not an epoch — **never read it to decide what a wrap is**. |
| `wrapped_key_b64` | The raw key sealed to the recipient's X25519 public key. |
| `recipient_device_id` | Who it is addressed to. |
| `sender_device_id` + `sig_hex` | Ed25519 detached signature over the signed message. |
| `key_role` | What the sealed bytes are. Absent means `epoch`. This is the discriminator. |
| `sender_holds_keyed_rows` | Blind-index wraps only: whether the sender already has rows under this key. |

The signature is what turns an anonymous sealed box into an authenticated one. It is the
answer to the second failed approach above.

### A wrap on this channel may not be an epoch

Read `key_role`, never `epoch_id`. `GdkEpochWrapSignature::carriesEpoch()` is the predicate; a
blind-index wrap sends `epoch_id: 0` only because the field is required by the shape and bound
into the signature. Anything that counts or iterates wraps and reasons about *epochs* must
filter on the role first — two tests asserting "exactly one epoch was fanned out" started
counting two the day the blind-index key began riding this channel.

Production reads it correctly today, and the distinction is worth stating because it is not
obvious: the `count` in the `GDK_EPOCH_PUSH` header is a **frame** count, not an epoch count.
Including the blind-index wrap in it is right — the receiver has to read that many frames.
`isEpochWrap()` likewise filters on the envelope `type`, which both roles share, so both are
forwarded and drained, which is also right.

### Why one message type carries two kinds of key

The counterparty blind-index key is not an epoch — it is never rotated, and adopting it as one
would make it an AEAD key nothing was encrypted under. It still needs exactly this channel:
sealed to a confirmed recipient, signed by a still-confirmed sender, and delivered to a
joining device. So it rides `GDK_EPOCH_WRAP` with `key_role: blind_index`, sent by
`fanOutAllEpochsToDevice()` after the epoch loop.

`GdkEpochWrapSignature::signingMessage()` appends the role term **only when the role is not
the default**. Two consequences, both deliberate: an epoch wrap signs byte-identically to one
a build without roles produced, so nothing about existing delivery changes; and a build that
does not know about roles verifies a role-bearing wrap against a message missing that term,
fails, and rejects it — rather than storing a blind-index key as an epoch key. Stripping
`key_role` in transit fails the same way.

What the receiver does with an adopted blind-index key, and when it refuses to adopt one, is
in [Which columns are encrypted at rest](sensitive-columns-at-rest.md).

The wrap is enqueued on `relay_mailbox` as an opaque blob addressed to the recipient. Nothing
about the enqueue depends on the recipient being online.

## Two delivery directions

Delivery is not one flow but two, and they are independent:

1. **Push on connect.** When a peer completes a Noise handshake,
   `SyncWebSocketHandler::deliverGdkEpochWraps()` sends a `GDK_EPOCH_PUSH` header frame
   (`{type, count}`) followed by one frame per pending wrap, then marks each one delivered.
   Noise cipher states are counter-based, so frames open in exactly the order they were
   sealed — the header first, the wraps behind it. A delivered wrap is cleared and never
   resent; a second delivery pass emits only its header, with `count: 0`.
2. **Drain your own mailbox.** `drainInboundEpochWraps()` reads this device's own inbound
   `relay_mailbox` rows and routes each blob through `GdkEpochControlHandler`. This needs no
   peer session at all — a wrap that arrived via an external relay or a forwarding peer
   converges the keyring on its own.

## The gates, in order

`GdkEpochControlHandler::handle()` applies four checks, and the *order* is part of the
contract:

1. **Well-formedness.** A message missing `epoch_id`, `wrapped_key_b64` or
   `recipient_device_id`, or naming a `key_role` this build does not know, is dropped before
   any libsodium call is made.
2. **Recipient identity.** `recipient_device_id` must be this device. A wrap addressed
   elsewhere is rejected here — *before* the sender is ever looked at, so a foreign-recipient
   wrap from a perfectly legitimate sender still goes nowhere.
3. **Sender authenticity.** `sender_device_id` must resolve to a device in this user's
   registry with a non-null `confirmed_at`, and `sig_hex` must verify under that device's
   Ed25519 public key. Both halves run *before* any `seal_open`. An unknown sender has no key
   to verify against and is rejected on the lookup alone.
4. **Open.** Only now is the sealed box opened with this device's X25519 secret key.

`handle()` returns a `GdkWrapOutcome` — `Applied`, `Refused`, or `Deferred` — and the caller
that owns a mailbox row confirms only the first two. `Deferred` means the sealed box was never
opened because this process holds no app-lock key, which is the **permanent** state of the
`sync:serve` daemon: it resolves a `Session` no middleware ever started, so
`AppLockKeyService::release()` returns null unconditionally. It is an outcome rather than an
exception because this class is contractually forbidden from throwing — the `catch (Throwable)`
that used to gate the confirm could never fire, so every wrap the daemon could not open was
confirmed away. `RelayMailbox` has no re-send and `fanOutAllEpochsToDevice()` fires once, at
pairing, so that deleted the only copy of the key.

The same drain now also skips a blob that is not a wrap at all, instead of handing it to the
handler and confirming the `Refused` away. Two protocols share this mailbox, and
`pendingWrapsForPeer()` already applied that guard in the outbound direction.

The consequence worth remembering: a wrap can fail for exactly one reason at a time, and
which reason depends on where it falls in that list. Tampering with the sealed bytes is
caught by the signature check at step 3, not by an AEAD failure at step 4.

## Which epoch the recipient treats as current

`appendEpoch()` advances `current_epoch` to whatever it applied last, and **nothing on the wire
says which epoch is current**. Epoch ids are random, so there is no order to fall back on, and
`RelayMailbox::drain()` returns rows by `created_at` — which a whole fan-out shares, because it
is enqueued inside one transaction.

Two things make arrival order agree with the sender's own answer:

- `fanOutAllEpochsToDevice()` delivers every other epoch first and the **current one last**.
- `drain()` orders by `(created_at, id)`, so a tie is broken by insert order rather than by
  whatever the database happened to return.

Without both, a device pairing after a revoke-and-rotate could settle on the retired epoch and
encrypt under the key the revoked device still holds. Signature verification cannot catch that:
the retired epoch's wrap is genuine.

**The residual, stated plainly.** This makes one sender's batch deterministic. It does not order
wraps from two different senders racing over an untrusted relay. Closing that needs an
authenticated "this is current" marker on the wrap, and the reason there is not one is
compatibility: any term appended to an epoch wrap's signing message makes a peer that predates
the term reject the wrap, which for a historical epoch means losing a key it needs to read its
own history. The blind-index role can afford that trade because rejection there is fail-safe;
for epochs it is not.

## Idempotence and epoch-id collisions

Epoch ids are minted by `GdkEpochId::mint()` as `random_int(1, 2^53 - 1)`, not as a local
counter starting at 1, precisely so two devices that each self-mint before hearing from the
other do not both call their key "epoch 1". Collisions are therefore vanishingly unlikely and
`reconcileCollision()` is close to dead code; it is kept because the cost of being wrong is a
key that decrypts history going away.

The same wrap can arrive twice — pushed on connect and again from a drained mailbox. Handling
an epoch that is already present must not duplicate it and must never move `current_epoch`
*backwards*.

Collisions are the harder case, and they are not hypothetical. Epoch ids are minted locally
and start at 1, so two devices that each self-mint an epoch before hearing from the other both
call their key "epoch 1" — same id, different bytes. This is exactly what an add-device
fan-out walks into.

The resolution turns on whether the local epoch has been *used*:

- **Nothing is encrypted under it locally** — no `op_log_entries` row carries that
  `gdk_epoch`. The peer's key replaces the local one and a warning is logged. Adopting costs
  nothing and makes the sender's rows readable. Dropping the inbound wrap instead, which is
  what an early version did, left every row the sender had encrypted permanently unreadable
  on this device.
- **A local row already carries that `gdk_epoch`.** The local key is the only way to read that
  row, so adopting the peer's would just trade one unreadable set for another. The local key
  is kept and the conflict is logged as an error rather than silently resolved.

## The mailbox carries two protocols

`relay_mailbox` is shared. For any given recipient it may hold epoch wraps *and* pairing
frames such as `PAIR_CONFIRM`, which the HTTP courier polls for separately.

The push step must forward only wraps. Forwarding indiscriminately sent a leftover
`PAIR_CONFIRM` down the Noise channel, where the reader discards it — and because that reader
stopped at the first frame it did not recognise, every wrap queued behind it was dropped too.
The peer ended up holding no epoch key at all, quarantining the very history it had just been
sent.

Ordering is what makes this reachable rather than rare: mailbox rows drain oldest-first, and a
pairing frame left over from the pairing ceremony is almost always *older* than the wraps that
follow it.

## The keyring lives outside the database

The decrypted keyring is persisted as an encrypted file at
`UserDataPathService::appPath('sync/gdk/<user_id>.enc')`, keyed by user id alone.

This bites in tests. `RefreshDatabase` rolls the database back between tests but does not
touch the filesystem, and SQLite reuses rowids after a rollback — so a later test whose user
lands on a previously-used id can inherit an earlier test's keyring file. Tests that care
either assert against a *delta* captured before the call, or `unlink()` the file first to
start from a genuinely empty state.

## See also

- [`architecture.md`](architecture.md) — where the GDK sits in the wider sync design.
- [`oplog-replay-under-live-triggers.md`](oplog-replay-under-live-triggers.md) — what happens
  to those encrypted rows when the log is replayed.
