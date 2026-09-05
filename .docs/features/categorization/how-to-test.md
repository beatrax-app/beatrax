# `Categorization` — how to test

Practical recipes for exercising the `Categorization` module in isolation.

## Unit tests

- **Location:** `Modules/Categorization/tests/Unit/`
- **What they test:** the matcher's ordering and determinism —
  ascending `priority` then `id`, actions by `position` then `id`, and
  a byte-identical list on a repeat call (`RuleEngineOrderingTest`);
  the condition matcher operator by operator across the text, amount
  and date value types and both combinators, plus the three rules that
  never fire — inactive, foreign-user, and no conditions at all
  (`RuleEngineConditionMatchingTest`); an amount bound never reaching
  a row settled in another currency
  (`AnAmountRuleDoesNotReachAnotherCurrencyTest`); the default-tree
  seeder's idempotence + global scope (`DefaultCategoryTreeSeederTest`);
  the default-rule seeder's per-user write + slug resolution
  (`DefaultCategorizationRuleSeederTest`); the merchant-memory
  query's batching shape (`MerchantMemoryQueryBatchTest`); the
  uncategorised-triage query's user-scoped result set
  (`UncategorizedTriageQueryTest`).
- **Common stubs:** the two `RuleEngine` tests build rules through the
  Eloquent models against a refreshed database and hand `match()` a
  synthetic `RuleMatchInput`. No HTTP layer is involved.

## Feature tests

- **Location:** `Modules/Categorization/tests/Feature/`
- **What they test:**
  - The merchant-memory fallback against a real DB — recency ahead
    of occurrence count, the empty-counterparty sentinel, and a
    foreign user's memory never firing (`RuleEvaluatorTest`).
  - The applier's two modes, last-writer-wins per action type, and
    the `manual` provenance skip (`RuleApplierActionsTest`).
  - The re-apply job over history, including its split and
    reconciled guards (`ReapplyRulesJobTest`).
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

- No invariant names this module and `transactions` together. The one
  that reaches it is `crossModuleRawTableWrites` (in
  `tests/Contracts/BoundaryArchTest.php`), which pins Categorization
  for `merchant_memories` and `merchants` and for nothing else — so a
  raw write to `transactions` from here fails the build with the
  offending file named. A new service that needs to change a
  transaction goes through Ledger's Public contracts:
  `UpdatesTransactionCategory`, `ReassignsCounterparty`,
  `SetsTransactionNote`. Reads are unrestricted on purpose, which is
  what lets `RuleApplier` read a note back after asking Ledger to
  write it.
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
  `source=rule`** — memory is a fallback, not a competitor. The stage
  consults it only when no rule fired with a `category` action, so
  either the rule did not match the row or it carries no category
  action. Check its conditions against the row and that it is still
  `active`, then run
  `vendor/bin/pest Modules/Categorization/tests/Unit/RuleEngineConditionMatchingTest.php`
  to confirm the operator semantics.
- **The provenance panel draws nothing after a manual correction** —
  it renders the rule card only for `source=rule`. A `source=memory`
  row gets the memory card and a `source=manual` row gets nothing at
  all, both by design. Run
  `vendor/bin/pest Modules/Categorization/tests/Feature/CategorizationProvenancePanelTest.php`
  to see the three variants.
- **A test failing with an unknown-value trigger error** — paired
  BEFORE INSERT / BEFORE UPDATE triggers reject any value outside the
  enum on `rule_conditions.field`, `rule_conditions.op`,
  `rule_conditions.value_type` and `categorization_rules.combinator`.
  Confirm the action layer is producing one of the allowed values; the
  trigger is the second layer behind the action-layer validation.
- **Merchant memory rows not appearing after a reclassify** — the
  listener find-or-creates the `merchants` row its NOT NULL FK points
  at, so a missing merchant is no longer a reason. What still stops it
  is a transaction whose `counterparty_normalized` is empty or the
  `CounterpartyKey::NONE` sentinel, and an un-categorise (a null
  `categoryId`), both of which return before any write. Inspect
  `transactions.counterparty_normalized` first; if it holds a value,
  check it against `merchants.normalized_name`, since the two must be
  derived the same way to join.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

