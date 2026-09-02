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
| `sender_holds_keyed_rows` | Blind-index wraps only: whether the sender holds rows keyed under **this** key. Read only for that role — an epoch wrap does not sign it, so reading it there would let a value outside the signature decide the message's fate. |

The signature is what turns an anonymous sealed box into an authenticated one. It is the
answer to the second failed approach above.

### A wrap on this channel may not be an epoch

Read `key_role`, never `epoch_id`. `GdkEpochWrapSignature::carriesEpoch()` is the predicate; a
blind-index wrap sends `epoch_id: 0` only because the field is required by the shape and bound
into the signature. Anything that counts or iterates wraps and reasons about *epochs* must
filter on the role first — two tests asserting "exactly one epoch was fanned out" started
counting two the day the blind-index key began riding this channel.

The distinction is easy to miss one level up: the `count` in the `GDK_EPOCH_PUSH` header is a
**frame** count, not an epoch count. Including the blind-index wrap in it is right — the receiver has to read that many frames.
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

The role term is **length-prefixed**, like every other variable-length field in that message.
It was the one exception, and it was safe only because a second class allowlisted the value
before the message was built — a coupling between two files that nothing stated. The allowlist
now runs *after* verification (see the gates below), so an arbitrary role string reaches the
signing message and the prefix is load-bearing rather than decorative. That changes the bytes a
blind-index wrap signs; an epoch wrap is untouched.

What the receiver does with an adopted blind-index key, and when it refuses to adopt one, is
in [Which columns are encrypted at rest](sensitive-columns-at-rest.md).

The wrap is enqueued on `relay_mailbox` as an opaque blob addressed to the recipient. Nothing
about the enqueue depends on the recipient being online.

## Three delivery legs

Delivery is not one flow, and the legs are independent:

1. **Push on connect, and read the peer's push back.** When a peer completes a Noise handshake,
   `SyncWebSocketHandler::deliverGdkEpochWraps()` sends a `GDK_EPOCH_PUSH` header frame
   (`{type, count}`) followed by one frame per pending wrap. Noise cipher states are
   counter-based, so frames open in exactly the order they were sealed — the header first, the
   wraps behind it. The peer then answers `GDK_EPOCH_ACK {count}` and **only that many rows are
   retired**, in the order they were sent. The same exchange then runs inverted: the peer
   announces its own push, this side applies it and answers with its own ack. Both directions
   always run, so neither side blocks on a phase the other skipped.
2. **Drain your own mailbox.** `InboundGdkWrapDrain::drainFor()` reads this device's own inbound
   `relay_mailbox` rows and routes each blob through `GdkEpochControlHandler`. This needs no
   peer session at all — a wrap that arrived via an external relay or a forwarding peer
   converges the keyring on its own. It pages by cursor through `PendingMailboxScan` — the same
   bounded walk leg 1's `pendingWrapsFor()` reads through — so a row it cannot retire costs one
   slot of a scan budget rather than holding the head of the window. One pass carries at most
   `MAX_WRAPS_PER_PASS` wraps, which is exactly what leg 1 clamps the peer's ack count to. It
   also expires rows past the mailbox TTL on the way through, which is the only thing that
   honours `expires_at`.
3. **Come back for it unlocked.** The drain above is called from the listener, and the listener
   can never open a wrap (see the outcomes below). So it is *also* called from contexts that do
   hold the app-lock key: `DevicesAndSyncSettingsSection::mount()` on the desktop, and
   `MobileSyncTriggerService::syncOnce()` on the phone, whose tick already proved it holds the
   key by resolving a device identity. Without this leg the retry outcome had no reachable
   consumer and a deferred wrap was deferred forever.

### Leg 4: repay a fan-out the screen never reached

`fanOutAllEpochsToDevice()` is called from `PairingFlowModal::enterSuccessStep()`,
and that method has exactly two call sites — the initiator's own `confirmMatch()`,
which reaches it only when the peer has *already* confirmed, and
`checkPairingState()`, the `wire:poll.3s.keep-alive` tick. Both live inside that
modal component, so "whichever side learns of the both-confirm first" is only
true while the modal is mounted. The two confirms are taps on two devices,
seconds apart, by one person walking between them; close the window, leave the
screen, quit the app, or let the app-lock idle timeout fire in that gap and the
token still reaches `confirmed` and the peer is still admitted to the registry —
with no epoch ever queued for it.

Nothing downstream noticed. `RelayMailbox` has no re-send, `fanOutAllEpochsToDevice()`
fired once, and `DevicesScreenOpening::recoverDeferred()` replays *local*
quarantine and never fans out to a peer. A fresh iPhone paired this way held
4,223 op-log entries, quarantined 460 of them `gdk_decrypt_failed`, and sat on
"Waiting for the encryption keys from the other device" permanently, with every
other signal — safety words matched, device listed, token `confirmed` — saying
the pairing had worked.

`device_registry.epochs_delivered_at` records the debt: null means owed, and
`fanOutAllEpochsToDevice()` stamps it inside the same transaction as the wraps
it accounts for, so a crash cannot mark a peer supplied by wraps that rolled
back. `DeliversOwedEpochs`, a terminate-time middleware beside
`ResumesPreSyncCapture`, reads `peersOwedEpochs()` after every authenticated
response — one covered lookup, and with nothing owed that read is the whole cost
— and pays what it finds. The column starts null for every existing peer on
purpose: re-delivery is idempotent, because an epoch already in the keyring
returns `Applied`, so the first request after the upgrade repays any device the
missed fan-out already stranded.

