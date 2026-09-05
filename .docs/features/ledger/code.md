# `Ledger` — code

The file-level map for the module.

## Directory layout

```
Modules/Ledger/
├── Public/
│   ├── Contracts/
│   │   ├── RecordsTransactions.php
│   │   ├── UpdatesTransactionCategory.php
│   │   └── RecordsStatementSummary.php
│   ├── Actions/
│   │   ├── RecordTransactions.php
│   │   └── UpdateTransactionCategory.php
│   ├── Dto/
│   │   ├── CanonicalTransaction.php
│   │   ├── RecordResult.php
│   │   ├── DashboardSummary.php
│   │   ├── Period.php
│   │   ├── PerCurrencyTile.php
│   │   ├── StatementSummaryData.php
│   │   ├── TopCategoryRow.php
│   │   ├── TransactionListPage.php
│   │   └── TransactionRowDto.php
│   ├── Events/
│   ├── Exceptions/
│   │   └── MoneyColumnMissingException.php
│   ├── Services/
│   │   ├── FingerprintComposer.php
│   │   ├── PeriodQuery.php
│   │   ├── PopulatedPeriodQuery.php
│   │   ├── StatementSummaryWriter.php
│   │   ├── ThisPeriodAtAGlanceQuery.php
│   │   ├── TopCategoriesByPeriodQuery.php
│   │   └── TransactionListQuery.php
│   └── ValueObjects/
│       └── Money.php
├── Internal/
│   ├── Console/
│   │   └── RederiveFingerprintsCommand.php
│   ├── Services/
│   │   └── FingerprintRederiveService.php
│   └── Http/Livewire/
│       ├── TransactionsList.php
│       └── TransactionDetail.php
├── Models/
│   ├── Account.php
│   ├── Category.php
│   ├── Currency.php
│   ├── ImportRun.php
│   ├── StatementSummary.php
│   └── Transaction.php
├── Database/
│   └── Migrations/   (the canonical schema and every change to it since)
├── Routes/
│   ├── web.php
│   └── console.php
├── Resources/views/
├── Providers/
│   └── LedgerServiceProvider.php
└── tests/
    └── Feature/
```

## Public API