- **Every matching rule fires, and the last one visited wins per
  action type.** `RuleEngine::match()` evaluates the whole active set
  in ascending `priority` then `id` and returns all of it; `RuleApplier`
  folds those actions into one desired action per type, so the
  `category`, `counterparty`, `note` or `tax_tag` that lands is the one
  from the **highest** `priority` rule, with the highest `id` breaking
  a tie. There is no score anywhere in this path. The full account is
  [Rule evaluation order](rule-evaluation-order.md).
  (`tests/Unit/RuleEngineOrderingTest.php`,
  `tests/Feature/RuleApplierActionsTest.php`)
- **Merchant memory is a fallback, never a competitor.**
  `ApplyAutoCategoryStage` calls `RuleEvaluator::lookupMemory()` only
  when no fired rule carried a `category` action, and the row it takes
  is the most recently seen one — `last_seen_at` first, then
  `occurrence_count`, then `id` — so a fresh correction outranks a
  stale memory with a bigger count.
  (`tests/Feature/RuleEvaluatorTest.php`,
  `tests/Feature/ApplyAutoCategoryStageTest.php`)
- **Neither layer crosses the user boundary.** Every rule, condition,
  action, memory and merchant pull is scoped by `where('user_id',
  $user->id)`; no other user's rule can fire for the current user.
  (`tests/Unit/RuleEngineConditionMatchingTest.php`,
  `tests/Feature/RuleEvaluatorTest.php`)
- **`ApplyAutoCategoryStage` never aborts an import.** On any exception
  from either layer, it logs a warning and returns
  `AutoCategorizationOutcomeDto::manual($tx)` so the row lands in the
  triage queue rather than the import bailing.
  (`tests/Feature/ApplyAutoCategoryStageTest.php`)
- **`AssignCategory` never writes to `transactions` directly.** Every
  successful manual categorisation routes through Ledger's
  `UpdatesTransactionCategory`. A raw write from here would be caught
  by `crossModuleRawTableWrites`, since this module is pinned for
  `merchant_memories` and `merchants` only.
  (`tests/Feature/AssignCategoryTest.php`)
- **`TransactionCategorized` fires exactly once per successful
  reclassify.** No double-dispatch on the same write; no dispatch when
  the underlying Ledger updater reports zero rows affected.
  (`tests/Feature/MerchantMemoryWriterTest.php`)
- **The "should the rule change too?" question has exactly one
  surface.** `CategorizationProvenancePanel` draws it inline on the
  transaction detail page from the row's own provenance; no layout
  mounts a second surface for it.
  (`tests/Feature/CategorizationProvenancePanelTest.php`)
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
  `ApplyAutoCategoryStage` bumps `hits_count` with an atomic `UPDATE`
  that adds one to the stored value, never a read-modify-write, and
  runs the whole bump loop inside one DB transaction so a mid-loop
  throw rolls every increment for the row back.
  (`tests/Feature/ApplyAutoCategoryStageTest.php`)
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

- **Empty rule corpus** — `RuleEngine::match` returns an empty
  `MatchedRule` list, so no category action fires and no `hits_count`
  is bumped. That on its own does not degrade the row to manual:
  merchant memory is still consulted afterwards, and only when
  `RuleEvaluator::lookupMemory` ALSO returns null does the stage
  return `AutoCategorizationOutcomeDto::manual($folded)`. Either way
  the import succeeds.
- **Memory candidate but counterparty is the empty sentinel** —
  `CounterpartyKey::NONE` short-circuits the memory JOIN, so the row
  falls through to manual unless a fired rule already set a category.
- **Rule condition with an unknown `op` or `value_type`** (e.g. a row
  that somehow bypassed the DB trigger) — `RuleEngine` reads that
  condition as false rather than throwing, so a corrupt row disables
  its own rule instead of taking down the run.
- **Buggy user-authored rule throwing inside the matcher** — caught
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
  `TransactionCategorized`; the action is a no-op.
- **Corrupt JSON in `auto_category_provenance`** —
  `AssignCategory::readPriorProvenance` swallows the `JsonException`
  and returns `null`, so `CategorizationProvenancePanel` renders the
  `none` variant (best-effort audit metadata; a corrupt blob must not
  crash the detail page).

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
  Documented in [categorization architecture](../../architecture/categorization.md#the-receipt-vs-statement-enrichment-conflict-resolver).
- No environment config keys; the module has no behaviour that varies
  by `BEATRAX_DEV_MODE` or any other runtime flag.
