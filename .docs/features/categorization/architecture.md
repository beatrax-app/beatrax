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
  - `MerchantMemoryWriter` — the three listeners that wire the module
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

## Read-side DTO field semantics

- **`CategorizationRuleDto`** — `priority` decides evaluation order
  (ascending, then `id`); `combinator` (`all` | `any`) decides how
  `conditions` combine, both enforced at the DB layer via paired
  BEFORE INSERT/UPDATE triggers. `hitsCount` is denormalised and
  incremented by `ApplyAutoCategoryStage` only at import time, never
  during re-apply. `active` lets the user disable a rule without
  deleting it; it is also flipped false by
  `DeactivateRulesOnReferentDelete` when a referenced category or
  counterparty is deleted.
- **`RuleConditionDto`** — `field` is meaningful only when
  `valueType = 'string'`; `amount`/`date` value types compare against
  the transaction's canonical property directly and carry a
  placeholder `field` (the DB column still requires a valid enum
  value). `op` validity per `valueType` is enforced at write time by
  `CreateCategorizationRule`/`UpdateCategorizationRule`, not merely by
  the DB-layer allow-list triggers. `value2` is populated only for the
  `between` op.
- **`RuleActionDto`** — `payload` is the raw type-specific JSON shape
  (`category_id`, `counterparty_id`, `{text, mode}`, or
  `{deduction_category_id, year?}`). `categoryPath`/`counterpartyName`
  are resolved display fields joined at read time by
  `CategorizationRuleQuery`, populated only for the matching action
  `type`, and null when the payload's embedded id no longer resolves
  (the rule itself is deactivated by `DeactivateRulesOnReferentDelete`
  in that case, but the read side stays defensive regardless).
  `position` orders this rule's actions' application (last-writer-wins
  across shared fields).
- **`AutoCategorizationOutcomeDto`** — three branches: `auto(...)` (a
  rule or merchant-memory row won, `ruleId`/`memoryId` preserved for
  the correction-divergence flow), `manual(...)` (no candidate scored
  above threshold, the row surfaces on `/uncategorized`). The wrapped
  `CanonicalTransaction` carries the row through to the writer.

## Rule CRUD actions: validation + IDOR guards

`CreateCategorizationRule`/`UpdateCategorizationRule` are the sole
permissible write path from the UI/API into `categorization_rules` +
its `rule_conditions`/`rule_actions` children, atomically inside one DB
transaction. Every check runs BEFORE any write:

- At least one condition and one action must be supplied.
- `combinator` must be `all` or `any`.
- Every condition's `(op, value_type)` pair must be inside the validity
  matrix: `string` supports `contains`/`equals`/`starts_with`; `amount`
  supports `>`/`<`/`between`/`equals`; `date` supports
  `before`/`after`/`between`. `op = 'between'` requires a non-null
  `value2`. An `amount` condition's `value`/`value2` must already be a
  signed integer minor-unit string — `RuleFormModal::conditionPayload()`
  performs the Euro-decimal-to-minor-unit scaling before calling in.
- `field` (meaningful only for `string` conditions) must be one of
  `merchant`/`description`/`counterparty`, mirroring the DB-layer
  allow-list trigger with a clearer PHP-side error.
- Every action's embedded payload id (`category_id`, `counterparty_id`,
  `deduction_category_id`) must resolve to a row visible to the caller
  — this is the IDOR seam for every id embedded in a `rule_actions`
  JSON payload, since the payload carries no FK. A miss throws
  `InvalidArgumentException`.

`UpdateCategorizationRule`'s child-row reconciliation is a targeted,
PK-preserving UPDATE/DELETE/INSERT diff keyed by the caller-supplied
`id` (present only for a row that already exists), never a
delete-all-then-reinsert — mirroring `SaveTransactionSplit`'s
leg-diffing convention. A caller-supplied `id` that does not belong to
the rule being updated (a forged or cross-rule id) is silently treated
as absent (inserted as new) rather than ever updating a row scoped to
a different rule.

`DeleteCategorizationRule` runs its lookup-then-delete inside a DB
transaction so the pair is atomic (no TOCTOU race with a concurrent
cascade); the category_id FK cascades on category delete, but deleting
a rule never retroactively un-categorises the transactions it
previously matched.

All three actions look the parent row up via
`where('id', ...)->where('user_id', ...)` and raise
`NotFoundHttpException` (404, never 403) on a cross-user or unknown
target, so the existence of other users' rules is never leaked through
the error path. A `QueryException` from a duplicate natural key is
translated to a `ValidationException` rather than surfacing a raw SQL
error.

## Rule engine internals

`RuleEngine::match()` is the pure, side-effect-free multi-condition
matcher shared by the import stage and the re-apply job: it only reads
`categorization_rules`/`rule_conditions`/`rule_actions` and returns a
list of firing rules (`MatchedRule`), never applies them. Three
invariants:

