---
phase: 07-email-template-matchers-categorization-learning
plan: 04
subsystem: categorization
tags: [categorization, rule-evaluator, merchant-memory, auto-categorization, receipt-conflict, livewire-sfc, modular-architecture]

requires:
  - phase: 07-email-template-matchers-categorization-learning
    plan: 01
    provides: categorization_rules + pending_enrichment_conflicts + auto_category_provenance migrations + AutoCategorizationOutcomeDto + CategorizationRuleDto + receipt_conflict_resolution enum column
  - phase: 07-email-template-matchers-categorization-learning
    plan: 02
    provides: Modules/Receipts/ bounded module + ReceiptConflictDetected event + PaypalReceiptMatcher precedent + NormalizeStage Public placement
  - phase: 02-asn-statement-coverage-camt-053-mt940
    provides: FingerprintComposer v3 + ENRICHED disposition + ApplyEnrichments cross-format dedup

provides:
  - "CategorizationRule Eloquent model (BelongsToUser, fillable, casts)"
  - "RuleEvaluator Internal service implementing D-711 specificity scoring (equals=100, memory=90, starts_with=50+len, contains=10+len; rule beats memory at equal score)"
  - "RuleEvaluationOutcome Internal DTO (sum-type via named static constructors)"
  - "MerchantMemoryWriter Internal listener subscribing to TransactionCategorized (CAT-02 storage half)"
  - "CategorizationRuleQuery + MerchantMemoryQuery + MerchantMemoryDto Public read services"
  - "AppliesAutoCategory Public contract (cross-module seam — ImportPipeline depends on the contract, not a Categorization Internal class)"
  - "ApplyAutoCategoryStage Internal pipeline stage (synchronous, side-effect-free on failure)"
  - "ImportPipeline constructor extended with AppliesAutoCategory $autoCategory; per-row apply() between ClassifyTransactionType and FingerprintStage"
  - "CanonicalTransaction widened with autoCategoryProvenance: ?array + withCategoryId/withAutoCategoryProvenance immutable withers"
  - "RecordTransactions persists auto_category_provenance JSON via the toAttributes() column mapping"
  - "Transaction Eloquent model: auto_category_provenance added to fillable + casts (array)"
  - "PendingEnrichment widened with conflictingFields: array<string, {stored, incoming}> defaulted to []"
  - "EnrichedDisposition + FingerprintDisposition::enriched() factory carry the conflictingFields map"
  - "FingerprintStage.classify() inspects counterparty_name / description / currency / amount_minor for non-null disagreement and populates the map"
  - "SourceRefRanker.isReceiptFormat(string): bool — single source of truth for paypal-receipt / ics-receipt / google-play-receipt slugs"
  - "ApplyEnrichments conflict branch: empty -> Wave 1 path; unset+receipt -> INSERT pending_enrichment_conflicts + dispatch ReceiptConflictDetected per field, SKIP per-field write; prefer_receipt -> apply incoming; prefer_first_write -> keep stored; user policy read into method-local variable (NO instance-level cache)"
  - "enriched_from provenance entry lists every column written (source_ref + any conflict resolutions applied)"
  - "ApplyReceiptConflictResolution Public action (T-07-11 enum whitelist; T-07-09 user-scoped reads + writes)"
  - "ReceiptConflictQuery Public service (latestForUser for SFC mount fallback)"
  - "ReceiptConflictToast Livewire SFC (services on action methods; #[On] event listener with cross-user defensive guard; mount() DB-pull fallback for backfill scenarios)"
  - "Blade view receipts::livewire.receipt-conflict-toast with UI-SPEC locked copy + Phase 5 toast chrome + NO auto-dismiss"
affects: [phase-07-05-rules-ui-correction-divergence]

tech-stack:
  added: []
  patterns:
    - "Cross-module Public contract for pipeline collaborators: AppliesAutoCategory (Categorization Public) mirrors the existing AppliesEnrichments + RecordsStatementSummary shape — ImportPipeline depends on the contract, never on the Categorization Internal class"
    - "Method-local cache for per-user state on singleton-bound actions: ApplyEnrichments reads users.receipt_conflict_resolution ONCE per __invoke() call into a local variable; no instance-level cache prevents cross-user leak on the same worker process (T-07-09 defence)"
    - "Singleton-safety reflection test pattern: assert no `private ?string $userPolicy` / similar cache property exists on the action class — catches the regression at unit-test time before it reaches production"
    - "Merchant-id derivation via JOIN on (user_id, normalized_name) — CanonicalTransaction + transactions carry no merchant_id column; deriving via the merchants table at query time avoids a cross-pipeline ripple change"
    - "Sync pipeline-stage placement after ClassifyTransactionType, before FingerprintStage — every source format (CSV / CAMT / MT940 / PayPal / ICS PDF / receipt) auto-categorises with one stage instead of per-adapter wiring"
    - "Side-effect-free pipeline stage: ApplyAutoCategoryStage's try/catch around RuleEvaluator returns AutoCategorizationOutcomeDto::manual on failure so a buggy rule never aborts an import (Validation Axis 7)"
    - "PHP-side match evaluation (mb_strtolower / mb_strpos) instead of SQL LIKE for rule.value — the value never reaches the SQL string (T-07-05 SQL-injection mitigation)"
    - "Specificity scoring with deterministic tiebreaker: loop evaluates rules first, memory second, and strict `>` comparison guarantees rule beats memory at equal score (D-711 algorithm)"
    - "Atomic memory-increment via raw('occurrence_count + 1') on UPDATE branch; literal 1 on INSERT — avoids the updateOrInsert pitfall where the raw expression evaluates to NULL on insert"
    - "Cross-user event guard on Livewire SFC: handleConflictDetected verifies event.userId matches CurrentUser->id() — local Livewire events should never carry a foreign userId, but the defensive guard makes any future regression fail-safe"
    - "Conflict-detection field normalization: case-insensitive + trimmed for strings, uppercase for currency, exact-int for amount_minor — silent whitespace / case differences are not real conflicts at the user-visible level"
    - "isReceiptFormat() centralised on SourceRefRanker — single source of truth for the paypal-receipt / ics-receipt / google-play-receipt slugs; plan 05 + future matchers share this check"

