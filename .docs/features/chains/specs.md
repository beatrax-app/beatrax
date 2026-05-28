# `Chains` — specs

The behavioural contract for the `Chains` module.

## Behavioral contracts

- **The resolver pass is read-only over `transactions`.** No code in
  `Modules\Chains\` writes the `transactions` table; enforced by the
  `noResolverWritesTransactions` arch invariant. The resolver expresses
  its conclusions as `chain_links` rows.
- **The state machine is the sole mutator of
  `card_statements.state`.** No write outside
  `Internal\CardStatementStateMachine` may change that column;
  enforced by the `noCardStatementStateWritesOutsideMachine` arch
  invariant.
- **Every successful resolver pass is idempotent.** Re-running the
  resolver against the same data produces the same `chain_links` rows;
  the `evidence.signature_hash` collision is a no-op. (`tests/Contracts/ChainResolutionIdempotencyTest.php`)
- **Per-user resolver passes never overlap.**
  `ResolveChainLinksJob::uniqueId() = $userId` plus
  `ShouldBeUniqueUntilProcessing` prevents two concurrent runs for the
  same user. The lock releases as soon as `handle()` begins, so a new
  dispatch can queue while the prior pass executes.
  (`tests/Feature/DatabaseQueueConcurrencyTest.php`)
- **Cross-user chain reads / writes return 404, not 403.**
  `ConfirmChainLink` and the Livewire pages all return
  `NotFoundHttpException` for any row not matching
  `(id, user_id)`. (`tests/Feature/CrossUserChainLinkIsolationTest.php`)
- **The dispatcher fires AFTER the import's outer transaction
  commits.** Calling `DispatchesChainResolution::dispatch()` inside the
  transaction closure would let the worker observe rolled-back state.
  The dispatcher's call site in `ConfirmImport` runs in the
  post-commit boundary; tests assert the queue is empty mid-transaction
  and populated post-commit. (`tests/Feature/ResolveChainLinksJobTest.php`)
- **Hint-shaped rows (NULL `to_transaction_id`) cannot be promoted.**
  `ConfirmChainLink` raises
  `ChainLinkRequiresConcretePartnerException` rather than triggering a
  SQLSTATE 23000. (`tests/Feature/DismissChainLinkHintTest.php`,
  `tests/Feature/ConfirmChainLinkTest.php`)
- **The auto-promotion learning loop fires at three confirmations per
  signature.** Three confirmed rows sharing an
  `evidence.signature_hash` flip every remaining same-signature
  candidate to `confirmed` with `resolver='rule'` in one DB
  transaction. (`tests/Feature/ConfirmChainLinkTest.php`)
- **`CardStatementUpserter::upsert()` runs BEFORE the dispatcher** in
  the `ConfirmImport` post-commit boundary, so the resolver always
  sees fresh `card_statements` rows for every ICS PDF the user just
  imported. (`tests/Feature/CardStatementUpsertOnImportTest.php`)
- **A `JobFailed` event for a `ResolveChainLinksJob` final-retry
  failure flips the audit row to `failed` with a truncated
  `last_error`.** The listener is the single sanctioned path for the
  failure transition; `chain_resolution_runs` rows that observed
  `running` but no `complete` and no `failed` are detected as orphans
  by the wizard. (`tests/Feature/FailedChainResolutionToastTest.php`,
  `tests/Feature/ChainResolutionRunsLifecycleTest.php`)
- **The schema enforces the `kind` and `state` allow-lists via paired
  BEFORE INSERT / BEFORE UPDATE triggers.** A bug in the application
  layer that produced an unknown value fails loud at the DB.
  (`tests/Feature/ChainLinksSchemaTest.php`,
  `tests/Feature/ChainResolutionRunsSchemaTest.php`)
- **The PayPal funding resolver does not double-pair the same ASN
  funder.** The ASN-direct arm only considers ASN rows not already
  cited as the `to` side of another `paypal_funding` chain_link, so
  two same-amount PayPal expenses never both claim the same ASN
  withdrawal. (`tests/Unit/Resolvers/PaypalFundingResolverAsnDirectArmTest.php`)

## Edge cases

- **Empty input** — `IcsSettlementResolver::resolve()` and
  `PaypalFundingResolver::resolve()` are no-ops; the audit row flips
  cleanly to `complete` with `linked_count = 0`.
- **Exceeded tolerance** — the ICS resolver writes a candidate row
  with `to_transaction_id = NULL` and
  `evidence.tolerance_used = 'exceeded'`. The DB-layer trigger pair
  permits a NULL endpoint only in `state='candidate'` for this kind +
  tolerance combination.
- **Concurrent job dispatches for the same user** — the second
  dispatch's unique-lock prevents enqueueing; the audit row inserted
  by the dispatcher remains `pending` and is reaped by the periodic
  cleanup job (see `Modules\Chains\Internal\Jobs` future work).
- **Worker hard-crashes mid-run** — the audit row stays `running`
  with no `completed_at`. The wizard's `wire:poll` surfaces the
  orphan with an "in progress" message; a manual retry re-dispatches.
- **A candidate's `to_transaction_id` has been deleted** — the FK is
  `cascadeOnDelete`, so the `chain_link` is dropped along with the
  transaction. The user never sees a dangling link.
- **`whereJsonContains('evidence->signature_hash', $hash)`** — the
  underlying SQLite JSON1 contract is exercised by
  `ChainLinksJsonContainsSmokeTest`. If a future SQLite build
  regresses, a documented one-line fallback to `whereRaw("json_extract(evidence,
  '$.signature_hash') = ?", [$hash])` is recorded in the action
  source.
