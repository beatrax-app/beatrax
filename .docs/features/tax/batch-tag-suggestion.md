# The batch-tag suggestion, and the state it has to outlive

Tagging one gym direct debit as deductible almost always means the other
eleven are deductible too. After a one-tap tag, if the counterparty has
two or more other untagged transactions in the same year,
`HandlesTaxTagging` raises a banner offering to tag them all.

The feature is four lines of intent and a pile of ordering constraints,
because the thing the banner needs to apply — the category and note the
user chose — is stored in picker fields that are wiped before the banner
can be clicked. Getting the order wrong does not throw; it tags eleven
transactions with the wrong category.

## The sequence

1. `tagTransaction()` writes a bare tag (all payload fields null — see
   [the write contract](tag-write-contract.md)), opens the picker, and
   computes the suggestion.
2. The user picks a category, maybe types a note, and saves.
3. `saveTaxCategory()` writes the real payload, **snapshots the category
   and note onto `$batchSuggestion`**, then calls `closePicker()`.
4. Some time later — possibly after opening the picker on an unrelated
   row — the user clicks the banner and `applyBatchTag()` runs.

Step 3 is the ordering requirement. `closePicker()` nulls
`pickerCategoryId`, `pickerNote`, `pickerYearOverride`,
`pickerBookedYear` and the rest. Move the snapshot after it and every
sibling transaction gets tagged with a null category and no note.

Reading the live picker state at step 4 instead is worse, not better,
because it is only wrong *sometimes*: if the user opened another row's
picker in between, the banner applies that row's category to eleven
transactions belonging to a different counterparty. The snapshot exists
so the banner can only ever apply the same category as the tag that
triggered it.

## `array_key_exists`, not `??`

`applyBatchTag()` reads the snapshot with `array_key_exists`:

```php
$categoryId = array_key_exists('categoryId', $this->batchSuggestion)
    ? $this->batchSuggestion['categoryId']
    : $this->pickerCategoryId;
```

A snapshotted `null` is meaningful — it means "the trigger tag was
deliberately saved with no category". `??` cannot tell that apart from
"no snapshot was taken", so it would fall through to whatever the live
picker holds, which is exactly the unrelated-row bug the snapshot was
added to prevent. The fallback branch is reachable only when no snapshot
exists at all.

## The year is keyed to the trigger row, not to today

The suggestion's `taxYear` is the *booked year of the transaction the
user just tagged*, resolved by `openPickerFor()` into `pickerBookedYear`
immediately before the count is taken:

```php
$taxYear = $this->pickerBookedYear ?? $this->resolveCurrentTaxYear($c);
```

Someone tagging 2023 history in June 2026 must be offered 2023 siblings.
The seasonal current tax year — see
[tax year resolution](tax-year-resolution.md) — would offer them 2026
siblings, count zero of them, and never show the banner at all on exactly
the bulk-tagging pass where it is most useful.

The resolved year is then stored *in* the suggestion array and re-read by
`applyBatchTag()`, so the year the banner counted and the year it writes
against can never drift apart in the gap between the two. The seasonal
year survives only as a fallback for a suggestion snapshot that predates
the `taxYear` key.

## Reconciled rows are removed before the count is reported

Reconciliation freezes a transaction, and tax classification is precisely
what a reconcile is meant to freeze. Every write path in the trait —
`tagTransaction()`, `saveTaxCategory()`, `untag()`, `applyBatchTag()` —
checks `TransactionStatusQuery::isReconciled()` first and warns without
writing.

`applyBatchTag()` cannot check one row at a time, so it calls
`reconciledIdsAmong()` once for the whole candidate list and removes
those ids before tagging anything. The count reported in the success
toast is taken *after* that filter, so "Tagged 3 more transactions" means
three rows were actually written. When the filter empties the list
entirely, the trait raises `tax::messages.batch_none_reconciled` rather
than claiming it tagged zero transactions — "nothing happened" and
"nothing needed to happen" read identically otherwise.

## Dismissal is for the life of the page

`applyBatchTag()` and `dismissBatch()` both set
`$batchSuggestionDismissed = true`, and `tagTransaction()` skips
computing a suggestion entirely while that flag is set. Nothing resets it
back to `false`, so once a user has applied or dismissed a banner they
will not see another one until the component is re-mounted by a fresh
page load. That is intentional: a banner that reappears on every
subsequent tag is nagging, and the user who dismissed it once has
answered the question.

## Why `$batchSuggestion` is a plain array

`BatchTagSuggestion` is a proper DTO, and `TaxTagQuery` returns one — but
the trait immediately unpacks it into a string-keyed array before storing
it on the component.

Livewire dehydrates public component state to JSON between requests, and
the stored value gains keys after construction (`taxYear` at compute
time, `categoryId` and `note` at save time) that the readonly DTO has no
constructor slot for. The array shape carries all three optional keys and
survives the round trip; the `@var` docblock on the property is what
keeps it honest at PHPStan level 10.

## The name in the banner is the reader's, not the importer's

The banner puts the counterparty name inside a sentence, so it has to
read in the same language as the rest of it.
`untaggedCountForCounterparty()` therefore selects `metadata` beside
`display_name`, decrypts the name, and only then hands both to
`CounterpartyDefaultName::resolve()`. For a row the resolver had to name
itself — `Unknown`, `Government`, `Bank fee` — the stored word is the
app's English, and without the second step a Dutch reader got "tag the
other 11 from **Government**" in an otherwise Dutch sentence. See
[the app's own words](../counterparties/resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name).

## Related

- [Tag write contract](tag-write-contract.md) — what each of those
  eleven `TagTransaction::execute()` calls actually writes.
- [Tax year resolution](tax-year-resolution.md) — the seasonal year this
  banner deliberately does not use.
- [`Tax` architecture](architecture.md) — the module surface as a whole.
