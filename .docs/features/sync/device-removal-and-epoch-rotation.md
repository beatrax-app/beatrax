# Removing a device: revoke, rotate, fan out

Removing a device from a household has to accomplish two separate things, and doing only one
of them is an access-control gap rather than a partial success.

- **Revoke only.** The removed device stops being trusted, so its op-log entries no longer
  verify and it can no longer join a session. But it still holds the current Group Data Key
  epoch, and every sensitive column written from that point on is encrypted under that same
  key. A device that keeps a copy of the database file — or that reaches the relay again —
  can read everything written after its removal.
- **Rotate only.** A fresh epoch is minted so future writes are unreadable to the removed
  device, but its Ed25519 key is still `confirmed_at` in `device_registry`. Its signatures
  still verify, so it can still write ops that peers accept, and it is still an eligible
  recipient in every subsequent key fan-out — which would hand it the new epoch immediately.

`GdkRotationService::rotateAndRevoke()` therefore does both, in one operation, in a fixed
order. The order is not cosmetic.

## The sequence

```
guard is_self  →  load the keyring (proves the KEK)  →  mint the new epoch id
      →  [ SQL transaction: revoke trust → append epoch → fan out ]
```

**1. Refuse to revoke the acting device.** `is_self = 1` can never be the target. Livewire
actions are invoked by the client, so a crafted `removeDevice(<self row id>)` reaches the
server as an ordinary request. Hiding the button in the Blade template is not a control;
the refusal has to be authoritative here. Revoking self clears this device's own
`confirmed_at`, which drops the user out of their own trusted-device set — the device stops
being able to hand its own entries to anybody, including a future replacement device.

**2. Load the keyring before touching `device_registry`.** `GdkKeyringService::loadKeyring()`
throws when the app-lock KEK is unavailable. If that throw happened *after* the revoke write,
a removal attempted while the app is locked would commit the revoke and never reach the
rotation — producing exactly the revoke-only state above, silently. Loading first turns a
locked app into a clean failure with nothing written. An empty keyring is not an error: it
means encryption was never enabled for this user.

