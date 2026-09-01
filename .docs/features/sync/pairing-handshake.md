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
bytes, instead of silently missing the lookup.

### A code that will not decode has not expired

16 bytes of base-32 is 26 characters, which `encode()` chunks into seven groups. The entry
field's placeholder showed four — `XXXX-XXXX-XXXX-XXXX` — so a reader who typed exactly the
shape they were shown produced a code the decoder could not read, and was answered with
*"This code is invalid or has expired. Ask the other device to generate a new one."* A fresh
code fixes nothing there: nothing was ever compared, and the reader has letters missing. The
placeholder now carries the real shape, and a code that fails to decode is refused with
`sync::pairing.code_incomplete` on the desktop and `mobile::pairing.errors.code_incomplete`
on the phone. `invalid_code` — the one that asks for a new code — is kept for the case that
actually calls for it: a complete code no live pairing row answers.

`pairing_tokens` is a transient scratch table. The permanent trust store is
`device_registry`, so `prune()` — which deletes every row past its TTL or already terminal —
can never lose trust state. It runs before each mint so stale initiator key material does not
accumulate.

### Expiry is compared as text

`expires_at` is a TEXT column compared **lexically** in SQL, by several writers and more
readers. That is correct only while every value shares one zero-padded **Zulu** format, so
`Instant::zulu()` is the single way any of them produces one: it converts to UTC *and*
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

`relay_mailbox.expires_at` is compared the same way for the same reason, and goes through the
same call — one implementation with one error message, rather than a second copy of the rule in
`RelayMailbox` that could drift from this one.

Rows written before the guard existed are **deleted**, not rewritten
(`2026_08_21_000001_drop_pairing_tokens_written_at_a_local_offset`). A mixed-format column
cannot be compared lexically at all, and this table is transient scratch — the permanent trust
store is `device_registry`, so nothing is lost. A handshake could not have survived the app
restart that runs the migration either way.

The guard first covered `expires_at` alone, because that is the column SQL orders. Its
siblings — `created_at`, `accepted_at`, `initiator_seeded_at` and the two `*_confirmed_at`
stamps — kept `toIso8601String()`, so one row carried a Zulu expiry beside a `+02:00`
confirmation and a reader could not tell from the value which rule it was written under. None
of them is compared lexically *today*; the rule is that the table has one format, not that
each column earns one the day something starts sorting it. Every writer goes through
`Instant::zulu()`, and `PairingRowTimestampsAreZuluTest` pins each of them.

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

All three LAN roads run that browse through one seam, `Pairing\LanPeerBrowser`, which owns
the browse timeout, the connect and request timeouts, and the peer bound. It is a
collaborator each road is given, not a trait each road mixes in: mixed in, it reached for
`$this->http` and `$this->discovery` on whatever class used it, so all three roads declared
two constructor dependencies their own bodies never named. Eight belongs to this road alone
and is passed in rather than declared: a typed code names no device, so any peer might be
the one holding it and asking too few asks the wrong ones. The seam's own default is four,
which is what a road spends when it already names the device it wants
(`LanPairingFrameCourier`, whose bound counts only peers advertising that id) or when it
runs on every three-second poll (`LanPairingFramePuller`). Both numbers are pinned in
`LanBrowseBoundsTest`, because a merge that flattened them would either halve the reach of a
typed code or double the blocking work inside a poll.

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

### Both clients have to ask

Seeding is a property of the **entry point**, not of the platform. Whichever device receives a
typed code is a responder holding a token and no keys, and that is as true of a desktop as of
a phone.

For a while only one of them knew it. `MobilePairingScan` asked the LAN and seeded;
`PairingFlowModal::submitCode()` decoded the word code and went straight to `accept()`, which
weighs it against **this** device's `pairing_tokens` — the one table the issuing device's row
has never been in. So the desktop arm could only ever accept a token this same device had
issued, and answered every real code with *"This code is invalid or has expired. Ask the other
device to generate a new one"* while the issuing screen showed that code with eight minutes
left on it. Both halves of the sentence were false, and desktop-to-desktop typed pairing had
never worked at all. `PairingFlowModalCrossDeviceTest` did not see it because its fixture
hand-seeded the responder with `seedFromInitiator()` — the very step the field does not supply.

