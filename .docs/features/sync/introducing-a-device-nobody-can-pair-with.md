# Introducing a device nobody can pair with

A household replaces a phone. The new phone pairs with the Mac and receives
everything, but it never paired with the phone it replaced, so it holds no key
for it. Every op that phone signed is unverifiable here, permanently, because
the second party to that ceremony no longer exists.

Measured on the real pair rather than argued: the Mac's op log held **6,802
entries — 6,603 its own, 44 the current phone's, and 155 signed by the phone
that was replaced**. No row data is at stake; the re-capture behind ADR-0024
means every row is separately covered by an op the Mac signed itself. What was
at stake was 155 quarantine rows a new phone could never clear, and a
sync-health screen reporting a fault its reader could not act on.

Two mechanisms answer it, and they are one exchange.

## The catch-up request says what this device can verify

`PeerCatchUpExchanger::buildRequest()` carries a `verifiable` list beside the
per-author cursors: the device ids of
`DeviceRegistryService::signatureVerificationKeys()`, which is the same map the
op-log verifier admits on. Advertising anything else would mean advertising an
author this device then refuses.

The answering side (`opsAfterWatermark()`) restricts the delta to those authors.
`countWithheldByAuthor()` is the mirror of that filter and counts what it took
out, per author, over the same two helpers so the two can never be answers to
different questions.

**A peer that names no authors at all filters nothing.** An older build does not
send the field, and silence is not a claim to be able to verify none of them —
reading it as one would withhold the whole history from every device in the
household that has not updated yet.

## Nothing that was withheld is withheld quietly

The narrowing is the alternative's stated cost in ADR-0027: a receiver ends up
holding less than the household has. Three surfaces stop that being silent.

| Where | What it says |
|---|---|
| The answering device's log | `IntroductionOffers` logs at **error**, with the peer, the authors and the totals. Error rather than warning for the reason `reportUnframable()` is one: a withholding nothing announces reads as an ordinary clean sync from every surface above it. |
| The `CATCH_UP_RESPONSE` | `withheld` carries a per-author count, so the asking device learns the size of what it is not getting. |
| The device list | `WithheldLedger` stores every count the answer carried, per (peer, author), and the screen reads it back — beside the introduction where there is one, and on its own where there is not. |

**The count is the half of the report that always applies.** It was originally
written into `device_introductions.withheld_entry_count`, which meant it only
survived when the same answer carried a well-formed identity for that exact
author — so the three cases that arrive without one all dropped it into the
*sender's* log and nowhere else:

- an author the answering peer has itself removed, which it may not vouch for;
- a relayed key that fails `WithheldHistory`'s hex and length check;
- an author this install already holds a `device_registry` row for, where the
  removal stands and `DeviceIntroductionService::record()` refuses the row.

`IntroductionOffers::record()` therefore writes the ledger **before** it reaches
the guard that returns early on an empty offer list. The ledger replaces a
peer's whole report each time rather than merging into it: an author the peer
no longer names is holding nothing back, and a number left standing after that
describes an exchange that has already been superseded. That is also what stops
a confirmed introduction sitting under a line saying its history cannot be read
until the reader confirms it.

The screen applies the same test one step earlier. An author this device can
already verify — paired since the report arrived, or introduced and confirmed —
is left off the standalone list, because the next exchange withholds nothing for
it and repeating the number would state a narrowing that has already ended.

The refusal for a device both installs removed is still logged at warning by
`IntroductionOffers::reportRefusedForRemovedDevices()`, because there is nothing
for a reader to *decide* there. The number is now on the screen anyway: what a
peer is holding back is a fact about this device's history whether or not there
is a button beside it.

## An introduction is stored, not trusted

For an author it withheld **and has itself confirmed**, the answering device
relays that device's name and Ed25519 public key. The receiver writes it to
`device_introductions` with `verification_confirmed_at` NULL.

The fingerprint shown is derived **here**, from the key that arrived and this
device's own, through the same `SafetyNumberDeriver` pairing uses. A fingerprint
copied from the sender would be a fingerprint of the sender's claim.