key-files:
  created:
    - "Modules/Categorization/Models/CategorizationRule.php"
    - "Modules/Categorization/Internal/Services/RuleEvaluator.php"
    - "Modules/Categorization/Internal/Services/RuleEvaluationOutcome.php"
    - "Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php"
    - "Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php"
    - "Modules/Categorization/Public/Contracts/AppliesAutoCategory.php"
    - "Modules/Categorization/Public/Services/CategorizationRuleQuery.php"
    - "Modules/Categorization/Public/Services/MerchantMemoryQuery.php"
    - "Modules/Categorization/Public/Dto/MerchantMemoryDto.php"
    - "Modules/Categorization/tests/Unit/RuleEvaluatorSpecificityTest.php"
    - "Modules/Categorization/tests/Feature/RuleEvaluatorTest.php"
    - "Modules/Categorization/tests/Feature/MerchantMemoryWriterTest.php"
    - "Modules/Categorization/tests/Feature/ApplyAutoCategoryStageTest.php"
    - "Modules/Categorization/tests/Feature/ApplyEnrichmentsConflictTest.php"
    - "Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php"
    - "Modules/Receipts/Public/Services/ReceiptConflictQuery.php"
    - "Modules/Receipts/Internal/Http/Livewire/ReceiptConflictToast.php"
    - "Modules/Receipts/Resources/views/livewire/receipt-conflict-toast.blade.php"
    - "Modules/Receipts/tests/Feature/ReceiptConflictResolutionTest.php"
  modified:
    - "Modules/Categorization/Providers/CategorizationServiceProvider.php (RuleEvaluator + ApplyAutoCategoryStage + CategorizationRuleQuery + MerchantMemoryQuery singletons; AppliesAutoCategory contract binding; MerchantMemoryWriter listener registration)"
    - "Modules/Ledger/Public/Dto/CanonicalTransaction.php (autoCategoryProvenance field + withCategoryId + withAutoCategoryProvenance withers; toAttributes() JSON-encodes the provenance column)"
    - "Modules/Ledger/Models/Transaction.php (auto_category_provenance added to fillable + casts as array)"
    - "Modules/Import/Internal/Pipeline/ImportPipeline.php (AppliesAutoCategory constructor injection; per-row apply() between classifier and fingerprint; PendingEnrichment threads conflictingFields)"
    - "Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php (classify() fetches receipt-relevant columns + detectConflicts() builds the disagreement map for ENRICHED dispositions)"
    - "Modules/Import/Public/Dto/PendingEnrichment.php (conflictingFields parameter with default [])"
    - "Modules/Import/Public/Dto/EnrichedDisposition.php (conflictingFields parameter with default [])"
    - "Modules/Import/Public/Dto/FingerprintDisposition.php (enriched() factory carries conflictingFields)"
    - "Modules/Import/Public/Services/SourceRefRanker.php (isReceiptFormat() centralised check)"
    - "Modules/Import/Public/Actions/ApplyEnrichments.php (Dispatcher DI; per-policy conflict branch; method-local user policy; enriched_from added list now includes per-field columns)"
    - "Modules/Receipts/Providers/ReceiptsServiceProvider.php (ApplyReceiptConflictResolution + ReceiptConflictQuery singletons; receipts.receipt-conflict-toast Livewire component)"

