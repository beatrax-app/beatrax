# Data model

The full beatrax dataset is one SQLite file per installation
(see [ADR 0005](../adr/0005-sqlite-wal.md)). The file holds the
application schema, the Laravel `database` queue tables, the
`database` cache + lock tables, and the sessions table — all
co-resident, all WAL-mode. This document describes the application
schema at the table level: who owns which tables, what the foreign-key
shape looks like, and which columns carry trust-boundary or
state-machine semantics.

The structural rules this schema obeys:
- Every user-scoped table carries `user_id` (see
  [ADR 0008](../adr/0008-multi-user-belongstouser.md)).
- Every monetary value lives as `amount_minor INTEGER` +
  `currency VARCHAR(3)`, cast through `MoneyCast` (see
  [ADR 0009](../adr/0009-brick-money-multi-currency.md)).
- Every `state` column is mutated by exactly one state-machine class;
  the arch invariants in `tests/Contracts/BoundaryArchTest.php` enforce
  this.
- Migrations live per-module under `Modules/<Name>/Database/Migrations/`;
  Laravel sorts by timestamp across all modules, so cross-module
  foreign keys work, but a column on a table belongs in the owning
  module's migrations.

## Tables by module

### Core (`Modules/Core/`)

| Table | Purpose |
| --- | --- |
| `users` | Username + hashed password + `is_owner` + `is_developer` + `force_password_change` + per-user preference flags (theme, default currency view, auto-import drop folder, close behaviour, community settings, recurring/drift settings) |
| `sessions` | Laravel sessions table (driver: `database`) |
| `password_reset_tokens` | Laravel default — present but unused (see [ADR 0010](../adr/0010-recovery-codes-no-smtp.md)) |
| `system_alerts` | Operator-facing alerts surfaced by the `SystemAlertsBanner`; the trigger for "doctor saw something wrong" |
| `user_preferences` | Sparse per-user preference rows beyond what fits as columns on `users` |

### Auth (`Modules/Auth/`)

| Table | Purpose |
| --- | --- |
| `user_recovery_codes` | Hashed one-time recovery codes per user; `(user_id, code_hash)` unique; state column managed by `UserRecoveryCodeStateMachine` |
| `oauth_secrets` | Per-user OAuth refresh tokens for Gmail API / Microsoft Graph; chmod-600 storage shape persisted via `OAuthSecretsRepository` |

### Ledger (`Modules/Ledger/`) — the canonical store

| Table | Purpose |
| --- | --- |
| `accounts` | Per-user bank / PayPal / ICS-card accounts. `starting_balance_minor` + `starting_balance_currency` for forecast bootstrap |
| `transactions` | The single canonical ledger. Carries `user_id`, `account_id`, `booked_at`, `amount_minor` + `currency`, `counterparty_id` (nullable), `category_id` (nullable), `transaction_type` enum, `payment_type` enum, `fingerprint` v3, `source_ref`, `enriched_from` JSON, `pair_transaction_id` (nullable, for chain links + transfers), `raw_payload` JSON |
| `categories` | Per-user category taxonomy seeded on user creation |
| `merchants` | Per-user merchant name dictionary used by the categorizer |
| `currencies` | The ISO-4217 currency list with per-currency exchange-rate rows for the "view all in EUR" toggle |
| `import_runs` | One row per import-batch; `enriched_count` separates new-row writes from enrichment-update writes |
| `statement_summaries` | One row per CAMT/MT940 statement period: opening + closing balance, period start + end, source format |
| `card_statements` | One row per ICS PDF statement: total amount, bulk-iDEAL settlement date, state column managed by `CardStatementStateMachine` |
| `card_statement_credits` | The per-line decomposition of an ICS statement; links each card line to the bulk-iDEAL settlement |

### Counterparties (`Modules/Counterparties/`)

| Table | Purpose |
| --- | --- |
| `counterparties` | Per-user resolved counterparties (merchant slug, display name, type-chip enum, primary IBAN, alias set). `transactions.counterparty_id` FKs here |

### Categorization (`Modules/Categorization/`)

