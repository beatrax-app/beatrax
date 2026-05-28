# `Categorization` — specs

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
  + 1` (atomic), not read-modify-write.
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
  `NormalizeStage::NO_COUNTERPARTY` short-circuits the memory JOIN; only
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
  - [`Core`](../core/specs.md) — `User`, `Clock`, `UserInstalled`
    event (the seed listeners subscribe to it).
  - [`Ledger`](../ledger/specs.md) — `Category` model,
    `UpdatesTransactionCategory` contract (the only sanctioned writer
    of `transactions.category_id`), `CanonicalTransaction` DTO.
  - [`Import`](../import/specs.md) — the `ImportPipeline` stage chain
    `ApplyAutoCategoryStage` slots into.
- **Depended on by**
  - [`Import`](../import/specs.md) — injects `AppliesAutoCategory` into
    the pipeline.
  - [`Counterparties`](../counterparties/specs.md) — the
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