key-decisions:
  - "Phase 07 Plan 04: RuleEvaluator derives merchant_id by JOINing the merchants table on (user_id, normalized_name = counterpartyNormalized) rather than reading it from CanonicalTransaction. CanonicalTransaction has no merchantId field; widening it would ripple through every ingestion adapter + NormalizeStage + tests. The merchants table enforces UNIQUE (user_id, normalized_name) so the join yields at most one merchant per normalized_name. MerchantMemoryWriter follows the same shape (reads counterparty_normalized off the transactions row, then JOINs merchants) because transactions.merchant_id does not exist either — confirmed by inspecting Modules/Ledger/Database/Migrations."
  - "Phase 07 Plan 04: ApplyAutoCategoryStage swallows RuleEvaluator exceptions inside a try/catch (logs at WARNING, returns AutoCategorizationOutcomeDto::manual) so a buggy rule never aborts an import. The user sees the row land uncategorised and can re-categorise manually — preferable to a half-imported file. Validated by the side-effect-free test that DROPs the categorization_rules table mid-import and asserts the manual fallback fires."
  - "Phase 07 Plan 04: Match operators evaluate in PHP via mb_strtolower / mb_strpos instead of SQL LIKE. The rule.value never reaches the SQL string, so a value like \"Robert'; DROP TABLE--\" matches as literal substring rather than syntax-erroring or dropping a table (T-07-05 mitigation; Pest test asserts the transactions table is still present after the matcher runs)."
  - "Phase 07 Plan 04: ApplyEnrichments reads users.receipt_conflict_resolution into a METHOD-LOCAL variable per __invoke() call — never on an instance property. The action is bound as a singleton; a private \$userPolicy property would be reused across users on the same queue-worker process, leaking the first user's policy to the second user. A reflection-based unit test asserts no `userPolicy` / `cached` instance property exists on the class to catch the regression at lint time."
  - "Phase 07 Plan 04: enriched_from provenance entry's `added` list now includes per-field columns when conflict resolutions land, not just `source_ref`. Past entries that only enriched source_ref still serialise as `['source_ref']`; entries that resolved conflicts via prefer_receipt now serialise as `['source_ref','counterparty_name', ...]` so an audit reader sees exactly which columns the run modified."
  - "Phase 07 Plan 04: Field-conflict detection in FingerprintStage uses field-appropriate normalisation: case-insensitive + trimmed for counterparty_name + description (so silent whitespace / case differences between a receipt's clean name and a CSV's NLPAYPAL prefix don't count as conflicts); uppercase compare for currency (USD === usd is not a conflict); exact-int compare for amount_minor (no floating-point fuzz). Wave 1 receipts already normalise to startOfDay so booked_at is not in the conflict-relevant set."
  - "Phase 07 Plan 04: The first-conflict toast SFC checks `currentUser->id() === event.userId` defensively inside the #[On] handler even though local Livewire events should never carry a foreign userId. The defensive guard makes any future regression in event-dispatch shape fail-safe (silent no-op rather than rendering another user's conflict to the wrong viewer)."
  - "Phase 07 Plan 04: The Blade auto-dismiss assertion uses file_get_contents on the view path directly rather than rendering the SFC through Livewire and inspecting the response body. Livewire's test response has no public render() method — a previous attempt failed with `Method Illuminate\\Http\\Response::render does not exist`. The file-level audit is equivalent: a setTimeout / data-auto-dismiss / x-init in the Blade source is what the UI-SPEC forbids; the file-level grep catches it regardless of render path."
  - "Phase 07 Plan 04: Mockery cannot stub the final RuleEvaluator class — the project enforces `final` on services as a codebase-wide convention. The side-effect-free test bypasses mocking by DROPping the categorization_rules table mid-test; the evaluator's first query throws a real SQL exception which the stage swallows. Mirrors the same "throw a real error" approach used elsewhere for exception-handling tests instead of mockery-overload (which would conflict with the final-class convention)."
  - "Phase 07 Plan 04: ApplyEnrichments tests resolve the action AFTER Event::fake (via a `resolveApplier()` helper) rather than caching the singleton in `beforeEach`. Event::fake rebinds the Dispatcher contract; an action resolved before fake() carries the original Dispatcher and the fake never observes the dispatch. Resolving lazily inside each test is the canonical Laravel-test workaround."

patterns-established:
  - "Cross-module pipeline contract for sister-module stages — AppliesAutoCategory (Categorization Public) mirrors AppliesEnrichments + RecordsStatementSummary; ImportPipeline injects the contract and binds to the concrete Internal stage via service provider"
  - "Method-local cache for per-user policy on singleton actions — read the user setting ONCE per __invoke() into a local variable, pass it down to per-row helpers; never cache on the action instance"
  - "Reflection-based singleton-safety unit test — `expect(ReflectionClass::getProperties())->not->toContain('userPolicy')` catches a cross-user state-leak regression at lint time before it ships"
  - "PHP-side rule matching with mb_strtolower / mb_strpos — rule.value never reaches SQL, defeating SQL-injection at the matcher boundary even for substring/prefix operators"
  - "JOIN-on-counterparty_normalized for merchant identity — Wave 3 RuleEvaluator + MerchantMemoryWriter both derive merchant_id this way; future Categorization queries follow the same shape"
  - "Sync pipeline-stage with side-effect-free try/catch — ApplyAutoCategoryStage's try/catch around the evaluator + manual fallback is the precedent for any future Import pipeline stage that depends on a cross-module service that could fail (a sibling matcher, a slow merchant-categorisation API call)"
  - "Cross-user defensive guard on Livewire SFC event listeners — `if (currentUser.id !== event.userId) return;` inside the #[On] handler is the codebase-wide pattern for any event-bridged SFC"
  - "Blade no-auto-dismiss assertion via file_get_contents — when the SFC's render path through Livewire's test helpers does not expose response.render(), reading the view file directly + greppgging for setTimeout/data-auto-dismiss/x-init is the canonical audit"
  - "Resolve-after-fake helper for Event::fake tests — when the singleton action injects a Dispatcher, the test must resolve the action AFTER Event::fake so the action's injected Dispatcher is the fake"

requirements-completed: [CAT-02]

duration: ~33min
completed: 2026-05-17
---

# Phase 7 Plan 04: Categorization Learning Core + First-Conflict Toast Wave 3 Summary

**End-to-end Wave 3: every transaction imported via ANY source (CSV / CAMT / MT940 / PayPal / ICS PDF / email receipts) runs through RuleEvaluator's D-711 specificity scoring + the merchant-memory lookup; matching rows land with `categoryId` set AND `auto_category_provenance` JSON stamped. Every manual categorization grows merchant_memories via MerchantMemoryWriter so the SECOND import of the same merchant auto-suggests the user's prior choice (CAT-02 demo path live). Receipt-vs-CSV field-value conflicts are detected by FingerprintStage and either held in pending_enrichment_conflicts + surfaced via the first-conflict toast (unset policy) or silently applied per the user's prior choice (prefer_receipt / prefer_first_write).**

