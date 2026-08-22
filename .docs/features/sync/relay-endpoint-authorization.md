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
relay. The credential is the draining device's **own** per-instance drain
secret: 32 random bytes, minted on first use by
`RelayConfig::deviceDrainSecret()` and persisted at
`secretsPath()/sync-relay-drain-secret.json` with the same
`mkdir 0700 → write → chmod 0600` discipline as every other secret in the
module. It is presented as an ordinary `Authorization: Bearer` header.

`RelayDrainRegistry` is the relay-side half. It is a trust-on-first-use store:
the first token ever seen for a device id is recorded as
`did → sha256(token)` and accepted; every later drain or confirm for that id
must present a token whose digest `hash_equals` the stored one. The compare is
timing-safe against a fixed-length hex digest, so it cannot leak how many
leading characters matched.

> **Supersedes the earlier scheme.** The relay once derived each device's drain
> token as `HMAC(relay auth token, did)`. The relay auth token travels in the
> pairing QR, so every peer that had ever paired could recompute *any* device's
> drain token and pull or delete that device's blobs. The registry above is
> what the code does, and [architecture.md](architecture.md) now describes the
> same mechanism.

The residual weakness of trust-on-first-use is worth naming: an attacker who
registers a victim's device id **before** the victim ever drains wins the slot.
That is a much narrower window than a token every paired peer could derive, but
it is not nothing.

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
collect them at all. `ZuluTimestamp::stamp()` is the only way any write site
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