- **Contracts/**
  - `RecordsTransactions::__invoke(iterable<CanonicalTransaction>
    $canonical, User $user, bool $captureForSync = true):
    RecordResult` — single sanctioned writer for
    `transactions`. Takes a batch, not a row. Idempotent on v3
    fingerprint via INSERT ON CONFLICT.
  - `UpdatesTransactionCategory::__invoke(int $transactionId,
    ?int $categoryId, User $user): int` — single sanctioned
    writer for `transactions.category_id`. Returns affected
    count.
  - `RecordsStatementSummary::__invoke(User $user,
    StatementSummaryData $data): void` — single sanctioned
    writer for `statement_summaries`.
- **Actions/** — default implementations of the three
  contracts.
- **DTOs/**
  - `CanonicalTransaction` — universal in-flight row shape.
    Chainable `with*` setters used by pipeline stages
    (`withCategoryId`, `withCounterpartyId`,
    `withAutoCategoryProvenance`, ...).
  - `RecordResult` — `(inserted, duplicates)` counts. There is
    no enriched count on it: enrichment is a separate pass
    (`AppliesEnrichments`) run by the caller.
  - `DashboardSummary` — `(period, inflow, outflow, net: Money,
    topCategories, recentTransactions, uncategorizedCount,
    isFirstRun)`.
  - `Period` — `(startDate, endDate, label)`.
  - `PerCurrencyTile`, `StatementSummaryData`,
    `TopCategoryRow`, `TransactionListPage`,
    `TransactionRowDto`.
- **ValueObjects/Money** — domain wrapper around `brick/money`.
- **Exceptions/MoneyColumnMissingException** — thrown by
  `Money` reads when a column is absent.
- **Services/**
  - `FingerprintComposer::compose(...)` — deterministic v3
    fingerprint compose; takes the canonical inputs.
  - `PeriodQuery::current($user) / previous($user)` — period
    resolver. Transient binding (depends on `CurrentUser`).
  - `ThisPeriodAtAGlanceQuery::for($user): DashboardSummary` —
    single dashboard read.
  - `PopulatedPeriodQuery::latestWithRecords($user, $inView):
    ?Period` — the latest period the reader has records in, or
    null when the period in view already has them and null again
    when the ledger is empty.
  - `TopCategoriesByPeriodQuery::for($user, $period):
    TopCategories` — `(rows: list<TopCategoryRow>, refunded:
    Money, refundedCategoryCount)`, narrowed by
    `Public/Support/OutwardSpend`.
  - `TransactionListQuery::recent($user, $daysBack = 90,
    $cursorId = null, $limit = 50, $cursorPostedAt = null,
    $currency = null): TransactionListPage` and
    `TransactionListQuery::fullHistory($user, $cursorId = null,
    $limit = 50, $cursorPostedAt = null, $currency = null):
    TransactionListPage` — keyset-paged, no filter bag.
  - `StatementSummaryWriter` — concrete
    `RecordsStatementSummary` impl.

## Internal services

- `Internal/Services/ConvertedSpendByCategory::forUserAndPeriod()` —
  the one place category spend crosses currencies; shared by
  `TopCategoriesByPeriodQuery` and `CategorySpendTrendQuery` so the
  dashboard's two spend panels cannot disagree. Returns
  `Internal/Dto/ConvertedCategorySpend` (converted map + the codes no
  rate reached).
- `Internal/Services/FingerprintRederiveService::run()` —
  re-derives every fingerprint to the current algorithm version.
- `Internal/Console/RederiveFingerprintsCommand` —
  `beatrax:rederive-fingerprints`. Registered behind a
  `runningInConsole()` guard.
- `Internal/Http/Livewire/TransactionsList` — `/transactions`
  page Livewire SFC. Drives the `TransactionListQuery`.
- `Internal/Http/Livewire/TransactionDetail` — per-row detail. It
  mounts Categorization's `CategorizationProvenancePanel` and no longer
  reads provenance itself: the panel's own render-time read is the
  single path from the column to the screen.

## Models + migrations

- `Models/Transaction` — maps to `transactions`. Uses
  `BelongsToUser`. Casts: `Money` for `amount`,
  `immutable_datetime` for `posted_at` / `settled_at`,
  `array` for `enriched_from` / `auto_category_provenance` /
  `raw_payload`. The `type` column is enforced by paired
  BEFORE INSERT / BEFORE UPDATE triggers (allow-list:
  `expense`, `income`, `transfer_in`, `transfer_out`,
  `payment_to_merchant`, etc.).
- `Models/Account` — maps to `accounts`. Uses
  `BelongsToUser`. `kind` enum enforced by triggers
  (`asn_checking`, `ics_card`, `paypal`, ...). Includes
  `starting_balance_minor` / `starting_balance_date` (this
  module's own auto-detected baseline, read through
  `AccountStartingBalanceQuery`) plus `opening_balance_minor` /
  `opening_balance_as_of_date` and `forecast_buffer_minor`
  (Forecasting's manual override and buffer, added by its
  migration).
- `Models/Category` — maps to `categories`. Per-user OR global
  (`user_id = NULL`).
- `Models/Currency` — `iso_code` (PK), display metadata.
- `Models/ImportRun` — per-import audit row.
- `Models/StatementSummary` — per-statement-period totals.

Migrations, the load-bearing ones summarised by purpose:

- Initial schema: currencies, accounts, categories,
  import_runs, transactions, merchants, merchant_memories.
- `2026_05_13_010001_rederive_fingerprints_to_v3.php` — the
  v2 → v3 migration that re-derived every row.
- `2026_05_13_010002_add_enriched_from_to_transactions.php` —
  the append-only enrichment chain.
- `2026_05_13_010003_add_enriched_count_to_import_runs.php`.
- `2026_05_13_010004_replace_transactions_fingerprint_unique_index.php`
  — the v3 unique index swap.
- `2026_05_13_010005_create_statement_summaries_table.php`.
- `2026_05_15_010001_add_raw_payload_to_transactions.php` —
  the per-row raw-payload blob (consumed by Chains'
  PayPal-funding deterministic arm).
- `2026_05_15_010002_add_pair_transaction_id_to_transactions.php`
  — the self-transfer pair link (consumed by
  [`Transfers`](../transfers/architecture.md)).
- `2026_05_17_020001_recreate_transactions_type_triggers.php`
  — the type-enum trigger refresh.
- `2026_05_27_000001_add_starting_balance_to_accounts_table.php`
  and
  `2026_05_27_000002_backfill_starting_balance_from_statement_summaries.php`
  — the per-account starting-balance addition, added then
  backfilled from the statement summaries already on hand.

## Provider wiring

`LedgerServiceProvider::register()`:

- Binds the three Public contracts to their default
  implementations.
- Singletons `FingerprintComposer`,
  `ThisPeriodAtAGlanceQuery`, `TopCategoriesByPeriodQuery`,
  `TransactionListQuery`.
- Transient bindings for `PeriodQuery` (per-request
  `CurrentUser` dependency) and
  `FingerprintRederiveService`.

`LedgerServiceProvider::boot()`:

- Loads migrations, web/console routes, views.
- Registers this module's Livewire components under the
  `ledger.*` namespace.
- Registers the `RederiveFingerprintsCommand` artisan command
  behind a `runningInConsole()` guard.
