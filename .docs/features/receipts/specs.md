# `Receipts` — specs

The behavioural contract for the `Receipts` module.

## Behavioral contracts

- **`RecordReceipt` is the SOLE sanctioned entry point for
  receipt processing.** Every inbox-fetched message and every
  user-dropped `.eml` flows through it; the matcher registry
  is internal to the action.
- **`MatcherRegistry` returns the highest-priority matching
  matcher.** Matchers are sorted by `priority()` descending at
  bind time; first `matches($input)` true wins.
- **A receipt with no matching matcher logs and skips.** No
  exception is thrown; the `MatchOutcomeDto::miss()` static
  is the documented no-match return shape. The matcher_key
  column on the source row stays NULL.
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
  subscriber owns them ([`Import`](../import/specs.md) owns
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
  sender** — `MatchOutcomeDto::miss()`; the row is logged
  with `matcher_key = NULL`; no enrichment fires.
- **A user-dropped `.mbox` containing several receipts** —
  `MboxIterator::iter($file)` yields each message; each is
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
  routes through `WizardEmailFileStep`.
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
  - [`Core`](../core/specs.md) — `Clock`, `BelongsToUser`.
  - [`EmailScan`](../email-scan/specs.md) — reads
    `InboxMessage` + the `.eml` blob via `EmlBlobStore`.
  - [`Import`](../import/specs.md) — calls
    `ApplyEnrichments`; reacts to `TransactionImported`.
  - [`Ledger`](../ledger/specs.md) — calls
    `RecordsStatementSummary`.
  - [`Desktop`](../desktop/specs.md) — `FileOpenedFromOs`
    event + `PendingFileIntent` store.
  - [`Categorization`](../categorization/specs.md) — reads /
    writes `pending_enrichment_conflicts`.
- **Depended on by**
  - [`Chains`](../chains/specs.md) — subscribes to
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
