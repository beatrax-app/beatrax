# `Categorization` — how to test

Practical recipes for exercising the `Categorization` module in isolation.

## Unit tests

- **Location:** `Modules/Categorization/tests/Unit/`
- **What they test:** the specificity scoring table
  (`RuleEvaluatorSpecificityTest`); the default-tree seeder's
  idempotence + global scope (`DefaultCategoryTreeSeederTest`); the
  default-rule seeder's per-user write + slug resolution
  (`DefaultCategorizationRuleSeederTest`); the merchant-memory
  query's batching shape (`MerchantMemoryQueryBatchTest`); the
  uncategorised-triage query's user-scoped result set
  (`UncategorizedTriageQueryTest`).
- **Common stubs:** the specificity test exercises `RuleEvaluator`
  against an in-memory rule + memory list and a synthetic
  `CanonicalTransaction`. No HTTP layer is involved.

## Feature tests

- **Location:** `Modules/Categorization/tests/Feature/`
- **What they test:**
  - The full evaluator behaviour against a real DB
    (`RuleEvaluatorTest`).
  - The stage's exception-fall-through + hits-count atomic increment
    (`ApplyAutoCategoryStageTest`).
  - The `AssignCategory` action's delegation to Ledger and event
    dispatch (`AssignCategoryTest`).
  - The merchant-memory listener's upsert + occurrence-count bump
    (`MerchantMemoryWriterTest`).
  - The receipt-vs-statement conflict resolver
    (`ApplyEnrichmentsConflictTest`).
  - The CRUD UI flows (`RulesPageTest`, `RuleFormModalTest`,
    `TriagePageTest`, `CategorizationProvenancePanelTest`).
  - The divergence flow end-to-end (`CorrectionDivergenceTest`).
  - The seeded-rule-set ≥40% gate against a sampled real-world
    distribution
    (`SeedRulesEndToEndCategorizationTest`, fixture in
    `tests/Fixtures/seed-rules-live-distribution.php`).
  - The cross-module link from triage to counterparties
    (`TriageCounterpartyLinkTest`).
- **Setup:** every test uses `RefreshDatabase`. Tests that need a
  populated category tree usually fire `UserInstalled` first so the
  default-tree seeder runs.

## Contract / arch invariants

- The repo-wide `noCategorizationWritesTransactions` arch invariant
  (in `tests/Contracts/BoundaryArchTest.php`) — forbids any class
  under `Modules\Categorization\` from importing
  `Modules\Ledger\Models\Transaction` for write. Adding a new
  Categorization service that needs to update `transactions` must go
  through Ledger's `UpdatesTransactionCategory` contract instead.
- `tests/Feature/SeedRulesEndToEndCategorizationTest.php` — the live-
  distribution ≥40% gate. Reduces the risk of a default rule set
  regression that lands an install where most transactions arrive
  uncategorised.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Categorization/tests

# Just the seeded-rule-set gate
vendor/bin/pest Modules/Categorization/tests/Feature/SeedRulesEndToEndCategorizationTest.php

# Stop on first failure for a focused debug session
vendor/bin/pest Modules/Categorization/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **The seeded-rule-set gate dropped below 40%** — the live-distribution
  fixture (`tests/Fixtures/seed-rules-live-distribution.php`) is a
  sampled snapshot. If the gate fails, the typical cause is either a
  newly-renamed merchant (e.g. a brand changed its display string) or
  a regression in the seed list. Read the failing assertion's diff
  to see which rows landed uncategorised, then either add a fixture
  row covering the new spelling or extend the seed list (`default-
  categorization-rules.php`).
- **Auto-category provenance shows `source=memory` but the user expected
  `source=rule`** — the rule and the memory candidate scored the same;
  the tiebreaker is "rule wins" but the rule must be the first
  candidate evaluated. Run
  `vendor/bin/pest Modules/Categorization/tests/Unit/RuleEvaluatorSpecificityTest.php`
  to confirm the score table.
- **`CategorizationDiverged` not firing on a manual reclassify against
  a rule's category** — the prior provenance is probably
  `source=memory`, not `source=rule`. Memory divergence is silent by
  design (see `CorrectionDivergenceTest`); only rule divergence opens
  the toast.
- **A test failing with "unknown field/match" trigger error** — the
  paired BEFORE INSERT / BEFORE UPDATE triggers on
  `categorization_rules.field / match` reject any value outside the
  enum. Confirm the action layer is producing one of the allowed
  values; the trigger is the second layer behind the action-layer
  validation.
- **Merchant memory rows not appearing after a reclassify** — the
  transaction probably has no resolved merchant. `MerchantMemoryWriter`
  skips the upsert in that case; the typical cause is a counterparty-
  normalization mismatch in the source row. Inspect
  `transactions.counterparty_normalized` against `merchants.normalized_name`.
