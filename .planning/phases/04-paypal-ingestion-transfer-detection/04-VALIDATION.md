---
phase: 4
slug: paypal-ingestion-transfer-detection
status: ready-for-execution
nyquist_compliant: true
wave_0_complete: false
created: 2026-05-15
plans_written: 2026-05-15
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: `04-RESEARCH.md` § "Validation Architecture" + the five concrete plans `04-{01..05}-PLAN.md`.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.0 (PHPUnit 11 engine), `pest-plugin-laravel ^4.0`, `pest-plugin-arch ^4.0`, `pest-plugin-snapshots ^2.0` |
| **Config file** | `phpunit.xml` (project root); module-local `Modules/<m>/tests/Pest.php` per Phase 1 convention |
| **Quick run command** | `vendor/bin/pest --filter "PaypalCsvAdapter\|PaypalTransactionRollup\|PairTransferCandidates\|ClassifyTransactionType\|TransactionDetailReclassify"` |
| **Full suite command** | `composer test` (alias for `pest --parallel`) |
| **Static-analysis gate** | `composer analyse` (Larastan level 10 strict — zero new errors) |
| **Style gate** | `composer format:check` (Laravel Pint — clean) |
| **Estimated runtime** | quick filter ~5s · full suite ~45s (Phase 3 baseline +~10s for Phase 4 surface) |

---

## Sampling Rate

- **After every task commit:** Run quick filter on the task scope (e.g. `vendor/bin/pest --filter PaypalTransactionRollupTest` during walker work)
- **After every plan wave:** Run full suite (`composer test`) + `composer analyse` + `composer format:check`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 10s for per-task quick filter

---

## Per-Task Verification Map

