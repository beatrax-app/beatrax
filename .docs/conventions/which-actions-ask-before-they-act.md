# Which actions ask before they act

A confirmation is a tax on the ordinary case, paid every time, to buy back the
rare one. It is worth paying only where the rare case cannot be undone. So the
order of preference here is:

1. **Make it reversible.** An undo costs the reader nothing when they meant it.
   `EnvelopeWriter::undoMove()` and the goals archive toast are the shape.
2. **Ask, if it cannot be made reversible.** Say what will happen and what is
   lost — never "Are you sure?".
3. **Ask for proof of identity**, where the action is an account-level one.
   `DeleteAccountAction` takes the account password.

An action that is already reversible gets none of the three. Adding a modal to
something the reader can simply undo makes the product feel dangerous where it
is not.

## The three shapes a question takes here

| Shape | When | Cost |
|---|---|---|
| `x-core::confirm-strip` | A row action, or a form whose destructive branch is one field among many. A `confirm*` method sets an id or a flag, the view swaps in the strip. | 3 keys |
| `wire:confirm` | A single high-stakes button that is not part of a row. The native dialog is unmissable, which is what a once-a-year action wants. | 1 key |
| A typed phrase or password | Account-level. `Core/EncryptedBackupRestore` types a phrase; `Auth/DeleteAccountSection` takes the password. | — |

Use one of the three. A fourth spelling is how ten inline "are you sure?" strips
came to disagree about everything, which is the story
`x-core::confirm-strip` carries in its own header.

## What the guard decides, and what it cannot

Whether a write can be taken back is a reading, not a scan, so
`tests/Contracts/AnIrreversibleActionAsksFirstArchTest.php` keeps a pinned list
of the actions judged irreversible and checks each still carries its question.
Whether a confirmation is one of the three shapes needs no judgement at all, and
two rules beside that list decide it for every Blade under `Modules/` and
`resources/`:

- A browser `confirm()` fails outright — `window.confirm` anywhere in a
  template, or a bare `confirm(` inside an Alpine handler. It is not a fourth
  shape, it is no shape.
- A `$wire.<method>()` reached from an Alpine handler fails where the method is
  one the pinned list names, or where its name opens with `truncate`, `delete`,
  `destroy`, `wipe`, `purge`, `erase`, `discard`, `revoke`, `unpair` or
  `forget`. `wire:confirm` and the strip both hang off a `wire:click`; neither
  can reach a call Alpine makes itself, so a destructive action spelled that way
  is ungated by construction whatever else the element carries. Move it to
  `wire:click`.

Both rules assert their denominator before they may report clean, so a scan that
silently read nothing cannot pass for a clean one.

Neither rule reads the words, and the words are where the second line of this
convention lives. `ledger::detail.delete.confirm_prompt` was *Are you sure?* in
all 26 locales under markup both rules pass — a `wire:click` behind an Alpine
flag, cancel first, confirm last.
`Modules/Ledger/tests/Feature/TheDeleteQuestionNamesWhatItTakesTest.php` decides
that one question the only way a machine can: the sentence has to share a word
stem with its own heading, and at least two with the inventory of what travels
with the row. That is a reading of one screen's vocabulary rather than a shape,
so it stays beside the screen instead of joining the two rules above.

## The icon-only actions, and what each one is

Every `x-core::emoji-action` in the product, and the judgment behind it.

| Action | What it does | Verdict |
|---|---|---|
| `confirmDelete` (cash book) | Deletes an entry | Asks — confirm strip |
| `confirmArchive` (goals, pots) | Archives, then offers undo | Asks, **and** reversible |
| `markComplete` (goals) | Sets a goal to completed | **Reversible** — the toast now carries `restore`, the same inverse archive uses |
| `dismissPeer` (sync) | Deletes a `sync_sessions` row | **Bare.** The next successful sync with that peer writes the row again (`SyncSession` upserts it), so this clears a diagnostic line, not a device. `removeDevice` is the one that unpairs, and that has a modal. |
| `dismiss` (savings insight) | Hides one insight | **Bare.** Nothing is destroyed; the insight is derived and returns when its condition does. |
| `dismiss` (install hint), `dismissReauthToast`, `clearFlash` | Closes a banner or toast | **Bare.** Nothing is written. |
| `removeLeg` (split editor) | Drops a leg from the unsaved form | **Bare.** In-memory only, undone by not saving. It already asks in the one case that is not just an edit — collapsing a split back to a single leg, via `confirmRemoveToOne`. |
| `removeCondition`, `removeAction` (rule builder) | Drops a row from the unsaved form | **Bare.** In-memory array edits on a modal the reader can close. |
| `dismiss` (notification row) | Sets `dismissed_at` on one inbox row | **Bare.** The toast carries the Undo, and `undoDismiss` clears the column outright — reversible, so it gets none of the three. The Dismissed tab is a second way back. |
| `undoDismiss` (notification row) | Clears `dismissed_at` | **Bare.** It *is* the inverse. |
| `markRead` (notification row) | Sets `read_at` | **Bare.** One-way — there is no mark-as-unread, and `state` carries no unread axis — but nothing is destroyed: the row keeps its place in All and its deep link still works. What is lost is a bold weight and a place in the unread count, which is not worth a question on every row. |
| `openEdit`, `startRename`, `$set`, `$dispatch`, `close` | Opens or navigates | **Bare.** Not mutations. |