## Performance

- **Duration:** ~33 minutes
- **Started:** 2026-05-17T06:59:18Z (orchestrator hand-off)
- **Completed:** 2026-05-17T07:32:19Z
- **Tasks:** 4
- **Files created:** 19
- **Files modified:** 11
- **Test count:** 58 new tests landed (12 RuleEvaluatorSpecificity unit, 16 RuleEvaluator feature, 7 MerchantMemoryWriter feature, 6 ApplyAutoCategoryStage feature, 9 ApplyEnrichmentsConflict feature, 9 ReceiptConflictResolution feature — all green)
- **Full suite:** 1105 passed / 6 skipped / 1 pre-existing failure (TransactionTypeTest, documented as deferred from Wave 0)

## Accomplishments

- **CategorizationRule Eloquent model** shipped with BelongsToUser trait + casts (active boolean, hits_count integer, category_id integer, created_at/updated_at immutable_datetime). Future CRUD actions (plan 05) write to the same model.
- **RuleEvaluator** Internal service implementing the D-711 specificity scoring algorithm:
  - equals = 100
  - memory = 90
  - starts_with = 50 + mb_strlen(value)
  - contains = 10 + mb_strlen(value)
  - Tiebreaker: rule beats memory (loop evaluates rules first; strict `>` comparison guarantees rule at score 90 wins over memory at score 90)
  - mb_strtolower / mb_strpos for Unicode-safe case-insensitive comparisons evaluated in PHP — rule.value never reaches SQL (T-07-05 mitigation; Pest asserts transactions table still exists after a value containing SQL meta-characters runs).
  - Memory lookup derives merchant_id via JOIN on `merchants(user_id, normalized_name = counterpartyNormalized)`; the empty-counterparty sentinel skips the memory candidate. The (user_id, active) composite index on categorization_rules makes the rule pull an indexed read.
- **MerchantMemoryWriter** listener subscribing to `TransactionCategorized`:
  - Skips when categoryId is null (un-categorize is not a memory-grow event).
  - Reads counterparty_normalized off the transactions row (scoped by user_id), then JOINs merchants (user_id, normalized_name) to derive merchant_id — transactions has no merchant_id column.
  - Upserts merchant_memories via the (user_id, merchant_id, category_id) UNIQUE constraint: literal 1 on INSERT, atomic `occurrence_count + 1` on UPDATE.
  - Idempotent + monotonic on repeat fire (verified by 3-fire test → occurrence_count = 3).
- **CategorizationRuleQuery + MerchantMemoryQuery + MerchantMemoryDto** Public read services for the /rules page + future correction-divergence drawer panel.
- **ApplyAutoCategoryStage** Internal pipeline stage bound to the new **AppliesAutoCategory** Public contract (cross-module seam — ImportPipeline depends on the contract, never on a Categorization Internal class). Side-effect-free on failure: catches Throwable, logs at WARNING, returns `AutoCategorizationOutcomeDto::manual($tx)` so a buggy rule never aborts an import.
- **ImportPipeline** constructor extended with `AppliesAutoCategory $autoCategory`; per-row `apply()` call inserted between `classifier.run()` and `fingerprint.classify()` so every source format auto-categorises with one synchronous stage.
- **CanonicalTransaction** widened with `autoCategoryProvenance: ?array` field + `withCategoryId(?int)` + `withAutoCategoryProvenance(?array)` immutable withers; `toAttributes()` JSON-encodes the provenance for the new `transactions.auto_category_provenance` column.
- **Transaction Eloquent model** gains `auto_category_provenance` in `$fillable` + `casts()` (array). RecordTransactions writes the column via the existing `toAttributes() + insertOrIgnore` path with no per-task code change.
- **PendingEnrichment + EnrichedDisposition + FingerprintDisposition::enriched()** widened with `conflictingFields: array<string, {stored, incoming}>` defaulted to `[]` (backward compatible with every existing call site).
- **FingerprintStage.classify()** now fetches counterparty_name / description / currency / amount_minor on the ENRICHED branch and runs `detectConflicts()` to build the disagreement map. Normalisation per field: trim + mb_strtolower for strings, uppercase for currency, exact-int for amount_minor.
- **SourceRefRanker.isReceiptFormat(string)** centralised on the ranker — single source of truth for paypal-receipt / ics-receipt / google-play-receipt slugs; plan 05 + future matchers share this check.
- **ApplyEnrichments** conflict branch:
  - empty conflictingFields → unchanged Wave 1 pure source_ref enrichment.
  - unset + receipt format → INSERT pending_enrichment_conflicts per field + dispatch `ReceiptConflictDetected` per field, SKIP per-field write (source_ref still enriches).
  - unset + non-receipt format → keep stored value silently (the toast is the receipt-vs-CSV mitigation only).
  - prefer_receipt → apply incoming values in the same UPDATE silently.
  - prefer_first_write → skip per-field write; source_ref still enriches.
  - User policy read into a **method-local variable per `__invoke()` call** — NO instance-level cache (T-07-09 singleton cross-user defence).
  - `enriched_from` provenance entry's `added` list now includes per-field columns when conflict resolutions land.
