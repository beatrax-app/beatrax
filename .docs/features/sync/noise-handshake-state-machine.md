# The Noise handshake state machine

Two Beatrax devices on the same network have to prove to each other that they
are the two devices the user paired, and then talk in private. The obvious
answer — TLS — does not fit. There is no certificate authority that will vouch
for `192.168.1.42`, the peers have no stable DNS name, and neither of them is
really a "server". Running a private CA would mean shipping a root of trust the
project does not want to own.

What the app already has is better suited: every device holds a long-term
X25519 static keypair, and pairing hands the peer's public half over an
out-of-band channel (a QR code or a typed word code) that the user then
confirms by comparing six words on two screens. The Noise Protocol Framework is
built to start from exactly that: known static keys, no PKI, mutual
authentication and forward secrecy in two or three messages.

`NoiseHandshakeState` implements two Noise patterns over X25519, ChaCha20-Poly1305
and BLAKE2b:

- **IK** — two messages. The initiator already knows the responder's static
  public key, so it can encrypt to it in the very first message. This is the
  reconnect path between devices that are already paired, and it is what the
  sync listener answers.
- **XX** — three messages. Neither side knows the other's static key in
  advance; both are transmitted during the handshake. This is the pattern for a
  first connection or a key rotation.

## The engine

The class is a token interpreter, not a hand-written protocol. Each pattern is
a list of messages, and each message is a list of tokens:

```
IK: [e, es, s, ss]  then  [e, ee, se]
XX: [e]  then  [e, ee, s, es]  then  [s, se]
```

`writeMessage()` walks the tokens for the current message index, appending
whatever bytes each token produces, then appends the (possibly encrypted)
payload. `readMessage()` walks the same tokens, advancing an offset by whatever
each token consumes. The tokens mean:

- `e` — emit or read a fresh ephemeral public key, and mix it into the running
  handshake hash.
- `s` — emit or read this party's static public key, encrypted once the state
  is keyed.
- `ee`, `es`, `se`, `ss` — perform one Diffie-Hellman and mix the shared
  secret into the chaining key. These append **nothing** to the message and
  consume **nothing** from it; they only advance the crypto state. That is why
  the write path returns `''` and the read path returns `0`.

All hashing and key derivation lives in `NoiseSymmetricState`: `mixHash()`
folds bytes into the running transcript hash, `mixKey()` runs a BLAKE2b-512
HKDF step that produces a new chaining key and a fresh cipher key.

## Four places where getting it backwards fails silently

### 1. The pre-message hash is asymmetric

In IK, before any message is sent, both parties mix the **responder's** static
public key into the handshake hash. The initiator mixes the remote key it was
given; the responder mixes its own. Same bytes, reached from opposite
directions. Mix the wrong one on either side and the two transcripts diverge —
the first message that carries an AEAD tag then fails to authenticate, with
nothing in the error saying the transcripts were never going to match.

### 2. `es` and `se` pair different keys on each side

For `es` the initiator pairs *its ephemeral secret* with *the peer's static
public*, while the responder pairs *its static secret* with *the peer's
ephemeral public*. `se` is the mirror image. Diffie-Hellman commutativity means
both arrive at the same shared secret; using the same pairing on both sides
produces two different secrets and the handshake dies at the next AEAD tag.

### 3. The `s` token changes width once the state is keyed

An unkeyed `s` is a bare 32-byte public key. A keyed `s` is 32 bytes plus a
16-byte AEAD tag — 48. `readRemoteStatic()` switches on
`NoiseSymmetricState::isKeyed()` for both the slice length and the number of
bytes it reports consuming. Read the wrong width and every subsequent token in
that message reads from the wrong offset.

In IK the `s` in message 1 always arrives keyed, because `es` runs before it.
The branch exists for XX, whose first message is a bare `e` with no key
established yet.

### 4. `split()` assigns the two keys by ROLE

This is the one that breaks quietly.

`NoiseSymmetricState::split()` derives two cipher keys from the final chaining
key with an empty-input HKDF step: `k1 = temp[0:32]`, `k2 = temp[32:64]`. Both
parties derive the same `k1` and the same `k2`. What differs is which one each
side sends with:

| Role | sends with | receives with |
| --- | --- | --- |
| Initiator | `k1` | `k2` |
| Responder | `k2` | `k1` |

`NoiseHandshakeState::split()` encodes that by returning `[k1, k2, peerStatic]`
for the initiator and `[k2, k1, peerStatic]` for the responder, and
`NoiseSession` takes them in `(sendCipher, recvCipher)` order.

Swap them on one side and nothing complains. `split()` cannot fail; there is
nothing to compare. The handshake completes, both sides log success, the peer
is authenticated — and then the very first transport message fails its
authentication tag. The symptom reads like a corrupt or truncated frame, and
the bug is nowhere near the frame.

## The suite name on the tin is not the suite in the tin

`NoiseSymmetricState` is seeded with the string
`Noise_IK_25519_ChaChaPoly_BLAKE2b`, and that string is the first thing mixed
into the handshake hash. It names a suite this class does not implement, in two
places:

