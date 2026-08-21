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
`counterparties` rows for this user with `type = 'unknown'`, ordered by
`updated_at` descending then `id` descending, capped at
`SCAN_LIMIT = 200`.

The cap is a cap, not a page. A user with more than 200 unknowns only
ever sees the 200 most recently updated ones — there is no second page,
and labelling those brings the next batch into range on the following
visit.

`$queueFirstId` carries the `?queue_first={id}` parameter from an
unknown card on the index page. That row is lifted to the front of the
list and everything else keeps its order, so clicking a specific
unknown lands on that card rather than on whatever was at the top.
An id that is not in the queue is ignored and the list is returned
unchanged.

`unknownCountForUser()` is the uncapped count, used for the progress
line.

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

## What the user does with it

The card is keyboard-first —
[the full key table is in the architecture page](architecture.md).

- **Y — accept.** `CounterpartyTriage::acceptSuggestion()` flips the
  row to `type = 'merchant'` and writes the suggested name to both
  `display_name` and `merchant_name`, encrypting both through the codec
  exactly as the create path does. The row keeps its **existing slug**:
  the resolver's collision-suffixing rule is deliberately bypassed here
  because only the type and the name change, and reusing the slug
  preserves the `(user_id, slug)` UNIQUE without a walk. Writing
  `merchant_name` is what anchors the row against
  `merchant_aliases.friendly_name` for
  [garbage collection](garbage-collection.md).
- **N — reject.** Sets `showSuggestion = false` for this card only,
  which hides the banner and leaves the manual-label section. Nothing
  is written; the suggestion returns on a later visit.
- **S — skip.** Advances the cursor without writing.
- Manual labelling accepts `merchant`, `personal`, `bank`, or
  `government` and routes the name through the codec the same way.
  `merchant_name` is set only for the `merchant` type.
- "Mark as ignored" sets `metadata.ignored = true` and leaves the type
  at `unknown`.

Accept, ignore, and manual-label all append the row's id to
`sessionDoneIds` rather than removing it from the queue, so the list
does not re-shuffle underneath the cursor mid-session. The progress
line reads `{seen} of {total} · {percent} % · ~{minutes} min
remaining`.

## Related

- [Resolution chain](resolution-chain.md) — how a row becomes
  `unknown` in the first place.
- [Module architecture](architecture.md) — the surface map and the
  triage keyboard shortcuts.
- [Garbage collection](garbage-collection.md) — why an accepted
  suggestion's `merchant_name` matters to retention.