- **ApplyReceiptConflictResolution** Public action: validates `$choice` against literal whitelist (T-07-11), UPDATEs `users.receipt_conflict_resolution`, resolves every held pending row per the policy, DELETEs each pending row. All reads + writes scoped by `user_id` (T-07-09; cross-user defence verified by feature test).
- **ReceiptConflictQuery** Public service: `latestForUser(User)` returns the most-recent pending conflict for the SFC to display on mount.
- **ReceiptConflictToast** Livewire SFC: services on action methods (Livewire convention), `#[On('receipt-conflict-detected')]` event listener with cross-user defensive guard, `mount()` DB-pull fallback for backfill scenarios, `useReceipt` + `keepStatement` actions wired to the Public action.
- **Blade view** per UI-SPEC § First-conflict receipt toast: locked copy ("Receipt and statement disagree.", "Use receipt", "Keep statement"), Phase 5 toast chrome (bottom-right max-w-sm rounded-lg border bg-white shadow-lg p-md), `role="alert"` + `aria-live="assertive"`, NO auto-dismiss.

## Task Commits

1. **Task 1: CategorizationRule + RuleEvaluator + MerchantMemoryWriter** — `b547fd1` (feat)
   - CategorizationRule Eloquent model + RuleEvaluator (D-711 scoring) + RuleEvaluationOutcome DTO
   - MerchantMemoryWriter listener (atomic occurrence_count increment)
   - CategorizationRuleQuery + MerchantMemoryQuery + MerchantMemoryDto Public services
   - 34 tests: 12 specificity-scoring unit + 16 rule-evaluator feature + 7 memory-writer feature (incl. SQL-injection-as-literal + cross-user defence)

2. **Task 2: ApplyAutoCategoryStage + ImportPipeline + provenance JSON wiring** — `7463250` (feat)
   - AppliesAutoCategory Public contract + ApplyAutoCategoryStage Internal stage (side-effect-free on failure)
   - CanonicalTransaction widened with autoCategoryProvenance + withCategoryId + withAutoCategoryProvenance withers
   - Transaction model auto_category_provenance fillable + array cast
   - ImportPipeline constructor extension + sync stage placement (classifier → autoCategory → fingerprint)
   - 6 ApplyAutoCategoryStage tests incl. end-to-end RecordTransactions persisting the JSON column

3. **Task 3a: PendingEnrichment + FingerprintStage + ApplyEnrichments conflict branch** — `e483784` (feat)
   - PendingEnrichment / EnrichedDisposition / FingerprintDisposition::enriched() widened with conflictingFields
   - FingerprintStage.classify() detectConflicts() with field-appropriate normalisation
   - SourceRefRanker.isReceiptFormat() centralised
   - ApplyEnrichments per-policy conflict branch + Dispatcher DI + method-local user policy + enriched_from added-column list
   - 9 ApplyEnrichmentsConflictTest cases incl. cross-user T-07-09 + singleton-safety reflection assertion + two-user policy independence

4. **Task 3b: ApplyReceiptConflictResolution + ReceiptConflictToast SFC** — `3f6280e` (feat)
   - ApplyReceiptConflictResolution Public action (T-07-11 whitelist + T-07-09 user-scoped)
   - ReceiptConflictQuery Public service (latestForUser for SFC mount fallback)
   - ReceiptConflictToast Livewire SFC with cross-user event guard + DB-pull mount
   - Blade view per UI-SPEC locked copy + Phase 5 toast chrome + NO auto-dismiss
   - ReceiptsServiceProvider singleton bindings + Livewire component registration
   - 9 ReceiptConflictResolutionTest cases incl. enum throw + two policy branches + cross-user + SFC mount + action flows + Blade audit + cross-user event guard

## Files Created/Modified

See `key-files` frontmatter above.

## Decisions Made

See `key-decisions` frontmatter above. 9 architectural / implementation decisions surfaced during execution.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Plan's PATTERNS excerpt referenced `$tx->merchantId` which does not exist on CanonicalTransaction**

- **Found during:** Task 1 (RuleEvaluator design)
- **Issue:** The plan's PATTERNS Wave 3 excerpt (lines 627-664) showed `$tx->merchantId === null` as the memory-lookup gate, but CanonicalTransaction has NO `merchantId` property — its 22 fields include `counterpartyNormalized` but no merchant id (the plan's own action text in 07-04-PLAN.md acknowledged this and instructed the JOIN-via-merchants approach). The plan-as-written would have produced a class-property error at the first test run.
- **Fix:** Implemented the documented JOIN approach: RuleEvaluator pulls the merchant row via `merchants AS m INNER JOIN merchant_memories AS mm ON mm.merchant_id = m.id WHERE m.user_id = ? AND m.normalized_name = ?`, with the empty-counterparty sentinel `NormalizeStage::NO_COUNTERPARTY` short-circuiting the memory lookup. Same shape for MerchantMemoryQuery.latestForCounterpartyNormalized().
- **Files modified:** `Modules/Categorization/Internal/Services/RuleEvaluator.php`, `Modules/Categorization/Public/Services/MerchantMemoryQuery.php`
- **Verification:** RuleEvaluatorTest covers memory-only / rule-only / both-match / empty-sentinel cases; all 16 cases green.
- **Committed in:** `b547fd1` (Task 1)

**2. [Rule 1 - Bug] PATTERNS excerpt for MerchantMemoryWriter referenced `transactions.merchant_id` which does not exist**

