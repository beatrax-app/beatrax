# What the quarantine tells the reader

`OpLogQuarantine` records an operation the merge layer refused. Nothing drains
it for most reasons, so a refusal is, in practice, a change that is not here and
never will be — and until this page's work, eleven of the fifteen reasons for
one reached nobody who was not holding a developer flag.

## What was measured

`QuarantineReason` has **fifteen** cases.

`QuarantineReason::recoverable()` names **four** of them:
`gdk_decrypt_failed`, `strategy_error`, `missing_reference` and
`split_sum_unreadable`. Those four are the whole of what a reader saw:
`HistoryReprojector::backlogState()` filters on exactly that list, and the
`SyncBacklogState` it returns is what the devices screen renders as *"Waiting to
be added"*.

The other **eleven** reached one production reader: `SyncHealthPage`, at
`/dev/sync-health`, behind `['web', 'auth', 'ensureDeveloperMode']`. Two of them
are `forged_signature` — a peer sent an operation whose signature did not verify
— and `primary_key_collision` — two devices minted the same row id, which is
divergence with no error anywhere. Those are the two conditions the reader most
needs to know about, and they were the two furthest from being told.

## A state is not a count, and a count is not an answer

`SyncBacklogState` is deliberately a **state** rather than a number, and that is
the right shape: *"this device is behind and catching up"* is what a reader can
act on, and *"7"* is not. Keeping that, the question for the other eleven is not
"where do we put the number" but "what is true of them that is not true of the
four".

Three things are, and each one changes the sentence:

- **Nothing takes them again.** `HistoryReprojector::replayQuarantined()` only
  ever replays rows whose reason is in `recoverable()`. A sender counts the
  operations it delivered, so a second sync does not re-offer them either. A
  refusal outside that set is terminal in practice: two demo transactions were
  lost that way before `missing_reference` was moved into `recoverable()`.
- **The reader can sometimes do something, and it is never the same thing.**
  Updating this device answers an unknown column. It does nothing at all for a
  forged signature.
- **They are not equally alarming.** A device you removed yourself producing a
  refusal is the mechanism working. A signature that did not verify is not.

So the surface is four blocks of copy, one per **outcome**, each carrying its own
count, its own statement of what is and is not lost, and its own next step.

## The four outcomes

`QuarantineOutcome` partitions the eleven, and it does so **against
`QuarantineReason::recoverable()` at runtime** rather than against a second
hand-kept list. `reasons()` filters the outcome's membership through that set,
so a reason that becomes retried-and-retired — which is a real and expected
change — leaves the terminal blocks and joins the backlog notice with no edit
here at all. A test asserts the two halves add up to fifteen with nothing in
both, and a second one asserts no recoverable reason is drawn as terminal.

Four-of-fifteen is therefore a **measurement, not a constant**: the moment
`recoverable()` grows, the split moves with it and the copy follows.

| Outcome | Reasons | What is true | What the reader can do |
|---|---|---|---|
| `TooNew` | `unknown_table`, `unknown_column` | A newer build wrote something this one has no schema for. The change is on the device that made it. | Update Beatrax here. The refused ones are not re-sent, so a change that matters has to be made again on this device. |
| `UntrustedAuthor` | `missing_device_key`, `unconfirmed_device` | Signed by a device never paired here, or one that was removed. Nothing was written and nothing already here changed. | Nothing, if the removal was deliberate. Otherwise the device list on the same screen. |
| `NotVerified` | `forged_signature`, `cross_user` | A signature did not verify against the key of the device claiming to have written it, or the entry named a different account. | Check the device list and remove anything unrecognised. Between a household's own devices neither of these should ever happen. |
| `Diverged` | `incomplete_create_row`, `delete_blocked_by_reference`, `impossible_date`, `primary_key_collision`, `unplaceable_collision`, `split_would_overfill_transaction` | The write itself could not be made, so the two devices hold different things — a row missing here, or one deleted elsewhere and still here. | Compare the record across the two devices and redo the change here. |

