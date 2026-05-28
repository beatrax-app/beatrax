# `Ledger` — how to test

Practical recipes for exercising the `Ledger` module in isolation.

## Unit tests

- **Location:** `Modules/Ledger/tests/Unit/` (when present)
- **What they test:** the `FingerprintComposer` deterministic
  output against fixture inputs; the `Money` value object's
  arithmetic + rounding; the `Period` resolution math against
  varying `period_start_day` values; the
  `MoneyColumnMissingException` raising path.

## Feature tests

- **Location:** `Modules/Ledger/tests/Feature/`
- **What they test:**
  - `RecordTransactions::record` end-to-end (INSERT ON CONFLICT
    with `enriched_from` append).
  - `UpdateTransactionCategory` end-to-end (scoped by user_id;
    cross-user no-op).
  - `ThisPeriodAtAGlanceQuery::for` against a multi-currency
    fixture month.
  - `TopCategoriesByPeriodQuery::for` ordering.
  - `TransactionListQuery::page` filters + pagination.
  - The `/transactions` Livewire SFC.
  - The transaction-detail SFC with auto-category provenance.
  - The `beatrax:rederive-fingerprints` artisan command's
    idempotence.

## Contract / arch invariants

- The repo-wide `noTransactionsWritesOutsideLedger` invariant —
  forbids any class outside
  `Modules\Ledger\Public\Actions\RecordTransactions` from
  INSERTing / UPDATEing the `transactions` table.
- The repo-wide
  `noCategoryColumnWritesOutsideLedgerUpdater` invariant —
  forbids any class outside
  `Modules\Ledger\Public\Actions\UpdateTransactionCategory`
  from writing `transactions.category_id`.
- The repo-wide `everyDomainModelUsesBelongsToUser` invariant —
  asserted via the `BelongsToUser` trait composition on every
  Ledger model.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Ledger/tests

# Just the dashboard query
vendor/bin/pest Modules/Ledger/tests --filter "ThisPeriodAtAGlance"

# Just the fingerprint composer
vendor/bin/pest Modules/Ledger/tests --filter "Fingerprint"

# Stop on first failure
vendor/bin/pest Modules/Ledger/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A re-imported row producing a duplicate transaction** —
  the v3 fingerprint inputs differ between the two imports.
  Compare `transactions.fingerprint` for both rows; the
  most common cause is a parser normalising counterparty
  differently across two adapter versions.
- **`MoneyColumnMissingException` on a query result** — the
  read query didn't select the expected money column.
  Confirm the SELECT lists `*_minor` and `*_currency` for
  every Money column the DTO mapper expects.
- **A transaction visible to the wrong user** — the
  `BelongsToUser` global scope should hide it; the most
  common cause is a query that explicitly bypasses the scope
  via `withoutGlobalScope(UserScope::class)`. The Ledger's
  Public queries never bypass; bypass elsewhere is a Rule 1
  bug.
- **`ThisPeriodAtAGlanceQuery::for` slow on a large month** —
  the underlying single SELECT relies on the
  `(user_id, posted_at)` composite index. Inspect with
  `EXPLAIN`; missing index plan suggests the query lost the
  composite (e.g. a recent migration changed the index
  shape).
- **`beatrax:rederive-fingerprints` producing different
  values on the second run** — the algorithm is supposed to
  be deterministic. Inspect the inputs; if a row's
  `counterparty_normalized` changes between runs, the
  normalisation step is the culprit (it depends on
  user-authored aliases that the user just added).
- **A new `transactions.type` value rejected at INSERT** —
  the paired triggers enforce the allow-list. Extend the
  allow-list via a migration that drops + recreates the
  triggers (see
  `2026_05_17_020001_recreate_transactions_type_triggers.php`
  for the pattern).
