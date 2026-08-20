# The blocking initial-sync gate on a joined phone

A phone that has just paired with a desktop holds an account, a device
identity and an empty ledger. Everything the user expects to see — balances,
transactions, budgets, the shape of their month — lives on the other device
and has to cross the wire before any of it is true here.

The obvious approach is to let the app open and fill in behind the user.
That is wrong for a finance app in a way that is not merely cosmetic. A
half-populated balance is not "loading"; it is a *wrong number*, rendered
with the same confidence as a right one. There is no spinner that makes
"€ 1,240" honest when the remaining half of the month has not arrived yet.
So the phone does not open. `SetupProgressScreen` stands in front of the app
with no cancel, no dismiss, no back and no skip, and releases only when the
device can render the truth.

The cost of that decision is that the gate now owns every way a first sync
can go wrong, and on a phone there are a lot of them.

## Why it has to be resumable

Mobile operating systems kill backgrounded processes routinely and without
warning. A first sync that pulls a year of history is exactly the kind of
long operation that gets killed: the user starts it, switches to check
something, and comes back to a process that no longer exists.

If progress lived in memory — or in a Livewire property, which is the same
thing with extra steps — every kill would restart the transfer from zero.
Worse, a naive restart would re-apply what it had already applied.

So progress is durable. `mobile_sync_progress` holds one row per
(user, peer device) carrying `records_applied`, `records_expected`, the
hybrid-logical-clock watermark `last_hlc_l` / `last_hlc_c`, the `phase`, and
a `reprojected_at` stamp. `InitialSyncPuller` is stateless between calls: a
freshly constructed instance in a cold-started process reads that row and
carries on from it.

Two consequences worth keeping:

- `SetupProgressScreen::mount()` reads the durable cursor via
  `InitialSyncPuller::progress()`, which does *not* drive a sync step. The
  very first paint after a relaunch therefore shows the true resumed
  percentage rather than flashing 0 and jumping. `isResuming` is derived
  there too, so the headline says "Resuming setup" instead of claiming this
  is a fresh start.
- De-duplication is not invented here. The puller recounts applied records
  on every step by asking the *same* watermark-scoped query the wire
  protocol itself uses (`PeerCatchUpExchanger::opsAfterWatermark`), read
  against the cursor's persisted watermark rather than the device's own
  write-side clock. A watermark only ever advances, so calling `pull()`
  again over an unchanged one counts zero and advances nothing.

One subtlety in that count: the watermark covers *this* device's writes as
well as the peer's. Counting everything after the watermark made a phone
report its own locally seeded rows as records received from a desktop it had
never reached. Only entries whose `device_id` is the resolved peer count as
progress.

## What one step actually does

`SetupProgressScreen::poll()` calls `InitialSyncPuller::pull()` once per
tick. Each call is bounded — it opens, does one thing, and returns. The
phone never runs a listener or a daemon; see
[the module architecture page](architecture.md) for why that is a platform
constraint rather than a preference.

A step, in order:

1. Load the device identity. A null identity — app-lock engaged, no key, or
   sync never enabled — means the step does nothing at all and mutates no
   cursor.
2. Resolve the peer: the single confirmed non-self device in
   `device_registry`. Household pairing is one-to-one, so there is exactly
   one, and multi-peer selection is deliberately out of scope.
3. Run one `MobileSyncTriggerService::syncOnce()` burst — LAN first, relay
   as fallback.
4. Recount from the watermark, advance the cursor, and decide whether the
   gate can release.

The LAN leg needs an address, and this is a real trap: the puller only
attempts LAN when handed a host and port, and at one point *every* caller
passed neither. The relay leg alone drains a mailbox without applying rows,
so the device sat at "0 of 0 records" indefinitely while looking busy.
`PeerLanAddress` closes that by deriving the host from the relay endpoint
the pairing QR carried — the machine that issued the QR is the machine
`sync:serve` is listening on, and it is the only address this device is ever
told.

## Keys before the data they decrypt

The single most important ordering constraint in the whole flow lives in
`LanSyncClient::runExchange()`.

The desktop's history is encrypted under group data keys the phone does not
have yet. Those keys arrive as epoch wrap frames — see
[GDK epoch wrap delivery](../sync/gdk-epoch-wrap-delivery.md) for the wrap
format and the sender-signature rule.

Draining those wraps *after* the catch-up exchange looks harmless and is
not. The first sync then applies the desktop's entire encrypted history
against an empty keyring, every entry quarantines as undecryptable, and
there is no replay path built into the catch-up protocol to send it again.
The device ends up permanently holding rows it can never read.

So the epoch phase runs **before** catch-up, inside the same still-open,
already-authenticated Noise session. Two details follow from that placement:

- **The phase announces its own length.** A trailing phase can end on a read
  timeout; a phase with catch-up queued behind it cannot, because the
  timeout read would swallow the catch-up request. The desktop therefore
  sends a `GDK_EPOCH_PUSH` header carrying a count, and the client reads
  exactly that many frames (clamped to 100 per connect — a larger backlog is
  picked up on the next connect rather than pinning the fiber).