The pattern in the four bare rows worth naming: **an in-form removal is undone
by not saving, and a derived row is undone by the thing that derives it.**
Neither needs a question, and giving them one would teach the reader to click
through the questions that matter.

## What was gated

| Action | Why it cannot be taken back |
|---|---|
| `RulesPage::triggerReapply` | Rewrites every category, counterparty, note and tax tag a rule put there, across the reader's whole history. Manual values and reconciled rows are skipped; everything else is overwritten with no record of the prior value. |
| `SettingsPage::save`, when the period start day moved | `EnvelopePeriodRekeyer::rekeyToCurrentPeriods()` deletes every `envelope_assignments` row and re-inserts it under new period keys, **summing** the amounts wherever two old periods fold onto one new one. Setting the day back re-runs the same merge on the summed rows rather than splitting them. It was a plain form save with nothing said. |
| `RecoveryCodesSection::regenerate` | Retires the only offline way back into the account. The codes are stored hashed and cannot be shown again, so a printed copy dies with them. The partner-facing equivalent, `ManageUserPage::regenerateCodes`, already asks the reader to type a username; this one asked nothing. |
| `HandlesTaxTagging::applyBatchTag` | Writes a tag onto every remaining untagged transaction for a counterparty in a tax year. The only inverse is `untag`, one transaction at a time. |
| `PreviewMigration::discard` | `DiscardMigrationRun` truncates seven staging tables. Recovery means uploading and re-parsing the whole export. It sat beside the confirm button with the same visual weight. |
| `AuditLogPage::truncateAll` | Deletes every `dev_mode_audit` row the developer owns. The log is write-only and nothing re-derives it: what was run, with which arguments, and what it printed is gone. It always asked — but with a hand-rolled `window.confirm` in an `x-on:click`, which is the fourth spelling the rules above now refuse. `wire:confirm` is the shape, since it is one button on a page rather than a row. |
| `TransactionDetail::unreconcile` | Nothing on the row is lost — it writes `status` and no other column. But there is no single-row inverse: `completeReconcile()` re-locks a whole account up to a statement date, and only when the balance matches. It takes the strip rather than `wire:confirm` for exactly that reason: it is the lightest of the three, and the question names the effect and the way back rather than a loss. |

The period-day move takes the strip rather than `wire:confirm` because it is one
field inside a form: the button is a plain submit and cannot carry a question
that only sometimes applies.

## A promise of an undo is not an undo

The undo button is drawn from the dispatched payload — the toast host renders it
behind `x-show="t.undoAction"` — so only `toastWithUndo()` puts one on screen.

`forecasting::scenario.toast.mutation_removed` read *"Mutation removed. Undo"*,
and the component called plain `toast()`. Every one of the 26 locales carried
the same trailing verb — `Ongedaan maken`, `Rückgängig`, `Скасувати` — and none
of them was a control. The word has been dropped from all 26 rather than an
inverse invented for a what-if row that is cheap to retype.
`tests/Contracts/AToastNamesNoUndoItCannotOfferArchTest.php` reads every plain
`toast()` in the product against its English line, so the next one cannot be
written.

## The strip on a phone

The strip is a flex row, and its two answers used to be shrinkable items in it.
On a coarse pointer the 44px floor's `min-width: 44px` **replaces** a button's
`min-width: auto`, so both answers squeezed to exactly 44px and broke their
label one word per line. Measured against the built stylesheet at 375px and
411px, in all 26 locales: 156 clipped labels, English's own *Keep it locked*
among them, three lines tall in a 44px box.

`flex-wrap` on the row and `shrink-0` on the two buttons fix it the way the
pots action row was fixed — the question keeps the first line and the answers
take a second when they do not fit beside it. Re-measured: nothing wraps,
nothing clips, nothing overflows, and every answer clears 44px at both widths.
`Modules/Core/tests/Feature/AConfirmStripKeepsItsAnswersReadableOnAPhoneTest.php`
holds all three, whichever tag the strip stands in for.

## Related

- [An icon-only action says its verb on touch](an-icon-only-action-says-its-verb-on-touch.md)
  — the verb these actions reveal on a hold, and why `title` is not a label
  on a phone
- [Conventions](00-index.md) — the comment policy these files are shaped by