| Table | Purpose |
| --- | --- |
| `categorization_rules` | Per-user rules: match conditions + assignment + specificity score |
| `merchant_aliases` | Per-user merchant-name normalisation aliases (raw description → canonical merchant name) |
| `merchant_memories` | Per-user (normalised-merchant-name, category) memory rows with occurrences counter |
| `pending_enrichment_conflicts` | Per-user receipt-vs-statement category conflicts awaiting the user's resolution-preference application |

### Recurring (`Modules/Recurring/`)

| Table | Purpose |
| --- | --- |
| `recurring_series` | Per-user detected series: cadence, mean amount, drift threshold percent, cluster counterparty key, state column managed by `RecurringSeriesStateMachine` |
| `recurring_series_occurrences` | Per-series matched transaction occurrences |
| `recurring_series_transitions` | Audit log of state transitions on `recurring_series.state` |

### DriftAlerts (`Modules/DriftAlerts/`)

| Table | Purpose |
| --- | --- |
| `drift_alerts` | Per-series drift detections: signed delta, annualised impact, state column managed by `DriftAlertStateMachine` |
| `drift_alert_transitions` | Audit log of state transitions on `drift_alerts.state` |

### Forecasting (`Modules/Forecasting/`)

| Table | Purpose |
| --- | --- |
| `forecast_runs` | Per-user forecast run: window (30/60/90), result_json (the per-day projection + percentile bands) |
| `forecast_scenarios` | Per-user named scenarios (e.g. "cancel Netflix") that mutate the projection without writing transactions |
| `forecast_scenario_mutations` | Per-scenario mutation entries (suppress this recurring series, add this hypothetical income, ...) |
| `forecast_shortfall_windows` | Per-run derived: contiguous date ranges where the projected balance crosses zero |

### Chains (`Modules/Chains/`)

| Table | Purpose |
| --- | --- |
| `chain_links` | The chain ledger: linking-side `transactions.id` + linked-side `transactions.id` + `kind` enum (extended with hint variants), confidence, resolution_run_id |
| `chain_resolution_runs` | Per-resolver-run audit: links created, candidates flagged, duration |
| `known_counterparty_ibans` | Per-user learnt aliases: IBAN ↔ counterparty-name bridge that sharpens subsequent chain resolution |

### Community (`Modules/Community/`)

| Table | Purpose |
| --- | --- |
| `community_merchant_mappings` | Optional community dataset; the Community module loads + serves this; the Categorization module ignores it unless the user explicitly imports an entry as a rule |

### EmailScan (`Modules/EmailScan/`)

| Table | Purpose |
| --- | --- |
| `inboxes` | Per-user (provider, account-email) entries; provider enum is Gmail / Microsoft Graph; `backfill_progress` written only by `InboxScanStateMachine` |
| `inbox_messages` | Per-message metadata: provider message ID, internal date, matcher key (Receipts boundary) |
| `inbox_scan_state` | Per-inbox UID-resume cursor + state column managed by `InboxScanStateMachine` |
| `known_senders` | Auto-seeded sender allow-list for inbox classification |
| `discovered_senders` | Senders found during scans that didn't match any matcher; queued for the user's matcher-rule curation |

### Receipts (`Modules/Receipts/`)

| Table | Purpose |
| --- | --- |
| `file_imports` | Per-source-file metadata (CSV / CAMT / MT940 / PDF / .eml / .mbox); the matcher-key column ties it back to which receipt matcher fired |

### Onboarding (`Modules/Onboarding/`)

| Table | Purpose |
| --- | --- |
| `wizard_progress` | Per-user step + completion state for the first-run wizard |

### DevMode (`Modules/DevMode/`)

| Table | Purpose |
| --- | --- |
| `dev_mode_audit` | Append-only audit of every developer-mode action (artisan run, destructive command, palette invocation) |

### Laravel framework tables

| Table | Purpose |
| --- | --- |
| `jobs` / `job_batches` / `failed_jobs` | The Laravel `database` queue (see [ADR 0007](../adr/0007-database-queue-driver.md)) |
| `cache` / `cache_locks` | The Laravel `database` cache; `cache_locks` is where `withoutOverlapping()` lives |

