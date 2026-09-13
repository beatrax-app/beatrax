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

A read that does not merely rank but asks whether a row comes **before** a
named one wants `::KEY_ACROSS_ACCOUNTS` beside it: the same columns as a row
value, so the comparison and the ordering are one key. See
[a comparison over the same key](#a-comparison-over-the-same-key).

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

Two reads, in one file, pick a row out of a tie on a per-device id today. They
are a baseline, not a licence: the count in the guard may fall, and may not
rise.

| Read | What fixing it needs |
| --- | --- |
| `Chains\Internal\Resolvers\PaypalFundingResolver` (two arms) | An ORDER BY change, and only that. Both arms order by nearness in time **first** and end on the id, and both cut at 20; past the cut the alias arm stops at the first two rows whose IBAN matches and the fuzzy arm keeps the first candidate at a tied score (`> $bestScore`), so the cut decides the answer. What it needs is a distance term followed by `NewestTransactionFirst::ACROSS_ACCOUNTS`, joined through `::ACCOUNT`, in both arms. |

Unlike the duplicate detector below, **the candidate sets already agree**:
neither arm carries an id in its `WHERE`, and the fuzzy arm's
`id <> $rowId` excludes the anchor row itself, which each device names
correctly out of its own numbering. Only the sequence within an agreed set
diverges, which is what makes this one a clause swap after all.

What it costs while it stands: `ChainLinkInsertHelper` **mints** the link id
and `chain_links_pair_uq` is `UNIQUE(user_id, from_transaction_id,
to_transaction_id, kind)`, so two devices answering with different funding legs
do not converge on one row — the pair holds two links out of one PayPal
expense. That is the one-transaction-in-two-chains the arms' own `existing.id`
exclusion exists to prevent, arriving over sync instead of from a second local
pass.

## The picks that were fixed

`Anomaly`'s `DuplicateChargeDetector` was the harder of the two known-divergent
reads and is fixed: it compares against the shared key rather than ranking on
an id, and the measurement it used to produce is below.

`Counterparties` had four transaction reads and the first pass fixed the two
that rank. `CounterpartyTriage::recentTransactionsFor()` — the five charges
shown as the evidence for a triage decision — and
`CounterpartyProfileQuery::recentActivity()` were left, both capped lists whose
contents the tie decides. Two more of the same shape were found elsewhere by
the guard: `Search`'s `DidYouMeanSuggester` builds its spelling-suggestion
corpus out of a capped window, and `Import`'s `AliasMatchPreviewQuery` counts
pattern matches inside one — the number a reader saves an alias on. All four
end on the shared clause now.

`Recurring`'s `MerchantDisplayName::fromTransactions()` was the same shape and
is already fixed, on `posted_at, booked_at, amount_minor, occurrence_ordinal`.
Those four are the fingerprint columns minus the account, so they are unique
only *within* one account: a merchant charged from two of them can still tie,
and the clause is better rather than total. It ends on `occurrence_ordinal`
rather than on an id, so this rule does not name it.

### The duplicate detector, and why it was not a clause swap

Three identical charges the bank booked on one day, numbered in statement order
by one device and in another order by its peer. Running the detector's old
predicate — `posted_at < anchor OR (posted_at = anchor AND id < $thisId)`, then
`ORDER BY posted_at DESC, id DESC` taken at one row — over both:

| evaluating | device A sibling | A | device B sibling | B |
| --- | --- | --- | --- | --- |
| X | — none — | silent | Y | **ALERT** |
| Y | X | **ALERT** | — none — | silent |
| Z | Y | **ALERT** | X | **ALERT** |

Each device raises two alerts, which is what "exactly one alert per pair" is
supposed to give. But they are not the same two: A files them against Y and Z,
B against X and Z. `anomaly_alerts.id` is minted and the dedup index is
`UNIQUE(transaction_id)`, which merges per CHARGE rather than per pair — so the
pair converges on **three** alert rows for one duplicate group.

The `id <` sat in the `WHERE`, not only in the `ORDER BY`, so the candidate
**sets** differed before any ordering happened and a clause swap would have
turned the guard green while the defect kept firing. What it took instead was a
**comparison** over the device-stable key rather than a ranking of it —
`NewestTransactionFirst::KEY_ACROSS_ACCOUNTS`, below.

## A comparison over the same key

An ordering answers "which of these is nearest". A backward-looking read asks
something the ordering cannot: "is this row strictly BEFORE that one". Those
are two halves of one key, and they have to be the same key or the read cuts on
one and ranks by the other.

`NewestTransactionFirst::KEY_ACROSS_ACCOUNTS` spells the columns of
`ACROSS_ACCOUNTS` as a row value — `(posted_at, booked_at, amount_minor,
currency, counterparty_normalized, occurrence_ordinal, charge_account.iban)` —
because SQLite compares a row value lexicographically, which is exactly what
that ORDER BY sorts by. Within one user the key is unique: it is
`transactions_fingerprint_uq` with `account_id` swapped for the IBAN that
account carries `UNIQUE(user_id, iban)` on. So "strictly less than this row's
tuple" is a total order with the anchor itself as the only tie, which is what
makes "backward-only" mean the same thing on both devices.

`AKeyComparedAsARowValueSpellsItsOwnOrderingArchTest` holds the two spellings
together — they are two hand-written column lists, and a pair that drifts apart
still runs.

### What it costs

Measured over the demo dataset (176 transactions) and a 60 000-row synthetic
ledger, comparing the old predicate against the new one row for row:

| | demo, 165 rows | 60 000-row ledger, 2 000 rows |
| --- | --- | --- |
| before | 0.0478 ms/row | 1.4484 ms/row |
| after | 0.0362 ms/row | 1.0285 ms/row |
| | **−24%** | **−29%** |

Cheaper, not dearer, and for a reason worth keeping: the old `posted_at >=
windowOpen` had no upper bound, so the seek ran from the window's start to the
end of the file — the whole remaining ledger when a backfill anchors years
back. SQLite decomposed the `OR` into a `MULTI-INDEX OR` over two searches and
sorted the union. The new shape states the window as a closed range beside the
exact comparison, so the same index
(`transactions_user_id_posted_at_index`) is seeked once on
`user_id=? AND posted_at>? AND posted_at<?`. The row value must stay **beside**
that range rather than replace it: a bare `(a, b, …) < (…)` gives the planner
nothing to seek on.

What it adds is one integer-primary-key lookup on `accounts` per candidate row,
one scalar subquery evaluated once for the anchor's own IBAN, and a temp B-tree
over the last six ORDER BY terms — which sorts only the rows surviving the
window, counterparty, amount, currency and type filters, nought to a handful in
practice.

## The picks that are allowed to end on an id

- `Ledger\Internal\Services\CounterpartyKeyProvenance` — a probe. It asks
  whether *any* stored digest reproduces under a candidate key, so which rows
  the sample holds cannot change the answer.
- `Ledger\Public\Services\SplitSumHealthCheck` — a diagnostic that reports at
  most N ids of rows whose legs disagree with their parent. The ids it prints
  **are** this device's ids; that is what the reader is handed.

## Recurring's occurrences, which are already fixed

`Recurring` ended the same three reads on
`Internal\Support\NewestOccurrenceFirst::SQL` —
`o.observed_at desc, t.booked_at desc, o.observed_amount_minor desc,
t.occurrence_ordinal desc` — reaching the charge through the joined
transaction, because nothing on the occurrence row itself is device-stable
past `observed_at`.

Both its table and its clause sit behind class constants
(`SeriesTables::OCCURRENCES`, `NewestOccurrenceFirst::SQL`), which is the shape
a scanner goes quietly blind on: an unresolved constant makes the read
invisible, and an invisible read reports as "allows N, **found 0**" — exactly
what a *fixed* read reports. The guard therefore names those three reads and
the column they end on rather than counting them, so the two cannot be
confused. Blinding the constant reader reds that control and the clause control
while every allow-list assertion stays green, which is what makes the control
worth its lines.
