# An ordering that picks, and one that walks

`transactions.id` and `counterparties.id` are per-device autoincrements.
`recurring_series_occurrences.id` is worse: `OccurrenceWriter` mints it with
`DeviceMintedRowId::mint()` precisely *because* no two devices could agree on
one, so it is random per device rather than merely counted per device.

An `ORDER BY` that ends on one of those ranks a tie one way here and the other
way there. Whether that matters is decided by what the query does next.

## The distinction

An ordering that **walks** — `chunk`, `chunkById`, `cursor`, `each`, or a bare
`get()` with no cap — visits every row whatever the sequence. The ordering only
paginates. `chunkById()` *requires* ordering by id and must never be changed.

An ordering that **picks** — bounded by `limit()`, `take()`, `first()`,
`value()`, `sole()`, a paginator, or ranked by a `ROW_NUMBER() OVER (…)` window
read at rank 1 — makes the tie decide **which rows the reader is shown at all**,
not merely their sequence. Two devices then show different rows for the same
question.

The rule, enforced by
[`AnOrderingThatPicksNeverEndsOnAPerDeviceIdArchTest`](../../tests/Contracts/AnOrderingThatPicksNeverEndsOnAPerDeviceIdArchTest.php):

> An ordering that picks may not end on a bare `id` against `transactions`,
> `counterparties` or `recurring_series_occurrences`. An ordering that walks
> every row may.

## What to end on instead

`Modules\Ledger\Public\Support\NewestTransactionFirst::ACROSS_ACCOUNTS`, joined
through `::ACCOUNT`. It is the device-stable half of
`transactions_fingerprint_uq` — that UNIQUE minus `user_id` and `account_id`,
the two columns a device counts for itself — plus the account named by its
IBAN, which is what the account *is* rather than what this device numbered it.
The measurement behind each term is in
[which charge is the newest](../features/counterparties/architecture.md#which-charge-is-the-newest).

It lives in `Ledger\Public\` because `Ledger` owns the `transactions` table and
five modules read it. One clause they share is the only way two devices agree;
a copy per module is a second answer waiting to drift.

## The guard reads raw strings too

The window spelling carries no `limit()` and no `first()` — the rank *is* the
cut — so a scanner that reads only fluent chains walks straight past it, and
that form is live in `CounterpartyIndexQuery::recentRowByCounterparty()`. The
guard tokenises each file, resolves class constants to a fixpoint (the clause
above is assembled from two of them), and reads the `ORDER BY` inside raw
fragments as well as the `orderBy*()` calls.

Its one honest blind spot is a chain assembled across two variables, and an
`orderBy($column)` whose term is a variable: an unreadable last term is not
reported, because the scanner says what it can see and no more. The live
example is `SearchQuery::palette()`, which takes its ordering from
`Ledger\Public\Services\TransactionCursor::orderNewestFirst()` rather than
spelling it in the chain — and that one is on the **walk** side anyway. A
keyset pager visits every row across its pages, and the `id` tail is required
by the row-value comparison (`(posted_at, id) < (?, ?)`) that the cursor pages
against: ordering one way and comparing the other repeats or skips a row at
every page boundary.

## The known-divergent baseline

Four reads pick a row out of a tie on a per-device id today. They are a
baseline, not a licence: the count in the guard may fall, and may not rise.

| Read | Why it is not fixed with a clause swap |
| --- | --- |
| `Anomaly\Internal\Detectors\DuplicateChargeDetector` | The id is load-bearing in the `WHERE` as well — `posted_at = anchor AND id < $thisId` is what makes a same-day pair backward-only — so the candidate **set** already differs between devices and reordering alone would be cosmetic. Of three identical same-day charges, device A alerts on the second and third and device B on the first and third; `anomaly_alerts.id` is `DerivedRowId::for(user_id, transaction_id)`, so the two file different alert rows for one duplicate. |
| `Chains\Internal\Resolvers\PaypalFundingResolver` (two arms) | Both order by nearness in time **first** and end on the id, and both cut at 20. The alias arm stops at the first two rows whose IBAN matches; the fuzzy arm keeps the first candidate at a tied score (`> $bestScore`). `ChainLinkInsertHelper` derives the link id. Fixing needs a distance-primary clause with a device-stable tail, which `NewestTransactionFirst` does not spell. |
| `Recurring\Internal\Queries\SeriesAccountResolver` | A `row_number()` window over `observed_at` then `rso.id`, read at rank 1, decides which account a series is filed under. The tie-break is a minted id, and the device-stable tail has to come from the joined transaction. |
| `Recurring\Public\Services\RecurringOccurrenceQuery` (two reads) | `latestOccurrencesForSeries()` feeds `DriftEvaluator`, which writes a derived alert id; `amountTrendForSeries()` cuts the chart at `maxPoints`. One of the two does not join `transactions` today, so the fix is a query change rather than a clause change. |

## The picks that were fixed

`Counterparties` had four transaction reads and the first pass fixed two of
them. `CounterpartyTriage::recentTransactionsFor()` — the five charges shown as
the evidence for a triage decision — and
`CounterpartyProfileQuery::recentActivity()` were left, both capped lists whose
contents the tie decides. Three more reads of the same shape were found
elsewhere by the guard: `Search`'s `DidYouMeanSuggester` builds its
spelling-suggestion corpus out of a capped window, `Import`'s
`AliasMatchPreviewQuery` counts matches inside one, and `Recurring`'s
`MerchantDisplayName::fromTransactions()` takes the single name a review screen
prints. All five end on the shared clause now.

## The picks that are allowed to end on an id

- `Ledger\Internal\Services\CounterpartyKeyProvenance` — a probe. It asks
  whether *any* stored digest reproduces under a candidate key, so which rows
  the sample holds cannot change the answer.
- `Ledger\Public\Services\SplitSumHealthCheck` — a diagnostic that reports at
  most N ids of rows whose legs disagree with their parent. The ids it prints
  **are** this device's ids; that is what the reader is handed.
