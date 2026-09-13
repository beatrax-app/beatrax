# The dial names its peer, and there is more than one

A phone runs no listener. Every exchange it has with a desktop starts with the
phone dialling out, and a Noise IK handshake makes the initiator name the
responder before it sends anything: `initIkInitiator()` takes the responder's
static X25519 public key, and the first message is encrypted to it. Offer the
wrong key and the responder cannot read msg1. It is not a slower handshake; it
is one that cannot complete.

So a dial carries two facts that have to agree — **where** and **whom**.

## They were resolved separately, from two different reads

`PeerLanAddress` answered *where* by taking the first entry of
`DeviceRegistryService::otherDeviceNames()`, which orders by `confirmed_at`
then `device_id`. `LanSyncClient` answered *whom* by taking the first entry of
`DeviceRegistryService::deviceX25519Keys()`, which carried **no order at all**;
the order it appeared to have was the planner's, because
`device_registry_user_idx (user_id, confirmed_at)` happens to satisfy the
predicate.

Two devices confirmed inside the same second have the same `confirmed_at` —
the column stores a second-resolution zulu stamp, so this is an ordinary state
for two devices paired in one sitting, not a rare one. Then the ordered read
breaks the tie by `device_id` and the unordered one breaks it by whichever row
the scan reached first, and the two name **different desktops**. Measured on a
registry holding `desktop-studio-1` and `desktop-laptop-2` confirmed at the
same stamp: the address resolved was the laptop's and the static key offered
was the studio's.

`InitialSyncPuller` made the same split a third time — its cursor peer came
from `deviceX25519Keys()` while `SetupProgressScreen` handed it an address
recalled for `otherDeviceNames()`'s first peer.

## What the fix is

The caller names the peer, and everything else is looked up by that name.

- `PeerDial` carries `deviceId`, `host` and `port` together. Nothing passes a
  bare host and port to a dial any more.
- `ConfirmedLanPeer::fromConfirmed()` looks the static key up **by device id**
  and answers null when this device holds no confirmed key-agreement key for
  it. There is no first-entry fallback: another device's key reaches nobody.
- `PeerLanAddress` stopped choosing the peer. It is the ladder for one *named*
  peer — last reached, then what a reader typed, then a guess derived from the
  relay endpoint's host — and its callers, which already know which peer they
  mean, say so.
- `deviceX25519Keys()` states the same order `otherDeviceNames()` does. Nothing
  keys on it any more, but a map whose order moves under an `ANALYZE` is a trap
  left for the next reader.

## Selecting by an unauthenticated hint is not a weakening

The device id a browse reports comes from the `did=` TXT record, which
authenticates nothing — anything on the subnet can answer. That is why the id
is used **only to choose which of this device's own confirmed keys to offer**,
and never as a reason to trust anyone. A liar gets the wrong key offered to it
and the handshake fails, which is exactly what it gets by not answering at all.
The trust decision stays where it was: the responder has to hold the private
half of a key this device confirmed through a safety-number comparison.

The alternative shape — try every confirmed key in turn and let the handshake
decide — was weighed and not taken as the primary road. It costs one dial and
one dial timeout per confirmed peer on every tick, to answer a question the
caller already knows the answer to. It remains the right shape for a caller
that genuinely cannot name its peer, and there is no such caller: a phone with
no confirmed peer holds no key to offer and skips the LAN leg entirely.

## The second desktop was never dialled

Naming the peer fixes *which* one is reached; it does not by itself reach the
others. `SyncScreen::syncNow()` resolved one address, dialled it, and stopped —
so in a household with two confirmed desktops the second was unreachable from
the phone for as long as the first existed, asleep or not. It now walks the
confirmed peers in `otherDeviceNames()`'s stated order.

The walk stops early on anything that is not about the peer it just tried.
`SyncAttemptOutcome::endsTheWalk()` says which: a sync that happened, and the
four answers that are about **this** device — a sealed identity, an unreadable
key file, sync never enabled, and the pause-on-cellular gate. Every remaining
peer would answer those the same way. `Unreachable` and `NotSecured` are about
the peer, so the walk continues, and an `Unreachable` never displaces a
`NotSecured` in what the reader is shown: "the other device answered and the
secure session did not open" is the more telling of the two.

## What this does not cover

`ManagesManualPeerAddress` still offers a reader one typed address, for the
first peer. A household on a network whose browse never answers can therefore
name where one of its two desktops is and not the other. The dial would now use
a second one; the settings surface does not yet collect it.

`InitialSyncPuller` still dials a single peer, which is deliberate: its
progress cursor is per `(user, peer)` and the import screen reports one
transfer. It resolves that peer's address itself, so the address and the key
name the same device.