| Req | Behavior (success criterion link) | Test Type | Automated Command | File Exists | Wave | Plan / Task |
|-----|-----------------------------------|-----------|-------------------|-------------|------|-------------|
| ING-05 | PayPal CSV row → ONE `SourceTransactionDto` per rolled-up logical payment (SC #1) | unit | `vendor/bin/pest --filter PaypalTransactionRollupTest` | created in W1 | 1 | 04-02 Task 1 |
| ING-05 | Filtered event types (Hold / Authorization / Reserve / Reversal) skipped at adapter boundary | unit | `vendor/bin/pest --filter "PaypalCsvAdapterTest::skipsHoldRows"` | created in W1 | 1 | 04-02 Task 2 |
| ING-05 | Currency-conversion pair rolls up into one canonical row with both legs (LED-03 reuse) | unit | `vendor/bin/pest --filter "PaypalTransactionRollupTest::foldsCurrencyConversion"` | created in W1 | 1 | 04-02 Task 1 |
| ING-05 | Re-importing the same PayPal CSV produces zero new rows (idempotency) | contract | `vendor/bin/pest --filter "IdempotencyContractTest" --group phase-4` | extended in W0 (RED), GREEN by W1 | 0→1 | 04-01 Task 3 (RED) → 04-02 Task 3 (GREEN) |
| ING-05 | Reconciliation gate: `sum(net) == closingBalance - openingBalance` (Pitfall 3) | unit | `vendor/bin/pest --filter "PaypalCsvAdapterTest::reconciles"` | created in W1 | 1 | 04-02 Task 2 |
| ING-05 | `HeaderSniffer::sniffPaypalCsv()` rejects non-PayPal CSV at boundary | feature | `vendor/bin/pest --filter "HeaderSnifferPaypalTest::rejectsBadPaypalCsv"` | created in W1 | 1 | 04-02 Task 2 |
| ING-05 | End-to-end: upload via wizard → preview → confirm → row count matches fixture | feature | `vendor/bin/pest --filter "PaypalCsvImportTest"` | created in W1 | 1 | 04-02 Task 3 |
| ING-05 | UploadWizard accepts paypal-csv issuer/format pair | feature | `vendor/bin/pest --filter "UploadWizardPaypalTest"` | created in W1 | 1 | 04-02 Task 3 |
| ING-09 | API path is deferred; no `paypal-api` route exists (D-79) | arch | `vendor/bin/pest --filter "BoundaryArchTest::noPaypalApiRoute"` AND `grep "Deferred" REQUIREMENTS.md` shows ING-09 row | created in W4 | 4 | 04-05 Task 1 |
| LED-04 | After both legs of an ASN→ICS settlement land, `pair_transaction_id` is set on both rows (SC #3) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::pairsAsnIcsSettlement"` | created in W2 | 2 | 04-03 Task 3 |
| LED-04 | Half-pair: one leg present → `type='transfer_out'` AND `pair_transaction_id IS NULL` (D-74) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::halfPair"` | created in W2 | 2 | 04-03 Task 3 |
| LED-04 | Partner lands later → BOTH `pair_transaction_id` columns populated atomically in one DB tx | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::partnerLandsLater"` | created in W2 | 2 | 04-03 Task 3 |
| LED-04 | Listener does NOT re-type already-classified rows (D-76 decoupling) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::doesNotRetype"` | created in W2 | 2 | 04-03 Task 3 |
| LED-04 | Listener writes BOTH sides symmetrically | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::listenerWritesBothSidesSymmetrically"` | created in W2 | 2 | 04-03 Task 3 |
| LED-04 | Listener cannot self-pair (`pair_transaction_id != id`) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::cannotSelfPair"` | created in W2 | 2 | 04-03 Task 3 |
| LED-04 | Cross-user safety: listener never pairs across `user_id` boundaries (T-04-04) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::doesNotCrossUsers"` | created in W2 | 2 | 04-03 Task 3 |
| LED-04 | Schema enforces `pair_transaction_id` FK with ON DELETE SET NULL | feature | `vendor/bin/pest --filter "SchemaTest::pairTransactionFk"` (raw SQLite PRAGMA) | created in W2 | 2 | 04-03 Task 1 |
| LED-04 | Partial index `transactions_unpaired_transfer_idx` exists | feature | `vendor/bin/pest --filter "SchemaTest::partialIndexExists"` | created in W2 | 2 | 04-03 Task 1 |
| LED-04 | ON DELETE SET NULL cascades correctly when partner is hard-deleted | feature | `vendor/bin/pest --filter "SchemaTest::onDeleteSetNullCascades"` | created in W2 | 2 | 04-03 Task 1 |
| LED-04 | Reclassifying one side via D-78 clears `pair_transaction_id` on BOTH rows | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::breaksPair"` | created in W3 | 3 | 04-04 Task 1 |
| LED-05 | Positive amount NOT (transfer / refund / fee) → `type='income'` (SC #4) | feature | `vendor/bin/pest --filter "ClassifyTransactionTypeTest::detectsIncome"` | created in W2 | 2 | 04-03 Task 2 |
| LED-05 | Positive amount that IS a `transfer_in` stays `transfer_in`, NOT `income` | feature | `vendor/bin/pest --filter "ClassifyTransactionTypeTest::transferInIsNotIncome"` | created in W2 | 2 | 04-03 Task 2 |
| LED-05 | PayPal `Refund` event-type row → `type='refund'`, NOT `income` | feature | `vendor/bin/pest --filter "PaypalCsvAdapterTest::classifiesRefund"` | created in W1 | 1 | 04-02 Task 2 |
| LED-05 | Manual override changes `type` and persists | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::changesType"` | created in W3 | 3 | 04-04 Task 1 |
| LED-05 | Reclassify preserves pair on transfer→transfer reclassify | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::preservesPairOnTransferToTransfer"` | created in W3 | 3 | 04-04 Task 1 |
| LED-05 | Cross-user reclassify returns 404 (cross-user 404 pattern from Phase 3-07) | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::crossUser404"` | created in W3 | 3 | 04-04 Task 1 |
| LED-05 | Invalid type rejected | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::rejectsInvalidType"` | created in W3 | 3 | 04-04 Task 1 |
| LED-05 | Dashboard "income" rollup never includes a `transfer_in` row | feature | `vendor/bin/pest --filter "DashboardIncomeTest::excludesTransfers"` | created in W3 | 3 | 04-04 Task 2 |
| LED-05 | Dashboard "income" rollup includes only `type='income'` rows | feature | `vendor/bin/pest --filter "DashboardIncomeTest::includesIncome"` | created in W3 | 3 | 04-04 Task 2 |
| LED-05 | Dashboard "expense" rollup excludes `transfer_out` rows | feature | `vendor/bin/pest --filter "DashboardIncomeTest::expenseTileExcludesTransfers"` | created in W3 | 3 | 04-04 Task 2 |
| (W0 contract) | TransactionImported event fires per inserted row, not for duplicates | feature | `vendor/bin/pest --filter "RecordTransactionsDispatchesEvent"` | created in W0 | 0 | 04-01 Task 3 |
| ING-05 (parser) | PayPal US-locale amounts parsed integer-only | unit | `vendor/bin/pest --filter "PaypalAmountParserTest"` | created in W1 | 1 | 04-02 Task 1 |
| ING-05 (parser) | PayPal US-locale dates parsed at startOfDay | unit | `vendor/bin/pest --filter "PaypalDateParserTest"` | created in W1 | 1 | 04-02 Task 1 |

*Status legend: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky · W0/W1/W2/W3/W4 = wave delivery slot*

---

## Wave 0 Requirements (plan 04-01)

- [ ] `local/paypal/.gitkeep` + verify `/local/` already gitignored (Task 1)
- [ ] `scripts/anonymize_paypal_csv.php` — idempotent two-pass deterministic anonymisation script (Task 1)
- [ ] `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` — committed redacted PayPal Activity Download fixture (Task 1)
- [ ] `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md` — fixture-record documentation (Task 1)
- [ ] `04-WAVE-0-FINDINGS.md` — D-60 (a–g) empirical reporting set (Task 1)
- [ ] `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php` — skeleton with Wave-0-empirical token vocabulary (Task 2)
- [ ] `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php` — skeleton with Wave-0-empirical event-type → classification map (Task 2)
- [ ] `Modules/Ingestion/Public/Exceptions/UnsupportedPaypalCsvLanguageException.php` (Task 2)
- [ ] `Modules/Ingestion/Public/Exceptions/UnknownPaypalEventTypeException.php` (Task 2)
- [ ] `Modules/Import/Public/Events/TransactionImported.php` — event class (Task 3)
- [ ] `RecordTransactions` fires `TransactionImported` per persisted row via constructor-DI Dispatcher (Task 3)
- [ ] `tests/Contracts/IdempotencyContractTest.php` — extended with `'paypal-csv'` dataset row (RED baseline) (Task 3)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Wave-0 anonymisation preserves parent-child links visually | ING-05 | Anonymisation correctness is fixture quality — automated diff against the unanonymised file would itself leak data | Run `scripts/anonymize_paypal_csv.php local/paypal/raw.csv > Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv`; visually verify each `Reference Txn ID` points at a `Transaction ID` that exists in the file |
| PayPal CSV reconciliation soft-warning copy reads calm/Linear | ING-05 (UI) | Visual aesthetic — out of scope for assertions | Open wizard with a reconciliation-mismatch fixture; confirm copy matches UI-SPEC; calm Linear-style |
| Three-issuer wizard ordering / aria-live cascade still feels right | D-69 | UX | Open `/import/upload` and confirm three issuer cards (ASN / ICS / PayPal) cascade format options without flicker; aria-live unchanged from Phase 3 |
| Reclassify dropdown calm aesthetic + single-click + inline toast | D-78 | UX | Open `/transactions/{id}`; reclassify; confirm toast appears; visual density matches Linear/Notion baseline |
| ROADMAP.md Phase 4 wording reflects ING-09 deferral | ING-09 | Doc edit | `git diff .planning/ROADMAP.md` shows SC #2 rewritten as "API path documented as deferred behind business-account trigger" |
| Phase 4 SC #3 end-to-end demo: import CAMT.053 + ICS PDF, see pair link | LED-04 | Operator demo | Use real or Phase 2/3 fixtures with matching ICS-CARD synthetic IBAN counterparty; confirm both rows have `pair_transaction_id` set in the DB |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies declared
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all RED references in the Per-Task Verification Map
- [x] No watch-mode flags (`--watch`) anywhere in commands
- [x] Feedback latency < 10s for quick filter
- [x] `nyquist_compliant: true` set in frontmatter once planner writes plans and updates this table with concrete task IDs

**Approval:** ready for `/gsd-execute-phase 04`
