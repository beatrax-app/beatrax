# `Ledger` — architecture

The `Ledger` module is the canonical store: it owns the
`transactions` / `accounts` / `categories` / `merchants` /
`merchant_memories` / `import_runs` / `currencies` /
`statement_summaries` tables and the SOLE sanctioned writers for
each. Every other module reads from these tables through Public
queries and writes through Public action contracts; no module
reaches into `Modules\Ledger\Internal` or runs raw INSERTs on the
canonical tables.

## What this module is for

The Phase 1 deliverable: "see my ASN month" lives here. Every later
module — `Chains`, `Recurring`, `Forecasting`, `DriftAlerts`,
`Counterparties` — reads from this module's tables; every adapter in
`Ingestion` and every parser in `Import` ultimately funnels through
`RecordsTransactions` here. The cross-cutting design lives in the
[data-model architecture topic](../../architecture/data-model.md);
this page describes the module's surface.

The "this period at a glance" query is the dashboard's load-bearing
read: aggregate totals across the user's period-start-day window
plus per-currency tiles plus the top categories. It runs in a single
read against indexed columns and surfaces the user's primary daily
question: "what did I spend, where did the money come from, and
what's pending?".

What the module explicitly does NOT do:

- It never categorises a transaction. The category is supplied by
  `Categorization` via `UpdatesTransactionCategory`; this module is
  the sole writer but it does not decide what to write.
- It never resolves counterparties. `Counterparties` resolves; this
  module persists the FK.
- It never speaks IMAP or PDF or CSV. `Ingestion` parses;
  `Import` orchestrates; this module persists.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `RecordsTransactions::record(CanonicalTransaction $tx, User
    $user): RecordResult` — the single sanctioned writer for
    `transactions`. Idempotent on the v3 fingerprint.
  - `UpdatesTransactionCategory::__invoke($transactionId,
    $categoryId, $user): int` — the single sanctioned writer for
    `transactions.category_id`. Returns affected row count.
  - `RecordsStatementSummary::record(StatementSummaryData $data,
    User $user)` — the single sanctioned writer for
    `statement_summaries`. Receipts module raises this.
- **Actions/**
  - `RecordTransactions` (impl. `RecordsTransactions`).
  - `UpdateTransactionCategory` (impl. `UpdatesTransactionCategory`).
- **DTOs/**
  - `CanonicalTransaction` — the universal in-flight row shape
    every module passes around (with chainable `with*` setters
    for stage-by-stage enrichment).
  - `RecordResult` — `(insertedCount, deduppedCount,
    enrichedCount)`.
  - `MoneyDto`, `Period`, `PerCurrencyTile`, `DashboardSummary`,
    `TransactionRowDto`, `TransactionListPage`,
    `TopCategoryRow`, `StatementSummaryData`.
- **ValueObjects/**
  - `Money` — domain-shaped wrapper around `brick/money` with the
    project's rounding semantics.
- **Exceptions/**
  - `MoneyColumnMissingException` — raised by `Money` reads when a
    money column is missing from a query result, instead of a
    silent zero.
- **Services/**
  - `FingerprintComposer::compose(...)` — v3 fingerprint
    deterministic compose. Singleton.
  - `PeriodQuery::current($user)` / `previous($user)` — the
    `users.period_start_day`-aware period resolver. Transient
    binding (depends on per-request CurrentUser).
  - `ThisPeriodAtAGlanceQuery::for($user)` — dashboard
    aggregate.
  - `TopCategoriesByPeriodQuery::for($user, $period)` — top
    categories within a period.
  - `TransactionListQuery::page($user, $filters, $pagination)` —
    the transactions list read.
  - `StatementSummaryWriter` (impl. `RecordsStatementSummary`).

`Internal/` houses the implementation:

- **Internal/Services/FingerprintRederiveService** — re-derives
  every fingerprint to the v3 algorithm.
- **Internal/Console/RederiveFingerprintsCommand** —
  `beatrax:rederive-fingerprints` artisan command.
- **Internal/Http/Livewire/TransactionsList** — the
  `/transactions` page.
- **Internal/Http/Livewire/TransactionDetail** — the per-row
  detail view.

## Key services + events

- `RecordTransactions::record($tx, $user)` — INSERT ON CONFLICT
  on the v3 fingerprint. Returns the inserted / dedupped /
  enriched counts so the caller can render a useful confirm
  result.
- `UpdateTransactionCategory::__invoke($txId, $catId, $user)` —
  scoped by `(id, user_id)`; returns affected count. Categorization's
  `AssignCategory` delegates here.
- `FingerprintComposer::compose(...)` — deterministic inputs
  (`normalized_counterparty`, `posted_at`, `settled_at`,
  `amount_minor`, `account_id`, `source_format`). v3 includes
  every input; v2 dropped `account_id` and was re-derived via
  migration.
- `PeriodQuery` — resolves the user's current and previous
  period given `users.period_start_day`. Transient binding to
  pick up the live `CurrentUser` per request.
- `ThisPeriodAtAGlanceQuery` — single-read dashboard aggregate.
  Returns a `DashboardSummary` DTO with per-currency tiles +
  totals.
- `StatementSummaryWriter` — Receipts raises a statement summary
  (the PayPal `statement_summary_total`, the ICS statement
  period totals); this module's writer persists.

The module raises no events; it persists in response to the
upstream pipeline.

## Data flow

The persistence path through the pipeline:

```
ImportPipeline.confirm
  → ConfirmImport (Import)
       → for each cached canonical row:
            RecordsTransactions::record(tx, user)
              → INSERT INTO transactions (...) ON CONFLICT(fingerprint)
                DO UPDATE (enrichment via enriched_from append)
              → return (inserted, dedupped, enriched) per row

Categorization (manual reclassify)
  → AssignCategory (Categorization)
       → UpdatesTransactionCategory::__invoke (Ledger)
            → UPDATE transactions SET category_id = ?
              WHERE id = ? AND user_id = ?

Receipts (statement-summary write)
  → RecordReceipt (Receipts)
       → RecordsStatementSummary::record (Ledger)
            → INSERT INTO statement_summaries
```

The dashboard read:

```
Dashboard SFC mount
  → PeriodQuery::current($user)
  → ThisPeriodAtAGlanceQuery::for($user)
       → single SELECT aggregating across the period
       → DashboardSummary { perCurrency: [...], total: Money }
  → TopCategoriesByPeriodQuery::for($user, $period)
       → indexed read against (user_id, posted_at, category_id)
  → render
```
