# `Chains` — how to test

Practical recipes for exercising the `Chains` module in isolation.

## Unit tests

- **Location:** `Modules/Chains/tests/Unit/`
- **What they test:** the state-machine transitions
  (`CardStatementStateMachineTest`); the upserter idempotence
  (`CardStatementUpserterTest`); each resolver in isolation
  against fixture inputs (`Resolvers/IcsSettlementResolverTest`,
  `Resolvers/PaypalFundingResolverTest`,
  `Resolvers/PaypalFundingResolverAsnDirectArmTest`,
  `RetypeByAliasResolverTest`); the fixture-parse smoke
  (`FixtureParseSmokeTest`).
- **Common stubs:** the resolver tests inject a stub `Clock`, a real
  in-memory SQLite, and a `LoggerInterface` spy. No HTTP layer is
  involved.
- **Fixture policy:** every resolver fixture mirrors a real-world
  shape; manually-injected unrealistic rows are forbidden (a lesson
  from an earlier wave where a synthetic fixture passed in isolation
  and failed in production).

## Feature tests

- **Location:** `Modules/Chains/tests/Feature/`
- **What they test:**
  - The full job lifecycle, including the audit-row state machine
    (`ResolveChainLinksJobTest`, `ChainResolutionRunsLifecycleTest`).
  - The schema-level enum + NULL-endpoint trigger pairs
    (`ChainLinksSchemaTest`, `ChainResolutionRunsSchemaTest`).
  - The `JobFailed` listener's audit-row flip
    (`FailedChainResolutionToastTest`).
  - Concurrent-job uniqueness on the `database` queue driver
    (`DatabaseQueueConcurrencyTest`).
  - Cross-user 404 posture (`CrossUserChainLinkIsolationTest`).
  - The card-statement back-population migration
    (`CardStatementBackPopulationTest`).
  - The card-statement upsert at import boundary
    (`CardStatementUpsertOnImportTest`).
  - The IBAN alias bridge feeding `IcsSettlementResolver`
    (`IcsSettlementResolverAliasBridgeTest`).
  - The interleaved-account healing scenario
    (`InterleavedAccountChainHealingTest`).
  - The wizard's resolver-status polling
    (`WizardChainResolutionStatusTest`).
  - The review queue + drawer Livewire pages (`ChainReviewQueueTest`,
    `ChainDrawerTest`).
  - The promotion + reject + dismiss actions
    (`ConfirmChainLinkTest`, `RejectChainLinkTest`,
    `DismissChainLinkHintTest`).
  - The cross-module counterparty link
    (`ChainCounterpartyLinkTest`).
  - The Public read APIs (`CardStatementQueryTest`,
    `CardStatementQueryNextSettlementForUserTest`,
    `ChainLinkQueryTest`).
  - The JSON1 `whereJsonContains` contract
    (`ChainLinksJsonContainsSmokeTest`).
- **Setup:** every test uses `RefreshDatabase`. Tests that drive the
  queue do so with `Queue::fake()` or the in-memory worker for the
  uniqueness contract.

## Contract / arch invariants

- `tests/Contracts/ChainResolutionIdempotencyTest.php` — re-running
  the resolver against the same data produces zero new
  `chain_links` rows.
- The repo-wide `crossModuleRawTableWrites` arch invariant — every
  raw-table write `Modules/Chains/` makes against a table it does not
  own is pinned by file, line, and table.
- The repo-wide `noCardStatementStateWritesOutsideMachine` arch
  invariant — only `Internal\CardStatementStateMachine` may write
  `card_statements.state`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Chains/tests

# Just the resolver unit tests
vendor/bin/pest Modules/Chains/tests/Unit/Resolvers

# Just the idempotency contract
vendor/bin/pest Modules/Chains/tests/Contracts/ChainResolutionIdempotencyTest.php

# Stop on first failure for a focused debug session
vendor/bin/pest Modules/Chains/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **The wizard sits on "resolving chains…" forever** — the most
  likely cause is the worker is not running. Start one with
  `php artisan queue:work --once` and watch the
  `chain_resolution_runs` row flip from `running` to `complete`. The
  second-most-likely cause is the worker is running but the
  `JobFailed` event never fired because the failure was not a final
  exhaustion — read `failed_jobs` to confirm.
- **A confirmed chain_link is missing from the chain-drawer** — the
  drawer queries via `ChainLinkQuery::chainTreeFor`. Check the
  underlying SQL with `DB::enableQueryLog()` in a test harness; the
  most common cause is the FK `to_transaction_id` was nulled out by a
  cascade delete that left the from-side intact.
- **Auto-promotion learning loop didn't fire after three
  confirmations** — count the confirmed rows sharing the signature
  hash; a common cause is the evidence JSON's keys are ordered
  differently between two writers, producing two different hashes.
  `ChainLinkInsertHelper` exists precisely to prevent that — confirm
  every INSERT site routes through it.
- **`chain_resolution_runs` lifecycle assertion failing** — read the
  row's transitions in order: `pending` (dispatcher), `running`
  (job's `handle()` start), then `complete` / `failed` (job end or
  `JobFailed` listener). Missing a transition typically means the
  expected event did not fire — e.g. the job throwing inside a
  `transaction()` closure swallows the exception and never reaches
  the listener.
- **A new chain `kind` rejected at INSERT with SQLSTATE 23000** — the
  paired BEFORE INSERT / BEFORE UPDATE triggers enforce the allow-list.
  Extending the kind set requires a migration that drops + recreates
  the triggers (see
  `2026_05_17_010006_extend_chain_links_kind_with_hint_variants.php`
  for the pattern).

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Chains` module.

## Behavioral contracts

- **The resolver pass is read-mostly over `transactions`.** The
  resolver expresses its conclusions as `chain_links` rows; the single
  exception, `RetypeByAliasResolver` retyping `transactions.type`, is
  pinned by `crossModuleRawTableWrites`.
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
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`, `LockStore`,
    `BelongsToUser`.
  - [`Ledger`](../ledger/how-to-test.md) — `Transaction`, `Account`,
    `FingerprintComposer` (signature hashing input).
  - [`Import`](../import/how-to-test.md) —
    `ResolvesKnownCounterpartyIban` contract (alias bridge between
    institution IBANs and the user's account ids).
  - [`Transfers`](../transfers/how-to-test.md) — `PairsTransferLegs` +
    `PairLookup` (self-transfer pair detection feeds the ASN-direct
    arm).
  - [`Receipts`](../receipts/how-to-test.md) — `ChainHintDetected` event
    raised by `RecordReceipt`.
- **Depended on by**
  - [`Import`](../import/how-to-test.md) — `ConfirmImport` calls
    `UpsertsCardStatements` then `DispatchesChainResolution` in the
    post-commit boundary.
  - [`Forecasting`](../forecasting/how-to-test.md) — `CardStatementQuery`
    feeds the next-settlement / forecast tile reads.
  - [`Recurring`](../recurring/how-to-test.md) — `SeriesFunderLink` DTO
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