**3. Mint the new epoch id from the ids already held.** See
[Epoch ids are minted, not counted](#epoch-ids-are-minted-not-counted).

**4. Revoke, append and fan out inside one SQL transaction.**

- *Revoke* clears `confirmed_at` on the target row. That is the exact column
  `DeviceRegistryService`'s device-key queries filter on, so this single write closes the
  Ed25519 gate and simultaneously removes the device from `deviceX25519Keys()`.
- *Append* adds the new epoch to the keyring and advances
  `sync_encryption_state.current_epoch` to it. Nothing about the previous epochs is
  discarded.
- *Fan out* seals the new key to every device still returned by `deviceX25519Keys()`,
  skipping self, and enqueues each wrap on the relay mailbox. Because the revoke already
  ran, the removed device is structurally absent from that loop — the exclusion is a
  consequence of step order, not a second filter that could drift.

Each wrap is signed with the acting device's own Ed25519 key, which is why the identity is
loaded only after `loadKeyring()` has proved the KEK is available. A null identity at that
point is not the ordinary locked case (the KEK is demonstrably present) — it is an
unexpected state, and the code fails closed rather than emitting an unsigned wrap no peer
could adopt. What happens to the wrap after it is enqueued is covered in
[Getting a group data key epoch onto every device](gdk-epoch-wrap-delivery.md).

### What the purge takes, and the one thing it must not

`DevicesAndSyncSettingsSection::removeDevice()` follows the rotation with
`DeviceRegistryService::purge()`, which deletes everything keyed to the device that would
otherwise keep surfacing it: its `sync_sessions` rows (what the status section lists), its
`relay_mailbox` rows, its `pairing_tokens`.

It does **not** delete the `device_registry` row. The row is already revoked, so every
confirmed-only query — `deviceKeys()`, `deviceX25519Keys()`, `confirmedDevices()`,
`otherDeviceNames()` — steps over it and the device is invisible in the UI. What survives is
its Ed25519 public key, and that key is the only thing that can ever verify the history the
device wrote. Deleting the row made `OpLogRebuilder::rebuild()` — which deletes every row a
CreateRow op created, then replays the log to put them back — refuse the whole of that
device's log as `missing_device_key` and never recreate a single row. A goal made on the
phone, the phone removed, one rebuild later: goals count 0, quarantine full, transaction
committed cleanly.

Retention grants the removed device nothing, and "reading history back" is what the wider map
from `retainedDeviceKeys()` is limited to *mechanically*, not merely by intent. `deviceKeys()`
is the admission anchor, and `GdkEpochControlHandler` still refuses a wrap the removed device
signs.

The limit is one question, asked before the retained key is ever consulted: is this exact entry
— same identity AND same signature — already in `op_log_entries`? Only verified entries are
ever persisted there, so a match is proof this device admitted it under a confirmed key once,
and it stays admitted. That is also the whole of the second door for an install where an older
build deleted the registry row: such an entry is accepted with no key at all.

An entry the durable log does **not** hold is new work, and a device with no `confirmed_at` is
unconfirmed however it got that way. It is quarantined as `unconfirmed_device` — a reason of
its own, because the registry row and its key are sitting right there and blaming a missing key
would name a cause the device list disproves. Nothing replays a quarantine recorded at this
gate: the entry never reached `op_log_entries`, and `HistoryReprojector` replays out of that
table. Where such an entry could reach this device at all — a still-confirmed third device
forwarding the removed one's writes — losing it is the price of the revocation this device
performed, but it is a loss, not a hold.

### The one residual

The SQL transaction covers the revoke, the `current_epoch` advance and the mailbox rows.
It cannot cover the keyring *file*: `appendEpoch()` re-encrypts and renames a file on disk,
and a filesystem rename does not join a SQLite transaction. A rollback after that point
leaves an extra epoch key in the keyring file that `current_epoch` does not point at. That
is benign — the keyring is append-only by design and an unused key decrypts nothing — but
it is the known edge, and it is why the rest of the operation is inside the transaction:
so the *revoke* can never be the thing that survives alone. `removeDevice()` logs the
exception through `SafeExceptionContext::describe()` when any of this fails, so the split
state has something naming it; it used to catch `\Throwable` with no bound variable at all.

## The revoke half is local to the device you perform it on

A removal does two things, and only one of them reaches the rest of the household.

The **rotation** is household-wide by construction. A new epoch is minted here and fanned out
to every device still confirmed here, and the removed device is structurally absent from that
loop. Every sensitive column written anywhere after the removal is sealed under a key it does
not hold, on every device.

The **revoke** is not. `confirmed_at` lives in `device_registry`, which appears **nowhere** in
`MergeRulesRegistry`: it is a device-local trust store on purpose, because an op that arrives
claiming trust is not a ceremony. So clearing the phone's `confirmed_at` on the desktop clears
it on the desktop. A second desktop in the same household still has that row, and until the
same removal is performed there:

- it still admits the phone's Noise handshake, because `deviceX25519Keys()` reads its own
  `confirmed_at`;
- it still ships the phone every op it has, including `amount_minor`, `booked_at`,
  `account_id`, `category_id`, `accounts.name` and `accounts.iban`, none of which
  `SensitiveFieldRegistry::columns()` seals;
- and it still accepts and relays the phone's *own* writes, because `opsAfterWatermark()`
  filters on a registry ROW rather than on `confirmed_at` — deliberately, so a removed
  device's history stays replicable — and `receiveOps()` verifies against the retained key map.

The mid-session revocation check and the `PEER_REVOKED` message do not close this either. They
are statements about **this** connection: the desktop tells the phone that *it* no longer
confirms it, and the phone clears its confirmation of the desktop. Neither says anything to a
third device, and neither is addressed to one.

### The notice is a claim about a removal, and only a removal makes it

Being told is terminal on the receiving side, by design: the drop is monotonic, and the only
way back is the whole pairing ceremony again. That is what makes the *trigger* load-bearing.

`SyncSession::authenticate()` refuses a device whose X25519 static key is not in the confirmed
map, and it refuses two very different devices on that one branch: the one this household
removed, and the one it has never admitted. A phone that has confirmed while this desktop's own
confirm is still in flight — held over the relay, or deferred behind a lock — is the second
kind, and telling it that it was removed turned a ceremony minutes from finishing into a
pairing that could not resume. So `tellPeerItIsRevoked()` now asks
`DeviceRegistryService::holdsRevokedDeviceWithKeyAgreementKey()` first: a row this registry
holds for that handshake key, with the confirmation taken off. With no such row there is no
removal to report, and the connection closes without claiming one. Nothing about admission
changed — the gate refused the device either way.

The receiving half is narrowed to match. `LanSyncClient` cleared **every** non-self row, on the
reasoning that it only ever dials one peer; a phone paired with two desktops lost the silent
one's confirmation to the other one's notice. One notice arrives over one session and speaks
for one device, so it now goes through `forgetPeerConfirmation()` for that device alone —
which is the path `SyncWebSocketHandler` already used for the mirror case.

### Which of the two behaviours is intended

Per-device is what ships, and the copy in the removal modal now says so: alongside "rotates the
key" and "cannot erase what is already there" it reads *"Your other devices keep their own
list. Until you remove it there too, they will go on syncing with it."* — shown only when
another confirmed device is actually there to keep it, so it is never a warning about nothing.

That is a statement of what the code does, not an argument that it is finished. A revocation
that propagates is the better end state, and the reasoning is the same one this page opens
with: revoke-only and rotate-only are each an access-control gap, and a removal where the
rotate half is household-wide while the revoke half is not is that same gap one level up. The
household-trust position — that members are co-equal — argues *for* propagation rather than
against it: there is no "my devices" and "your devices" for a per-device removal to be scoped
to, so a removal is a household act whoever performs it.

The shape it would take is a **signed, monotonic revocation**: the acting device states "device
X is revoked", signed with its own Ed25519 key, carried over the channels the epoch fan-out
already uses, and applied by a recipient through the `forgetPeerConfirmation()` path that
`MSG_PEER_REVOKED` already exercises. Monotonic is the load-bearing word — a revocation can only
ever *remove* trust, so an unauthenticated or replayed one cannot grant any, which is exactly
why `device_registry` must never become a merged table instead.

What that is not is a small change. It is a new wire message with its own delivery, replay and
re-pairing semantics, verifiable only on a real multi-device harness, and it is a behaviour
change, which this repository requires a merged spec change for first. A removal that propagates
*sometimes* would be harder to reason about than one that plainly does not, so the interim
position is the honest one rather than the convenient one: the code, this page and the on-screen
copy all say the same thing.

## Epoch ids are minted, not counted

`GdkEpochId::mint()` picks `random_int(1, 2^53 - 1)` and retries against the ids this
keyring already holds. It is not `max(held) + 1`, and the first epoch is not `1`.

A counter is unique only among the epochs **one device** happens to hold. Two devices that
rotate without having heard from each other both compute the same next number over
different key bytes. The result is not a merge conflict that surfaces — it is silent data
loss in both directions: every op the other device wrote at that epoch id decrypts to
nothing, because the local keyring holds a key of that id and it is the wrong one. Worse,
once *both* keys are in use, neither can be discarded; the collision is unresolvable rather
than merely inconvenient.

Random minting makes the collision astronomically unlikely instead of routine. Nothing reads
epoch ids in order — every lookup is by exact id — so ordering was never a property the
identifier needed, and uniqueness was.

The ceiling of `2^53 - 1` is the largest integer that survives a JSON round-trip exactly.
Epoch ids travel inside wrap payloads, so an id that arrived rounded would name a key nobody
holds.

## Adding a device is the mirror image, not a rotation

`fanOutAllEpochsToDevice()` is the add-device counterpart. It wraps **every** epoch already
in the keyring to the newly-confirmed peer's X25519 key. There is no rotation, no revoke and
no `appendEpoch()` — it is purely additive delivery of keys that already exist, so the new
device can read history rather than only what happens next.

Only a device that is confirmed, not self, and carries both a `device_id` and an
`x25519_public_key_hex` is an eligible target. Wrapping to an unconfirmed device would hand
a key to an identity that has not completed the safety-number comparison.

## Why the keyring is append-only

`GdkKeyring::withEpoch()` returns a new keyring with the epoch appended and never discards
one. `OpLogRebuilder::rebuild()` replays the entire persisted op-log, and rows in that log
are tagged with the epoch they were encrypted under — including epochs retired years ago.
Dropping a key would make that history permanently unreadable.

`withEpochReplaced()` is the single exception, reserved for adopting the group's
authoritative key over a local one of the same id that has never been used. The caller
proves that before calling; see
[Idempotence and epoch-id collisions](gdk-epoch-wrap-delivery.md#idempotence-and-epoch-id-collisions).

## See also

- [Getting a group data key epoch onto every device](gdk-epoch-wrap-delivery.md) — how a
  wrap is authenticated and adopted on the receiving side.
- [Sensitive columns at rest](sensitive-columns-at-rest.md) — what the epoch key actually
  encrypts.
- [Sync architecture](architecture.md).
