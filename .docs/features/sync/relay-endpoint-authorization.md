# The relay endpoints: what authenticates what

Two devices are rarely awake on the same network at the same time. A phone is
in a pocket while the desktop is running; the desktop is asleep when the phone
is used. LAN-direct sync only works when both ends are up together, so
something has to hold a message until the other side comes back.

The obvious shape for that is a server with accounts: devices log in, the
server knows who they are, and it hands each one its own messages. That is
exactly what this design refuses. An account system means the relay operator
learns who the users are, which devices belong to whom, and how many households
there are — the metadata the end-to-end encryption was supposed to make
irrelevant. So the relay stores opaque blobs addressed by device id and knows
nothing else. It never decrypts, never JSON-decodes a blob, and never touches a
`user_id` column.

That leaves an awkward question: if there are no accounts, what stops anybody
from reading anybody's mailbox? The answer is different for each of the three
routes, and the asymmetry is deliberate.

## `POST /relay/deliver` — deliberately unauthenticated

Anyone can drop a blob into any mailbox. There is no credential on this route
at all.

This is safe because posting into a mailbox does not get you anything. Every
blob is either Noise ciphertext or a sealed box addressed to a specific device
key; a recipient that cannot authenticate a blob drops it. An attacker who
floods a mailbox achieves nothing an attacker on the same network could not
already achieve by dialling the peer directly.

What an open endpoint *does* cost is disk and bandwidth, so the bounds that
replace authentication are all resource bounds:

| Bound | Value | Refusal |
| --- | --- | --- |
| Per-source-IP request budget | 120 per 60s window | `429 rate_limited` |
| Device id shape | `[A-Za-z0-9_:.-]{1,128}` | `422 invalid_did` |
| Blob size | 65536 + 1024 bytes | `413 blob_too_large` |
| Undelivered rows per recipient | 1000 | `429 mailbox_full` |

The blob cap is 64 KB — the transport framer's own payload ceiling — plus 1 KB
of headroom for Noise and AEAD overhead. `RelayClient` enforces the same number
client-side; the server repeats it because a caller that skips the client
entirely must not be able to smuggle a larger blob past a client-only check.

The quota check and the insert run inside one transaction, so concurrent
deliveries cannot race past the cap.

### The rate limit runs before routing, not inside deliver

`route()` calls the limiter before it decides which handler to dispatch to.
That ordering is load-bearing. Drain and confirm each resolve a row from the
database *inside* their own authorization check, so when only deliver was
throttled, an unauthenticated flood against drain still cost a query per
request — and deliver, the endpoint that was throttled, is the one that needs
it least.

The bucket key is the source address with the ephemeral port stripped, so every
connection from one host shares one budget. The limiter itself is capped at
4096 tracked sources and prunes expired windows only when it grows past that,
so it cannot become the exhaustion vector it exists to prevent.

## `GET /relay/drain` and `DELETE /relay/drain/{id}` — bound to one device

Retrieval is what needs gating, and it is gated per device rather than per
relay or per install. The credential is a drain token minted for **one device
id**, persisted at `secretsPath()/sync-relay-drain-tokens.json` with the same
`mkdir 0700 → write → chmod 0600` discipline as every other secret in the
module, and presented as an ordinary `Authorization: Bearer` header.

`RelayDrainRegistry` is the relay-side half. It is a trust-on-first-use store:
the first token ever seen for a device id is recorded as
`did → sha256(token)` and accepted; every later drain or confirm for that id
must present a token whose digest `hash_equals` the stored one. The compare is
timing-safe against a fixed-length hex digest, so it cannot leak how many
leading characters matched.

### An unknown row id answers `401`, not `404`

`handleConfirm()` resolves the row's `recipient_did` and then requires a token
scoped to that device. A row that does not exist and a row the caller is not
entitled to get the same `401 unauthorized`. Answering `404` for the first
would let a caller enumerate which mailbox rows exist without holding any
credential at all.

The same reasoning shapes the ordering in `drainRejection()`: a missing `did`
is reported as a malformed request and only a *valid* did that fails the token
check is reported as unauthorized, so a caller who simply forgot a parameter is
not told they have a credentials problem.

## A drain token names the one device it drains

Trust-on-first-use answers "is this the same caller as last time". It cannot
answer "is this credential even *about* this device", and on its own it
registered whatever bearer arrived for a device id nobody had drained yet.
Two things followed, both demonstrated against the real route:

- A token registered for `device-a` drained `device-b`'s mailbox, because
  `device-b` had not claimed its slot.
- The relay-wide token that used to travel in the pairing QR — a copy of which
  every peer that ever paired is still holding — drained an unclaimed mailbox.

A device's *first* drain was therefore effectively unauthenticated, and that is
precisely the window in which its GDK epoch wraps are sitting in
`relay_mailbox`. `RelayDrainToken` closes it by making the credential name its
device:

```text
bdt1.<sha256(device_id)>.<32 random bytes, hex>
```

`RelayDrainRegistry` refuses anything that does not parse as that shape with
the tag for the device id in the request, **before** it consults the store at
all. A relay-wide bearer names no device and is refused everywhere; a token
minted for another device names that one and is refused here. Trust-on-first-use
still decides which of two callers holding correctly-shaped tokens for the same
id wins the slot — the digest binding is what it always was — but a caller now
has to be holding a token minted for that exact id to be in the race.

The device tag is a digest rather than the id itself only so the token is not a
second place the id is written in the clear. It tells the relay nothing new:
the relay is handed the device id in the very request the token authorizes.

The residual weakness of trust-on-first-use is worth naming and is unchanged:
an attacker who knows a victim's device id and registers it **before** the
victim ever drains wins the slot. What is gone is the escalation — that the
attacker did not need to mint anything, because a working token had already
been handed to them.

