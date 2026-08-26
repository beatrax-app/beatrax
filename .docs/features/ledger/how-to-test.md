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
  - `RecordTransactions::__invoke` end-to-end (INSERT ON
    CONFLICT with `enriched_from` append) — it takes an
    iterable batch, so a fixture passes a list or a generator,
    never a single row. `RecordTransactionsChunkingTest`
    covers the per-chunk transaction boundary and
    `RecordTransactionsDispatchesEventTest` the one-event-per-
    inserted-row rule (and that a fingerprint duplicate raises
    nothing).
  - `UpdateTransactionCategory` end-to-end (scoped by user_id;
    cross-user no-op).
  - `ThisPeriodAtAGlanceQuery::for` against a multi-currency
    fixture month.
  - `TopCategoriesByPeriodQuery::for` ordering.
  - `TransactionListQuery::recent` / `fullHistory` — the
    display-currency projection and the keyset cursor. There is
    no `page()` and no page number; the cursor unit test is
    `tests/Unit/TransactionCursorTest.php` and the list-side
    behaviour is `TransactionsListInfiniteScrollTest`.
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

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Ledger` module.

## Behavioral contracts

- **`RecordsTransactions` is the SOLE sanctioned writer for
  `transactions`.** No other module / no other class issues
  INSERTs / UPDATEs against the table. The arch invariant
  `noTransactionsWritesOutsideLedger` enforces it.
- **`UpdatesTransactionCategory` is the SOLE sanctioned writer
  for `transactions.category_id`.** The arch invariant
  `noCategoryColumnWritesOutsideLedgerUpdater` enforces it.
- **`RecordsStatementSummary` is the SOLE sanctioned writer for
  `statement_summaries`.**
- **`RecordTransactions::__invoke` is idempotent on the v3
  fingerprint.** INSERT ON CONFLICT(fingerprint) DO UPDATE
  (append to `enriched_from`); re-importing the same row never
  produces a duplicate transaction.
- **`enriched_from` is append-only.** Every stronger
  `source_ref` value observed for a fingerprint appends a new
  entry; no entry is ever removed. The historical chain of
  observations survives.
- **The v3 fingerprint includes `account_id`.** v2 omitted it
  (a documented bug where cross-account same-amount rows
  collided); v3 includes it and the
  `rederive_fingerprints_to_v3` migration re-derived every row.
- **Every `transactions.type` value is a documented enum.**
  Paired BEFORE INSERT / BEFORE UPDATE triggers reject any
  value outside the allow-list. The trigger pair was
  recreated by
  `2026_05_17_020001_recreate_transactions_type_triggers.php`
  after a schema evolution.
- **`pair_transaction_id` is NULLable and bidirectional.**
  Self-transfers (a `transfer_out` on account A + a
  `transfer_in` on account B for the same amount and
  approximately same date) link via the column;
  [`Transfers`](../transfers/how-to-test.md) owns the matcher; this
  module persists the FK.
- **`raw_payload` carries the source-format-specific
  per-row blob.** PayPal-funding's deterministic arm
  (`Chains::PaypalFundingResolver`) reads it; the column
  is intentionally opaque to every other module.
- **Cross-user reads / writes return 404.** Every Public
  query and action filters by `(id, user_id)`; the
  `BelongsToUser` global scope is the second-layer guard.
- **`Money` reads throw `MoneyColumnMissingException` for an
  absent money column.** No silent zero fallback; the
  exception's message names the missing column so the bug
  surfaces immediately.
- **`ThisPeriodAtAGlanceQuery::for` is a single read.** The
  dashboard's load-bearing query is one SELECT aggregating
  across the period; no N+1 calls.
- **`PeriodQuery` honours `users.period_start_day`.** A user
  with `period_start_day = 25` sees their "month" run from
  the 25th of one month to the 24th of the next. Default 1.
- **The fingerprint rederive command is idempotent.** Running
  `beatrax:rederive-fingerprints` twice produces no change
  on the second run (the v3 algorithm is deterministic).

## Edge cases

- **A canonical row with no resolved category** —
  `RecordTransactions::__invoke` inserts with
  `category_id = NULL`; the row appears in the uncategorised
  triage queue (Categorization owns the read-side query).
- **A canonical row with no counterparty** — insert with
  `counterparty_id = NULL`; the row is filterable but does
  not link from the counterparty index.
- **Two imports racing on the same fingerprint** — INSERT
  ON CONFLICT serializes at the DB layer; exactly one row
  lands; both callers see the same `transaction.id` on the
  second read.
- **An import row whose Money column is null** — `Money`
  refuses to construct; the row is rejected at the DTO
  boundary upstream (Ingestion / Import). Reaching the
  Ledger writer with a null Money column is a bug.
- **A `transactions.type` typo in upstream code** — paired
  triggers reject the INSERT as SQLSTATE 23000; the
  pipeline surfaces the error.
- **A statement summary insert that conflicts on an existing
  period** — `StatementSummaryWriter` handles the conflict
  per its schema (the summary table's UNIQUE constraint is
  on `(user_id, account_id, period_start, period_end)`);
  re-runs produce no duplicates.
- **`ThisPeriodAtAGlanceQuery` with an empty period** —
  returns an empty `DashboardSummary` with `total =
  Money::zero(currency)`; renders cleanly.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`,
    `BelongsToUser`, `CurrentUser`.
  - `brick/money` — `Money` value object wraps it.
- **Depended on by**
  - [`Ingestion`](../ingestion/how-to-test.md) — implements the
    `AccountResolver` contract declared there.
  - [`Import`](../import/how-to-test.md) — calls
    `RecordsTransactions::__invoke` once per confirm in
    `ConfirmImport`, handing it the whole cached batch with
    `captureForSync: false`.
  - [`Categorization`](../categorization/how-to-test.md) — calls
    `UpdatesTransactionCategory` via its `AssignCategory`.
  - [`Chains`](../chains/how-to-test.md) — reads `transactions` +
    `raw_payload` + `pair_transaction_id`; never writes.
  - [`Recurring`](../recurring/how-to-test.md) — reads
    `transactions` for cadence detection.
  - [`Forecasting`](../forecasting/how-to-test.md) — reads
    `accounts.starting_balance_minor` (through this module's
    `AccountStartingBalanceQuery`) /
    `opening_balance_minor` / `forecast_buffer_minor`.
  - [`Calendar`](../calendar/architecture.md) — reads
    `accounts.starting_balance_minor` through the same reader for
    the past-day balance line.
  - [`Counterparties`](../counterparties/how-to-test.md) —
    reads + persists FK on `transactions.counterparty_id`.
  - [`Receipts`](../receipts/how-to-test.md) — calls
    `RecordsStatementSummary` and
    `AppliesEnrichments` (the latter via Import).
  - [`Transfers`](../transfers/how-to-test.md) — reads + writes
    `transactions.pair_transaction_id`.

## Configuration + feature flags

- `users.period_start_day` — per-user period anchor.
- `accounts.starting_balance_minor` /
  `starting_balance_date` — the Ledger-owned, auto-detected
  baseline every balance opens on, read only through
  `AccountStartingBalanceQuery`. Distinct from
  `accounts.opening_balance_minor`, which is Forecasting's
  manual override.
- `accounts.forecast_buffer_minor` — Forecasting-supplied
  buffer.
- The v3 fingerprint algorithm is fixed in code; bumping
  to v4 requires a re-derive migration following the
  `2026_05_13_010001` pattern.
- The `transactions.type` allow-list lives in the
  trigger pair; extending it requires a migration that
  drops + recreates the triggers.
