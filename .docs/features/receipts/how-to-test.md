# `Receipts` — how to test

Practical recipes for exercising the `Receipts` module in
isolation.

## Unit tests

- **Location:** `Modules/Receipts/tests/Unit/` (when present)
- **What they test:** the per-matcher parser against fixture
  HTML / text bodies for representative senders; the
  `EmlMimeReader::parse` against `.eml` fixtures; the
  `MboxIterator` against a multi-receipt `.mbox`; the
  `MatcherRegistry` priority-sort logic.
- **Common stubs:** matchers are pure-function over the parsed
  MIME payload; no stubs needed.

## Feature tests

- **Location:** `Modules/Receipts/tests/Feature/`
- **What they test:**
  - The migration cleanliness (`Phase7MigrationsTest`).
  - `RecordReceipt` end-to-end for each matcher (assert
    `ApplyEnrichments` called; assert
    `RecordsStatementSummary` called; assert
    `ChainHintDetected` raised for each hint).
  - The `WizardEmailFileStep` for a drop-an-.eml flow.
  - The `ReceiptConflictToast` for a pending conflict.
  - The cross-user 404 posture on every action.
  - The chain-hint dispatcher (`DispatchChainHintsFromReceipt`)
    raising one `ChainHintDetected` per hint.
  - The drop-an-.eml extension filter
    (`HandleFileOpenedFromOs`).

## Contract / arch invariants

- The repo-wide `noReceiptsWritesToTransactions` — forbids
  any class in `Modules\Receipts\` from importing
  `Modules\Ledger\Models\Transaction` for write.
- The repo-wide `noReceiptsWritesToStatementSummaries` —
  forbids any class outside `Modules\Ledger\Public\Services\StatementSummaryWriter`
  from writing `statement_summaries`. This module calls the
  Ledger writer.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Receipts/tests

# Just one matcher
vendor/bin/pest Modules/Receipts/tests --filter "PaypalReceiptMatcher"

# Just the chain-hint dispatch
vendor/bin/pest Modules/Receipts/tests --filter "ChainHint"

# Stop on first failure
vendor/bin/pest Modules/Receipts/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A receipt that should match returns
  `MatchOutcomeDto::miss()`** — walk the matcher registry:
  the highest-priority matcher's `matches($input)` returned
  false. The most common cause is a header / sender check
  on the matcher that didn't fire (the registered domain
  changed, the From header has unexpected whitespace).
  Inspect the `MatcherInputDto` headers in a debugger.
- **A new matcher not appearing in the registry** — confirm
  the FQN was appended to `MATCHER_FQNS` in the provider and
  that the class is autoloadable (the `class_exists()` gate
  silently skips missing classes). Run
  `php artisan tinker` →
  `app(MatcherRegistry::class)->all()` to see the active
  list.
- **A chain hint not raising `ChainHintDetected`** —
  `DispatchChainHintsFromReceipt` listens for
  `TransactionImported`. Tail `/dev/queue` and `/dev/logs`
  to confirm the transaction was actually persisted; the
  listener does not fire pre-persist.
- **A `.eml` drop landing on the wrong wizard step** —
  `Desktop::FileOpenedFromOs` is consumed by multiple
  modules (`Import` for `.csv`, `Receipts` for `.eml`).
  Confirm the extension is `.eml` (uppercase `.EML` is not
  matched today — extend the filter if needed).
- **A receipt-conflict toast appearing for every receipt** —
  the `pending_enrichment_conflicts` write should only fire
  on actual disagreements (parsed amount diverges from
  transaction amount, or parsed merchant disagrees with an
  existing categorisation). If the toast fires for every
  receipt, the conflict-detection threshold is too tight or
  the existing categorisation source is wrong.
- **`Phase7MigrationsTest` failing after a schema change** —
  the test asserts the three Phase 7 migrations land cleanly
  on a fresh SQLite. A failure usually means a later
  migration depends on a column added here that the test
  doesn't seed; the fix is usually to extend the test's
  setup, not weaken the migration.
