# Tagging a transaction: scope, payload, and uniqueness

A tax tag is one row in `tax_transaction_tags` carrying a deduction
category, a free-text note and an optional year override. It looks like
the most ordinary write in the product. It is not, and the reason it is
not is that all three of its interesting properties fail *silently*: the
wrong shape of write does not raise, it produces a tax export with a
number in it that nobody can trace back to a row.

This page covers what a tag attaches to, how the table stops duplicates,
and why the update path rewrites three columns at once instead of
patching the one you asked it to.

## What a tag is attached to

`tax_transaction_tags.transaction_split_id` decides the tag's scope:

- **NULL** — the tag covers the whole transaction.
- **NOT NULL** — the tag covers exactly one leg of a split transaction.
  See [`Ledger` architecture](../ledger/architecture.md) for how splits
  and legs relate to their parent transaction.

Both kinds of row carry the *parent* transaction's id in
`transaction_id`. That single fact is the source of the module's most
easily-reintroduced bug, and it is why `TaxTagQuery::forTransactionIds()`
adds `whereNull('tag.transaction_split_id')` rather than reading every
row for the transaction.

Drop that filter and a leg-scoped tag makes the *parent* row's badge
light up as tagged. The badge then offers an untag, and the
whole-transaction untag path scopes to `whereNull('transaction_split_id')`
too — so it matches zero rows, deletes nothing, and returns without
error. The user clicks a lit badge and nothing happens, forever, with no
entry in any log.

Callers that genuinely need per-leg state use
`forTransactionIdsWithLegs()`, which keys its result map by
`"{transactionId}:{transactionSplitId}"` for a leg tag and
`"{transactionId}:whole"` for a whole-transaction tag. Both methods issue
exactly one `whereIn` for the whole batch and treat absence from the map
as "untagged".

### Supersession

Once a transaction has *any* leg-scoped tag, its whole-transaction tag
row stops being surfaced by `TaxYearQuery::forUser()` — the query keeps
every row with a non-null `transaction_split_id` and, for the null ones,
only those whose transaction has no leg-tagged sibling.

The row is **not deleted**. It is a pure read-time exclusion, so
un-splitting a transaction brings the original tag back rather than
losing it. Without the exclusion, a tag applied before the transaction
was split would be exported alongside the legs that replaced it, and the
year total would count the same money twice.

## Uniqueness is two indexes, not one

The table started with `unique(user_id, transaction_id)`. Adding leg
scoping widened that to
`unique(user_id, transaction_id, transaction_split_id)` so a whole-tx tag
and a leg tag on the same transaction could coexist.

That widening quietly removed the guarantee it looked like it kept.
SQLite (and PostgreSQL) treat every `NULL` as distinct for uniqueness
purposes, so `unique(user_id, transaction_id, transaction_split_id)` does
**not** reject two whole-transaction rows for the same transaction — both
have `transaction_split_id = NULL`, and `NULL` never equals `NULL`. A
double-clicked "Tag" button could therefore insert two whole-tx rows, and
`TaxYearQuery` would count the deduction twice.

The fix is a second, partial index:

```sql
CREATE UNIQUE INDEX tax_tags_whole_tx_unique
  ON tax_transaction_tags (user_id, transaction_id)
  WHERE transaction_split_id IS NULL
```

It is also what makes `TagTransaction`'s race guard mean anything. The
action does a select-then-insert, and catches
`UniqueConstraintViolationException` to retry as a guarded update when it
loses the race to a concurrent request. Without a constraint that can
actually be violated on the whole-tx path, that `catch` block is
unreachable and the losing request silently inserts a duplicate instead.

## The payload is written whole, never patched

`TagTransaction::updateExisting()` always writes `updated_at`. It writes
the three payload columns — `deduction_category_id`, `note`,
`tax_year_override` — only when at least one of them arrived non-null,
and then it writes **all three together**.

Two consequences follow, and callers depend on both:

**A bare re-tag is a no-op on the payload.** The one-tap "Tag" button on
an already-tagged row calls `execute()` with all three payload arguments
null. Nothing in the existing tag is touched. This is what makes the
button idempotent and safe to press twice.

**Any non-null field rewrites the other two.** A caller that means to
change only the category, and passes `null` for the note because it never
read one, clears a note the user typed. Nothing reports this — the update
succeeds, the export just comes out without the note.

`RuleApplier::writeTaxTag()` in the `Categorization` module is the
internal caller that hits this. It reads `note` back out of the existing
row and forwards it verbatim alongside the category it actually wants to
change, purely to avoid wiping it. Any new caller writing a partial
change has to do the same.

`created_at` is never rewritten on update; it is the "first tagged" audit
signal and re-tagging must not move it.

## Ownership checks are 404, never 403

`TagTransaction::execute()` verifies, before any write:

- the transaction exists **and** belongs to the acting user;
- the `transaction_split_id`, when given, belongs to both that
  transaction and that user — a forged id could otherwise attach a tag to
  someone else's leg, or to a leg of a different transaction;
- the `deduction_category_id`, when given, belongs to the acting user.

Every miss throws `NotFoundHttpException`, never a 403 and never a silent
fallback to "uncategorised". A 403 would confirm that the id exists and
belongs to somebody; a fallback would produce a tag the user did not ask
for. The same 404-not-403 posture covers every category mutation in
`TaxCategoryWriter`.

`tax_year_override`, when given, must land within ±10 years of
`Clock::now()->year` or the call throws `InvalidArgumentException`. Time
arrives through the injected `Clock` so the window is exercisable with
the standard clock fake rather than by waiting a decade.

## Provenance and side effects

`field_provenance` has rows only for `transactions`, so a leg-scoped tag
stamps the same whole-transaction `tax_tag` key that a whole-transaction
tag does. `$provenanceSource` defaults to `'manual'`; the rule engine
passes `'rule'` so a rule-applied tag stays distinguishable from one the
user set by hand.

Both `TagTransaction` and `UntagTransaction` dispatch an `EntityMutated`
op for the sync log — reading the composite row's primary key back after
the write, since the row's identity is
`(user_id, transaction_id, transaction_split_id)` and the pk cannot be
guessed from the arguments. `UntagTransaction` reads the id *before* the
delete for the same reason: after the row is gone there is nothing left
to name it by. Both also re-index the transaction through the nullable
`SearchIndexWriterContract`, so the note text enters and leaves search
results with the tag.

## Related

- [Tax year resolution](tax-year-resolution.md) — which year a tagged
  transaction counts toward, and how leg amounts are summed.
- [The batch-tag suggestion](batch-tag-suggestion.md) — the "tag N more"
  banner that fans a single tag out over a counterparty's year.
- [`Tax` architecture](architecture.md) — the module surface as a whole.
- [`Ledger` architecture](../ledger/architecture.md) — transactions,
  splits and legs.
