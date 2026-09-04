# Triage suggestions and the confidence ladder

Step 7 of the [resolution chain](resolution-chain.md) is a catch-all:
anything the six matchers could not place becomes a `type = 'unknown'`
counterparty. Those rows are real — they carry transactions, they show
up in totals — they just have no identity yet. `/counterparties/triage`
is where the user gives them one, one card at a time.

The obvious way to help is to re-run the resolver on the unknown row.
That cannot work: the row is unknown *because* the resolver already
failed on it, so a second pass returns the same answer. What has
changed since is the row's history. By the time the user reaches
triage, the unknown counterparty has usually accumulated several
transactions, each with its own description string — and the merchant
resolver may well succeed on some of those descriptions even though it
failed on the one that created the row.

`Modules\Counterparties\Public\Queries\CounterpartyTriageQueue`
therefore asks the merchant resolver about each description separately
and treats **agreement across descriptions** as the confidence signal.
Ten of ten descriptions naming the same merchant is a different claim
from one of ten, and the UI says so in different words.

## Building the queue

`forUser(User $user, ?int $queueFirstId = null)` reads
`counterparties` rows for this user with `type = 'unknown'`, minus the
ones the reader marked ignored, ordered by `updated_at` descending then
`id` descending, capped at `SCAN_LIMIT = 200`. The ignore exclusion is
`LabelCounterparty::excludeIgnored()` — a SQL predicate on
`metadata->ignored`, applied before the cap so a dismissed row does not
spend one of the 200 slots.

The cap is a cap, not a page. A user with more than 200 unknowns only
ever sees the 200 most recently updated ones — there is no second page,
and labelling those brings the next batch into range on the following
visit.

`$queueFirstId` carries the `?queue_first={id}` parameter from an
unknown card on the index page and from the unknown profile's "Label
this counterparty" CTA. That row is lifted to the front of the list and
everything else keeps its order, so clicking a specific unknown lands
on that card rather than on whatever was at the top. An id that is not
in the queue is ignored and the list is returned unchanged.

It also **overrides the ignore exclusion**. Both links stay on screen
for a row the reader dismissed, and a reader who clicks one is asking
for that row by name — which outranks their standing "stop offering me
this one". Without the override those two links land on somebody else's
card with nothing to say why. `unknownCountForUser()` has no such
parameter and always excludes.

`unknownCountForUser()` is the uncapped count, used for the progress
line and the sidebar badge. It carries the same ignore exclusion: a
badge counting rows the reader has already dismissed sends them to a
screen with nothing on it.

## Building one suggestion

`suggestionFor(Counterparty $unknown)` reads the **20 most recent**
transactions linked to that counterparty — `where('user_id')` and
`where('counterparty_id')`, ordered `posted_at` then `id` descending,
selecting only the `description` column.

Each description is decrypted through
`Modules\Sync\Public\Services\SensitiveColumnCodec::decryptValue()`
before anything looks at it. Substring and corpus matching against AEAD
ciphertext always misses, so skipping this step would silently produce
zero suggestions for every encrypted user.

Then, per description:

- An empty description is skipped entirely and does **not** count
  towards the denominator.
- A description that the codec **blanks** — ciphertext no epoch in this
  device's keyring opens — is skipped for the same reason, and the test
  for it runs *after* the decrypt, not before. It cannot resolve to
  anything, so counting it would present a unanimous suggestion as a
  weak one: ten readable rows all saying "Albert Heijn" plus ten
  unreadable ones scored 50 %, i.e. `low`.
- Everything else increments `$total` and goes through
  `MerchantNameResolver::resolve()`.
- A resolved name increments that name's entry in the tally. A miss
  counts towards `$total` but towards no name — a description that
  resolves to nothing is evidence against confidence, not neutral.

`arsort` puts the winner first. The share is integer percent:

```
sharePercent = round((topHits / total) * 100)
```

`null` comes back when `$total` is zero (no non-empty descriptions) or
when the tally is empty (nothing resolved at all). The triage page then
renders no banner and no accept/reject buttons — only the manual-label
section.

## The ladder

Two thresholds on the resolver — `CONFIDENCE_HIGH = 80` and
`CONFIDENCE_MEDIUM = 60` — cut the share into three bands.

