# `Categorization` — code

The file-level map for the module.

## Directory layout

```
Modules/Categorization/
├── Public/
│   ├── Contracts/
│   │   ├── AppliesAutoCategory.php
│   │   └── AssignsCategory.php
│   ├── Actions/
│   │   ├── CreateCategorizationRule.php
│   │   ├── UpdateCategorizationRule.php
│   │   ├── DeleteCategorizationRule.php
│   │   └── Concerns/NormalisesRuleInput.php
│   ├── Dto/
│   │   ├── AutoCategorizationOutcomeDto.php
│   │   ├── CategorizationRuleDto.php
│   │   ├── CategoryOption.php
│   │   ├── MerchantMemoryDto.php
│   │   ├── RuleActionDto.php
│   │   ├── RuleConditionDto.php
│   │   ├── RuleInput.php
│   │   ├── TriageBatch.php
│   │   └── TriageRow.php
│   ├── Enums/
│   │   ├── ActionType.php
│   │   ├── ConditionOperator.php
│   │   ├── ConditionValueType.php
│   │   ├── NoteMode.php
│   │   └── RuleCombinator.php
│   ├── Events/
│   │   └── TransactionCategorized.php
│   ├── Http/Livewire/
│   │   ├── InlineCategoryPicker.php
│   │   └── CategorizationProvenancePanel.php
│   └── Services/
│       ├── UncategorizedTriageQuery.php
│       ├── CategoryOptionsQuery.php
│       ├── CategorizationRuleQuery.php
│       └── MerchantMemoryQuery.php
├── Internal/
│   ├── Actions/
│   │   └── AssignCategory.php
│   ├── Jobs/
│   │   └── ReapplyRulesJob.php
│   ├── Pipeline/
│   │   └── ApplyAutoCategoryStage.php
│   ├── Services/
│   │   ├── ActiveRule.php
│   │   ├── ActiveRuleSet.php
│   │   ├── MatchedRule.php
│   │   ├── RowMatcher.php
│   │   ├── RuleApplier.php
│   │   ├── RuleEngine.php
│   │   ├── RuleEvaluator.php
│   │   └── RuleMatchInput.php
│   ├── Listeners/
│   │   ├── DeactivateRulesOnReferentDelete.php
│   │   ├── SeedDefaultCategoryTree.php
│   │   ├── SeedDefaultCategorizationRules.php
│   │   └── MerchantMemoryWriter.php
│   └── Http/Livewire/
│       ├── TriageInbox.php
│       ├── RulesPage.php
│       ├── RuleFormModal.php
│       └── Concerns/
│           ├── MapsRuleRows.php
│           └── ValidatesRuleForm.php
├── Models/
│   ├── CategorizationRule.php
│   ├── RuleCondition.php
│   └── RuleAction.php
├── Database/
│   ├── Migrations/
│   └── Seeders/
│       ├── DefaultCategoryTreeSeeder.php
│       ├── DefaultCategorizationRuleSeeder.php
│       ├── default-categorization-rules.php   ← fixture file
│       └── Demo/
│           ├── DemoCategorizationRulesSeeder.php
│           └── DemoMerchantMemorySeeder.php
├── Routes/
│   ├── web.php       (/uncategorized, /rules)
│   └── console.php
├── Resources/views/
├── Providers/
│   └── CategorizationServiceProvider.php
└── tests/
    ├── Unit/
    ├── Feature/
    └── Fixtures/
        └── seed-rules-live-distribution.php
```

The `Category` model and the `merchant_memories` + `merchants` tables
live in [`Ledger`](../ledger/code.md). This module owns
`categorization_rules` and the `auto_category_provenance` JSON column
on `transactions`.

## Public API