Confirming is one reader on one device, because the other end of the original
ceremony is gone. The screen says that in as many words rather than borrowing
pairing's language: there is no second screen to compare against, and what is
being trusted is the device that vouched.

## The boundary, and why it is structural rather than a filter

A confirmed introduction grants **signature verification and nothing else**.
That is not enforced by remembering to exclude it from three queries; it is
enforced by where the key lives and what is stored beside it.

- **A different table.** Every confirmed-only query in this module is a
  `whereNotNull('confirmed_at')` over `device_registry`. Nothing reachable from
  `device_registry` can ever return an introduced key, because there is no row
  there to return.
- **And the registry still wins, read back rather than assumed.** `record()`
  refuses to store an introduction for a device the registry has a row for, in
  either direction. `signatureVerificationKeys()` applies the same exclusion in
  SQL, because the two orderings are not the same event: an introduction
  confirmed *before* that device paired left a second grant behind, and the
  removal the reader later performed did not reach it — so the device went on
  verifying through the weaker door. `DeviceIntroductionService::forUser()`
  excludes those rows too, or the list would offer "confirmed for signatures"
  next to a grant no longer being made.
- **No transport key at all.** An introduction carries the Ed25519 signing half
  only. `device_introductions` has no `x25519_public_key_hex` column, so the
  Noise static key a session authenticates against has nowhere to land even if
  a query were widened by mistake.
- **One reader.** `DeviceRegistryService::signatureVerificationKeys()` is the
  only method that reads the table's keys. It feeds the four places that build
  `OpLogReplayer`'s `$deviceKeys` map, and one more: the list of authors this
  device advertises it can verify, which has to be the same set or the device
  asks for ops it will then refuse. All five are pinned by an arch test.

What deliberately keeps using `deviceKeys()` — the paired-only map — is as much
of the boundary as what does not:

| Call site | Why it stays paired-only |
|---|---|
| `GdkEpochControlHandler::confirmedSenderKey()` | Epoch delivery. E2-R19 names it. |
| `InitialSyncPuller::resolvePeerDeviceId()` | Chooses which peer to dial; an introduced device is not dialable. |
| `DeviceRegistryService::deviceX25519Keys()` | Transport authentication, already pinned by `AConfirmedPeerKeyHasOneSourceArchTest`. |

`AReplayIsAdmittedOnlyByAConfirmedDeviceKeyArchTest` covers the other half. It
allows either admission anchor at a replayer site and still refuses the retained
map — and it reads the second anchor back, so accepting a wider map stays
conditional on that map being the reader's own confirmations and nothing else.

## A cursor is not spent on a refusal

`SyncSession::receiveOps()` used to merge `retainedDeviceKeys()` into the
caller's map, admit an entry on the wider one, and advance the peer watermark
over everything it admitted. Since the E2-R13 gate tightened, such an entry is
refused by the replayer and quarantined under `unconfirmed_device` — which is
not in `QuarantineReason::recoverable()`, and `HistoryReprojector` replays out
of `op_log_entries`, which a refused entry never reached. The cursor had moved
past it, so no peer would ever offer it again.

The caller's map is now the whole gate. An entry whose author this device
cannot verify is **held**: not replayed, so no quarantine row accrues per
arrival, and not watermarked, so the very next catch-up after a confirmation
delivers it. That hold is what makes confirming an introduction do anything at
all — without it the reader confirms a key for ops the cursor has already
skipped.

The retained map is still read, for one purpose: telling an author this install
once trusted (an introduction can restore it) from one it has never heard of
(nothing can). It names which in the error line and admits neither.

## Removal

Dismissing an introduction deletes its row. No epoch rotation follows, because
an introduced device was never sent an epoch — there is no key material to take
back.

`DeviceRegistryService::purge()` does not touch this table and does not need
to. A purge leaves the `device_registry` row standing with its `confirmed_at`
cleared, and a row there is what the exclusion above keys on: the introduction
stops granting anything the moment the registry row exists, whichever of the
two arrived first.
