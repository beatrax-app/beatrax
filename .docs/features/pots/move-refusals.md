# Why a pot operation can be refused, and how the refusals differ

`PotWriter::transfer()` distinguishes six ways a move can be turned down, each
with its own exception type. `PotsPage::movePot()` used to catch three of them
and let the rest fall into `catch (\InvalidArgumentException)` — and because
`PotNotFoundException` extends `InvalidArgumentException`, four unrelated
situations arrived at the reader as one sentence:

> That pot could not be saved. Check the fields and try again.

Driven through the real Livewire endpoint, a move from a yen pot to a euro pot
and a move to a pot id that does not exist produced byte-identical output. Two
things were wrong with that sentence, and both are the same defect twice:

- **It named a cause that had been ruled out.** Moving between pots on two
  accounts, and picking one pot as both source and target, are ordinary things
  a person does. Nothing is malformed — the operation is simply not supported —
  so re-checking correct fields could never clear it. Because "different
  accounts" is also *every* cross-currency case, a reader holding a yen pot and
  a euro pot was given no hint that currency was involved at all.
- **It described the wrong operation.** "That pot could not be saved" is the
  create/edit form's wording. `fundPot()`, `withdrawPot()` and `movePot()`
  reused it, so a reader who was *moving money* was told a pot had failed to
  save.

## One refusal, one sentence

| Refusal | Type | What the reader is told | Where |
| --- | --- | --- | --- |
| No target chosen | *(guarded in the component)* | `errors.select_target_pot` | under the **Move to** select |
| Source pot gone | `PotNotFoundException` | `errors.pot_missing` | toast, sheet closes |
| Amount unreadable or ≤ 0 | `InvalidPotAmountException` | `errors.amount_invalid` | under the amount box |
| Source and target are one pot | `SelfTransferException` | `errors.move_same_pot` | under the **Move to** select |
| Target pot gone | `TargetPotNotFoundException` | `errors.move_target_missing` | under the **Move to** select |
| The two pots sit on different accounts | `CrossAccountTransferException` | `errors.move_cross_account`, naming the target's account | under the **Move to** select |
| Amount exceeds the source pot | `InsufficientUnallocatedException` | `errors.amount_exceeds_pot_balance`, naming the pot and the figure | under the amount box |

`TargetPotNotFoundException` narrows `PotNotFoundException` and is caught first —
the subclass would otherwise be swallowed by its parent's arm and print the
message for the wrong pot, which is the same mistake one level down. The source
is the card the reader opened and the target is the one they picked, and only
one of the two can be corrected from the sheet.

`fund()` and `withdraw()` carried the same swallowing: their only non-amount
refusal is `PotNotFoundException`, and it read as "check the fields" too. Both
now close the sheet and say the pot is gone, because a sheet whose subject no
longer exists is a sheet about nothing.

## The target's own error slot

The target refusals are held in `PotsPage::$errorTarget`, not in `$errorAmount`,
and not only because they belong under a different control. `updated()`
re-tests a standing amount refusal against every keystroke and clears it once a
readable figure is typed — so a "pick another pot" message parked in
`$errorAmount` vanished the moment the reader touched the amount box, without
the reason for it having changed at all.

The **Move to** select is bound `wire:model.live` for the mirror reason. Bound
deferred, a new pick reaches only the client-side proxy, so `updated()` never
runs and the refusal stands over a pot the reader has already changed — the same
shape as the amount refusal that used to outlive its own correction.

## Why "no such pot" and "not yours" stay one message

`PotNotFoundException` covers both, deliberately, and the page keeps them
indistinguishable. `findOwnedActivePot()` filters on `user_id`, so another
user's pot id and an id nobody holds take the same branch and produce the same
sentence. Told apart, the refusal would answer *"does this id exist for someone
else?"* for any number a caller cared to try — an enumeration oracle over
another household member's pots, handed out one refusal at a time. The
distinction the page *does* draw is between the reader's **own** source and
target pots, which they supplied themselves and which tells them nothing new.

## The backstop that stays generic

Each money handler keeps a final `catch (\InvalidArgumentException)`. Nothing
reaches it: `fund()`, `withdraw()` and `transfer()` give every refusal they have
a type of its own, and each type is caught above. It exists so an unexpected
failure degrades to a message instead of an error page, and it says only
`errors.operation_failed` — that it did not go through and no money moved. A
more specific sentence there would be a guess, and the guess would be printed
as fact.

`createPot()` and `updatePot()` still use `errors.generic` — *"That pot could
not be saved"* — and there the wording is correct: they are the save. What
remains reachable under it is a stale account or goal id from a picker rendered
before the row went away, and re-checking those fields is genuinely the fix. The
one refusal that was **not** a stale field is the initial amount, which now
throws `InvalidPotAmountException` and lands under the amount box beside the
figure that caused it, instead of under the name.