- String condition operators (`contains`/`equals`/`starts_with`) are
  evaluated in PHP via the `mb_*` family (Unicode-safe, case-insensitive)
  after a broad `where('user_id', ...)->where('active', 1)` pull — the
  user-authored condition `value` is NEVER pushed into a SQL
  pattern-match clause. This is the SQL-injection mitigation for
  untrusted rule input.
- Every rule/condition/action pull is scoped by `user_id` so a foreign
  user's rule never fires for the current user.
- Match ordering is a deterministic pure function of (rule set, input):
  rules and their actions are pulled `->orderBy('priority')->orderBy('id')`
  / `->orderBy('position')->orderBy('id')` at the SQL layer, never
  re-ordered in PHP — depending on an unordered pull's incidental return
  order would break idempotent re-apply / sync convergence.

`RuleApplier` is the dual-mode action executor that turns a
`list<MatchedRule>` into effects, same-field conflicts resolved
last-writer-wins by execution order:

- **`applyAtImport()`** folds `category`/`counterparty`/`note` actions
  onto a `CanonicalTransaction` DTO via withers before persistence — no
  op-log, no DB write, no event dispatch. `tax_tag` cannot fire at
  import (no persisted `transaction_id` yet) and is silently skipped.
- **`applyAtReapply()`** DELEGATES every field write to the Ledger
  Public guarded actions (or `TagTransaction` for `tax_tag`) — this
  service never writes `transactions` raw. Rules are reduced to one
  desired action per action `type` before any write, so last-writer-wins
  resolves to exactly one write attempt per field. `field_provenance` is
  read once up front; any field already stamped `'manual'` is skipped
  entirely. A malformed action payload (missing/non-numeric embedded id,
  unrecognised type) skips just that one action (logged, never thrown)
  so one broken action never aborts a whole import row or re-apply pass.
- The `tax_tag` re-apply path reads the existing tax note and passes it
  through unchanged (decrypted first, since it is a
  `SensitiveFieldRegistry` column) so a rule-driven category/year change
  never silently wipes a user-authored note.

`RuleEvaluator` (a legacy name, now memory-lookup-only) is the sole
surviving merchant-memory fallback: it pulls the highest-`occurrence_count`
`merchant_memories` row for a transaction's merchant so
`ApplyAutoCategoryStage` can apply it only when no fired rule carried a
`category` action.

## Re-apply-to-history job

`ReapplyRulesJob` walks a user's non-split transactions in chunks and
re-runs `RuleEngine::match()` + `RuleApplier::applyAtReapply()` against
every eligible row. It adds the two guards `RuleApplier` cannot apply on
its own:

- **Split guard**: `whereNotExists(transaction_splits)` excludes every
  split transaction entirely — a split's category/tax semantics are
  leg-owned, so re-apply never even reads a split parent.
- **Reconciled guard**: `TransactionStatusQuery::reconciledIdsAmong()`
  is called once per chunk (never per-row) so a reconciled/locked
  transaction is skipped before `RuleEngine`/`RuleApplier` see it.

`hits_count` is not touched here — that counter is bumped only by
`ApplyAutoCategoryStage` at import time; re-apply counts matches via
the cache progress payload instead (`rule-reapply:{userId}`, TTL'd, no
new synced table). The job is a plain `ShouldBeUnique` (re-triggerable
on every user click, unlike `BackfillAnomaliesJob`'s
first-activation-only shape) — a fresh dispatch after a prior run
completes is a legitimate new pass, not a duplicate. A row whose
`match()`/`applyAtReapply()` throws is skipped (logged, counted in
`rows_errored`) rather than failing the whole queued job.