| The framework says | This class does |
| --- | --- |
| `HASHLEN` is 64 for BLAKE2b — the chaining key, the handshake hash and each HKDF half are all 64 bytes | `HASH_BYTES = 32`, so BLAKE2b is truncated and the protocol name is zero-padded to 32 rather than 64 |
| `HKDF(ck, ikm)` is three chained `HMAC-HASH` calls | One `sodium_crypto_generichash(ikm, key: ck, 64)`, split in half |

Neither is a weakness on its own: BLAKE2b is designed to be used keyed and is
not length-extendable, and a 32-byte chaining key still carries 256 bits. What
they cost is the property the suite name promises. **No conforming Noise
implementation can complete a handshake with this one**, and no published
vector for the named suite can be reproduced by it — which is why
`noise_test_vectors.json` holds anchors this implementation generated rather
than the reference suite's.

The published vectors are checked in anyway, as
`noise_published_vectors.json`, and
`TheNoiseVectorsAreOurOwnAndNotThePublishedOnesTest` re-derives one of them
from `ext-sodium` alone before running the same input through this class. That
positive control is the point: without it, "our handshake does not reproduce
the published vectors" and "the published vectors are wrong" look identical
from here. They are not — the vectors reproduce exactly, and the divergence is
this class's.

Correcting it is a wire-format change of the same kind as the nonce byte order
below, and a larger one: it moves every byte of every handshake, so two devices
that have already paired could no longer speak. It is therefore recorded here
rather than done in passing.

## Turn taking and terminal state

The initiator writes even-indexed messages (0, 2, …) and the responder writes
odd-indexed ones. `assertTurn()` enforces this, so a caller that reads when it
should write gets a `LogicException` naming the message index rather than a
mysterious crypto failure two steps later. Once `split()` has run the object is
terminal: any further read or write throws.

## Transport nonces

After the split, each direction gets a `NoiseCipherState` — an AEAD cipher plus
a monotonic nonce counter. Two details matter:

- The cipher is `sodium_crypto_aead_chacha20poly1305_ietf_*`, the 12-byte-nonce
  IETF variant. **Not** XChaCha20-Poly1305, whose nonce is 24 bytes. Reaching
  for the wrong one produces a working-looking implementation that cannot talk
  to any other Noise implementation.
- The counter is held as two 32-bit words rather than one integer, because PHP
  integers are signed 64-bit and would overflow before reaching Noise's true
  MAXNONCE. The exhaustion guard therefore trips at `PHP_INT_MAX` rather than
  at 2^64−1, which is conservative in the safe direction: it refuses to encrypt
  slightly early rather than silently reusing a nonce.

### Nonce byte order, and the version boundary it moved

The Noise specification (rev34 §12.3) forms the 96-bit ChaChaPoly nonce as 32
bits of zeros **followed by** the little-endian encoding of `n`. `buildNonce()`
emitted the opposite layout — `n` in bytes 0–7, zeros in bytes 8–11 — and now
emits the spec's.

The two layouts are byte-identical at `n = 0`, which is why nothing noticed:
both ends ran the same implementation, and every message either side could
compare against a vector encrypts at `n = 0`. The vendored handshake vectors
were regenerated from this implementation rather than taken from the reference
suite, so they could not see it either. `NoiseTransportNonceFollowsTheSpecTest`
now pins the encoding independently, against nonce bytes vendored from the spec
text rather than from any implementation.

Two consequences worth stating plainly:

- **This is a wire-format change.** Every transport message from the second in a
  direction onward, and the XX handshake's third message — which encrypts the
  initiator's static key at `n = 1`, because the cipher from message 2's
  `mixKey` has already encrypted one payload — differ between a build before
  this change and one after. An old peer and a new peer cannot complete an XX
  handshake. IK is unaffected: every one of its handshake encryptions is at
  `n = 0`.
- The XX vector's message-3 ciphertext in `noise_test_vectors.json` was
  re-derived for the same reason. It is a determinism anchor, not an
  interoperability proof; the fixture says so.

## The test-only ephemeral seam

`setEphemeralKeypair()` injects a fixed ephemeral keypair so a handshake is
byte-for-byte reproducible against a stored vector. A fixed ephemeral destroys
forward secrecy outright, so the method throws unless `APP_ENV` is `testing` or
`local`, and it must be called before the first read or write.

## What the handshake does and does not prove

A completed handshake proves the peer holds the private half of the static key
that was presented. It does **not** prove that key belongs to a device this
user trusts. That check is separate and comes after:
`SyncSession::authenticate()` compares `NoiseSession::peerStaticPublicKey()`
against the confirmed-device map from `DeviceRegistryService::deviceX25519Keys()`
and refuses the session on no match.

## See also

- [Peer session lifecycle](peer-session-lifecycle.md) — how the handshake fits
  into a full sync exchange.
- [LAN discovery trust model](lan-discovery-trust-model.md) — why discovery
  hands over candidates rather than trusted peers.
- [Sync architecture](architecture.md) — the surrounding module.