## The trust boundaries inside the schema

A handful of columns carry semantics that the rest of the codebase
treats as load-bearing:

- **`transactions.fingerprint`** — v3 fingerprint composer; the unique
  index `(user_id, account_id, fingerprint)` is what makes idempotent
  imports work. Replacing this index is a v3-to-v4 migration that
  re-derives every fingerprint.
- **`transactions.enriched_from`** — append-only JSON column tracking
  source provenance across enrichments. Never overwritten in place; new
  enrichments append a new entry.
- **`transactions.pair_transaction_id`** — the chain + transfer pair
  pointer; written only by the Chains resolver and the Transfers
  detector (see [Chain resolution](chain-resolution.md)).
- **`transactions.payment_type`** — typed enum; the
  `noPaymentTypeStringLeak` arch invariant requires every PaymentType
  string literal to live inside the enum class.
- **`transactions.raw_payload`** — the per-row raw parser output kept
  for debugging + future re-derivation. Never read by application code
  at runtime; consumed only by the `/dev/console` row-inspector and by
  re-derivation migrations.

## The state columns and their machines

The arch invariants enforce that each `state` column has exactly one
mutator:

| Table.column | Sole mutator |
| --- | --- |
| `recurring_series.state` | `Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine` |
| `drift_alerts.state` | `Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine` |
| `inbox_scan_state.state` | `Modules\EmailScan\Internal\StateMachines\InboxScanStateMachine` |
| `inboxes.backfill_progress` | `Modules\EmailScan\Internal\StateMachines\InboxScanStateMachine` |
| `card_statements.state` | `Modules\Ledger\Internal\StateMachines\CardStatementStateMachine` |
| `user_recovery_codes.state` | `Modules\Auth\Internal\Recovery\UserRecoveryCodeStateMachine` |

Direct UPDATEs to these columns from anywhere else fail the
`BoundaryArchTest` invariant. The transitions tables
(`recurring_series_transitions`, `drift_alert_transitions`) are the
audit log of state changes — every transition writes a row capturing
the from-state, to-state, timestamp, and the user-or-system actor.

## The cross-module foreign-key map

The FK shape that matters at the architecture level:

- `transactions.user_id` → `users.id`
- `transactions.account_id` → `accounts.id`
- `transactions.counterparty_id` → `counterparties.id` (nullable)
- `transactions.category_id` → `categories.id` (nullable)
- `transactions.pair_transaction_id` → `transactions.id` (nullable, self-reference)
- `transactions.import_run_id` → `import_runs.id`
- `card_statement_credits.card_statement_id` → `card_statements.id`
- `card_statement_credits.transaction_id` → `transactions.id` (the ICS-line FK back to the canonical row)
- `chain_links.linking_transaction_id` + `chain_links.linked_transaction_id` → `transactions.id`
- `recurring_series_occurrences.transaction_id` → `transactions.id`
- `drift_alerts.recurring_series_id` → `recurring_series.id`
- `inbox_messages.inbox_id` → `inboxes.id`
- `forecast_runs.user_id` → `users.id`; `result_json` carries no FKs (snapshot at run time)

Every cross-module FK respects the read-only / write-only contracts
the arch invariants enforce: Chains reads `transactions` and writes
`chain_links`; Forecasting reads everything and writes
`forecast_runs`; etc.

## Migrations are append-only

The convention: never modify a shipped migration. Schema changes land
as new migration files, never as in-place edits. The
`rederive_fingerprints_to_v3.php` migration is the canonical example —
a v2-to-v3 fingerprint composer change shipped as a separate
migration that re-derived every transaction's fingerprint rather than
rewriting the original `create_transactions_table` migration.

This matters because users who installed v1.0 and upgrade to v2.0 run
the new migrations on top of their existing v1.0 schema. The
migrations have to apply cleanly to a populated database; the only
way to guarantee that is for every change to live as its own forward
migration.