- **Found during:** Task 1 (MerchantMemoryWriter design)
- **Issue:** The plan's PATTERNS excerpt (lines 666-708) said "Read merchant_id off the transaction" with `$txRow->merchant_id`. The transactions table schema (verified via `grep merchant_id Modules/Ledger/Database/Migrations/`) shows merchant_id appears ONLY on merchant_memories — never on transactions itself.
- **Fix:** MerchantMemoryWriter now reads `counterparty_normalized` off the transaction row (scoped by user_id), then JOINs merchants (user_id, normalized_name) to derive merchant_id. Mirrors the RuleEvaluator's JOIN shape so both writers use the same merchant-identity logic. Empty-counterparty sentinel + absent-merchant-row both short-circuit gracefully.
- **Files modified:** `Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php`
- **Verification:** MerchantMemoryWriterTest covers the empty-sentinel and absent-merchant skip cases + the foreign-user defence; all 7 cases green.
- **Committed in:** `b547fd1` (Task 1)

**3. [Rule 1 - Bug] CanonicalTransaction wither method uses ?? fallthrough incorrectly when the caller wants to clear the field**

- **Found during:** Task 2 (CanonicalTransaction wither design)
- **Issue:** First draft of `withCategoryId(?int $categoryId)` used a centralised `cloneWith()` helper that did `$categoryId ?? $this->categoryId` — meaning `withCategoryId(null)` would NOT clear the field (the null coalesces to the current value). For our specific call site (ApplyAutoCategoryStage only calls with non-null values) this would have been latent, but the API would mislead future callers.
- **Fix:** Inlined each wither to explicitly construct the new instance with the new value passed verbatim. `withCategoryId(null)` now clears the field as documented in the PHPDoc.
- **Files modified:** `Modules/Ledger/Public/Dto/CanonicalTransaction.php`
- **Verification:** PHPStan max + Pint green; existing CanonicalTransaction tests continue to pass.
- **Committed in:** `7463250` (Task 2)

**4. [Rule 3 - Blocking] Mockery cannot stub the final RuleEvaluator class for the side-effect-free test**

- **Found during:** Task 2 (ApplyAutoCategoryStageTest TDD GREEN)
- **Issue:** First draft of the side-effect-free test used `Mockery::mock(RuleEvaluator::class)` to throw a synthetic exception. Mockery rejected the request: "The class \Modules\Categorization\Internal\Services\RuleEvaluator is marked final and its methods cannot be replaced." The codebase enforces `final` on services as a convention; Mockery's `overload:` prefix would conflict with that.
- **Fix:** Replaced the mock with a real DB-induced failure — the test drops the `categorization_rules` table mid-test, the evaluator's first query throws a real SQL exception, and ApplyAutoCategoryStage swallows it per the side-effect-free invariant. The test now verifies the actual production exception path rather than a mocked synthetic one.
- **Files modified:** `Modules/Categorization/tests/Feature/ApplyAutoCategoryStageTest.php`
- **Verification:** 6/6 ApplyAutoCategoryStage tests green; the side-effect-free behaviour is now exercised against a real SQL exception.
- **Committed in:** `7463250` (Task 2)

**5. [Rule 1 - Bug] Event::fake rebinds the Dispatcher AFTER ApplyEnrichments was already resolved in beforeEach**

- **Found during:** Task 3a (ApplyEnrichmentsConflictTest TDD GREEN)
- **Issue:** The first draft cached `$this->applier = $this->app->make(AppliesEnrichments::class)` in `beforeEach`. Then each test called `Event::fake([ReceiptConflictDetected::class])` which rebinds the Dispatcher contract. The action's injected Dispatcher was already resolved → the dispatch went to the original (not the fake) → `Event::assertDispatched` saw zero dispatches.
- **Fix:** Added a `resolveApplier()` helper function that resolves the action AFTER `Event::fake()` is set up. Tests call `(resolveApplier())([...])` instead of `($this->applier)([...])`. Canonical Laravel-test workaround when the action injects a Dispatcher.
- **Files modified:** `Modules/Categorization/tests/Feature/ApplyEnrichmentsConflictTest.php`
- **Verification:** All 9 conflict tests green.
- **Committed in:** `e483784` (Task 3a)

**6. [Rule 1 - Bug] Test seeded pending_enrichment_conflicts row with bogus transaction_id (999999) — FK violation**

- **Found during:** Task 3a (cross-user T-07-09 test)
- **Issue:** First draft seeded a "foreign" pending_enrichment_conflicts row with `transaction_id => 999999` to set up the cross-user defence test. The pending_enrichment_conflicts table has `foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete()` — the FK constraint rejected the bogus id at insert time.
- **Fix:** Updated the test to seed a real foreign transaction owned by the other user (via the new `seedConflictTransaction` helper with `$other` + `$otherAccount`), then seeded the pending row with that real transaction.id. The cross-user defence assertion is unchanged: the action only touches rows whose `user_id` matches the current user.
- **Files modified:** `Modules/Categorization/tests/Feature/ApplyEnrichmentsConflictTest.php`
- **Verification:** Cross-user test green.
- **Committed in:** `e483784` (Task 3a)

**7. [Rule 1 - Bug] Test passed `importRunId: 99` to PendingEnrichment but no ImportRun with id=99 existed → FK violation on holdConflicts**

