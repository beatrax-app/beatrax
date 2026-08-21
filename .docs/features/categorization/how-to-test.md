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

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Categorization` module.

## Behavioral contracts

- **The rule evaluator picks at most one winner per transaction, by
  specificity score.** Equals beats memory beats starts_with beats
  contains; at equal score, the rule beats the memory. The score
  algorithm is documented in
  [architecture/categorization](../../architecture/categorization.md).
  (`tests/Unit/RuleEvaluatorSpecificityTest.php`,
  `tests/Feature/RuleEvaluatorTest.php`)
- **The rule evaluator never crosses the user boundary.** Every
  rule + memory + merchant lookup is scoped by `where('user_id',
  $user->id)`; no other user's rule can fire for the current user.
  (`tests/Feature/RuleEvaluatorTest.php`)
- **`ApplyAutoCategoryStage` never aborts an import.** On any evaluator
  exception, it logs a warning and returns
  `AutoCategorizationOutcomeDto::manual($tx)` so the row lands in the
  triage queue rather than the import bailing.
  (`tests/Feature/ApplyAutoCategoryStageTest.php`)
- **`AssignCategory` never writes to `transactions` directly.** Every
  successful manual categorisation routes through Ledger's
  `UpdatesTransactionCategory` (the sole `transactions` mutator).
  Enforced by the `noCategorizationWritesTransactions` arch invariant.
  (`tests/Feature/AssignCategoryTest.php`)
- **`TransactionCategorized` fires exactly once per successful
  reclassify.** No double-dispatch on the same write; no dispatch when
  the underlying Ledger updater reports zero rows affected.
  (`tests/Feature/MerchantMemoryWriterTest.php`)
- **`CategorizationDiverged` fires only when a manual pick contradicts
  a still-active rule.** Memory divergence is silent — memory grows
  transparently via `MerchantMemoryWriter` on every
  `TransactionCategorized`. The static
  `CategorizationDiverged::fromProvenance` is the single decision point.
  (`tests/Feature/CorrectionDivergenceTest.php`)
- **The seeded rule corpus targets universal merchants only — no
  personal identifiers.** A live-distribution sampled fixture
  (`tests/Fixtures/seed-rules-live-distribution.php`) drives an
  end-to-end auto-categorisation gate that the seed must clear ≥40%
  on representative data.
  (`tests/Feature/SeedRulesEndToEndCategorizationTest.php`)
- **The default category tree is global.** Rows are seeded with
  `user_id = NULL` so every user inherits the same starting set; a
  user-owned override with the same slug is never demoted to global by
  a re-run.
  (`tests/Unit/DefaultCategoryTreeSeederTest.php`,
  `tests/Unit/DefaultCategorizationRuleSeederTest.php`)
- **The matched-rule counter is monotonic under concurrent imports.**
  `ApplyAutoCategoryStage` uses `UPDATE … SET hits_count = hits_count
  - 1` (atomic), not read-modify-write.
- **The CRUD surface for rules respects per-user scope.**
  `CreateCategorizationRule` / `UpdateCategorizationRule` /
  `DeleteCategorizationRule` filter by `user_id`; a cross-user mutate
  attempt is invisible / 404-equivalent.
  (`tests/Feature/RuleFormModalTest.php`, `tests/Feature/RulesPageTest.php`)
- **Triage pages render only the current user's uncategorised rows.**
  `UncategorizedTriageQuery` and the `TriageInbox` Livewire page both
  filter by `user_id`. (`tests/Feature/TriagePageTest.php`,
  `tests/Unit/UncategorizedTriageQueryTest.php`)

## Edge cases

- **Empty rule corpus** — `RuleEvaluator` returns
  `RuleEvaluationOutcome::none()`; the stage degrades to
  `manual($tx)`; the import succeeds.
- **Memory candidate but counterparty is the empty sentinel** —
  `CounterpartyKey::NONE` short-circuits the memory JOIN; only
  rule candidates participate in scoring.
- **Rule with an unknown `field` or `match` value** (e.g. a row that
  somehow bypassed the DB trigger) — silently skipped by the evaluator;
  it never picks an unsanctioned operator.
- **Buggy user-authored rule throwing inside the evaluator** — caught
  by `ApplyAutoCategoryStage`; the import row falls back to manual; the
  user sees it in triage. The warning is logged with
  `(user_id, source_format, source_row_index, exception, message)` so
  a developer can reproduce.
- **Concurrent imports both matching the same rule** — both call the
  atomic `UPDATE` so `hits_count` is monotonic. The two rows land with
  the same `auto_category_provenance` and the read-side query sees the
  rule's hits incremented by two.
- **Re-running the default-tree seeder on an existing install** —
  idempotent via `updateOrCreate` keyed on `(slug, user_id = NULL)`. A
  user-owned category with the same slug is never overwritten because
  the lookup is scoped to `user_id IS NULL`.
- **Manual reclassify reports zero rows affected (e.g. the row
  vanished)** — `AssignCategory` does NOT dispatch
  `TransactionCategorized` and does NOT dispatch
  `CategorizationDiverged`; the action is a no-op.
- **Corrupt JSON in `auto_category_provenance`** —
  `AssignCategory::readPriorProvenance` swallows the `JsonException`
  and returns `null`, so the divergence path is skipped (best-effort
  audit metadata; a corrupt blob must not crash a reclassify request).

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`, `UserInstalled`
    event (the seed listeners subscribe to it).
  - [`Ledger`](../ledger/how-to-test.md) — `Category` model,
    `UpdatesTransactionCategory` contract (the only sanctioned writer
    of `transactions.category_id`), `CanonicalTransaction` DTO.
  - [`Import`](../import/how-to-test.md) — the `ImportPipeline` stage chain
    `ApplyAutoCategoryStage` slots into.
- **Depended on by**
  - [`Import`](../import/how-to-test.md) — injects `AppliesAutoCategory` into
    the pipeline.
  - [`Counterparties`](../counterparties/how-to-test.md) — the
    `CounterpartyResolver` reads the same `categorization_rules` table
    indirectly via the matchers that look up counterparties from
    descriptions.
  - The triage UI in the Web layer — calls
    `UncategorizedTriageQuery` + `AssignCategory`.

## Configuration + feature flags

- `users.receipt_conflict_resolution` — per-user preference column
  (added by `2026_05_17_010004_add_receipt_conflict_resolution_to_users`)
  that the receipt-vs-statement enrichment conflict resolver respects.
  Documented in [categorization architecture](../../architecture/categorization.md#receipt-vs-statement-enrichment).
- No environment config keys; the module has no behaviour that varies
  by `BEATRAX_RUNTIME` or any other runtime flag.