`primary_key_collision` has since moved into `recoverable()`: the re-home gives
it a retry and `RetriedCollisionCreates` a retirement, so
`QuarantineOutcome::reasons()` masks it out of `Diverged` and the reader is told
about it by `SyncBacklogState` instead. The row above keeps the line because
`whileTerminal()` does — a reason moving back needs no archaeology to find the
sentence it used to be given. The half of that event no pass can undo — a
collision on a table declaring no natural key — is recorded as
`unplaceable_collision` and is drawn here, so making the first recoverable did
not cost the second its disclosure. See
[architecture.md](architecture.md#retrying-a-create-two-devices-minted-one-id-for).

`NotVerified` is the only one painted as a danger. Spending that colour on a
removed device teaches the reader to read past it, and the one outcome that is a
security event is the one that cannot afford that.

## The refusals the top line could not see

Measured on a Galaxy A51 paired to a Mac, after two full exchanges the reader
had asked for by hand and 2,348 operations crossing. The devices screen read:

```
Synchronisatiestatus
Alle apparaten zijn up-to-date
· gesynchroniseerd 1s geleden
```

At that instant the phone's own database held **65 refused operations** —
`strategy_error` 38, `primary_key_collision` 17, `missing_reference` 10 — and of
the 189 transaction ids the two devices shared, 82 named a different transaction
on each. Nothing on any screen outside `/dev/sync-health` said so.

Three surfaces each had a reason not to:

- `SyncStatusService::overallStatus()` reached `AllSynced` off the **outbound**
  queue alone. Its settled arm asked what this device still owes a peer and what
  a peer is withholding; neither question is about an operation that arrived
  here and was turned away. "Nothing left to send" and "the two devices agree"
  are both worth knowing and only one of them is what that sentence claims.
- `SyncQuarantineNotice` draws `QuarantineOutcome`, which by construction speaks
  for the **terminal** half. All 65 were recoverable, so it drew nothing.
- The backlog notice draws `SyncBacklogState`, and `backlogState()` filters
  through `withinPassWindow()`. Every one of the 65 was older than the watermark
  the last pass stamped — `history_reprojected_at` was `23:21:11` and the newest
  hold was `23:21:10` — so it answered `None`.

Each surface was answering its own question correctly. Between them they left a
device holding 65 refusals telling the reader every device was up to date.

`SyncOverallStatus` therefore gained two cases, and they are split by
**recoverability** rather than by count, because a loss and a wait are different
facts about the reader's data:

| Case | What is true | Tone |
|---|---|---|
| `Refused` | A refusal nothing takes again is here. The two devices hold different things and no exchange closes the gap. | warning |
| `Held` | A refusal a later pass can still answer is here. Nothing is lost yet. | info |

Both rank inside the settled arm, below `Offline` and above `Behind`, on the
same rule the withheld line was ranked on: **what clears the state**. Nothing at
all clears a terminal refusal, so it goes above `Withheld`; a pass that can
answer a hold clears one, so `Held` goes below it; the next exchange clears an
unsent change, so both go above `Behind`. They are asked only in that arm
because the states above it — error, syncing, offline — make no claim of
agreement for a refusal to contradict.

`RefusedOperations::tally()` is the reader behind both, and it counts **distinct
`(table_name, pk)` per half** rather than rows. A create is captured per field,
so the ten `missing_reference` entries measured above are three records missing
from the phone; per half rather than per reason because a record refused twice
under two reasons is still one record. On the measured phone the two halves come
out at 0 terminal and **20 held**, against 65 entries.
`SyncQuarantineNotice` counts the same way for each of its blocks, so the line
and the notice under it cannot disagree about what counting means.

### A count needs somewhere to go

Both new lines carry a link to `#sync-refused-changes`, which is
`SyncQuarantineNotice`'s root element on the same screen. That link is why the
notice grew a block for the recoverable half: a status line reporting a number
whose detail surface is empty is barely better than the silence it replaced.

The held block's copy names a **condition and never an act**, the same rule the
withheld copy is written under and for a sharper reason — see the next section.

### Nothing retries a hold whose blocker is data

`QuarantineReason::recoverable()` claims a retry for `missing_reference`, and in
steady state there is none. `withinPassWindow()` reopens only on two events: key
material moving, or `HistoryReprojector::PASS_REACH` being bumped. Neither of
those is the event that undoes a missing reference — **the parent row arriving
is**, and no watermark moves when it does. `clearSettled()` retires a hold once
the held row itself turns up; it does not look at the row the hold *references*.

Measured on the same phone: the three `anomaly_alerts` creates held for
`missing_reference` name transactions 6, 7 and 8, and all three of those
transactions are on the device. Two of the three would apply today; the third
would be recognised as already present. None of them will be offered again — the
sender counts what it delivered, which is why a second exchange left the count
at ten — and no pass will take them again either.

That is recorded here rather than repaired here: the merge path is being worked
on elsewhere. What it settles is the wording. `Held` says a change has **not
been applied yet** and stops; it does not say one is waiting to be retried,
because on this build nothing is.

## Where it renders

`SyncQuarantineNotice` is a Livewire component with **one** public method,
`render()`. That is a contract, not an accident: `op_log_quarantine` is evidence
of a defect in the merge layer, and a control that applies a refused operation
anyway writes the very data the refusal is about.
`AQuarantineSurfaceOffersNoWayToApplyWhatItRefusedArchTest` fails on a wire
action or on any control in the template.

It is mounted from `devices-and-sync-settings-section.blade.php`, beside the
backlog notice and never folded into it: that one reports a wait, and a reader
told *"waiting to be added"* about a forged signature has been told the one thing
that is not true of it. `/data-devices` is the only screen that mounts that
section, and both shells route to it, so a fix here reaches the desktop and the
phone at once.

Its root element carries `id="sync-refused-changes"` and is in the DOM whether
or not it has a block to draw, so the status line's link never dangles.

## Two tables that stay internal, and what they owed anyway

`deferred_op_captures` and `sync_backfill_state` are read by their own machinery
and rendered by nothing. Both stay that way, and the argument is the same for
each: **neither is a fact about the reader's data — both are facts about how far
this device has got.**

- `deferred_op_captures` holds coordinates, never values. The change it names is
  already in this device's own tables; what is outstanding is announcing it to a
  peer. `DeferredOpCaptureDrain` empties it on the first request that can sign,
  which is the same request that would have drawn a count, so the number would be
  stale before it was read.
- `BackfillProgress` is a cursor into this device's own history. Where it has got
  to is not something a reader can act on, and a percentage of a walk nobody
  asked for is noise.

What was genuinely missing is that **both make this device owe a peer, and
neither had an operation in `op_log_entries` to prove it.**
`SyncStatusService::hasUndeliveredLocalOps()` compared the newest local
operation against the last session's close, so a device holding a full deferred
queue, or halfway through its first backfill, answered *"All devices up to
date"*. It now asks `DeferredOpCaptures::hasPending()` and
`BackfillProgress::isOpen()` first, and both route into the `Behind` status that
already exists — *"Changes not yet sent"*, which is exactly what is true.

That is the whole of what those two tables owe a reader: not a number of their
own, but the honesty of the sentence one screen up.

## The guard

`tests/Contracts/EveryQuarantineReasonReachesANonDeveloperReaderArchTest.php`
enumerates the reasons **off the live enum by reflection** — never by grep, which
is how an earlier sweep mis-triaged a whole set — and asserts each one reaches a
Livewire component that no `ensureDeveloperMode` route stands in front of.

It carries three self-checks so it cannot pass by finding nothing: the number of
reasons it was written against (fifteen, pinned, because a new case is a
decision somebody has to take), the set the backlog notice speaks for (read off
`recoverable()`, never pinned, because that set legitimately moves), and a
planted template proving the scanner does not credit a symbol named only inside
a Blade comment. Gating is read off the runtime route table, because the
middleware alias only resolves through `gatherMiddleware()`.

A reason that deliberately reaches no reader is allowed, and has to be written
into `QUARANTINE_READER_DELIBERATELY_UNREAD` with the argument for it. The list
is empty.

## A hold is not a quarantine, and it needed its own line

A quarantined operation arrived and was refused here. A **withheld** one never
arrived: the peer read the `verifiable` list on the catch-up request, left the
author out of the answer, and said how many entries it kept and for whom. The
receiving device's cursor for that author does not move, so the entries are
owed rather than lost: they arrive in full if that author is ever confirmed
here.

*If* is doing real work in that sentence. A peer relays history for every author
it holds a key for, and vouches for only the ones it paired with, so a hold
comes in two kinds and only one of them has an act in it. Where an identity was
offered, the reader confirms it and the entries follow. Where the peer knows the
author only through an introduction of its own, it may carry the data and may
not pass the identity on — a vouch made on the strength of a vouch is a chain —
so the count arrives with nothing beside it and no confirmation anywhere ends
it. **Copy on any of these surfaces therefore names a condition, never an act:**
*"arrives once one of your devices passes on that identity and you confirm it"*
is still true when nothing ever does, and *"confirm that device to recover
these"* is a button that will not be there.

That is also why it could not borrow the quarantine's surfaces. Nothing was
refused, so `op_log_quarantine` has no row; nothing is broken, so `Error` would
be wrong; nothing is in flight, so `Syncing` would be wrong.
`SyncOverallStatus::Withheld` is its own line, ranked above `Behind` and below
`Offline`. The ranking does not rest on the reader having an act — half the time
they do not. It rests on what clears the state: an unsent change leaves on the
next exchange, and a hold leaves on no exchange at all. A state nothing in the
system will resolve on its own outranks one that resolves itself, whether or not
the reader can hurry it along.

Two surfaces read it, and both read it through one object:

- `SyncStatusService::overallStatus()` for the aggregate the settings screen and
  the phone's sync screen both mount.
- `InitialSyncPuller`, which is the harder case. Its completion report is the
  only thing a reader sees during setup, and it computed `records_expected` as
  `records_applied`, so a first sync short by an entire replaced phone's history
  drew a full bar and a "This device is synced" heading. The expected count is
  now what arrived **plus** what the peer declared held, and `SyncCompleteScreen`
  carries the sentence that says which.

`WithheldHistoryReport` is that one object. It exists because the answer is not
the ledger row: a row names the last exchange's report, and an author the reader
has confirmed since is one the *next* exchange withholds nothing for. Filtering
on what this device can verify **now** is what keeps a confirmation from leaving
a warning standing on one screen and cleared on another.

The count it totals is the largest report per author, never the sum across
peers. Two peers holding the same author's work back are two accounts of one
gap.

## An empty quarantine is not an answer either

Everything on this page is about an operation that **arrived** and was refused. A quantity that
never became an operation is outside it entirely, and reads as health: on a pairing that copied
8,481 records with `op_log_quarantine` at 0 on both devices, seventeen `tax_transaction_tags`
rows had produced no op-log entries at all on the sending device. Nothing was refused because
nothing was offered.

So the reading "quarantine is 0, therefore the sync lost nothing" is wrong in the one direction
that matters, and no amount of work on this table fixes it. The counterpart guard is on the
sending side: the pre-sync walk counts rows against captured creates for every covered table
before it is allowed to record itself as finished, and says what it covered even when it covered
everything. See [Silence is not a report](pre-sync-history-capture.md#silence-is-not-a-report).

## Related

- [Sync architecture](architecture.md) — the merge layer that produces these refusals
- [A mutation a keyless process cannot sign](a-mutation-a-keyless-process-cannot-sign.md) — `deferred_op_captures` end to end
- [Capturing the history that predates sync](pre-sync-history-capture.md) — the walk `BackfillProgress` tracks
- [Sensitive columns at rest](sensitive-columns-at-rest.md) — the epoch split behind `AwaitingKey`
