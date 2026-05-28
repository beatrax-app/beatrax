# `Ledger` — specs

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
- **`RecordTransactions::record` is idempotent on the v3
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
  [`Transfers`](../transfers/specs.md) owns the matcher; this
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
  `RecordTransactions::record` inserts with
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
  - [`Core`](../core/specs.md) — `User`, `Clock`,
    `BelongsToUser`, `CurrentUser`.
  - `brick/money` — `Money` value object wraps it.
- **Depended on by**
  - [`Ingestion`](../ingestion/specs.md) — implements the
    `AccountResolver` contract declared there.
  - [`Import`](../import/specs.md) — calls
    `RecordsTransactions::record` per row in
    `ConfirmImport`.
  - [`Categorization`](../categorization/specs.md) — calls
    `UpdatesTransactionCategory` via its `AssignCategory`.
  - [`Chains`](../chains/specs.md) — reads `transactions` +
    `raw_payload` + `pair_transaction_id`; never writes.
  - [`Recurring`](../recurring/specs.md) — reads
    `transactions` for cadence detection.
  - [`Forecasting`](../forecasting/specs.md) — reads
    `accounts.starting_balance_minor` /
    `opening_balance_minor` / `forecast_buffer_minor`.
  - [`Counterparties`](../counterparties/specs.md) —
    reads + persists FK on `transactions.counterparty_id`.
  - [`Receipts`](../receipts/specs.md) — calls
    `RecordsStatementSummary` and
    `AppliesEnrichments` (the latter via Import).
  - [`Transfers`](../transfers/specs.md) — reads + writes
    `transactions.pair_transaction_id`.

## Configuration + feature flags

- `users.period_start_day` — per-user period anchor.
- `accounts.starting_balance_minor` —
  Forecasting-supplied opening balance.
- `accounts.forecast_buffer_minor` — Forecasting-supplied
  buffer.
- The v3 fingerprint algorithm is fixed in code; bumping
  to v4 requires a re-derive migration following the
  `2026_05_13_010001` pattern.
- The `transactions.type` allow-list lives in the
  trigger pair; extending it requires a migration that
  drops + recreates the triggers.
