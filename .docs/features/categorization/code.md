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
│   │   └── DeleteCategorizationRule.php
│   ├── Dto/
│   │   ├── AutoCategorizationOutcomeDto.php
│   │   ├── CategorizationRuleDto.php
│   │   ├── CategoryOption.php
│   │   ├── MerchantMemoryDto.php
│   │   ├── TriageBatch.php
│   │   └── TriageRow.php
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
│   ├── Pipeline/
│   │   └── ApplyAutoCategoryStage.php
│   ├── Services/
│   │   ├── RuleEvaluator.php
│   │   └── RuleEvaluationOutcome.php
│   ├── Listeners/
│   │   ├── SeedDefaultCategoryTree.php
│   │   ├── SeedDefaultCategorizationRules.php
│   │   └── MerchantMemoryWriter.php
│   └── Http/Livewire/
│       ├── TriageInbox.php
│       ├── RulesPage.php
│       └── RuleFormModal.php
├── Models/
│   └── CategorizationRule.php
├── Database/
│   ├── Migrations/
│   └── Seeders/
│       ├── DefaultCategoryTreeSeeder.php
│       ├── DefaultCategorizationRuleSeeder.php
│       ├── default-categorization-rules.php   ← fixture file
│       └── Demo/DemoMerchantMemorySeeder.php
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
  - `CategorizationRuleDto`, `CategoryOption`, `MerchantMemoryDto`,
    `TriageBatch`, `TriageRow` — read-model DTOs that flow into the
    Livewire pages.
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
  Logs and falls back to `manual($tx)` on evaluator exceptions; bumps
  `categorization_rules.hits_count` atomically on a successful rule
  match.
- `Internal/Services/RuleEvaluator` — pulls rules + memory, scores,
  returns the highest. All comparisons in PHP, never via SQL LIKE
  (SQL-injection mitigation for user-authored values). Field +
  operator enum allow-lists mirror the DB triggers; an unknown value is
  silently skipped rather than evaluated.
- `Internal/Services/RuleEvaluationOutcome` — `(categoryId, source,
  ruleId, memoryId, score)` with a `none()` zero-state factory.
- `Internal/Listeners/SeedDefaultCategoryTree` — runs the
  `DefaultCategoryTreeSeeder` (13 top-level sections + 17 leaves,
  `user_id = NULL`, idempotent).
- `Internal/Listeners/SeedDefaultCategorizationRules` — runs the
  `DefaultCategorizationRuleSeeder`, which reads the
  `default-categorization-rules.php` fixture and inserts per-user
  matching rows. The seed targets universal merchants only —
  no personal identifiers.
- `Internal/Listeners/MerchantMemoryWriter` — handles
  `TransactionCategorized`. Upserts `merchant_memories` and bumps
  `occurrence_count`. Skips writes when the transaction has no resolved
  merchant.

## Models + migrations

- `Models/CategorizationRule` — maps to `categorization_rules`. Uses
  `BelongsToUser`. Fields: `field` (enum: `merchant` / `description` /
  `counterparty`), `match` (enum: `equals` / `starts_with` / `contains`),
  `value`, `category_id` (FK to `categories`), `hits_count`, `active`,
  `notes`. Both enum columns enforced by paired BEFORE INSERT / BEFORE
  UPDATE triggers — a typo in an action fails loud at the DB layer.

Migrations:

- `2026_05_17_010003_create_categorization_rules_table.php` — initial
  create. UNIQUE `(user_id, field, match, value)` blocks duplicate
  inserts; index `(user_id, active)` is the evaluator hot-path read.
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

## Provider wiring

`CategorizationServiceProvider::register()`:

- Binds `AssignsCategory` → `AssignCategory` (default implementation).
- Binds `AppliesAutoCategory` → `ApplyAutoCategoryStage` so the
  ImportPipeline depends on the Public contract, not the Internal
  class.
- Singletons every Public Action / Service and the
  `MerchantMemoryWriter` (stateless), `RuleEvaluator`,
  `ApplyAutoCategoryStage`, `DefaultCategorizationRuleSeeder`.

`CategorizationServiceProvider::boot()`:

- Loads migrations, web/console routes, views.
- Subscribes the two seed listeners to `UserInstalled` in order: tree
  first (so categories exist), rules second (so slugs resolve).
- Subscribes `MerchantMemoryWriter` to `TransactionCategorized`.
- Registers the six Livewire components under the `categorization.*`
  namespace.