Both surfaces now run the same three steps, and each reports the four `PairingOfferLookup`
endings in its own copy: `CodeNotAccepted` (a peer answered and refused — it may simply be the
wrong desktop), `CodeMalformed` (never asked, so no answer about the network can be true of
it), `NoPeerReached`, and `RateLimited`. `NoPeerReached` splits again on
`PeerDiscovery::reach()`: a silence where the question reached the network is a silence, and a
silence where it never left the device is an unasked question, which is a different sentence.

Accepting is not the end of the responder's turn. The initiator's row is on the initiator's
machine and nothing the accept wrote is visible there, so a `PAIR_RESPONDER_ACCEPT` frame has
to carry it across — otherwise the issuing device sits on its own show-code step until the
token lapses, while the responder waits at a trust gate the peer has never been shown.

### A phone can only be scanned

Resolving a typed code means browsing for `MdnsAdvertiser::SERVICE_TYPE` (`_beatrax-sync._tcp`)
and asking each answering peer for `PairingOfferRequestHandler::OFFER_PATH`. Both of those live
inside `sync:serve`, which is started only by `Modules\Desktop\Internal\Native\SyncListenerProcess`
and only when `Native\Desktop\Facades\ChildProcess` exists. No phone has that class, runs that
daemon, or advertises that service, and on iOS it cannot even issue the browse
([why](../mobile/ios-lan-discovery-entitlement.md)).

So a word code minted on a phone names a row nothing on the network can look up — not from a
desktop, and not from another phone. Fixing the desktop's own lookup does not change that; the
code is unfindable because nobody is offering it. The QR carries the identity inline and needs
no lookup at all, which is why it is the whole offer a phone can honestly make: `showMyCode()`
mints no word code on a mobile runtime, and the step renders `scan_on_other` in place of
`enter_on_other`. A computer has no camera, so the working route to a desktop is the other
direction — show the desktop's code and read it here.

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

### One derivation, both screens

The comparison is only meaningful if the two screens derive from the same row the same way, so
there is exactly one implementation of "read the row's two bound keys and turn them into six
words": `PairingTokenRowReader::safetyWords()`. Both screens reach it through the same call —
`PairingGateway::safetyWordsFor()` — the desktop from `PairingFlowModal`, the phone from
`MobilePairingScan`.

That includes the failure branches, which matter as much as the happy path: a row where only
one side has bound a key, and a bound key that no longer decodes, both yield an empty list on
both screens. An empty list is a comparison the human cannot make, and `confirm()` refuses the
digest it produces — so a divergence degrades into a refusal rather than into two screens
showing different words that one of them believes.

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

Two transports reach that binding: a typed code goes through `accept()`, a relayed or LAN
frame through `applyResponderAccept()` → `bindResponderOntoRow()`. Both take the new expiry
from `PairingTokenService::extendedExpiry()`, so the grow-only rule cannot hold on one route
and not the other.

### Nothing writes `expired`; the lapse is derived at read time

The `expired` edge in the diagram above is never a write. `expire()` runs only from an explicit
user cancel, and `prune()` **deletes** rather than marks and runs only when a *new* token is
minted — which the device that is merely waiting on a safety number never does. There is no
sweeper, and on a phone there could not usefully be one.

So the TTL lapse is derived wherever the row is read: `hasLiveHandshake()` and `inFlight()` do it
with `expires_at > Instant::zulu(now)` in SQL, and `PairingTokenRowReader::state()` — the
one the responder's 3-second poll goes through — does it in PHP, returning `expired` for a row
whose column still says `pending` or `awaiting_confirm`. Without it the phone's poll had the
right branch and could never reach it: the screen said "waiting for the other device" for as
long as it was left open, minutes after the token had died and the retry loop had given up.