**Encrypted-user decrypt-before-match**: `counterparty_name`/`description`
are decrypted via `SensitiveColumnCodec` before `RuleMatchInput` is
built, so substring conditions match plaintext even though the two
columns are ciphertext at rest. This is safe only under `dispatchSync`
(the job's only reachable dispatch shape from `RulesPage::triggerReapply`)
— the KEK is always the caller's own request-context key, verified via
`AppLockKeyService::release()`. If that check ever finds no KEK
(a future queued/daemon dispatch origin), decrypting would silently
no-op and the run would silently classify nothing — the guard logs a
warning once per run rather than crashing, since a not-yet-unlocked
session should not abort an otherwise-legitimate re-apply for
non-encrypted users.

## App-level referential integrity

`rule_actions.payload` embeds `category_id`/`counterparty_id` as opaque
JSON with no FK, so deleting a referenced category or counterparty
would otherwise leave an active rule pointing at a dangling id.
`DeactivateRulesOnReferentDelete` replaces the missing FK cascade: it
listens on the framework's own `eloquent.deleting: {FQCN}` wildcard
event name (a plain string, wired in `CategorizationServiceProvider::boot()`)
for the Ledger `Category` and Counterparties `Counterparty` models —
never importing either model class, keeping this module's coupling at
the same "raw table name" level every other cross-module read here
uses. It deactivates the whole rule (not just the offending action row)
because a rule missing one of its required actions would violate the
structural invariant that a rule always has at least one condition and
one action. Every UPDATE is scoped by the deleted row's own `user_id`
and touches only `categorization_rules.active`; a global (unowned,
`user_id IS NULL`) referent has no single user to scope by and is a
defensive no-op, since neither module exposes a delete path for a
global row today.

## Merchant memory growth

`MerchantMemoryWriter` listens for `TransactionCategorized` and grows
`merchant_memories` so future imports for the same normalised merchant
auto-suggest the chosen category. It skips when `categoryId` is null
(un-categorize is not a memory-grow event) and when the transaction's
`counterparty_normalized` is the empty-counterparty sentinel. It used to
skip a third case — no `merchants` row for `(user_id, normalized_name)` —
on the premise that populating that table was a Ledger/NormalizeStage
concern. Nothing ever did: the only insert in the tree was the demo
seeder, so on a real install `merchants` stayed empty, `merchant_memories`
could never grow, and this whole layer was dead. The listener now
find-or-creates the row its NOT NULL FK points at, and publishes it
before the memory, because a peer needs the merchant first.
`merchants.normalized_name` is compared directly against
`transactions.counterparty_normalized`, so the two carry the same
construction: for a user with at-rest encryption on, both hold a keyed
one-way digest under one derivation domain rather than the merchant name.
See [Which columns are encrypted at rest](../sync/sensitive-columns-at-rest.md).
It upserts on the `(user_id, merchant_id, category_id)` UNIQUE
constraint via an insert-then-catch-and-update pattern: the DB
serialises competing inserts, so exactly one of N concurrent events
lands the row and the rest fall through to an atomic
`occurrence_count + 1` UPDATE, avoiding the check-then-insert TOCTOU
window a SELECT-first approach would have. The listener stays
synchronous — memory growth is a tiny indexed write that benefits from
staying in the same DB transaction frame as the category assignment.

## Import-time stage: encryption + failure posture

`ApplyAutoCategoryStage::apply()` matches `RuleMatchInput::fromCanonical($tx)`
— the incoming, pre-persistence DTO — never a stored `transactions` row.
`ImportPipeline::preview()` always calls this BEFORE `RecordTransactions`
writes (and encrypts) anything, so the values matched are plaintext
regardless of whether the user has encryption enabled; no decrypt is
needed here (only `ReapplyRulesJob` reads a persisted, potentially
ciphertext row).

The whole matching + applying + `hits_count` bump + memory-fallback flow
runs inside one try/catch. If anything throws, the stage logs a warning
and returns `AutoCategorizationOutcomeDto::manual($tx)` built from the
ORIGINAL, untouched canonical row, so the import is never aborted by a
buggy rule — the row lands in the uncategorised bucket instead. The
`hits_count` bump loop itself runs inside a DB transaction so a
mid-loop exception rolls back every increment for the row rather than
leaving earlier rules permanently bumped when the outer catch discards
the categorization and falls back to manual. `hits_count` is bumped
once per matched rule at import time only; `ReapplyRulesJob` never
touches this counter.

## Default seeders

`DefaultCategoryTreeSeeder` installs a Dutch-household-shaped default
tree (13 top-level sections, 17 leaves) with `user_id = NULL` so it
acts as the shared starting set for every user. It is keyed on
`(slug, user_id = NULL)` so the lookup only ever matches the global
default-tree row, never a per-user override sharing the same slug —
re-running is safe and never demotes a user-owned category to global.

Structure is re-asserted on every run; the **name** is written once, at
creation, alongside `name_is_default = true`. The name goes in as the
app's canonical English and is translated per reader at render time
from this module's `categories.php` lang files, keyed by slug — so the
tree follows a language change instead of freezing in the signup
locale. Re-asserting the name on a later run would undo a rename, which
is why it is creation-only. Anything reading or matching a category
name has to go through the seam that knows this:
[category display names](../ledger/category-display-names.md).

`DefaultCategorizationRuleSeeder` installs the universal-merchant rule
set per-user (since `RuleEvaluator` scopes its pull by `user_id` with
no NULL fallback — a global rule would never fire). It maps each
fixture row's category slug to the corresponding global-tree category,
creating one `CategorizationRule` parent + one condition + one
`category`-type action. Idempotency is checked explicitly (rather than
relying on a UNIQUE constraint, which the normalized schema no longer
carries): a rule "already exists" when the user owns a
`CategorizationRule` with a matching `rule_conditions` row, in which
case the row is skipped entirely — preserving that existing rule's
`hits_count`/`active`/`priority` rather than resetting real usage data.
A fixture row whose category slug doesn't resolve is logged and
skipped rather than aborting the whole seed. Unlike
`DefaultCategoryTreeSeeder` (a framework `Seeder`),
`DefaultCategorizationRuleSeeder` deliberately does NOT extend
`Illuminate\Database\Seeder` — it is a plain service called from a
listener, never from `db:seed`, so skipping the base class keeps its
constructor DI-clean.

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