| Share of examined descriptions | `TriageSuggestion::$confidence` | Banner copy |
|---|---|---|
| ≥ 80 % | `high` | ✨ Looks like **{name}** — confidence high |
| 60 – 79 % | `medium` | ✨ Maybe **{name}** — confidence medium |
| 1 – 59 % | `low` | Pattern match: **{name}** — confidence low. Verify before linking. |
| no resolution at all | *no suggestion* | no banner |

The copy lives in `counterparties::triage.suggestion_high` /
`suggestion_medium` / `suggestion_low`, and the view picks the key by
matching on `$confidence`. The `**…**` markers are converted to
`<strong>` after the copy is HTML-escaped, so a merchant name can never
inject markup.

The band also picks the banner's CSS class — `suggestion`,
`suggestion medium`, `suggestion low` — so the visual weight drops as
the claim weakens. Only the `low` band's copy tells the user to check
before accepting; that sentence is part of the band, not decoration.

The band's own two answers — accept and reject — are the card's primary
pair while it is on screen, so `Save label` steps down to the outline
button and the accept keeps the only solid fill. With no banner, `Save
label` is the only way to record a decision and takes that fill itself.
One solid button per card, whichever arm the reader is on.

## The reasoning line

`TriageSuggestion` carries three fields and the banner is never
rendered without all of them: `suggestedCounterpartyName`,
`confidence`, and `reasoning`.

`reasoning` is the sub-line under the banner and it shows the raw
numbers the band was derived from — `counterparties::triage.reasoning`,
`":hits of :total recent transactions on this IBAN resolve to :name."`
It exists so "confidence medium" is never an unexplained verdict: the
user can see that it means 7 of 11, and decide accordingly.

It is built with `Lang::get()` rather than `sprintf`. The banner above
it is localised; an English format string here put two languages in one
card.

## The recent-transactions list, and why its amounts are signed

Under the suggestion the card shows the last five transactions on this
IBAN. Their amounts are rendered **signed**, and that is deliberate
rather than incidental: this screen exists to make the reader answer
*what is this?* about an IBAN they do not recognise, and direction is
one of the strongest signals available for it. Money arriving monthly
and money leaving monthly point at completely different answers — an
employer versus a landlord, a refund versus a charge.

An unconditional `abs()` here made a €52,60 charge and a €52,60 refund
render identically, and put the screen at odds with `/transactions`,
which shows the same row signed. A reader cross-checking one against
the other got two different numbers for one transaction with no way to
tell which was authoritative.

The currency comes off the row too, not a hardcoded `EUR`.
`Money::format()` already places the symbol and the sign per locale —
Dutch puts the symbol first, `€ -23,45` — so nothing here needs its own
formatting, only to stop discarding what the row already carries.

`abs()` elsewhere in this module is **correct and must not be swept**:
`CounterpartyIndexRow.php:41-42` and
`counterparty-profile.blade.php:57` wrap 12-month totals and per-month
averages, where "total spent with this counterparty" as a magnitude is
the intended presentation, and `counterparty-index.blade.php:198` sizes
a chart bar, which cannot be negative. The rule is the distinction: an
aggregate may be shown as a magnitude, a single transaction may not.

## What the user does with it

The card is keyboard-first —
[the full key table is in the architecture page](architecture.md).

