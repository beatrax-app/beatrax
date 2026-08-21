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
| `epoch_id` | Which epoch these bytes are the key for. `0` when the wrap is not an epoch. |
| `wrapped_key_b64` | The raw key sealed to the recipient's X25519 public key. |
| `recipient_device_id` | Who it is addressed to. |
| `sender_device_id` + `sig_hex` | Ed25519 detached signature over the signed message. |
| `key_role` | What the sealed bytes are. Absent means `epoch`. |

The signature is what turns an anonymous sealed box into an authenticated one. It is the
answer to the second failed approach above.

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

The consequence worth remembering: a wrap can fail for exactly one reason at a time, and
which reason depends on where it falls in that list. Tampering with the sealed bytes is
caught by the signature check at step 3, not by an AEAD failure at step 4.

## Idempotence and epoch-id collisions

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