### Two local users, two tokens

Device ids are per user: each user on an install has their own identity
key-file and their own `device_registry` self-row. The secret this scheme
replaced was per *install*, so all of an install's users presented the same
bearer. Binding that one secret to a single device id would have bound the
relay to whichever local user drained first and answered `401` to the second
one forever, which is why the token is minted per device id rather than per
install.

That also *removes* a metadata leak rather than adding one. Under the install-
scoped secret, two device ids on one install presented byte-identical bearers,
so the relay could link them as one household without decrypting anything. Two
independently minted tokens cannot be correlated that way.

### Upgrading an install that has already paired

Nothing about pairing, identity or `relay_mailbox` changes, so no re-pairing is
needed and no blob is lost. Two files migrate themselves:

- The install's drain secret is replaced by the per-device token file on the
  first drain after the upgrade. The superseded
  `sync-relay-drain-secret.json` and `sync-relay-token.json` are deleted once
  the replacement is safely written — an inert secret still reads like a live
  one to whoever finds it next.
- The relay's registry entries gain a version marker
  (`{"v": "bdt1", "hash": …}`). A binding written by the old scheme is a bare
  hash string, indistinguishable from a new one as hex, so entries without the
  marker are **dropped** on read. Honoured instead, they would `401` the
  upgraded owner out of its own mailbox permanently; dropped, the device
  re-registers on its next drain. They are worth nothing as security anyway —
  any bearer could have written them, which is the defect.

Both ends must be on the new build for a relayed drain to succeed. In the
out-of-box path they are the same bundle: the desktop runs `relay:serve` and
its own client. Where they are not — a phone that auto-updated ahead of the
desktop that hosts its relay, or the reverse — drain and confirm answer `401`
until the lagging side upgrades. Nothing is lost while that lasts: undelivered
blobs keep their 30-day window, LAN-direct sync is unaffected, and the drain
succeeds once both ends match. An old client cannot be accepted by a new relay
by definition — its credential is the install-wide one this exists to refuse.

## Confirm marks delivered; it does not delete

`DELETE /relay/drain/{id}` stamps `delivered_at` and rewrites `expires_at` to
seven days out. It does not remove the row.

Hard-deleting on confirm would make a retry fail in a confusing way: the second
`DELETE` would find no row, resolve no recipient, and come back `401` —
indistinguishable from a credentials problem, for a device that did everything
right and merely retried after a crash. Keeping the row for seven days makes
the retry return `200`.

The two lifetimes are:

- **Undelivered:** 30 days from creation.
- **Delivered:** 7 days from confirmation.

Garbage collection compares `expires_at` as a plain string, which is only
correct while every timestamp is zero-padded UTC Zulu — an offset form such as
`+02:00` sorts wrongly and would either collect live blobs early or never
collect them at all. `Instant::zulu()` is the only way any write site
produces one, and both halves of it are load-bearing: it converts to UTC *and*
asserts the shape, throwing rather than writing a value the comparison cannot
order. An inlined `->toIso8601String()` looks like the same call and is not —
it emits the local offset, which is exactly what filled `pairing_tokens` with
`+02:00` rows that a migration then had to delete.

## Transport: a pinned self-signed certificate

The relay serves TLS whenever it has material to serve it with. The certificate
is self-signed and valid for 3650 days, because no CA can vouch for a LAN
address and nobody is watching for an expiry on a machine in someone's study.

Trust therefore does not come from the certificate chain. It comes from an SPKI
pin — `sha256//<base64>` over the DER SubjectPublicKeyInfo, exactly the shape
curl's `CURLOPT_PINNEDPUBLICKEY` expects — carried in the pairing QR alongside
the identity keys. A camera is an out-of-band channel; the pin that arrives
that way admits exactly one key rather than anything a public CA has signed.
Pinning the *key* rather than the certificate means re-issuing the certificate
on the same key keeps existing peers working.

Two rules follow, and both are fail-closed:

- `verify => false` is only ever set **together with** a pin. It disables chain
  and name validation, which a self-signed LAN certificate cannot satisfy, and
  the pin is what still narrows trust to one key. Without a pin the connection
  is encrypted to whoever answers, which on a LAN is whoever wants to.
- Some TLS backends silently ignore `CURLOPT_PINNEDPUBLICKEY`, which would
  leave `verify => false` with an inert pin. `RelayClient::backendHonorsPinning()`
  allow-lists the backends known to implement it — OpenSSL, LibreSSL, BoringSSL,
  GnuTLS — and refuses to connect on anything else rather than connecting
  unauthenticated.

## Plaintext is allowed to LAN hosts only

`RelayConfig` refuses `http://` to anything routable off this network, and
permits it to a host that cannot leave it. "Cannot leave it" means `localhost`,
loopback `127/8`, or an RFC 1918 private IPv4 address. A domain name never
qualifies — DNS resolves to wherever it likes.

Link-local `169.254` and other reserved ranges are refused along with
everything public. That is not pedantry: `169.254.169.254` is the cloud
metadata endpoint, and a scanned QR must not be able to aim a plaintext POST at
it.

Refusing *every* `http://` endpoint was tried and broke the out-of-box path
outright: the desktop's own relay is plain HTTP on a private address, so a
phone would store the endpoint from the QR and then refuse to send the very
delivery the pairing handshake was waiting on.

`wouldAcceptEndpoint()` exists so a caller can ask this question *before*
persisting an endpoint. Storing one the client later refuses leaves a device
holding a relay it can never send to.

## See also

- [The Noise handshake state machine](noise-handshake-state-machine.md) — what
  makes a relayed blob unreadable to the relay.
- [Peer session lifecycle](peer-session-lifecycle.md) — the direct path the
  relay is a fallback for.
- [Sync architecture](architecture.md) — the surrounding module.
