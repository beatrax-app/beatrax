# `Categorization` — architecture

The `Categorization` module owns the rule-based classifier that pre-fills
a category on every incoming transaction, the per-user merchant memory
that learns from manual corrections, the triage queue for whatever the
classifier could not decide, and the categorization-rules CRUD surface
behind `/rules`.

## What this module is for

A finance dashboard is only as calm as its category column. If the user
has to pick a category for every transaction every month, the dashboard
stops being a dashboard and becomes a chore. The module therefore tries
to land every imported transaction in a category at import time using
two layers — author-written rules and per-user merchant memory — and
only surfaces the rest in the triage queue.

The shape of the two-layer classifier and the receipt-vs-statement
conflict resolver is documented in the
[categorization architecture topic](../../architecture/categorization.md).
This module-feature page describes the module's surface; the
cross-cutting design lives in the topic page.

What the module explicitly does NOT do:

- It never writes to the `transactions` table directly. The single
  sanctioned mutator of `transactions.category_id` is Ledger's
  `UpdatesTransactionCategory` action; `AssignCategory` here delegates
  to it, then dispatches `TransactionCategorized` for downstream
  reactions.
- It never auto-categorises a transaction the rule evaluator could not
  match. There is no fuzzy heuristic that fires "maybe this is groceries"
  — uncategorised is honest; a wrong guess buried in the dashboard is
  worse than an entry in the triage queue.
- It never seeds rules from personal data. The shipped seed set targets
  universal Dutch-household merchants (streaming brands, supermarkets,
  energy suppliers, tax authorities, the bulk-iDEAL settlement marker).
  Anything personal — employer names, family names, Tikkie / P2P — the
  user authors against their own data via the triage queue.

## Module boundary

`Public/` exposes the action surface, the contracts the rest of the
codebase depends on, the events other modules can react to, and the
read-model queries the UI consumes:

- **Contracts/** — `AssignsCategory` (the manual-categorise write path),
  `AppliesAutoCategory` (the ImportPipeline-stage contract).
- **Actions/** — `AssignCategory` (default `AssignsCategory` impl),
  `CreateCategorizationRule`, `UpdateCategorizationRule`,
  `DeleteCategorizationRule`.
- **DTOs/** — `AutoCategorizationOutcomeDto` (returned by the
  ImportPipeline stage), `CategorizationRuleDto`, `CategoryOption`,
  `MerchantMemoryDto`, `TriageBatch`, `TriageRow`.
- **Events/** — `TransactionCategorized` (raised on every successful
  categorisation; `Categorization` itself listens to update merchant
  memory; other modules MAY listen), `CategorizationDiverged` (raised
  when a manual reclassify contradicts a still-active rule; the
  `CorrectionDivergenceToast` SFC consumes it).
- **Services/** — `UncategorizedTriageQuery`, `CategoryOptionsQuery`,
  `CategorizationRuleQuery`, `MerchantMemoryQuery` (read-side queries
  for the Livewire pages, all `BelongsToUser`-scoped).

`Internal/` houses the implementation:

- **Internal/Pipeline/ApplyAutoCategoryStage** — the synchronous
  ImportPipeline stage bound to `AppliesAutoCategory`. Runs the
  evaluator and stamps `categoryId` + `autoCategoryProvenance` on the
  canonical row.
- **Internal/Services/RuleEvaluator** — the specificity-scored matcher.
- **Internal/Services/RuleEvaluationOutcome** — small value object that
  carries the winning candidate (or "none") between the evaluator and
  the stage.
- **Internal/Listeners/SeedDefaultCategoryTree** + `SeedDefaultCategorizationRules`
  + `MerchantMemoryWriter` — the three listeners that wire the module
  into `UserInstalled` and `TransactionCategorized`.
- **Internal/Http/Livewire/** — `TriageInbox`, `InlineCategoryPicker`,
  `RulesPage`, `RuleFormModal`, `CategorizationProvenancePanel`,
  `CorrectionDivergenceToast`.

The arch invariant `noCategorizationWritesTransactions` keeps the module
honest: only `AssignCategory` (via Ledger) and `ApplyAutoCategoryStage`
(via the ImportPipeline's persist boundary) ever cause a write to the
`transactions.category_id` column.

## Key services + events

- `RuleEvaluator` — pulls the user's active rules and merchant memory,
  scores each candidate (`equals=100`, `memory=90`,
  `starts_with=50+length`, `contains=10+length`), returns the highest.
  Rule beats memory at equal score (tiebreaker). All comparisons are
  case-insensitive and Unicode-safe (`mb_*` family).
- `ApplyAutoCategoryStage` — the ImportPipeline stage. On any evaluator
  exception, falls back to `AutoCategorizationOutcomeDto::manual($tx)`
  so a single buggy rule never aborts an import. Increments the rule's
  `hits_count` counter atomically with an `UPDATE … SET hits_count =
  hits_count + 1` so the `/rules` table can show how often each rule
  has actually fired.
- `AssignCategory` — the manual-categorise write path. Reads prior
  `auto_category_provenance`, delegates the write to Ledger's
  `UpdatesTransactionCategory`, then dispatches `TransactionCategorized`
  and conditionally `CategorizationDiverged` (when the user's manual
  pick contradicts a still-active rule).
- `MerchantMemoryWriter` — listens for `TransactionCategorized`, upserts
  `(user_id, merchant_id, category_id)` into `merchant_memories`, and
  bumps `occurrence_count` so the per-user memory weight grows with
  use.
- `SeedDefaultCategoryTree` + `SeedDefaultCategorizationRules` — listen
  for `UserInstalled`. The category-tree seeder runs first (writes the
  global `user_id = NULL` rows with the Dutch-aware tree, idempotent
  via `updateOrCreate` keyed on `(slug, user_id = NULL)`), then the
  rule seeder follows so every rule's category slug already resolves.

## Data flow

The auto-categorisation path is one synchronous stage inside the
ImportPipeline:

```
ImportPipeline.preview()
  → … (parse, normalize, classify-transaction-type, classify-payment-type)
  → ApplyAutoCategoryStage
       → RuleEvaluator.evaluate($tx, $user)
           ├─ pull active rules for user (indexed (user_id, active))
           ├─ score each candidate
           ├─ JOIN merchants for memory candidate
           └─ return highest-scoring outcome
       → if outcome.categoryId:
            stamp tx.categoryId + tx.autoCategoryProvenance
            bump categorization_rules.hits_count atomically
  → … (counterparty-resolve, fingerprint)
  → persist
```

A manual reclassify (the triage queue, the inline picker, the
transaction detail page) takes the other entry point:

```
Livewire pick → AssignCategory($transactionId, $categoryId, $user)
  → read prior auto_category_provenance
  → UpdatesTransactionCategory (Ledger — sole transactions writer)
  → dispatch TransactionCategorized
       → MerchantMemoryWriter upserts memory + bumps occurrence_count
  → if prior provenance was a rule AND new category ≠ rule.category_id:
       dispatch CategorizationDiverged
       → CorrectionDivergenceToast renders Update rule / Keep current
```
