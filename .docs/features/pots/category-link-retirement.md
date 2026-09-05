# The category-link retirement, and how a reader is told about it

A pot could once be linked to a budget category. Envelope budgeting replaced
that, and the link is retired
([ADR-0017](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0017-envelope-budgeting-replaces-category-pots.md)).
Retiring it moves the reader's money without the reader asking, which is the
one thing this application cannot do quietly. This page is what the code does
and where the reader hears about it.

## What the cutover does

`Modules/Budgets/Database/Migrations/2026_07_05_000010_run_envelope_budgeting_cutover.php`
runs `EnvelopeActivationService::activate()`. Both shells reach it the same
way — the desktop's `FirstLaunchBootstrap` and the phone's
`MobileFirstLaunchBootstrap` each run pending migrations at boot, and the
migration is dated past the schema-dump cutoff, so it is pending on both.

For every user whose `users.envelope_activated_at` is still null:

1. The stamp is claimed in one conditional `UPDATE`, so a concurrent or
   repeated call cannot archive the same pots twice.
2. The stamp is announced to paired devices — it is the carryover fold's
   genesis anchor, and a peer that reads it as null reads every synced
   assignment as zero.
3. Every pot the user owns with `status = 'active'` **and a non-null
   `category_id`** goes through `PotWriter::archive()`.

A walk that throws part-way un-claims the stamp, so the next run repeats it
rather than leaving a user half-converted.

## What archiving is, and what it is not

`PotWriter::archive()` writes one `pot_movements` row of kind
`released_on_archive` for the negative of the pot's balance, then sets
`pots.status = 'archived'`. That is the whole of it.

- **Nothing is deleted.** The pot row, its name, its `category_id`, and every
  movement it ever held stay in the database, including the release itself.
- **The account balance does not change.** A pot is an allocation over one
  account, not a container of its own: releasing moves money from *allocated*
  to *unallocated* on the same account, and `real = allocated + unallocated`
  holds throughout.
- **An already-empty pot writes no movement at all** — a zero-amount row is
  refused, so a pot that held nothing is archived and nothing else.
- **Goal-linked and unlinked pots are untouched.** The walk filters on
  `category_id`, and `PotWriter::assertXorLink()` means a pot can never hold
  both a goal and a category, so a goal-linked pot is never in the population.
- **Archived pots stay visible**, in the collapsed "Archived pots" disclosure
  on the pots page, at a balance of zero.

The retirement is one-way: `down()` is deliberately empty, because rolling back
would resurrect category-linked pots and un-stamp the anchor for users who have
since assigned real envelope months against it.

## What the reader is told

Two channels, because neither reaches everybody on its own.

**The release body.** It is generated from the commit history the tag spans and
no hand-maintained file may be its source
([OPS-R11](https://github.com/beatrax-app/spec/blob/main/70-operations/README.md)),
so the note lives in a commit's `BREAKING CHANGE:` footer. `cliff.toml` sorts
that group first and renders the footer's full prose beneath the entry, rather
than the one subject line every other entry gets — which is what
[OPS-R12](https://github.com/beatrax-app/spec/blob/main/70-operations/README.md)
asks for. See [cutting a release](../../runbooks/release-cut.md#a-breaking-change).

**The banner.** A release body reaches a reader who goes looking for it. The
desktop updater renders no notes of its own — it links out to the release page,
and a stable release is a draft until a human publishes it — and a phone takes
its update from an app store. So the app says it too, once, on the screen where
the money is.

`Modules\Pots\Public\Actions\RecordCategoryPotRetirementAlert` raises a
`system_alerts` row of kind `pots.category_link_retired` at the end of the
activation walk. It reads the movements the walk has just written rather than
counting as it goes, so the figures come from the rows themselves.

- **One row per currency.** Two currencies have no sum, and a single figure
  over both would be a wrong number rather than a missing one.
- **A derived id**, keyed on the kind, the user and the currency. Every device
  that upgrades runs the cutover for itself over the same rows, so both land on
  one row, and the dismissal reaches a primary key the peer already holds.
- **Nothing is raised when nothing moved.** A fresh install, and a reader whose
  category-linked pots were all empty, see no banner: there is no released money
  to point at.
- **The sentence is a copy spec, not a sentence.** `CopyLine` plus
  `CopyParam::money()` in `metadata` means the count declines and the amount
  formats in the *reader's* language, not in whichever was active when the
  migration ran.

The row's action is a link to Budgets rather than an acknowledgement, and
Dismiss sits beside it: reading the row is not doing the work it asks for.

## Where the code is

| Concern | File |
|---|---|
| The cutover migration | `Modules/Budgets/Database/Migrations/2026_07_05_000010_run_envelope_budgeting_cutover.php` |
| The walk | `Modules/Budgets/Public/Services/EnvelopeActivationService.php` |
| The same walk for a fresh install | `Modules/Budgets/Internal/Listeners/ActivateEnvelopeBudgetingOnInstall.php` |
| Archive, and the release movement | `Modules/Pots/Public/Services/PotWriter.php` |
| The banner row | `Modules/Pots/Public/Actions/RecordCategoryPotRetirementAlert.php` |
| The kind | `Modules/Core/Public/Enums/PotAlertKind.php` |
| The copy | `Modules/Core/Resources/lang/*/alerts.php` |
| The release-note rendering | `cliff.toml` |

## Related

- [`architecture.md`](architecture.md) — the movement model the release rides on
- [Cutting a release](../../runbooks/release-cut.md) — where the note is won
- [Reader-language copy](../notifications/reader-language-copy.md) — the
  `CopyLine` seam the banner stores its sentence through
