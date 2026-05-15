---
phase: 4
slug: paypal-ingestion-transfer-detection
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-15
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: `04-RESEARCH.md` § "Validation Architecture" (the canonical Phase Requirements → Test Map).

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

> Task IDs land once plans are written; this section lists the contract-level tests every plan MUST hold. The planner expands each test into a concrete task and updates the table.

| Req | Behavior (success criterion link) | Test Type | Automated Command | File Exists | Wave |
|-----|-----------------------------------|-----------|-------------------|-------------|------|
| ING-05 | PayPal CSV row → ONE `SourceTransactionDto` per rolled-up logical payment (SC #1) | unit | `vendor/bin/pest --filter PaypalTransactionRollupTest` | ❌ W0/W1 | 0–1 |
| ING-05 | Filtered event types (Hold / Authorization / Reserve / Reversal) skipped at adapter boundary | unit | `vendor/bin/pest --filter "PaypalCsvAdapterTest::skipsHoldRows"` | ❌ W1 | 1 |
| ING-05 | Currency-conversion pair rolls up into one canonical row with both legs (LED-03 reuse) | unit | `vendor/bin/pest --filter "PaypalTransactionRollupTest::foldsCurrencyConversion"` | ❌ W1 | 1 |
| ING-05 | Re-importing the same PayPal CSV produces zero new rows (idempotency) | contract | `vendor/bin/pest --filter "IdempotencyContractTest" --group phase-4` | ✓ extends `tests/Contracts/IdempotencyContractTest.php` (add `paypal-csv` dataset row in W0 RED) | 0→1 |
| ING-05 | Reconciliation gate: `sum(net) == closingBalance - openingBalance` (Pitfall 3) | unit | `vendor/bin/pest --filter "PaypalCsvAdapterTest::reconciles"` | ❌ W1 | 1 |
| ING-05 | `HeaderSniffer::sniffPaypalCsv()` rejects non-PayPal CSV at boundary | feature | `vendor/bin/pest --filter "HeaderSnifferTest::rejectsBadPaypalCsv"` | ❌ W1 | 1 |
| ING-05 | End-to-end: upload via wizard → preview → confirm → row count matches fixture | feature | `vendor/bin/pest --filter "PaypalCsvImportTest"` | ❌ W1 | 1 |
| ING-09 | API path is deferred; no `paypal-api` route exists (D-79) | arch | `vendor/bin/pest --filter "BoundaryArchTest::noPaypalApiRoute"` and `REQUIREMENTS.md` "Deferred" section contains ING-09 | ❌ W4 | 4 |
| LED-04 | After both legs of an ASN→ICS settlement land, `pair_transaction_id` is set on both rows (SC #3) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::pairsAsnIcsSettlement"` | ❌ W2 | 2 |
| LED-04 | Half-pair: one leg present → `type='transfer_out'` AND `pair_transaction_id IS NULL` (D-74) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::halfPair"` | ❌ W2 | 2 |
| LED-04 | Partner lands later → BOTH `pair_transaction_id` columns populated atomically in one DB tx | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::partnerLandsLater"` | ❌ W2 | 2 |
| LED-04 | Listener does NOT re-type already-classified rows (D-76 decoupling) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::doesNotRetype"` | ❌ W2 | 2 |
| LED-04 | Listener writes BOTH sides symmetrically | arch | `vendor/bin/pest --filter "BoundaryArchTest::listenerWritesBothSides"` (or feature-level coverage) | ❌ W2 | 2 |
| LED-04 | Listener cannot self-pair (`pair_transaction_id != id`) | feature | covered by `pairsAsnIcsSettlement` assertion | ❌ W2 | 2 |
| LED-04 | Cross-user safety: listener never pairs across `user_id` boundaries (T-04-04) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::doesNotCrossUsers"` | ❌ W2 | 2 |
| LED-04 | Schema enforces `pair_transaction_id` FK with ON DELETE SET NULL | feature | `vendor/bin/pest --filter "SchemaTest::pairTransactionFk"` (raw SQLite pragma) | ❌ W2 | 2 |
| LED-04 | Reclassifying one side via D-78 clears `pair_transaction_id` on BOTH rows | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::breaksPair"` | ❌ W3 | 3 |
| LED-05 | Positive amount NOT (transfer / refund / fee) → `type='income'` (SC #4) | unit | `vendor/bin/pest --filter "ClassifyTransactionTypeTest::detectsIncome"` | ❌ W3 | 3 |
| LED-05 | Positive amount that IS a `transfer_in` stays `transfer_in`, NOT `income` | unit | `vendor/bin/pest --filter "ClassifyTransactionTypeTest::transferInIsNotIncome"` | ❌ W3 | 3 |
| LED-05 | PayPal `Refund` event-type row → `type='refund'`, NOT `income` | unit | `vendor/bin/pest --filter "PaypalCsvAdapterTest::classifiesRefund"` | ❌ W1 | 1 |
| LED-05 | Manual override changes `type` and persists | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::changesType"` | ❌ W3 | 3 |
| LED-05 | Dashboard "income" rollup never includes a `transfer_in` row | feature | `vendor/bin/pest --filter "DashboardIncomeTest::excludesTransfers"` | ❌ W3 | 3 |

*Status legend: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky · W0/W1/W2/W3/W4 = wave delivery slot*

---

## Wave 0 Requirements

- [ ] `local/paypal/` directory + verify `/local/` already gitignored
- [ ] `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` — committed redacted PayPal Activity Download fixture (anonymised via two-pass deterministic counter map per Research Pitfall 5)
- [ ] `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md` — fixture-record documentation (mirrors `ics-sample-1.md`)
- [ ] `scripts/anonymize_paypal_csv.php` — idempotent regex-driven anonymisation script
- [ ] `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php` — skeleton with Wave-0-empirical token vocabulary (EN or NL, whichever the fixture surfaces)
- [ ] `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvEventTypeMap.php` — skeleton with Wave-0-empirical event-type → classification map (filtered set per D-62)
- [ ] `Modules/Import/Public/Events/TransactionImported.php` — event class (listener depends on it; the event does NOT yet exist per Research Finding 1)
- [ ] `RecordTransactions` fires `TransactionImported` per persisted row (Wave 0 wiring, not yet present)
- [ ] `tests/Contracts/IdempotencyContractTest.php` — extended with `'paypal-csv'` dataset row (RED baseline)
- [ ] `04-WAVE-0-FINDINGS.md` — committed empirical reporting set per D-60 (a–g): language, event vocabulary, `Reference Txn ID` chain shapes, `Funding Source` column presence, FX shape, "Transfer to bank" row shape, reconciliation gate result

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Wave-0 anonymisation preserves parent-child links visually | ING-05 | Anonymisation correctness is fixture quality — automated diff against the unanonymised file would itself leak data | Run `scripts/anonymize_paypal_csv.php local/paypal/raw.csv > Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv`; visually verify each `Reference Txn ID` points at a `Transaction ID` that exists in the file |
| PayPal CSV reconciliation soft-warning copy reads calm/Linear | ING-05 (UI) | Visual aesthetic — out of scope for assertions | Open wizard with a reconciliation-mismatch fixture; confirm copy matches UI-SPEC; calm Linear-style |
| Three-issuer wizard ordering / aria-live cascade still feels right | D-69 | UX | Open `/import/upload` and confirm three issuer cards (ASN / ICS / PayPal) cascade format options without flicker; aria-live unchanged from Phase 3 |
| ROADMAP.md Phase 4 wording reflects ING-09 deferral | ING-09 | Doc edit | `git diff .planning/ROADMAP.md` shows SC #2 rewritten as "API path documented as deferred behind business-account trigger" |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies declared
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all ❌ references in the Per-Task Verification Map
- [ ] No watch-mode flags (`--watch`) anywhere in commands
- [ ] Feedback latency < 10s for quick filter
- [ ] `nyquist_compliant: true` set in frontmatter once planner writes plans and updates this table with concrete task IDs

**Approval:** pending