Every write below goes through
`Modules\Counterparties\Internal\Actions\LabelCounterparty`, which is
what makes triage a well-behaved second writer of `counterparties`
rather than a silent one — see [the seam](#the-write-seam) below.

- **Y — accept.** `CounterpartyTriage::acceptSuggestion()` flips the
  row to `type = 'merchant'` and writes the suggested name to both
  `display_name` and `merchant_name`, encrypting both through the codec
  exactly as the create path does.
- **N — reject.** Sets `showSuggestion = false` for this card only,
  which hides the banner and leaves the manual-label section. Nothing
  is written; the suggestion returns on a later visit.
- **S — skip.** Advances the cursor without writing.
- Manual labelling accepts `merchant`, `personal`, `bank`, or
  `government` and routes the name through the codec the same way.
  `merchant_name` is set only for the `merchant` type. A type outside
  those four writes nothing at all; a blank name writes nothing and
  says so, on `draftName`, rather than leaving the one button that
  records a decision inert with nothing on screen to explain it.
- "Stop asking about this one" sets `metadata.ignored = true` and
  leaves the type at `unknown`. The row stays on file with its history
  intact; it is the queue that stops offering it, on this visit and
  every later one.

Accept, ignore, and manual-label all append the row's id to
`sessionDoneIds` rather than removing it from the queue, so the list
does not re-shuffle underneath the cursor mid-session. The progress
line reads `{seen} of {total} · {percent} % · ~{minutes} min
remaining`.

### A decision does not move the cursor

`sessionDoneIds` filters the decided row out of `remaining` on the next
render, so the row behind it slides into the index the cursor already
holds. Every write path also incremented the cursor on top of that, and
the two together stepped past a row: labelling the first of three
unknowns offered the third, and the second was never put in front of the
reader at all. Only `nextItem` / `previousItem` / `skipForNow` move the
cursor now;
`Modules/Counterparties/tests/Feature/LabellingOneUnknownOffersTheNextOneNotTheOneAfterItTest.php`
walks a queue of three and names every card it was shown.

### What the reader typed is theirs

The display name and the type are `wire:model` bindings on `$draftName`
and `$draftType`, and `$drafts` keeps one pair per counterparty id. They
were an Alpine `x-model` before, so a Livewire re-render dropped them:
typing *Geldlener BV* and pressing the next control lost it with no
warning, and going back did not return it. Moving the cursor stashes the
draft against the row being left and loads the row being arrived at;
deciding a row drops its draft, because it is decided.

`counterparties::triage.draft_kept` is the line under the save button
that says so. It is copy rather than a confirmation because the input
survives — [a reversible action gets none of the
three](../../conventions/which-actions-ask-before-they-act.md#the-three-shapes-a-question-takes-here).

### Why ignoring is not drawn in red

Nothing is destroyed and nothing is one-way. The row keeps its type, its
IBAN and its transactions, and it stays on `/counterparties` as an
unknown card whose "Label this counterparty" link carries
`?queue_first={id}` — which **overrides the queue's own ignore filter**,
as the [queue section](#building-the-queue) above describes. So the way
back is one tap, and the button that took it was the only rose control
on the screen. It is an outline button beside the skip now, and
`counterparties::triage.not_now_note` says where the row went instead of
a colour implying it was destroyed.

## The write seam

Three things every triage write owes the rest of the app, none of which
belong in a Livewire component:

**The slug follows the display name.** Accept and manual-label both
rename the row, so both re-derive the slug through
`CounterpartySlugResolver::resolveUnique($userId, $name, $rowId)`. A row
slugged `bol` renamed to "Albert Heijn" and left at `bol` is a row the
next import cannot find: the resolver slugifies "Albert Heijn", finds
`albert-heijn` free, and `firstOrCreate`s a **second** counterparty for
the same entity — exactly the fragmentation
[`slugIsFreeFor()`](resolution-chain.md#slug-allocation-and-the-decrypt-before-compare-rule)
exists to prevent. The third argument is the row's own id, because a
rename knows which row it is moving and two rows may carry the same
display name. Collisions still walk the numeric suffix, so renaming
onto a name another row already holds lands `albert-heijn-2` rather
than colliding on the `(user_id, slug)` UNIQUE.

**The write is announced.** Each one dispatches
`Modules\Sync\Public\Events\EntityMutated` with `mutationType: 'edit'`
and **plaintext** field values, the same shape and for the same reason
as the resolver's own announcement: `OpLogWriter` seals the sensitive
columns again under the current key epoch, so handing it stored
ciphertext would encrypt it twice and the peer would never read it
back. Without this a reader who triaged forty unknowns on the desktop
found forty unchanged rows on their phone.

**The ignore predicate has one spelling.** `metadata.ignored` is written
here and read by `CounterpartyTriageQueue`, both through
`CounterpartyMetadataKey::Ignored`. `metadata` is not a
`SensitiveFieldRegistry` column, so the read is a SQL predicate rather
than a post-hydrate filter.

## Related

- [Resolution chain](resolution-chain.md) — how a row becomes
  `unknown` in the first place.
- [Module architecture](architecture.md) — the surface map and the
  triage keyboard shortcuts.
- [Retention](retention.md) — why a row triage labels is kept for good.
