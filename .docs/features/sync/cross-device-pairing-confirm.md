# Pairing two devices that share no database

Pairing has to end with a symmetric result: the desktop holds the phone in its
`device_registry` with `confirmed_at` set, the phone holds the desktop in *its* registry the
same way, and both screens showed the human the same safety words before either committed.

The awkward part is that the two devices share nothing. Each has its own `pairing_tokens` and
`device_registry` tables. The only channels between them are a QR code the human physically
carries across (out of band, and therefore trustworthy) and a relay that forwards frames
(in band, and therefore not).

Two tempting shortcuts fail:

- **Trust what the relay delivers.** A relay that substitutes its own identity into the
  responder-accept frame becomes the desktop's "phone".
- **Test it against one database.** Two distinct `device_id` values written to the *same* row
  can never demonstrate that anything propagated. Cross-device tests need genuinely separate
  databases per side, which is what `CrossDevicePairingHarness` provides.

## The handshake

1. **Desktop issues.** `issue()` writes a `pairing_tokens` row carrying the initiator's
   identity. `initiator_seeded_at` stays `NULL` — this row was created locally, not from a
   scan.
2. **Phone seeds.** After scanning the QR, `seedFromInitiator()` creates the phone's *own*
   local row, binding the initiator identity that came off the QR and stamping
   `initiator_seeded_at`. Without this step `accept()` would find no pending row at all,
   because the desktop's row lives in a database the phone cannot see. Repeated scans of the
   same code are idempotent — same row, no duplicates. Malformed key material is refused
   without writing anything.
3. **Phone accepts.** `accept()` binds the phone's own responder identity and moves the row
   to `awaiting_confirm`.
4. **Desktop applies the accept.** `applyResponderAccept()` writes the responder identity
   carried by the frame and reaches `awaiting_confirm` too.
5. **Both sides derive safety words** from the initiator and responder Ed25519 public keys,
   through the one shared `PairingGateway::safetyWordsFor()` seam rather than a per-screen
   copy. They match only if nobody tampered with step 4.
6. **Each human confirms locally** (`confirm()`), and each side sends the other a
   `PAIR_CONFIRM` signed with its Ed25519 device key over
   `PairingFrame::confirmSigningMessage(tokenHash, fromDeviceId, toDeviceId, fromKx, toKx)`.
7. **Each side applies the peer's confirm** with `applyPeerConfirm()` and admits the peer.

## The three answers `applyPeerConfirm()` can give

They arrive as a `PeerConfirmResult`, or as `null`. Deferral is deliberately not spelled with a
`PairingState`: a deferred frame moves no row, so there is no state to name, and putting one on
the enum would make it a value the `state` column could be written from.

| Result | Meaning |
| --- | --- |
| `PeerConfirmResult::applied(PairingState::Confirmed)` | Signature verified and the local human had already confirmed. The peer is admitted. |
| `PeerConfirmResult::deferred()` | Signature verified, but the local human has *not* confirmed yet. **No confirmation is recorded**, and the answer carries no state at all. |
| `null` | Rejected: bad signature, or the local row is expired or cancelled. |

Deferral is the load-bearing one. A correctly-signed frame can never complete a pairing on
its own — arriving early, the row stays `awaiting_confirm` with the peer column unset. Both
halves of the gate are local: your own human, and the peer's signature.

What the early frame does not do is vanish. It is parked on the row and replayed — verified
again from scratch — the moment the local human confirms, because the peer that sent it stops
re-emitting as soon as *its* side reaches `confirmed`, and a LAN push is answered `202` and
kept by nobody. See
[a deferred confirm is held, not dropped](pairing-handshake.md#a-deferred-confirm-is-held-not-dropped).

## Neither half of this depends on a screen being open

Both devices reach the state above by tapping in a modal, and the ordinary next thing a human
does is close it. What used to move the frames — drain the mailbox, re-emit this side's
confirm — lived on that modal's three-second poll, so closing it on either device stopped
redelivery and left the ceremony half-finished.

`PendingPairingCourier` does that work now, driven by the `sync:serve` timer on a desktop and
by the ordinary request cycle on a phone, which has no daemon and no scheduler to give it. It
carries confirmations; it never creates one, because the only thing it re-emits is a frame the
local `<side>_confirmed_at` stamp says the human already authorised. See
[redelivery must not depend on an open screen](pairing-handshake.md#redelivery-must-not-depend-on-an-open-screen).

## What a relay attacker can and cannot do

Substituting an attacker identity into the responder-accept frame makes the two screens show
*different* safety words. That is the whole point of the safety number, and it is the human's
job to notice.

If the human confirms anyway, the attacker — who genuinely owns the key it substituted — can
complete that one row on the desktop. This is a human gate, not a cryptographic one, and the
design accepts it.

What the attacker can never do is complete the **real phone's** row. The phone bound the real
desktop's Ed25519 public key from the physically-scanned QR, which never travelled over the
relay. A forged `PAIR_CONFIRM` claiming to be the desktop fails signature verification against
that key, `applyPeerConfirm()` returns `null`, and no desktop key ever lands in the phone's
registry. The blast radius of a substituted identity is one device's row, not the pair.

## Admission rules

- **Only a seeded initiator is admitted.** A row created by plain `issue()` carries a
  placeholder initiator id, not a real device. Confirming such a row admits the *responder*
  and must never create a phantom registry row for the initiator. `initiator_seeded_at` is
  what distinguishes the two paths.
- **A device id colliding with the local self-row is refused.** A crafted QR carrying this
  device's own `device_id` must not overwrite this device's own keys, and must not produce a
  second non-self row for the same id. The guard is symmetric — it holds on the initiator
  side and the responder side alike.
- **Re-delivery is idempotent.** Applying the same responder-accept twice returns the same
  row; applying the same `PAIR_CONFIRM` three times leaves exactly one registry row for the
  peer.
- **Expiry is final.** A cancelled row (`expire()`) and a naturally TTL-lapsed row behave
  identically: a later `PAIR_CONFIRM` is rejected and nothing is admitted. The grace window
  is five minutes.

## See also

- [`architecture.md`](architecture.md) — the pairing ceremony in the wider sync design.
- [`gdk-epoch-wrap-delivery.md`](gdk-epoch-wrap-delivery.md) — what gets sent to a device once
  it is confirmed, and why leftover pairing frames on the shared mailbox matter.

## Why the courier sends before it collects

`PendingPairingCourier::tick()` re-emits this device's own confirm *before* it
drains what has arrived, and the order is load-bearing rather than stylistic.

Collecting first can finish the ceremony on this device — the peer's confirm
lands, both sides are stamped, the row reaches `confirmed`. But a finished row
is the courier's own stop signal: `liveCeremonyOwnedBy()` answers only for
`pending` and `awaiting_confirm`. So the tick that completed the pairing would
return without ever having offered this device's confirmation to the peer, and
the peer would sit at `awaiting_confirm` until the token expired.

That is precisely the one-sided pairing the courier exists to end, rebuilt one
level up — and it is what the first implementation did. The asymmetry in cost is
what settles it: re-sending a frame the peer already holds costs one idempotent
apply, while not sending it costs the peer the pairing.