**Acknowledging before retiring is the point of leg 1.** Confirming a row the moment it was
handed to the transport marked it delivered even when the connection dropped before the peer saw
it, and `RelayMailbox` has no re-send: `fanOutAllEpochsToDevice()` fires once, at pairing. The
receiver's half of that contract is that it never acknowledges a wrap into nothing — a blob it
cannot open is written into **its own** inbox first, which is what makes an acknowledgement
truthful and what leg 3 later comes back for.

## The gates, in order

`GdkEpochControlHandler::handle()` applies five checks, and the *order* is part of the contract:

1. **Envelope shape.** A message missing `epoch_id`, `wrapped_key_b64`, `recipient_device_id`,
   `sender_device_id` or `sig_hex` is set aside before any libsodium call is made. So is one
   whose `key_role` is not a string, or whose `sender_holds_keyed_rows` is not a boolean on a
   wrap that actually carries that role.
2. **Recipient identity.** `recipient_device_id` must be this device. A wrap addressed
   elsewhere is rejected here — *before* the sender is ever looked at, so a foreign-recipient
   wrap from a perfectly legitimate sender still goes nowhere.
3. **Sender authenticity.** `sender_device_id` must resolve to a device in this user's
   registry with a non-null `confirmed_at`, and `sig_hex` must verify under that device's
   Ed25519 public key. Both halves run *before* any `seal_open`.
4. **What the role names.** Only now is `key_role` compared against the roles this build knows,
   and a blind-index wrap required to carry the reserved `epoch_id`. Judging either earlier is
   what let a value the signature does not cover decide a message's fate — see below.
5. **Open.** Only now is the sealed box opened with this device's X25519 secret key.

The consequence worth remembering: a wrap can fail for exactly one reason at a time, and which
reason depends on where it falls in that list. Tampering with the sealed bytes is caught by the
signature check at step 3, not by an AEAD failure at step 5.

### Four outcomes, and which of them may retire the carrier

`handle()` returns a `GdkWrapOutcome`, and the caller that owns a mailbox row asks
`consumesCarrier()` rather than restating the split:

| outcome | meaning | carrier |
| --- | --- | --- |
| `Applied` | the key is in the keyring, or was already | retired |
| `Deferred` | cannot be decided **yet** | kept |
| `Retained` | decided, and deliberately not adopted, over a local key with more claim | kept |
| `Refused` | provably invalid for this device however often it is redelivered | retired |

It is an outcome rather than an exception because this class is contractually forbidden from
throwing — the `catch (Throwable)` that once gated the confirm could never fire, so every wrap
the listener could not open was confirmed away. `RelayMailbox` has no re-send and
`fanOutAllEpochsToDevice()` fires once, at pairing, so that deleted the only copy of the key.

`Deferred` covers "no app-lock key in this process", which is the **permanent** state of the
`sync:serve` daemon — it resolves a `Session` no middleware ever started, so
`AppLockKeyService::release()` returns `null` unconditionally. It also covers two conditions
that are not permanent at all and used to be terminal:

- **A sender this device has not confirmed yet.** During pairing, whether the registry row is
  written before the wrap arrives is an ordering question, not a fact about the sender. Refusing
  and retiring meant a wrap that arrived one step early was destroyed.
- **An envelope this build cannot parse, or a role it does not know.** A stored blob some other
  party mutated is indistinguishable from one a later build wrote in a shape this build has not
  learned. Retiring the first destroys a key nothing re-sends; keeping both costs a mailbox row
  until the TTL reclaims it.

That second point is why step 4 moved. `sender_holds_keyed_rows` was parsed and type-checked
*before* the signature ran and, for an epoch wrap, is **not in the signing message at all** — so
appending one JSON key to a stored blob made `extractWrapFields()` return null, retired the row,
and destroyed a GDK epoch key with no signature ever objecting. The field is now read only for
the role that signs it, and the role allowlist runs only over a value a verified signature
covers.

The drain also skips a blob that is not a wrap at all, instead of handing it to the handler and
retiring the `Refused` away. Two protocols share this mailbox, and `pendingWrapsFor()` applies
the same guard in the outbound direction.

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

The resolution when two ids do collide turns on whether the local epoch has been *used*:

- **Nothing is encrypted under it locally** — no `op_log_entries` row carries that
  `gdk_epoch`. The peer's key replaces the local one and a warning is logged. Adopting costs
  nothing and makes the sender's rows readable. Dropping the inbound wrap instead, which is
  what an early version did, left every row the sender had encrypted permanently unreadable
  on this device.
- **A local row already carries that `gdk_epoch`.** The local key is the only way to read that
  row, so adopting the peer's would just trade one unreadable set for another. The local key
  is kept, the conflict is logged as an error rather than silently resolved, and the outcome is
  `Retained` — the wrap still holds the only copy of the peer's key for that epoch, which is
  what reading the peer's rows would need.

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

- [Which columns are encrypted at rest](sensitive-columns-at-rest.md) — what an adopted
  blind-index key decides, and the two-sided exchange that decides it.
- [`architecture.md`](architecture.md) — where the GDK sits in the wider sync design.
- [`oplog-replay-under-live-triggers.md`](oplog-replay-under-live-triggers.md) — what happens
  to those encrypted rows when the log is replayed.
