# LAN discovery: everything it finds is a guess

A device has to learn where its peer is before it can dial it, and there is no
server to ask. mDNS answers that question without one — a device advertises
`_beatrax-sync._tcp` with a `did={deviceId}` TXT record and others browse for
it — but mDNS has no notion of authenticity whatsoever. Anything on the network
can answer, can claim any service name, and can publish any TXT record it
likes.

So discovery in this module is not a trust boundary and is not treated as one.
Everything it produces is a **candidate address**: somewhere worth attempting a
Noise handshake. The handshake is the gate. The registry pre-filter that drops
advertisements whose `did=` is not a confirmed device is an optimisation — it
avoids burning handshakes on rogue advertisers — not a security control.

## One implementation, because phones have no `dns-sd`

`MulticastMdnsQuery` speaks mDNS on the wire rather than shelling out to the
platform responder, because a phone has neither `dns-sd` nor `avahi`. Without
it a fresh device could only ever learn where the desktop is from a scanned QR,
which is why the typed-code arm of the import flow could not succeed at all.

There was a second implementation, `MdnsBrowser`, which drove `dns-sd -B` on
macOS and `avahi-browse` on Linux. Nothing resolved it — the desktop browses
through `MulticastMdnsQuery` like the phone does — so it was removed rather
than kept as a platform-specific path no build exercised.

Two details of the surviving implementation are non-obvious:

- It binds an **ephemeral** UDP port rather than 5353 and sets the
  unicast-response bit (RFC 6762 §5.4) on its question, asking responders to
  answer straight back to that socket. Receiving the multicast reply instead
  would require joining the group, which mobile runtimes do not reliably allow
  and which Android gates behind a multicast lock. Claiming port 5353 would
  also collide with any resolver the OS already runs.
- It applies no registry filter of its own. The device using it has no
  confirmed peers yet — that is the entire reason it is browsing.

## Parsing packets from an adversary

`MdnsResponseParser` is split out from the socket precisely so the wire format
can be driven with crafted packets in tests. Every byte it reads was written by
whoever answered on the LAN.

### A records are deliberately never used for addressing

An mDNS record's owner name is chosen by whoever sends it. A hostile responder
can emit an A record whose owner name is the instance being looked for, place
it *before* the real SRV, and — under first-address-wins — have its address
recorded as the instance's. The fetch then goes wherever the attacker likes,
including off the local network.

So the address kept for an instance is the host the datagram physically
arrived from, and nothing else. That address has at least been proven
reachable, and `MdnsInstanceTable::recordAddress()` keeps the first one it is
given so a later record cannot displace it.

`DnsRecordType::Address` is still in the enum, and `apply()` matches it
explicitly to `null`. That is the point: the case is handled and does nothing,
rather than falling through a default arm where a later edit might quietly
start honouring it.

### Compression pointers must point backwards

RFC 1035 name compression lets a label be replaced by a pointer to an earlier
offset. A packet whose pointer points forwards, or at itself, produces an
infinite walk.

`readName()` refuses to follow a pointer whose target is not strictly *before*
every pointer it has already followed. A monotonically decreasing target can
only be followed a bounded number of times, so the walk terminates without
needing a hop budget or a visited set. The offset the caller sees is fixed at
the first pointer, so parsing continues just past the pointer in the record
where it appeared, which is what the format requires.

### Everything else is bounded

- Name 255 bytes, label 63 bytes (RFC 1035 §2.3.4).
- 256 records per message. One responder is one device; anything past that is
  broken or hostile.
- 9000 bytes per datagram — well above Ethernet, with margin for a jumbo frame,
  so a long response is not silently truncated.
- Ports range-checked to 1–65535 in both parsers.
- Device ids capped at 64 bytes and matched against `[A-Za-z0-9-]+`. The id is
  compared against the registry and rendered in the UI, so it is held to the
  shape the advertiser publishes rather than accepted as free text.
- A declared rdlength that overruns the datagram refuses the record instead of
  reading whatever follows in memory.

### Answers arrive across sections and across datagrams

Answer, authority and additional sections are all read the same way: a
responder is free to put the SRV in one and the TXT in another. A responder is
also free to split its answer across several datagrams, which is why a PTR —
which names an instance without saying where it is — records the sender
straight away. The instance stays addressable when its SRV and TXT turn up
later.

`MdnsInstanceTable` exists to accumulate those fragments per instance. An
instance missing either a device id or an address is skipped when the table is
converted to peers: without the id there is nothing to match against the
registry, and without the address there is nowhere to dial.

## Advertising

`MdnsAdvertiser` publishes `Beatrax-{deviceId}` with a `did={deviceId}` TXT
record via `dns-sd -R` or `avahi-publish-service`. When neither binary exists
it returns silently and the caller falls through to a manually configured
host:port or to the relay.