Only `pending` and `awaiting_confirm` lapse (`PairingState::lapsesOnTtl()`). A `confirmed` row
past its expiry confirmed while it was still live — `confirm()` and `PeerConfirmVerifier` both
refuse past the TTL — and its trust already sits in `device_registry`, so reporting it as expired
would retract a pairing that really happened, which is what a phone backgrounded across the
boundary would have seen.

`confirm()` derives which side is confirming from the **caller's own device id**, never from
a client-supplied string. A device can only ever confirm the side it actually owns; an
unknown token and a device that owns neither side collapse to the same refusal.

Before it asks any of that it asks whether the ceremony is still one a tap may finish:
`ceremonyIsLive()` requires an in-flight `state` **and** `expires_at` in the future. It reads
both because the two say different things — `expired` is what `expire()` and
`expireUnfinished()` write when the reader cancels, and a lapsed `expires_at` is what the
countdown running out looks like on a row nobody wrote to. For a while it read neither, so a
ceremony the reader had cancelled could still be finished into a **confirmed** `device_registry`
row minutes or years later: full Noise admission, Ed25519 op verification and GDK fan-out
eligibility, for a pairing that had been called off. Accept-after-expiry, a second accept and a
relayed peer-confirm-after-cancel were all already refused; this was the one door that was not,
while `PairingState`'s own comment asserted that it was.

## The two frames