- **A receipt-driven hint references a transaction that hasn't been
  imported yet** — the `CreateChainLinkFromHint` listener runs in-line
  after `RecordReceipt` persists the canonical transaction, so the
  FK on `chain_links.from_transaction_id` always binds.
- **A user runs `/import` and the chain pass right after a previous
  pass failed** — the dispatcher inserts a fresh `pending` row; the
  prior `failed` row remains as an audit trail.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/specs.md) — `User`, `Clock`, `LockStore`,
    `BelongsToUser`.
  - [`Ledger`](../ledger/specs.md) — `Transaction`, `Account`,
    `FingerprintComposer` (signature hashing input).
  - [`Import`](../import/specs.md) —
    `ResolvesKnownCounterpartyIban` contract (alias bridge between
    institution IBANs and the user's account ids).
  - [`Transfers`](../transfers/specs.md) — `PairsTransferLegs` +
    `PairLookup` (self-transfer pair detection feeds the ASN-direct
    arm).
  - [`Receipts`](../receipts/specs.md) — `ChainHintDetected` event
    raised by `RecordReceipt`.
- **Depended on by**
  - [`Import`](../import/specs.md) — `ConfirmImport` calls
    `UpsertsCardStatements` then `DispatchesChainResolution` in the
    post-commit boundary.
  - [`Forecasting`](../forecasting/specs.md) — `CardStatementQuery`
    feeds the next-settlement / forecast tile reads.
  - [`Recurring`](../recurring/specs.md) — `SeriesFunderLink` DTO
    feeds the recurring-series funder display.
  - The top-nav layout in `Core`'s `livewire.top-nav` view — the
    badge composer in this module's provider injects the open-candidate
    count.

## Configuration + feature flags

- The chain-link `kind`, `state`, `resolver`, and `confidence`
  semantics are documented in the
  [chain-resolution architecture topic](../../architecture/chain-resolution.md).
- The `AUTO_PROMOTE_THRESHOLD = 3` constant in `ConfirmChainLink` is
  the only tunable; lowering it would auto-promote on the first or
  second observation, which the project explicitly rejected (the user
  must teach the loop three times before it acts on its own).
- The job's retry budget (`tries = 3`, `backoff = [60, 300, 900]`) is
  fixed in the job class — increasing it without bound would let a
  resolver bug run indefinitely.
- No per-user preference flag; the resolver runs on every
  `ConfirmImport` for every user.