- **Contracts/**
  - `AssignsCategory` — single-method contract for the manual-categorise
    write path. Bound to `Internal/Actions/AssignCategory`; a neighbour
    resolves the contract and never names the class.
  - `AppliesAutoCategory` — single-method contract injected into
    `ImportPipeline`. Bound to `ApplyAutoCategoryStage`.
- **Actions/**
  - `CreateCategorizationRule` / `UpdateCategorizationRule` /
    `DeleteCategorizationRule` — the CRUD trio behind `/rules`.
- **DTOs/**
  - `AutoCategorizationOutcomeDto` — `(canonical, autoApplied, provenance,
    ruleId, memoryId)` returned by `ApplyAutoCategoryStage`. Two factory
    methods: `auto(...)` and `manual($tx)`.
  - `CategorizationRuleDto`, `RuleConditionDto`, `RuleActionDto`,
    `RuleInput`, `CategoryOption`, `MerchantMemoryDto`, `TriageBatch`,
    `TriageRow` — the rule shape the CRUD actions take and the
    read-model DTOs that flow into the Livewire pages.
- **Events/**
  - `TransactionCategorized` — `(transactionId, categoryId, userId)`.
    Raised by `AssignCategory` after every successful write.
- **Services/**
  - `UncategorizedTriageQuery` — pages the rows that need triage,
    optionally suggested-with-counterparty-link.
  - `CategoryOptionsQuery` — the `(slug, name)` list the inline picker
    renders.
  - `CategorizationRuleQuery` — the read-model for `/rules`.
  - `MerchantMemoryQuery` — the read-model the user opens from a
    transaction's provenance panel.

## Internal services

- `Internal/Actions/AssignCategory` — implements the Public
  `AssignsCategory`. Delegates the write to Ledger, dispatches
  `TransactionCategorized`, and stamps `field_provenance`. Its static
  `readPriorProvenance(...)` is the decoder
  `CategorizationProvenancePanel` reads the column through.
- `Internal/Pipeline/ApplyAutoCategoryStage` — the ImportPipeline stage.
  Logs and falls back to `manual($tx)` on any exception from the
  matcher or the memory lookup; bumps `categorization_rules.hits_count`
  atomically, once per matched rule.
- `Internal/Services/RuleEngine` — the matcher. Reads
  `ActiveRuleSet::forUser()` and returns every rule that fired as a
  `list<MatchedRule>`; it names no winner, and `RuleApplier` resolves
  competing actions by type. All comparisons run in PHP, never via a
  SQL `LIKE` (the SQL-injection mitigation for user-authored values);
  a condition whose stored `op` or `value_type` does not resolve to
  its enum returns false rather than throwing.
- `Internal/Services/ActiveRuleSet` — the rule book, read whole in
  three queries per user and held for the life of the instance.
- `Internal/Services/RuleApplier` — applies a `list<MatchedRule>`,
  onto the `CanonicalTransaction` DTO at import and through Ledger's
  Public contracts at re-apply.
- `Internal/Services/RuleEvaluator` — `lookupMemory($tx, $userId)`,
  the merchant-memory fallback, consulted only when no fired rule
  carried a `category` action. It returns the raw joined row or null.
- `Internal/Jobs/ReapplyRulesJob` — re-runs the engine and the applier
  over a user's existing transactions, skipping splits and reconciled
  rows.
- `Internal/Listeners/SeedDefaultCategoryTree` — runs the
  `DefaultCategoryTreeSeeder` (the shared `user_id = NULL` tree,
  idempotent).
- `Internal/Listeners/SeedDefaultCategorizationRules` — runs the
  `DefaultCategorizationRuleSeeder`, which reads the
  `default-categorization-rules.php` fixture and inserts per-user
  matching rows. The seed targets universal merchants only —
  no personal identifiers.
- `Internal/Listeners/MerchantMemoryWriter` — handles
  `TransactionCategorized`. Find-or-creates the `merchants` row its
  NOT NULL FK points at, upserts `merchant_memories` and bumps
  `occurrence_count`. Skips when `categoryId` is null or the
  counterparty is the empty sentinel.
- `Internal/Listeners/DeactivateRulesOnReferentDelete` — deactivates a
  rule whose action payload names a category or counterparty that has
  been deleted, on the Eloquent delete event and on the
  `EntityMutated` announcement a query-builder delete makes.

## Models + migrations

- `Models/CategorizationRule` — maps to `categorization_rules`. Uses
  `BelongsToUser`. Fields: `priority`, `active`, `combinator`
  (`all` / `any`), `notes`, `hits_count`, plus `conditions()` and
  `actions()` `HasMany` relations. The match itself lives on the
  children, not here.
- `Models/RuleCondition` — maps to `rule_conditions`: `field`, `op`,
  `value_type`, `value`, `value2`. There is no `position`; conditions
  are read in `id` order. The three enum columns are enforced by paired
  BEFORE INSERT / BEFORE UPDATE triggers — a typo in an action fails
  loud at the DB layer.
- `Models/RuleAction` — maps to `rule_actions`: `type`, `payload`
  (type-specific JSON with no FK of its own), `position`.

Migrations, the load-bearing ones summarised by purpose:

- `2026_05_17_010003_create_categorization_rules_table.php` — the
  original one-condition-one-category shape, since redesigned. Index
  `(user_id, active)` is the matcher's hot-path read.
- `2026_05_17_010004_add_receipt_conflict_resolution_to_users.php` —
  per-user preference for the receipt-vs-statement enrichment conflict
  resolver.
- `2026_05_17_010005_create_pending_enrichment_conflicts_table.php` —
  queue for unresolved enrichment conflicts. Read by the conflict
  resolver page.
- `2026_05_17_010006_add_auto_category_provenance_to_transactions.php`
  — adds the JSON `auto_category_provenance` column to `transactions`
  that `ApplyAutoCategoryStage` writes and `AssignCategory` reads to
  detect divergence.
- `2026_07_06_000001_redesign_categorization_rules_table.php`,
  `…_000002_create_rule_conditions_table.php`,
  `…_000003_create_rule_actions_table.php` and
  `…_000005_migrate_existing_rules_to_conditions_actions.php` — the
  multi-condition, multi-action redesign and the data move onto it.
- `2026_07_06_000004_add_field_provenance_to_transactions.php` — the
  per-field provenance column a `manual` value blocks a rule from
  overwriting.

## Provider wiring

`CategorizationServiceProvider::register()`:

- Binds `AssignsCategory` → `AssignCategory` (default implementation).
- Binds `AppliesAutoCategory` → `ApplyAutoCategoryStage` so the
  ImportPipeline depends on the Public contract, not the Internal
  class.
- Singletons `RuleEvaluator`, `MerchantMemoryQuery`,
  `CreateCategorizationRule`, `UpdateCategorizationRule`,
  `DefaultCategorizationRuleSeeder` and
  `DeactivateRulesOnReferentDelete`; scopes `CategoryOptionsQuery` to
  the request; binds `DeleteCategorizationRule` fresh each resolve.

`CategorizationServiceProvider::boot()`:

- Loads migrations, web/console routes, views.
- Subscribes the two seed listeners to `UserInstalled` in order: tree
  first (so categories exist), rules second (so slugs resolve).
- Subscribes `MerchantMemoryWriter` to `TransactionCategorized`.
- Subscribes `DeactivateRulesOnReferentDelete` to three events: the
  framework's `eloquent.deleting:` wildcard for the Ledger `Category`
  and Counterparties `Counterparty` models, named as plain strings so
  neither model class is imported, and `EntityMutated`, which is how a
  query-builder delete — the shape that fires no model event —
  announces itself.
- Registers this module's Livewire components under the
  `categorization.*` namespace.