Two frames carry the halves neither device can compute for itself. They take whichever road is
open — see [The two roads](#the-two-roads-and-why-the-lan-one-had-to-be-built).

**`PAIR_RESPONDER_ACCEPT`** (phone → desktop) carries the responder's device id and public
keys so the desktop's local row can bind them. It makes no trust decision. Applying it is
idempotent for a redelivery of the *same* responder, and a *different* one may still take the
slot while nobody has confirmed anything — see
[What a confirmation is bound to](#what-a-confirmation-is-bound-to) for why replacement had to
be allowed and what stops it becoming a capture. Unlike `accept()`, the lookup deliberately has
no state filter: a redelivered frame has to be recognisable as idempotent even after the row
has already advanced past `pending`. Anything terminal, expired into another state, or
unrecognised fails closed rather than re-opening a handshake that has moved on.

Two frames are refused outright, whichever road they arrived by: one naming **this** device as
the responder, and one that would replace a responder slot **this** device already occupies. A
device binds its own side through `accept()` and never through an inbound frame, so neither can
come from an honest peer — and a phone that applied one would find itself owning neither side of
its own row, unable to confirm the pairing it started. That is a permanent denial from a single
hostile answer to the phone's own collection request, which is the road such a frame arrives by.

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
   `PairingRowGuards` answers with a `PairingSide`, and every column that side selects comes
   off that enum: `columnPrefix()`/`peerPrefix()` for the bound keys and device ids,
   `confirmedAtColumn()`/`peerConfirmedAtColumn()` for the two stamps. All four route through
   a single `peer()` flip, so a device's own column and its peer's cannot resolve to the same
   name — the failure otherwise available here is both confirmations landing on one side,
   `bothConfirmed()` never tripping, and no exception raised anywhere.
4. **Is the signature authentic?** The frame must be signed by the key **the row bound for the
   peer side**, and `confirming_device_id` must equal the device id that same row bound. Both
   are checked against the row, never against the frame's own assertions about who it is.

A `PeerConfirmContext` is returned only when all four pass. Its existence *is* the assertion
that the gates passed; nothing downstream re-derives a side or re-checks a signature.

5. **Has the local human confirmed yet?** This is the load-bearing one. A validly signed peer
   confirm cannot by itself drive the row toward `confirmed`. Until the local user has
   visually matched the six words and tapped confirm, the answer is
   `PeerConfirmResult::deferred()` — carrying no `PairingState`, because nothing moved — and
   the relay row is **left in the mailbox** for redelivery. Without this gate, a peer that
   completed its own confirmation would drag the local device into a confirmed pairing the
   local human never approved — which would make the safety-number comparison decorative.

### A deferred confirm is held, not dropped

Deferral used to rely entirely on somebody sending the frame again, and only one of the two
roads does. The relay keeps its copy: a deferred outcome leaves the mailbox row pending.
A **LAN push** does not — `POST /pair/frame` answers `202`, which the sender reads as
received, and the receiver kept nothing. What redelivered it was the peer's own three-second
re-emit, which is gated on the peer's row not yet being `confirmed`.

Those two facts close on each other in the ordinary order of a real ceremony. The phone
confirms first, the desktop defers and discards, the desktop's human taps, the desktop sends
*its* confirm, the phone applies it and reaches `confirmed` — and stops re-emitting. The
desktop is then left in `awaiting_confirm` with `responder_confirmed_at` still null, holding
no proof it can ever be given again, while the phone shows a completed pairing. One-sided
trust, and the TTL is the only thing that ends it.

So a deferred frame is now parked on its own pairing row (`deferred_peer_confirm`, written by
`HeldPeerConfirm`) and replayed by `confirm()` the moment this device's own side is stamped.
The replay is a full second pass through `authenticatePeerConfirm()` — same addressing check,
same side derivation, same signature verify against the key the row binds *now*. Being stored
grants a frame nothing that arriving would not have granted it, which is why a responder that
rebound in between finds the held signature refused rather than inherited: it was signed over
keys the row no longer holds. A rebind clears the column for the same reason it clears
nothing else — dead key material has no business sitting on a live row.

The gate above is untouched by this. A held confirm still cannot move the row on its own;
the local tap is what makes it actionable, and without a tap it is simply a blob that expires
with its token.

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

`PairingFrameCourier::drainAndApply()` polls this device's own relay mailbox. It never throws
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
| `PAIR_CONFIRM` answered `PeerConfirmResult::deferred()` | **no** — left for redelivery once the local side confirms |
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
that cannot see each other, and with no relay configured two devices on one wifi
could not pair at all. The phone re-emitted its accept 86 times over four minutes
against a `RelayRefusedException` and then stopped without saying so.

The WebSocket cannot carry these frames. Its Noise session authenticates against
the confirmed-device registry, and a device mid-pairing is by definition not in it
yet. So the frames take the shape `/pair/offer` already took — routes in front of
the upgrade, answered by the listener itself.

| Route | Direction | Who answers |
|---|---|---|
| `POST /pair/frame` | responder → initiator | the listening device applies it |
| `GET /pair/frames?device=&proof=` | initiator → responder | the listening device hands over what is waiting, once the caller has proved it is that device |

Two routes rather than one because only ONE side of a pairing listens. The desktop
runs the daemon; a phone runs no server and advertises nothing, so it can never be
dialled. Rather than the desktop pushing, the phone collects on the three-second
poll it already runs — from the address its scan recorded first, then from any
peer a browse turned up. The scanned address is not a fallback on iOS: it is the
only road, because the browse there returns nothing and cannot be made to.

`POST /pair/frame` answers **204** when it applied the frame, **202** when it is
holding one it cannot finish yet, and **404** for a refusal it will never change
its mind about. The 202 exists because `Deferred` is not `Applied`: a valid
`PAIR_CONFIRM` waits until the local human has compared the words, and answering
204 told the sender the opposite of what the enum means. Both 204 and 202 mean the
peer received it, so the relay does not get a second turn either way.

### Proving you are the device the frames are for

`sync:serve` binds `0.0.0.0`, so this route is reachable by anything on the wifi —
and it serves the same `relay_mailbox` rows that `GET /relay/drain` protects with a
per-device bearer token. Naming a device id cannot be the qualification: device ids
are advertised over mDNS, and the blobs handed back carry the `token_hash` an
attacker needs to rebind a live pairing.

So the caller signs. `PairingFrame::pullProofMessage()` is a domain-separated
message over the collecting device's own id, signed with its Ed25519 secret key,
and `PairingPullAuthorizer` verifies it against the public half **the listener's own
pairing row already bound for that device**. Ordering makes that possible: nothing
is ever waiting for a device the row has not bound, because the desktop's confirm
only exists after the phone's accept landed.

The proof carries no timestamp and does not expire within the handshake, which is
deliberate. An attacker who can observe a pull request can already read the plain
`http` response it produces, so replay grants nothing the transport did not. What
the signature does remove is the attacker who can *send* but not *see* — every
other host on the subnet. A clock-skew window would have bought nothing and cost a
phone whose clock is wrong its entire return leg.

An unproven caller gets the same `{"frames":[]}` a device with nothing waiting
gets. Which pairings are in flight is exactly what a prober would like to learn.

`PairingFrameCourier::deliver()` tries the LAN, then the relay, then holds the frame
in `PairingPeerOutbox` for collection. The fallback is silent by design: which road
a frame took is not something the reader chose or can act on. *Any* relay failure
falls through to the holding space, not only a refusal — a relay that is configured
but unreachable is the case the holding space most exists for, and a transport-level
exception used to escape the catch and lose the frame.

`takeFor()` hands out row ids alongside the frames and marks nothing delivered.
The route confirms them only once the answer has been serialised, so a response
that never got built leaves the frames waiting rather than destroying the only copy
of the desktop's confirm.

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

### One browse per poll

`MulticastMdnsQuery::browse()` always burns its full two-second timeout — it collects
answers until the deadline and never returns early. A pairing poll asks three times:
the frame puller, the responder-accept delivery, and the confirm re-emit. Six seconds
of blocking work inside a three-second poll, before any of the requests that follow.

`CachedPeerDiscovery` sits in front of it as the container's `PeerDiscovery`, holding
one answer per service type for 2.5 seconds — shorter than the poll, so each poll
still gets a fresh browse, and the calls inside one poll share it. Peers do not move
mid-ceremony.

The interface is also the seam a test binds: `Http::fake()` never reached the
multicast socket underneath, so tests of this path answered from whatever happened to
be advertising on the machine running them.

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

## The poll must survive the window losing focus

Both polled steps carry `wire:poll.3s.keep-alive`, and the modifier is the point.
Livewire runs a poll whose tab reports `document.hidden` at one tick in twenty —
a sensible default for a dashboard, and the wrong one here, because this ceremony
*instructs* the reader to pick up the other device. The window that has to notice
the peer's accept is the one guaranteed not to be in front of them.

The observed shape was exact. A desktop showed a code at 15:53:53 and drained on
the dot every three seconds until 15:54:58; the reader turned to the phone, and
the next two drains landed 87 and 110 seconds apart. Nothing else in that poll
skips a tick at random — the 95% throttle is the only stochastic branch in it. The
phone's accept arrived at 15:57:32, into a poll running roughly once a minute, and
the app-lock took the screen before a tick came round. The desktop sat on "Step 2
of 3 — Show this code" for the whole ceremony, and the human comparison that is
the sole trust gate of this protocol was never offered.

## A ceremony outlives the screen that started it

`awaiting_confirm` survives an auto-lock, a navigation and a reload, because it
lives on the row. `PairingFlowModal::openModal()` has resumed one for a while —
it reads `inFlightFor()`, derives which side this device owns, and lands on the
comparison step. What was missing is that nothing on the page said so. Data &
Devices listed the confirmed devices and offered "Pair a new device", which is
the opposite of what the reader needs to be told and reads as *starting over*;
the token then expired unmentioned, and the peer waited on a confirmation nobody
could reach.

So the section names it: `DevicesAndSyncSettingsSection` asks for the in-flight
row this device owns a side of and renders the peer's name with a way back in.
Cheap because it is the same `PairingGateway` reads the modal already makes, and
scoped the same way — a row belonging to two *other* devices is not this device's
ceremony and stays invisible, exactly as the modal's own resume treats it.

The auto-lock itself is deliberately left alone. A pairing is precisely the moment
the reader has walked away from this keyboard, which is the case the lock exists
for; suppressing it would trade a real protection for the convenience of not
re-entering a passphrase.

## A pairing outlives the lock that interrupts it

Restoring the screen is not enough on its own, because the row can die while
nobody is looking at it. The lock fires at five minutes idle; a ceremony has at
most ten, and from the accept onward only what is left of them. The observed run
landed exactly in that gap — the token expired at 16:03:53 and the reader
unlocked around 16:05, so a resume surface would have had nothing to offer.

So `extendCeremonyAcrossLock()` gives a returning reader a fresh
`ACCEPT_GRACE_MINUTES` — the same constant that already answers "how long do two
humans need to compare six words". It reuses `extendedExpiry()`, so it is
grow-only in the same sense every other writer is: a ceremony with longer to run
is never shortened.

**This lets an idle timeout lengthen a trust window, which is a real weakening
and is stated as one.** What bounds it:

- Only `awaiting_confirm`. A `pending` row binds no responder, so there is nothing
  to compare and a longer window buys only a longer race for the responder slot.
- Only a row **this device owns a side of**, so a ceremony between two other
  devices on the account is not revived by a third watching the page.
- Only while **unlocked**, and that is not a flag anyone sets: the extension needs
  a device id, `DeviceIdentityLoader` needs the app-lock KEK to produce one, and
  `AppLockKeyService::release()` answers null while locked. A locked app cannot
  reach the write at all.
- Only from two human moments: `DevicesAndSyncSettingsSection::mount()`, a reader
  arriving at the surface, and `HoldPairingCeremonyOpenOnUnlock`, the listener on
  `Modules\Auth\Public\Events\AppLockUnlocked` — a reader typing their PIN.
  Never from the three-second poll, which would renew forever.

  Both, not one: the listener catches a lapse the lock caused and reaches a reader
  who never opens this screen, while the mount catches a lapse the lock did **not**
  cause — a thirty-minute idle setting outlives a ten-minute ceremony with no lock
  in between. The extension is an idempotent, grow-only `UPDATE`, so running it
  twice costs nothing. The "only while unlocked" bound above still holds for the
  listener: it runs after `unlock()` has stored the key, so `release()` answers,
  and it no-ops when no user is bound to the guard.

What reviving a lapsed row re-opens is the responder-rebind window: a peer on the
LAN can take a responder slot nobody has confirmed. That is a **denial, not a
capture**, because `confirm()` binds the tap to the keys behind the words that
were displayed — a rebind makes the digest stop matching and the confirmation is
refused out loud. So the revival hands an attacker the same stall they could
already cause before the lock, and no new road to a confirmed pairing.

### The extension and its disclosure are one render

The condition that holds the ceremony open and the sentence that admits it are
the same `@if` in `devices-and-sync-settings-section.blade.php`. That is the
point: an extension nobody is told about is a lock policy quietly overridden,
while a stated one is a bounded exception with a way out — the notice names
cancelling as the thing that puts the ordinary limit back.

The copy does not claim the lock is off, because it is not. The app still locks
on its own schedule and still demands the passphrase. What outlives the timeout
is the pairing window, and that is what the line says.

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
two humans compared and is order-independent for the same reason `derive()` is. It is
computed in one place — `SafetyNumberDeriver::digestOfWords()`, reached from a screen
through `PairingGateway::safetyDigestOf()`. A caller with its own copy of the formula
would keep computing the old value if the formula ever changed, and every confirmation
would be silently refused.

The keys the digest matched ride in the `WHERE` of the `UPDATE` that stamps the
confirmation, rather than being checked and then trusted: a rebind landing between the
two matches no row, so nothing is stamped.

A refusal is a *rendered* refusal on both screens. `confirm()` returns `null` for two
different things — this device owns neither side, and the keys behind the shown words
have moved — and both need the confirm step to say so, re-derive the words from what the
row now binds, and leave the button pressable. Reported as silence it is a spinner that
never resolves, which is the denial the replacement rule was introduced to remove.

The re-emit that follows is gated on the row's own `*_confirmed_at` column for this
side, never on the screen's `awaitingPeer` flag. The flag is set by the refusal path
too, so gating on it shipped a signed `PAIR_CONFIRM` every three seconds asserting a
confirmation `confirm()` had explicitly declined to record. Reading the row also
survives the screen: a modal closed and reopened has forgotten that it tapped, and used
to stop re-emitting for a peer still waiting.

## Redelivery must not depend on an open screen

Everything above kept a ceremony alive across a lock, a reload and a closed modal — but the
thing that actually *moved frames* still lived inside the modal. `checkPairingState()` drained
the relay and re-emitted this side's confirm on its three-second poll, and that poll exists
only while the pairing modal is on screen. Close it on either device and re-emission stops.

That is not a cosmetic gap. The ordinary end of a ceremony is two humans tapping and both
putting their devices down. The desktop's confirm reaches the relay (or its own outbox, on a
LAN with no relay) and waits for the phone to collect it; the phone's confirm waits for the
desktop. With both modals closed nothing collects and nothing re-emits, and the pairing ends
half-done — the same one-sided trust the held-confirm fix removed one level down.

So the transport half moved out of the screen into `PendingPairingCourier`, and the modal poll
now only advances the wizard. One mechanism, three drivers, and no second redelivery policy
that could disagree with the first.

### What the courier is allowed to do

Two things, and deliberately not a third:

- **Collect.** Drain this device's relay mailbox, and — where it holds an identity — ask the
  peer for anything waiting in its outbox.
- **Re-emit this device's own confirm**, read off `<side>_confirmed_at`, which `confirm()` is
  the only writer of and only ever writes behind the safety-number match.

It **mints nothing**. There is no path through the courier that produces a confirmation, only
paths that carry one the local human already gave. That is what makes a courier safe to run
from a process with no human attached to it: no tap, no stamp, nothing to re-emit, and a
validly signed peer confirm is still deferred exactly as it is inbound.

### Outward before inward

The tick sends before it collects, and the order is load-bearing rather than incidental.
Collecting first can *finish* the ceremony on this device — the peer's confirm arrives, the
local stamp is already there, the row goes `confirmed` — and a confirmed row is this courier's
stop signal. The very tick that completed the pairing would then exit without ever having
offered this device's own confirmation, and the peer would be left waiting for a frame nobody
will send again. That is the original defect rebuilt one level up, and the first version of
this courier had it.

Re-sending a frame the peer already holds costs one idempotent apply. Not sending it costs the
peer the pairing.

### The drivers, and the device where one of them cannot exist

| Device | Driver | Can it sign? |
|---|---|---|
| Desktop | a `DaemonTimer` tick inside `sync:serve` | **no** |
| Any device, any screen | `CarriesPendingPairingFrames`, after the response on the `web` group | yes, when unlocked |

`DaemonTimer` is an interface with one implementation, `DaemonTicker`, which is the only place
in the tree that names Revolt's event loop — the same containment `DaemonShutdownSignal`
already gives the rest of Amp, and the one `ThirdPartyContainmentArchTest` asks for. That is not ceremony: what finishes a ceremony is now a *scheduled
tick*, and a claim of that shape has to be testable without standing up a loop and a socket
to watch it happen. `DaemonSchedulesThePairingCourierTest` asserts the daemon asks for a
three-second tick, asks for none when it booted with no user, and that running the tick with
nothing to carry cannot throw into the loop's error handler.

`DaemonTicker` refuses to schedule a second tick over a live one. Work on that loop is
synchronous, so a slow tick must cost the sockets one delay rather than a queue of ticks
stacked behind it.

The daemon is the only thing on this device that runs with no window open at all, and it is
already the process serving the handshake's own routes — so it needs no new process, no
supervision and no cron entry. It is handed the X25519 transport keypair and *not* the Ed25519
signing key ([`SyncDaemonIdentity`](device-identity-key-files.md)), so it can only collect.
That is exactly the half the desktop is missing: its inbound LAN road is a push its own
listener already answers, and what no screen was draining was the relay.

The scheduler and the queue were both rejected. The desktop has both, so either would have
worked there — but **the phone has neither**, and it has no daemon either. A scheduler entry
that never fires on iOS is worse than no entry, because the table above would then claim
coverage the phone does not have. On a phone the only thing that reliably runs is a request
the app itself makes, so that is what drives the courier there: every request, from every
screen, gated on a live handshake and throttled to one tick per three seconds. It runs from
`terminate()`, after the response has gone out, because a browse burns its full timeout.

What this does **not** claim: a backgrounded phone carries nothing. iOS will not run this, and
saying otherwise would be the covered-but-not-really failure above. What rescues the ceremony
anyway is that the desktop holds the phone's confirm durably for the life of the token — in
the relay mailbox or its own `PairingPeerOutbox` — so the phone completes on its next tick from
whatever screen it happens to open. The frame stopped being ephemeral, which is the property
that makes a foreground-only driver enough.

### When it stops

The stop rule is the row, not a counter. `liveCeremonyOwnedBy()` answers only for a row that is
`pending` or `awaiting_confirm`, unexpired, and owned by this device — and every way a ceremony
ends removes it from that set:

| Ending | How the courier sees it |
| --- | --- |
| Both sides confirmed | state becomes `confirmed`, which is not a live state |
| The user cancelled | `expire()` writes `expired` |
| The token lapsed | `expires_at` fails the comparison; nothing has to write anything |
| A new ceremony started | `prune()` deleted the row |

So an abandoned ceremony is never resurrected, and there is no retry against a dead token. The
tick returns `false` and does not even resolve a transport.

### What it costs

Idle, a tick is **one covered index read** (`pairing_tokens_user_expires_idx` is
`(user_id, expires_at, state)`) and nothing else — no HTTP, no browse, no writes. That is the
state a device is in essentially always.

During a ceremony the bound is the ceremony itself: at most `TTL_MINUTES` (10), and after the
accept `ACCEPT_GRACE_MINUTES` (5) at a time. At one tick per three seconds that is a couple of
hundred ticks, each costing at most one relay round trip, one cached browse and one peer
request — which is precisely what the modal poll already spent. Nothing new is on the wire; the
same traffic simply stopped depending on a window being open. A device with no relay configured
makes no network request at all on the collecting half, because `drainAndApply()` returns early.

The daemon's tick blocks its event loop for the duration of a relay round trip, so it will not
start a second tick while one is running. A slow relay costs the listener one delay rather than
a growing queue behind it.

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

## The holding space holds a frame, not every copy of it

`PairingPeerOutbox` caps a peer at `MAX_PENDING_PER_PEER = 16`, and its own
comment says why that is generous: "A handshake puts at most two frames in
flight per peer. The cap is a flood guard, not a working limit."

The poll re-emits the responder-accept every three seconds, and each re-emit is
byte-identical — same token hash, same keys, same name. Appending them filled
the cap with sixteen copies of one frame in forty-eight seconds. Measured on a
real iPhone: `relay_mailbox` held ids 1–16, all 388 bytes, all
`PAIR_RESPONDER_ACCEPT`, all for the same recipient, stamped 22:54:04 through
22:54:49, `delivered_at` null, `expires_at` a month out.

Two consequences, and the second is the worse one:

- The first sixteen failures were **silent**. `queueFor()` returned true, so
  the poll cleared its flash and the screen showed nothing but the spinner.
  Only the seventeenth attempt found the quota full, threw, and finally said
  something — after forty-eight seconds of black-holing.
- Every later frame to that peer was refused, including the `PAIR_CONFIRM` the
  ceremony cannot finish without, until the duplicates expired thirty days on.
  On a phone that is permanent: `PairingFramePullHandler` is the only drain and
  is mounted solely by `sync:serve`, which no phone runs, so nothing can ever
  come and collect them.

`deliverIfUnderQuota()` takes `foldIdentical`, set only by the pairing outbox:
an identical frame already waiting counts as stored and returns true without a
row. Re-emission is unchanged; what stops is the duplication. The relay server
passes the flag off, so nothing about relay delivery moves.

One test moved with it. `PairingConfirmReEmitTest` proved re-emission by
counting `relay_mailbox` rows, which folding makes flat, so it now counts POSTs
to `PairingFrameRequestHandler::FRAME_PATH` instead — matched on the method and
the whole path, because the poll also GETs `/pair/frames`, whose path contains
`/pair/frame`.