Two guards:

- The device id must match a UUID v4 exactly, or nothing is advertised.
  Symfony's `Process` passes argv, so there is no shell-injection risk; the
  concern is a device id containing whitespace or control characters producing
  a malformed mDNS instance name.
- Output is disabled on the process. Nothing reads it, and leaving the pipes
  open gives Symfony's destructor something to block on: `close()` reads them
  with `stream_select()` and no timeout, and a long-lived `dns-sd` never
  reaches EOF. Under parallel test runs that wedged a worker alive and silent
  indefinitely.

## `GET /pair/offer`: the one route in front of the WebSocket

A device that was handed only a typed word code has no way to learn the other
device's public identity, and a fresh responder cannot accept a pairing token
without it. `PairingOfferRequestHandler` sits in front of the sync listener's
WebSocket handler and answers exactly that one path, delegating everything else
to the WebSocket untouched — one route without taking on a router dependency
for it.

What it returns is **public identity only**. The pairing QR may additionally
carry the relay endpoint and the TLS pin, because a camera is an out-of-band
channel that an attacker has to be physically present to observe. This route is
on the wire the attacker is already on, so it never carries either.

Two more properties:

- Every refusal returns the same `404` body. An unknown token, an expired one,
  one belonging to another user, and one whose ceremony has already finished
  are indistinguishable on purpose — probing this endpoint must teach an
  attacker nothing beyond "no". That body is a single constant in
  `Transport\Concerns\AnswersInJson`, shared with the frame route, the pull
  route and the relay daemon, along with the `rate_limited` body and the
  per-host bucket key: three routes each writing out their own "no" is how one
  of them eventually says something the other two do not.
- The rate limiter runs before the lookup, so a flood is refused on the
  cheapest path and never reaches the database. As on the relay, the bucket key
  drops the ephemeral port so one host is one bucket.

### The three answers a client can get, and the three things it may say

The single `404` body is a *server* decision and must stay one. The **client**
still has three distinguishable outcomes without the server telling it anything
more, and `PairingOfferLookup` is the enum that keeps them apart:

| What happened | Case | What the screen says |
|---|---|---|
| No HTTP response at all — connect refused, mDNS miss, timeout | `NoPeerReached` | "Cannot reach the other device…" — the only outcome for which a network question is the right question |
| A peer answered and refused, or answered something unusable | `CodeNotAccepted` | "No device on this network accepted that code." |
| A peer answered `429` | `RateLimited` | "Too many attempts. Wait a minute and try again." |

`RateLimited` is not cosmetic. Folded into `CodeNotAccepted` it produced *"This
code is invalid or has expired. Ask the other device to generate a new one"* —
advice that cannot work, and that sends the reader to mint a fresh code straight
into the same bucket. The responder screen re-emits every three seconds, so a
phone that has hit the limiter is exactly the phone most likely to be told this.

Across several peers the precedence is **RateLimited > CodeNotAccepted >
NoPeerReached**: a typed code names no device, so any peer may be the one holding
it, and "wait" is true whichever of them that is while "regenerate" is false for
the limited one. A peer that answers with a real offer still wins over all three
— being limited by the wrong desktop must not discard the right one's reply.

`LanPairingOfferFetcher` reads `HttpStatus::TOO_MANY_REQUESTS`, the same constant
`PairingOfferRequestHandler` answers with, so the two sides cannot drift.

## What actually proves identity

Nothing on this page does. Identity is established twice, both times outside
discovery:

1. **The user compares safety numbers.** Six words derived from both devices'
   Ed25519 public keys, shown on both screens, confirmed by a human. This is
   the out-of-band step that makes the rest meaningful.
2. **The handshake checks the static key.** `SyncSession::authenticate()`
   compares the X25519 static key the Noise handshake revealed against the
   confirmed-device map and refuses the session on no match.

`DiscoveryMode::isFromNetwork()` exists to mark the distinction in code: an
address learned from the network is a candidate the handshake still has to
prove, as opposed to one that came from configuration or the relay.

`DiscoveredPeer::wsUrl()` emits plaintext `ws://` on purpose. The Noise session
already provides confidentiality, integrity and peer authentication end to end;
a WebSocket-layer TLS certificate would add nothing over it and would need a
LAN PKI the project does not have.

## See also

- [The Noise handshake state machine](noise-handshake-state-machine.md) — the
  gate discovery hands candidates to.
- [Pairing two devices without trusting the network](pairing-handshake.md) —
  the safety-number ceremony that does the proving.
- [The relay endpoints](relay-endpoint-authorization.md) — the fallback when no
  peer is found on the network.
- [Sync architecture](architecture.md) — the surrounding module.