- **An unrecognised frame is skipped, not fatal.** Ending the loop on the
  first frame the client did not understand discarded every wrap queued
  behind it, and the desktop had already marked all of them delivered. The
  keys were simply gone.

The security property that must survive any refactor: a wrap reaches
`GdkEpochDeliveryGateway` **only** from inside this authenticated session.
Nothing unauthenticated may ever be wired into that path. The handshake that
establishes the session is described in
[the Noise handshake state machine](../sync/noise-handshake-state-machine.md).

## What the gate releases on

Finishing the sync leg is necessary and not sufficient. `pull()` reports
`complete` only when all three hold:

1. `syncOnce()` returned `true` — the full bidirectional catch-up exchange
   finished in this step. This is also what fixes `records_expected`: the
   phone has no way to learn the peer's total ahead of a completed exchange,
   so whatever is locally applied at that moment *becomes* the expected
   total.
2. The keyring is non-empty (`sync_encryption_state.current_epoch` is set).
   Without this a relay-only or not-yet-delivered import reports complete and
   lands the user on a dashboard of rows that failed to decrypt.
3. The op log has been re-projected, stamped by `reprojected_at`.

Point 3 exists because of the ordering above being imperfect in practice.
Entries can arrive and quarantine *before* the keyring is populated — over
the relay, for instance, where there is no single session imposing an order.
The first `pull()` step to observe a non-empty keyring therefore re-projects
the entire persisted op log exactly once per cursor, so anything quarantined
earlier now decrypts and projects.

Re-projection blocks the request it runs in, which produced a UI bug worth
knowing about. Running it in the same tick that finished the transfer made
the screen jump straight from "transferring" to "done", skipping the step
that actually takes the time — the user watched a frozen bar and then a
completed one. There is now an explicit `rebuilding` phase: one tick
persists the phase and returns, so the screen renders the rebuild step, and
the *next* tick performs it.

A re-projection that throws is logged and leaves `reprojected_at` null.
Completion stays gated on it, so the next tick retries rather than crashing
the poll.

## Reading a stall

A blocking screen with nothing to say is indistinguishable from a hung one.
Every non-working outcome is therefore named by `SyncBlockedReason`, whose
backing values are `mobile::setup.blocked.*` translation keys —
`SetupBlockedReasonsHaveCopyTest` walks `cases()` and fails on a case added
without copy, so a new reason cannot ship as a blank line on screen.

| Reason | Meaning |
|---|---|
| `NoPeer` | No confirmed peer yet, or no usable identity. |
| `NoKeys` | Peer reached, keyring still empty. |
| `Unreachable` | The sync burst ran and did not complete. |
| `Reprojecting` | History rebuild announced or in progress. |
| `Locked` | The app-lock key went away mid-flow. |
| `Revoked` | The peer says it no longer knows this device. |
| `Retrying` | The poll tick itself threw. |

Two of those are there because of specific failures:

- **`Revoked` is terminal, not retryable.** When a peer answers
  `PEER_REVOKED`, `LanSyncClient` clears the local `confirmed_at`. Every
  later tick then found no confirmed peer and reported "waiting for the
  other device" — which is what a device that had *never* paired reports.
  The screen span forever on a pairing that could not come back.
  `peerRevokedUs()` distinguishes the two by looking for a row that is
  paired but no longer confirmed: "no longer" has a row, "not yet" does not.
- **`Retrying` exists because this tick *is* the screen.** Letting an
  exception out of `poll()` answered 500, which Livewire discards. The view
  kept its last frame and looked perfectly alive while nothing ran again.

`SetupStep` maps each reason onto one of four ordered stages — connect,
keys, transfer, rebuild — so a long stage reads as "step 3 of 4" rather than
a hang. The progress bar reports how far the *current* step has got, not the
ceremony as a whole; a ceremony-wide number sat at 100% throughout the
rebuild because the transfer it measured had already finished. And the bar
is only determinate during transfer when `records_expected` genuinely
exceeds `records_applied` — since expected is derived from applied, treating
it as a total renders a full bar the instant the first row lands.
Everything else reports indeterminate, which is honest about not knowing.

## The one silent case left

An import exists because another device *has* data. Completing one with
`records_applied === 0` is an upstream defect, not a quiet success — but
"0 of 0" on a finished screen is indistinguishable from a sync that had
nothing to carry. The puller logs a warning on exactly that shape. Nothing
else in the system would say so.

## Related

- [Mobile module architecture](architecture.md) — cold start, pairing, and
  why every native crossing is a single bounded operation.
- [Pairing handshake](../sync/pairing-handshake.md) — how the phone got a
  confirmed peer in the first place.
- [GDK epoch wrap delivery](../sync/gdk-epoch-wrap-delivery.md) — the wrap
  format, sender signatures, and idempotency on re-delivery.
- [Op log merge rules](../sync/op-log-merge-rules.md) — what re-projection
  replays.
- [Device removal and epoch rotation](../sync/device-removal-and-epoch-rotation.md)
  — the other side of `PEER_REVOKED`.
