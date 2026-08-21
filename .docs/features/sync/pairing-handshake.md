# Pairing two devices without trusting the network

Two devices have to end up holding each other's Ed25519 public key, marked confirmed, so that
op-log entries from one verify on the other. Every channel available to carry that key
authenticates nothing:

- **LAN discovery** is multicast. Anything on the subnet can answer.
- **The relay** is a deliberately zero-knowledge store-and-forward hop. It cannot read what it
  carries, and equally cannot vouch for it.
- **A QR code** is genuinely out of band — a camera pointed at a screen is not on the network
  — but only the *initiator's* half travels that way.

So the exchange cannot be the trust decision. Something the two humans can compare has to be,
and that is the six-word safety number. Everything before the comparison produces a
*candidate* identity; nothing before it grants anything.

## The token

`PairingTokenService::issue()` mints `bin2hex(random_bytes(16))` — 128 bits — and persists
only `sha256(token)`. The plaintext token is returned once, shown once, and never stored.

For hand-typing it is rendered by `WordCodeEncoder` as RFC 4648 base-32 (`A–Z`, `2–7`) in
four-character dash-separated chunks. That alphabet has no `0`/`O` and no `1`/`I`, so a code
read off one screen and typed into another does not depend on the reader telling those glyphs
apart. Decoding strips dashes and spaces, upper-cases, and insists the result is exactly 16
bytes — a truncated or over-long paste fails as "invalid code" instead of silently missing
the lookup.

`pairing_tokens` is a transient scratch table. The permanent trust store is
`device_registry`, so `prune()` — which deletes every row past its TTL or already terminal —
can never lose trust state. It runs before each mint so stale initiator key material does not
accumulate.

### Expiry is compared as text

`expires_at` is a TEXT column compared **lexically** in SQL, by several writers and more
readers. That is correct only while every value shares one zero-padded **Zulu** format, so
`PairingExpiry::stamp()` is the single way any of them produces one: it converts to UTC *and*
asserts the shape, and throws rather than writing a value the comparison cannot order.

The invariant used to be asserted in a comment instead, and the comment was wrong —
`toIso8601String()` emits the *app timezone's* offset, and every live row read `+02:00`. That
was self-consistent only because one process wrote and compared them all. It stops being
self-consistent the moment the two sides of a comparison disagree about the offset: across the
DST changeover, on a device whose timezone changes between issue and comparison, or between
the app and its own `sync:serve` daemon, which is a separate process against the same database
and need not have inherited the same `TZ`. An offset form sorts against a Zulu `now` by its own
local hour digits, so the failure is quiet in both directions — a good code refused, or a TTL
that silently stretches by the size of the offset.

The same invariant is enforced the same way, and for the same reason, on `relay_mailbox`
(`RelayMailbox::assertZulu()`).

Rows written before the guard existed are **deleted**, not rewritten
(`2026_08_21_000001_drop_pairing_tokens_written_at_a_local_offset`). A mixed-format column
cannot be compared lexically at all, and this table is transient scratch — the permanent trust
store is `device_registry`, so nothing is lost. A handshake could not have survived the app
restart that runs the migration either way.

## Two ways in, one shape

**Camera.** The QR carries `beatrax://pair?v=1&token=…&ed=…&kx=…&device=…`, optionally the
initiator's device name, and optionally relay bootstrap parameters. The relay endpoint,
bearer token and certificate pin travel together in `RelayBootstrap` because the last two
mean nothing without the first — a bearer token with no endpoint is a secret with no
destination. The pin is what makes a self-signed relay certificate trustworthy: the QR is an
out-of-band channel the network cannot touch, so the key it names is the only one accepted.

**Typed word code.** The responder has the token but not the initiator's keys.
`LanPairingOfferFetcher` browses for devices advertising the sync service and asks each one
in turn — at most eight, so a hostile peer answering a browse many times over cannot cost
one request per answer — whether it holds this token.