- **Found during:** Task 3a (unset-policy conflict test + two-user test)
- **Issue:** `PendingEnrichment.importRunId` flows into the `pending_enrichment_conflicts.import_run_id` column, which is `foreignId(...)->constrained('import_runs')->nullOnDelete()`. The FK constraint rejected the bogus `99` at insert time.
- **Fix:** Updated all conflict-creating tests to pass `$tx->import_run_id` (the real id from the seeded transaction's ImportRun) into PendingEnrichment. Tests that don't reach holdConflicts (no-conflict / prefer_receipt / prefer_first_write / non-receipt) still work with `importRunId: 99` because those paths never call `pending_enrichment_conflicts.insertOrIgnore`.
- **Files modified:** `Modules/Categorization/tests/Feature/ApplyEnrichmentsConflictTest.php`
- **Verification:** All 9 conflict tests green.
- **Committed in:** `e483784` (Task 3a)

**8. [Rule 2 - Critical Functionality] FingerprintStage's unused CONFLICT_FIELDS constant tripped PHPStan max**

- **Found during:** Task 3a (PHPStan run on Modules)
- **Issue:** First draft of FingerprintStage declared `private const CONFLICT_FIELDS = ['counterparty_name', ...]` as documentation of which fields the conflict-detection inspects. PHPStan max's `classConstant.unused` rule flagged it because the four field names are hard-coded as direct property accesses in `detectConflicts()` (each field has bespoke normalisation logic that does not loop over the array).
- **Fix:** Removed the unused constant. The PHPDoc inside `detectConflicts()` lists the four fields verbosely with the normalisation rationale, which is a more useful documentation site than a constant that didn't drive any logic.
- **Files modified:** `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php`
- **Verification:** PHPStan max green on the whole Modules tree.
- **Committed in:** `e483784` (Task 3a)

**9. [Rule 1 - Bug] Livewire test response has no public render() method for the auto-dismiss audit**

- **Found during:** Task 3b (ReceiptConflictResolutionTest auto-dismiss test)
- **Issue:** First draft used `Livewire::test(ReceiptConflictToast::class)->render()` to grab the rendered HTML for the no-auto-dismiss assertion. The Livewire test response is an `Illuminate\Http\Response` instance which has no public `render()` method — the call throws `BadMethodCallException: Method Illuminate\Http\Response::render does not exist.`
- **Fix:** Replaced the SFC render with a direct `file_get_contents` on the Blade view path. The audit is equivalent — a setTimeout / data-auto-dismiss / x-init in the Blade source is what the UI-SPEC forbids, and the file-level grep catches it regardless of render path. The test now asserts on the Blade source text.
- **Files modified:** `Modules/Receipts/tests/Feature/ReceiptConflictResolutionTest.php`
- **Verification:** All 9 ReceiptConflictResolution tests green.
- **Committed in:** `3f6280e` (Task 3b)

**10. [Rule 2 - Critical Functionality] PHPStan max flagged useless `(int) cast` on DatabaseManager.transaction()'s int-typed closure return**

- **Found during:** Task 3b (PHPStan run on Modules/Receipts)
- **Issue:** First draft of ApplyReceiptConflictResolution.__invoke had `return (int) $this->db->connection()->transaction(function (): int { ... });`. PHPStan max's `cast.useless` rule flagged the cast because the closure's `: int` return type already narrows the value to `int<0, max>`.
- **Fix:** Removed the redundant cast — `return $this->db->connection()->transaction(...)` returns the int directly.
- **Files modified:** `Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php`
- **Verification:** PHPStan max green on the whole Modules tree.
- **Committed in:** `3f6280e` (Task 3b)

---

**Total deviations:** 10 auto-fixed (5 Rule 1 bugs, 3 Rule 2 critical functionality, 2 Rule 3 blocking). The two largest deviations (#1 + #2) were inconsistencies between the plan's PATTERNS code excerpts and the actual schema — the plan's action text correctly described the JOIN-via-merchants approach, but the PATTERNS excerpts (which the executor would normally cargo-cult) referenced `$tx->merchantId` / `$txRow->merchant_id` neither of which exists. The fixes implemented the action-text intent. No architectural changes (Rule 4) required.

**Impact on plan:** All deviations were necessary for correctness or test green-ness. No scope creep — every file landed is on the plan's file list except `Modules/Categorization/Internal/Services/RuleEvaluationOutcome.php` (the documented internal return-shape DTO the plan's action text specifies) and `Modules/Categorization/Public/Dto/MerchantMemoryDto.php` (the documented readonly DTO MerchantMemoryQuery returns, also referenced by the plan's action text). Both are sibling files to plan-listed files in the same module/namespace, not architecture extensions.

## Issues Encountered

- **Pre-existing TransactionTypeTest failure (out of scope):** `Modules\\Ledger\\tests\\Unit\\TransactionTypeTest::it-rejects-an-invalid-transaction-type` continues to fail in the full suite. Documented as deferred in Wave 0 + Wave 1 + Wave 2 SUMMARIes. Verified pre-existing: not caused by any plan 04 change.

## Process Deviations

None. All commits made on main branch (no worktree was created by the orchestrator); no protected-ref operations; no `git stash`; no destructive operations.

## Known Stubs

- **CategorizationRule has no CRUD action shipped in this plan.** Plan 05 adds CreateCategorizationRule / UpdateCategorizationRule / DeleteCategorizationRule Public actions + the /rules page. RuleEvaluator currently reads rules from raw DB seeds — the test-only path. End-user rule creation lands in plan 05.
- **ReceiptConflictToast is not yet mounted globally in `resources/views/layouts/app.blade.php`.** Plan 05 adds the layout mount alongside the other 2 globally-mounted SFCs (RuleFormModal + CorrectionDivergenceToast) — this is the plan-stated boundary. The SFC class + Blade view are shipped now so plan 05 only needs to add the `@livewire('receipts.receipt-conflict-toast')` line.
- **`hits_count` denormalised counter on categorization_rules is NOT incremented by ApplyAutoCategoryStage in this plan.** Plan 05 (the /rules page that surfaces the hits column) is the natural site for the increment. Adding it now would have required tracking the rule_id from AutoCategorizationOutcomeDto through to a side-effect on the rules table, which the plan does not require for the CAT-02 demo path.

## Threat Flags

None. The plan's `<threat_model>` covers:
- **T-07-05** (SQL injection via rule.value) — mitigated by PHP-side match evaluation (mb_strtolower / mb_strpos); the rule.value never reaches the SQL string. Pest asserts a value containing `"Robert'; DROP TABLE transactions--"` matches as literal substring and the transactions table is still present post-test.
- **T-07-09** (cross-user merchant_memory / rule / pending-conflict leak) — mitigated by `where('user_id', $user->id)` on every read in RuleEvaluator, MerchantMemoryWriter, CategorizationRuleQuery, MerchantMemoryQuery, ApplyEnrichments, ApplyReceiptConflictResolution, and ReceiptConflictQuery. Cross-user feature tests pass for every action. The singleton-safety reflection unit test on ApplyEnrichments catches the regression at lint time.
- **T-07-11** (ApplyReceiptConflictResolution accepts arbitrary $choice) — mitigated by literal whitelist `in_array($choice, ['prefer_receipt', 'prefer_first_write'], true)` with `InvalidArgumentException` throw on mismatch. The DB-layer trigger on `users.receipt_conflict_resolution` enum provides defence-in-depth so a direct UPDATE bypass would also fail.

No new threat surface introduced.

## Self-Check: PASSED

**Created files (spot check via `test -f`):**
- `Modules/Categorization/Models/CategorizationRule.php` — FOUND
- `Modules/Categorization/Internal/Services/{RuleEvaluator,RuleEvaluationOutcome}.php` — ALL FOUND
- `Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php` — FOUND
- `Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php` — FOUND
- `Modules/Categorization/Public/Contracts/AppliesAutoCategory.php` — FOUND
- `Modules/Categorization/Public/Services/{CategorizationRuleQuery,MerchantMemoryQuery}.php` — ALL FOUND
- `Modules/Categorization/Public/Dto/MerchantMemoryDto.php` — FOUND
- `Modules/Categorization/tests/Unit/RuleEvaluatorSpecificityTest.php` — FOUND
- `Modules/Categorization/tests/Feature/{RuleEvaluator,MerchantMemoryWriter,ApplyAutoCategoryStage,ApplyEnrichmentsConflict}Test.php` — ALL FOUND
- `Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php` — FOUND
- `Modules/Receipts/Public/Services/ReceiptConflictQuery.php` — FOUND
- `Modules/Receipts/Internal/Http/Livewire/ReceiptConflictToast.php` — FOUND
- `Modules/Receipts/Resources/views/livewire/receipt-conflict-toast.blade.php` — FOUND
- `Modules/Receipts/tests/Feature/ReceiptConflictResolutionTest.php` — FOUND
- This SUMMARY.md — about to be created/committed

**Commits (verified via `git log --oneline | grep`):**
- `b547fd1` (Task 1 — CategorizationRule + RuleEvaluator + MerchantMemoryWriter) — FOUND
- `7463250` (Task 2 — ApplyAutoCategoryStage + ImportPipeline + provenance) — FOUND
- `e483784` (Task 3a — PendingEnrichment + FingerprintStage + ApplyEnrichments conflict) — FOUND
- `3f6280e` (Task 3b — ApplyReceiptConflictResolution + ReceiptConflictToast SFC) — FOUND

**Verification:**
- 58 new Wave 3 tests green (12 specificity + 16 rule-evaluator + 7 memory-writer + 6 auto-cat + 9 conflict + 9 receipt-conflict-resolution)
- 1105 full-suite tests passing (6 skipped legitimately; 1 pre-existing TransactionTypeTest failure carried forward since Wave 0)
- PHPStan max + Pint green on every touched file (Modules/Categorization + Modules/Import + Modules/Ledger + Modules/Receipts)
- BoundaryArchTest 20/20 green incl. Modules/Categorization Internal containment + Modules/Receipts Internal containment + noEmailFetchFromReceipts (no new boundary violations from this plan)

## Next Phase Readiness

Wave 3 backend + first-conflict toast SFC are complete. Wave 4 (Plan 07-05) inherits:
- CategorizationRule model + RuleEvaluator + ApplyAutoCategoryStage live; only the /rules page CRUD + RuleFormModal + the layout mount for ReceiptConflictToast + CorrectionDivergenceToast + RuleFormModal SFCs remain.
- merchant_memories grows automatically on every TransactionCategorized event so the CAT-04 "auto-categorize from history" UI work has real data to render.
- AppliesAutoCategory contract is bound; future correction-divergence flow reads `transactions.auto_category_provenance` to render the rule-that-fired panel.
- ApplyReceiptConflictResolution + ReceiptConflictToast SFC + Blade view are ready; plan 05 adds the global mount line in `resources/views/layouts/app.blade.php` (one-line edit alongside the other two new SFCs).

Phase 4 deferred-items still pending: `Modules\\Ledger\\tests\\Unit\\TransactionTypeTest::it-rejects-an-invalid-transaction-type` (environment-shaped Pest harness issue carried forward since Wave 0).

---
*Phase: 07-email-template-matchers-categorization-learning*
*Plan: 04*
*Completed: 2026-05-17*
