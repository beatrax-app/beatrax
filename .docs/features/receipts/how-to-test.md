# `Receipts` — how to test

Practical recipes for exercising the `Receipts` module in
isolation.

## Unit tests

- **Location:** `Modules/Receipts/tests/Unit/` (when present)
- **What they test:** the per-matcher parser against fixture
  HTML / text bodies for representative senders; the
  `EmlMimeReader::read` against `.eml` fixtures; the
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
  `MatchOutcomeDto::unmatched()`** — walk the matcher registry:
  no matcher's `canHandle($msg)` returned true, so
  `MatcherRegistry::dispatch` fell through to `unmatched()`.
  The most common cause is a header / sender check on the
  matcher that didn't fire (the registered domain
  changed, the From header has unexpected whitespace).
  Inspect the `MatcherInputDto` headers in a debugger.
- **A new matcher not appearing in the registry** — confirm
  the FQN was appended to `MATCHER_FQNS` in the provider and
  that the class is autoloadable (the `class_exists()` gate
  silently skips missing classes). Run
  `php artisan tinker` →
  `app(MatcherRegistry::class)->supportedKeys()` to see the
  active list.
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

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Receipts` module.

## Behavioral contracts

- **`RecordReceipt` is the SOLE sanctioned entry point for
  receipt processing.** Every inbox-fetched message and every
  user-dropped `.eml` flows through it; the matcher registry
  is internal to the action.
- **`MatcherRegistry` returns the highest-priority matching
  matcher.** Matchers are sorted by `priority()` descending at
  bind time; `dispatch` stops at the first `canHandle($msg)`
  true and returns that matcher's `match($emlRaw)` outcome.
- **A receipt with no matching matcher is recorded as
  unmatched, not skipped.** No exception is thrown and nothing
  is logged; `MatchOutcomeDto::unmatched()` is the documented
  no-match return shape, and `RecordReceipt` stamps the
  `file_imports` row `status = unmatched`. The matcher_key
  column on the source row stays NULL. `skipped($reason)` is a
  different outcome: a matcher DID claim the message and then
  found the body was not a transaction.
- **Adding a new matcher is one constant edit + one class
  ship.** Append the FQN to `MATCHER_FQNS` in the provider;
  ship the class implementing `SenderMatcher`. The provider's
  `class_exists()` gate skips gracefully if the class is
  missing.
- **`ChainHintDetected` fires once per extracted hint.** A
  PayPal receipt with both a funding-card hint AND a
  refund-of hint produces two events.
- **The `DispatchChainHintsFromReceipt` listener runs AFTER
  the canonical transaction is persisted.** It subscribes to
  `Import::TransactionImported`, which is raised by
  `ConfirmImport` AFTER the row is INSERTed. The FK on
  `chain_links.from_transaction_id` therefore always binds
  cleanly when `Chains::CreateChainLinkFromHint` runs.
- **`HandleFileOpenedFromOs` only consumes `.eml` / `.mbox`
  paths.** Other extensions pass through to whichever
  subscriber owns them ([`Import`](../import/how-to-test.md) owns
  `.csv`).
- **Enrichments flow through `Import::ApplyEnrichments`**,
  not directly into `transactions`. The
  `noReceiptsWritesToTransactions` arch invariant blocks any
  direct INSERT/UPDATE.
- **Statement summaries flow through
  `Ledger::RecordsStatementSummary`**, not directly into
  `statement_summaries`.
- **The `pending_enrichment_conflicts` table is the conflict
  audit log.** `RecordReceipt` writes a pending conflict row
  when a parsed receipt disagrees with the existing
  categorisation; `ApplyReceiptConflictResolution` is the sole
  sanctioned resolution writer.
- **Cross-user reads / writes return 404.** Every Public
  query and action filters by `(id, user_id)`.

## Edge cases

- **An `.eml` that parses cleanly but matches no registered
  sender** — `MatchOutcomeDto::unmatched()`; the `file_imports`
  row lands `status = unmatched` with `matcher_key = NULL`; no
  enrichment fires.
- **A user-dropped `.mbox` containing several receipts** —
  `MboxIterator::iterate($file)` yields each message; each is
  processed by `RecordReceipt` independently.
- **A matcher that throws during `match()`** —
  `RecordReceipt` does NOT catch (matchers are project-
  controlled code; a crash should surface as a bug, not be
  silently swallowed); the exception propagates to the queue
  worker's error handler.
- **A receipt whose extracted total disagrees with the
  matched transaction's amount by more than threshold** —
  pending conflict row written; user resolves via the toast.
- **A `.eml` dropped before login** —
  `Desktop::PendingFileIntent` persists; after login the user
  lands on `/desktop/file-staging`; clicking Start import
  redirects to the import wizard.
- **A re-fetched inbox message** — the `matcher_key` column
  on `inbox_messages` is the dedup signal; re-running
  `RecordReceipt` on a row that already carries a matcher_key
  re-runs the matcher (idempotent) but does NOT raise
  duplicate enrichments — `ApplyEnrichments` keys on the
  fingerprint + enrichment payload.
- **A chain hint that references a card the user does not
  own** — `Chains::CreateChainLinkFromHint` writes the hint
  with `to_transaction_id = NULL`; the user dismisses via
  `Chains::DismissChainLinkHint` or the chain resolver later
  populates the link.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `Clock`, `BelongsToUser`.
  - [`EmailScan`](../email-scan/how-to-test.md) — reads
    `InboxMessage` + the `.eml` blob via `EmlBlobStore`.
  - [`Import`](../import/how-to-test.md) — calls
    `ApplyEnrichments`; reacts to `TransactionImported`.
  - [`Ledger`](../ledger/how-to-test.md) — calls
    `RecordsStatementSummary`.
  - [`Desktop`](../desktop/how-to-test.md) — `FileOpenedFromOs`
    event + `PendingFileIntent` store.
  - [`Categorization`](../categorization/how-to-test.md) — reads /
    writes `pending_enrichment_conflicts`.
- **Depended on by**
  - [`Chains`](../chains/how-to-test.md) — subscribes to
    `ChainHintDetected`.
  - The shared layout — renders `ReceiptConflictToast`.

## Configuration + feature flags

- The `receipts.matcher` container tag — adding a matcher is
  one constant edit + one class ship.
- No env flag changes the matcher behaviour; the matchers
  are pure functions over the parsed MIME payload.
- The matcher `priority()` integer is per-matcher; tied
  matchers fall back to registration order. The current
  triple (PayPal / ICS / Google Play) has distinct
  priorities.