The offer request carries `sha256(token)`, never the token itself. The row is stored under
the hash, so the hash is all a lookup needs, and the token stays on the device that typed it
rather than going to whichever peer answered a multicast question first. The reply carries a
device id and two public keys and nothing else — no relay parameters, for the reasons set out
in [LAN discovery: everything it finds is a guess](lan-discovery-trust-model.md#get-pairoffer-the-one-route-in-front-of-the-websocket).
A reply is discarded unless *both* keys decode, so a hostile answer cannot reach the seeding
call with half an identity.

`PairingOfferService` serves the other side of that exchange, and answers only while the
handshake is still live: `awaiting_confirm` counts alongside `pending`, because a responder
retrying after a lost frame is asking about a row its own accept already advanced. The name
it returns is the row's own `initiator_name` when the row was seeded, and otherwise this
device's registry name — a row this device issued carries no stored initiator name.

`PairingOfferRateLimiter` caps a source IP at 10 requests per 60 seconds. A 128-bit token is
already infeasible to guess; the limiter's real job is to stop the endpoint being usable as a
cheap probe for whether a pairing is currently in flight. Its own window map is pruned once
it exceeds 1024 tracked sources, so the limiter cannot become the exhaustion vector it
guards against.

### Why the responder seeds a local row

`accept()` binds a responder identity onto a row **this database already holds**. On the
initiator that row exists — `issue()` wrote it. On the responder it does not: the initiator's
identity arrived from a QR or an offer reply, not from a local mint.

`seedFromInitiator()` is the counterpart of `issue()` for exactly that reason. It writes a
local `pairing_tokens` row carrying the scanned initiator identity, keyed on the same token
hash, and is idempotent — a row already seeded for this token hash and user is returned as-is
rather than duplicated. It also stamps `initiator_seeded_at`, which later decides whether the
initiator is admitted at all (see [Admission](#admission)).

Key material is validated at every one of these boundaries. `SafetyNumberDeriver::hexToRawKey()`
requires exactly 64 lowercase hex characters and throws a typed `InvalidPublicKeyException`
rather than a raw `SodiumException`, so the Livewire layer surfaces a generic "invalid code"
flash instead of a 500 — and so the stored `*_pub_hex` columns are always well-formed by the
time safety-number derivation reads them.

## The safety number

`SafetyNumberDeriver::derive()` takes the two raw 32-byte Ed25519 public keys, **sorts them
byte-wise**, hashes the concatenation, and maps six two-byte chunks through `% 2048` into the
BIP39 English word list.

The sort is what makes the six words order-independent: both sides call `derive()` with the
arguments in whatever order they happen to hold them, and both get the same list. If the
words match on both screens, the keys match, and the humans have authenticated the exchange
out of band.

Keys are asserted to be exactly 32 raw bytes before hashing. A safety number derived from a
short or over-long key is meaningless, and silently hashing junk would produce six words that
look exactly as convincing as real ones.

The word list is a flat 2048-entry constant rather than a Composer dependency — small,
stable, and directly security-relevant, since the derivation is nothing but an index into it.

## The state machine

```
pending ──accept()──► awaiting_confirm ──both sides confirmed──► confirmed
   │                        │
   └────────────────────────┴──► expired  (TTL elapsed, at any point)
```

`accept()` requires state `pending` and an unexpired row. That is the single-use guard: once
accepted the row leaves `pending`, so a replayed accept of the same token can never advance it
again.

Accepting extends the window by five minutes — but only ever **grows** it. The new expiry is
`max(existing expiry, now + grace)`, so an accept that arrives early cannot shorten a
handshake that still has longer to run.

`confirm()` derives which side is confirming from the **caller's own device id**, never from
a client-supplied string. A device can only ever confirm the side it actually owns; an
unknown token and a device that owns neither side collapse to the same refusal.

## The relayed frames

When the two devices are not on the same LAN, two frames travel via the relay.

**`PAIR_RESPONDER_ACCEPT`** (phone → desktop) carries the responder's device id and public
keys so the desktop's local row can bind them. It makes no trust decision. Applying it is
idempotent for a redelivery of the *same* responder and refuses a *different* one — first
binding wins. Unlike `accept()`, the lookup deliberately has no state filter: a redelivered
frame has to be recognisable as idempotent even after the row has already advanced past
`pending`. Anything terminal, expired into another state, or unrecognised fails closed
rather than re-opening a handshake that has moved on.

**`PAIR_CONFIRM`** is Ed25519-signed by the confirming device's own secret key, which the
relay never holds and therefore cannot forge. `PairingFrame::confirmSigningMessage()` covers,
length-prefixed and behind a domain-separation context:

```
beatrax-pair-confirm:v1 | token_hash | confirming_device_id | peer_device_id
                        | confirming device X25519 | peer device X25519
```

Length-prefixing each field means a value containing the `|` delimiter cannot shift field
boundaries in the signed string. The domain-separation prefix means a signature minted here
can never be replayed as valid input to another signing domain, even though the same Ed25519
device key signs elsewhere (`GdkEpochWrapSignature` uses the same construction for the same
reason).

Binding **both X25519 sealing keys** is the non-obvious part. The Ed25519 identity is what
the safety number authenticates; the X25519 key is what the transport later seals to. A relay
that swapped the responder's X25519 in the accept frame would otherwise get itself into the
sealing path while the safety words still matched. Committing to the sealing keys as each
device holds them means a swap makes the two sides reconstruct different messages, and the
verify fails.

This is deliberately **not** checked with `verifyAny()` against a legacy payload shape that
omitted the X25519 keys. Accepting the old shape would re-open exactly the substitution it
closes. A cross-version pairing fails closed and simply retries once both devices update; no
persistent trust state is stranded.

### The gate sequence, in dependency order

`PeerConfirmVerifier::authenticatePeerConfirm()` runs these in order because each depends on
the one before:

1. **Locate the row** by token hash, in `awaiting_confirm` or `confirmed`, unexpired. The
   hash is then re-checked in constant time against the column — the `WHERE` already matched
   it, so this guards against the column and the query disagreeing.
2. **Is the frame addressed here?** `peer_device_id` is populated by the *sender* from its own
   view of who the recipient is. On receipt it must equal this device's own self identity, or
   the frame was never meant for this device.
3. **Which side does this device own?** Established from the row, and checked before the
   signature step — the peer columns the signature check reads are chosen by that side.
4. **Is the signature authentic?** The frame must be signed by the key **the row bound for the
   peer side**, and `confirming_device_id` must equal the device id that same row bound. Both
   are checked against the row, never against the frame's own assertions about who it is.

A `PeerConfirmContext` is returned only when all four pass. Its existence *is* the assertion
that the gates passed; nothing downstream re-derives a side or re-checks a signature.

5. **Has the local human confirmed yet?** This is the load-bearing one. A validly signed peer
   confirm cannot by itself drive the row toward `confirmed`. Until the local user has
   visually matched the six words and tapped confirm, the result is `'deferred'` and the
   relay row is **left in the mailbox** for redelivery. Without this gate, a peer that
   completed its own confirmation would drag the local device into a confirmed pairing the
   local human never approved — which would make the safety-number comparison decorative.

## Admission

`finalizeIfBothConfirmed()` is the shared tail of both the local `confirm()` and the relayed
`applyPeerConfirm()`, so both paths reach identical admission semantics:

```
both sides confirmed → state = confirmed → admit responder → [ admit initiator ]
```

The responder is always admitted into `device_registry`. The initiator is admitted **only**
when `initiator_seeded_at` is set — that column is written exclusively by
`seedFromInitiator()`, so it marks a row built from a genuinely scanned QR or fetched offer.
A row created by `issue()` holds a placeholder initiator device id belonging to this very
device, and admitting from it would write a bogus peer row.

`PairedDeviceAdmitter` derives the stored safety words from `(initiator Ed25519, responder
Ed25519)` in that fixed order, so both sides of a pairing persist the identical word list —
the derivation itself is order-independent, but fixing the argument order keeps the two
databases byte-identical.

Two guards protect the self row: the admit returns early if the incoming device id equals this
user's `is_self` device id, and every lookup and update is additionally scoped to
`is_self = 0`. A crafted peer device id therefore cannot overwrite this user's own keys even
if the first guard were bypassed.

The stored name comes from the peer's own accept frame, or a translated placeholder. It is
never `DeviceNameDetector`, which reports *this* machine — that is how a paired phone once
appeared in the device list as "This device (Mac)". The name is cosmetic throughout: it is not
part of the signed confirm message and grants nothing, so a forged one is a wrong caption and
never a trust decision.

## Draining the mailbox

`PairingRelayCourier::drainAndApply()` polls this device's own relay mailbox. It never throws
out of the poll — a missing self device, an unconfigured relay, a drain secret that cannot be
minted and a transient relay outage all collapse to "nothing to poll".

Each device presents its **own** per-device drain secret, TOFU-verified by the relay, rather
than a token every relay peer could recompute.

The rule that matters is when a mailbox row is deleted. A row is confirmed away only when it
is **terminally** handled:

| Outcome | Terminal? |
| --- | --- |
| Undecodable blob or unparseable JSON | yes — it will never become decodable |
| `PAIR_RESPONDER_ACCEPT` | yes — it either binds, no-ops idempotently, or fails closed |
| `PAIR_CONFIRM` returning `'deferred'` | **no** — left for redelivery once the local side confirms |
| `PAIR_CONFIRM`, any other result | yes |
| `GDK_EPOCH_WRAP` | **no** — not this transport's frame |

That last row is the one that bites. Epoch wraps wait in the same mailbox for the
authenticated sync session to carry them; a pairing poll that confirmed one away deleted it,
and the peer was left permanently without that epoch's key.

Device ids arriving in a drained frame are accepted only if they are a UUIDv4 — the exact
shape `DeviceIdentityService` mints. That bounds length and character set, and structurally
excludes the `|` delimiter used in the signing message.

## The two roads, and why the LAN one had to be built

The frames above travelled one road: the relay. That is correct only for devices
that cannot see each other, which is what this page said and what the
implementation did not do — with no relay configured, two devices on one wifi
could not pair at all. The phone re-emitted its accept 86 times over four minutes
against a `RelayRefusedException` and then stopped without saying so.

The WebSocket cannot carry these frames. Its Noise session authenticates against
the confirmed-device registry, and a device mid-pairing is by definition not in it
yet. So the frames take the shape `/pair/offer` already took — routes in front of
the upgrade, answered by the listener itself.

| Route | Direction | Who answers |
|---|---|---|
| `POST /pair/frame` | responder → initiator | the listening device applies it |
| `GET /pair/frames?device=` | initiator → responder | the listening device hands over what is waiting |

Two routes rather than one because only ONE side of a pairing listens. The desktop
runs the daemon; a phone runs no server and advertises nothing, so it can never be
dialled. Rather than the desktop pushing, the phone collects on the three-second
poll it already runs.

`PairingFrameCourier::deliver()` tries the LAN, then the relay, then holds the frame
in `PairingPeerOutbox` for collection. The fallback is silent by design: which road
a frame took is not something the reader chose or can act on.

The outbox reuses `relay_mailbox` rather than adding a table. The shape is already
right — routed by device id, one pending index, its own garbage collection — and a
second table with the same columns is how two expiry policies drift apart. Only
pairing frames are ever served out of it, for the reason the drain table above
gives: an epoch wrap handed to whoever asked would be marked delivered and strand
the peer without that key.

This is not a new trust boundary. These frames already crossed a channel that
authenticates nothing, the relay being deliberately zero-knowledge, so every
guarantee lives inside the frame. Carrying them over the LAN removes a third party
rather than adding one.

## Opening the pairing screen must not restart the listener

The daemon reads its Noise transport keypair once, from the environment it was
spawned with, so a daemon started while the app was locked holds none and
refuses every handshake. The only way to hand it one is to spawn it again:
`SyncTransportCredentialsAvailable` stops the running `sync:serve` and starts a
replacement.

`PairingFlowModal::openModal()` used to dispatch that on **every** open, which is
self-defeating on the open that matters. The ceremony tells the user to come back
to this screen to compare the six words; coming back tore down the process that
was serving the handshake, five seconds after the click, and the modal that did it
then found nothing left to resume.

So the dispatch is conditional on `PairingGateway::hasLiveHandshake()`: no live
row, and a restart costs nothing — it is the warm-up before a ceremony that has
not started. A live row, and the running daemon is left alone. `pending` counts as
live alongside `awaiting_confirm`: a code has been shown and the peer may be
dialling it at that moment, which is exactly the window the observed failure fell
into.

One case is left uncovered, deliberately. A daemon that booted keyless while a
handshake was already live — only reachable by relaunching the app mid-ceremony —
stays keyless for the rest of that ceremony, which keeps the LAN road shut and
leaves the relay and the outbox. Cancelling expires the row, and the next open
credentials the daemon normally. Restarting to rescue that case would mean
restarting in exactly the state where a restart is destructive.

## What a confirmation is bound to

`confirm()` takes the fingerprint of the six words the human actually compared and
refuses if the row's keys no longer produce them.

Without that, the tap means "confirm whatever this row says now", and a responder
that rebinds between the reading and the tap inherits a confirmation nobody gave
it. That matters because a binding no one has confirmed CAN be replaced: first
binding wins absolutely handed anyone on the same network a permanent denial —
answer an mDNS browse, harvest the token hash the responder sends in the clear,
race an accept in first, and the real phone could never bind. Allowing the
replacement fixes the denial; binding the confirmation to the compared keys is what
stops the replacement becoming a capture.

The digest is over the derived words, not the keys, so it is exactly the thing the
two humans compared and is order-independent for the same reason `derive()` is.

## See also

- [Pairing two devices that share no database](cross-device-pairing-confirm.md) — the same
  ceremony told as a flow, plus what a relay attacker can and cannot achieve.
- [LAN discovery: everything it finds is a guess](lan-discovery-trust-model.md) — the
  discovery and offer-endpoint side of the typed-word-code path.
- [Device identity and its key file](device-identity-key-files.md) — where the Ed25519 and
  X25519 keys being exchanged come from.
- [Getting a group data key epoch onto every device](gdk-epoch-wrap-delivery.md) — what the
  newly confirmed device receives next.
- [Removing a device](device-removal-and-epoch-rotation.md) — the reverse operation.
- [Sync architecture](architecture.md).
