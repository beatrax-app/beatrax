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
- The repo-wide `noResolverWritesTransactions` arch invariant — no
  class under `Modules\Chains\` may import
  `Modules\Ledger\Models\Transaction` for write.
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
