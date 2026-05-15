---
phase: 04-paypal-ingestion-transfer-detection
verified: 2026-05-16T00:00:00Z
status: human_needed
score: 4/4 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Upload a real PayPal CSV through the three-issuer wizard end-to-end (browser)"
    expected: "Wizard shows PayPal issuer option → Activity Download (CSV) format → account-naming step on first upload → preview table with one row per logical payment → confirm → rows appear in /transactions list"
    why_human: "The Livewire wizard flow (UploadWizard → PreviewWizard → ConfirmImport) requires browser interaction; Livewire test coverage exists for individual steps but the full click-through on a real browser tab cannot be verified programmatically."
  - test: "Open /transactions/{id} for a PayPal import row and exercise the Reclassify dropdown"
    expected: "Dropdown lists all Transaction::TYPES except the current type; selecting a new type and clicking Save shows the inline Alpine toast; refreshing the page confirms the new type persists"
    why_human: "The Alpine.js toast display (x-on:toast.window, x-show, x-transition.opacity) and the wire:model.live binding are not exercised by the existing Livewire component tests; a browser is needed to confirm the toast appears and disappears after 3 seconds."
---

# Phase 4: PayPal Ingestion + Transfer Detection Verification Report

**Phase Goal:** User can import PayPal activity — via CSV (canonical; the Reporting API path is deferred behind a business-account trigger per ING-09) — with the event-log rolled up into a single canonical transaction per payment, and have ASN↔ICS / PayPal↔bank moves correctly flagged as internal transfers rather than income.
**Verified:** 2026-05-16T00:00:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths (Roadmap Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|---------|
| 1 | User can upload a PayPal activity CSV and see one transaction per payment (fees, holds, and currency-conversion rows enriching that single row) | ✓ VERIFIED | `PaypalTransactionRollup::rollup()` implements a three-pass walker (skip → partition parents/children → fold). `PaypalCsvImportTest` end-to-end test runs the full fixture through pipeline → persist; `PaypalTransactionRollupTest` covers the 4-row USD Cloudflare chain (USD parent + EUR fx leg + USD fx leg + funding child → single DTO with `settledAmountMinor=-927, settledCurrency=EUR`). IdempotencyContractTest `paypal-csv` dataset row is GREEN. |
| 2 | PayPal Reporting API is documented as deferred behind a business-account upgrade trigger; CSV remains the supported path | ✓ VERIFIED | ROADMAP.md SC #2 rewritten. REQUIREMENTS.md has `## Deferred / Future-Revisit` section with ING-09 row + trigger. Traceability table row: `ING-09 | Phase 4 | Deferred`. `BoundaryArchTest::noPaypalApiRoute` asserts no `PaypalApiAdapter` / `PaypalReportingApi` / `paypal-api` pattern exists under `routes/` or `Modules/`. |
| 3 | Internal moves between own accounts appear as paired transfer-out / transfer-in rows linked via `pair_transaction_id` and never inflate income totals | ✓ VERIFIED | Migration `2026_05_15_010002_add_pair_transaction_id_to_transactions.php` adds self-referential FK + partial index `transactions_unpaired_transfer_idx`. `TransfersServiceProvider::boot()` registers `PairTransferCandidates` on `TransactionImported::class` synchronously (no `ShouldHandleEventsAfterCommit`). `PairTransferCandidatesTest` covers ASN→ICS same-batch pairing, half-pair-then-close-on-second-import, cross-user isolation, WINDOW_DAYS ±3-day boundary, and self-pair guard. |
| 4 | Genuine income is flagged distinctly from internal transfers, with manual override available | ✓ VERIFIED | `ClassifyTransactionType` stage runs between NormalizeStage and FingerprintStage; subtractive income rule (positive amount AND type not in transfer/refund/fee → income). `DashboardIncomeTest` regression proves `transfer_in` and `refund` rows never inflate `ThisPeriodAtAGlanceQuery`'s income tile. `TransactionDetail::reclassify()` atomically clears `pair_transaction_id` on both sides on non-transfer reclassify; `TransactionDetailReclassifyTest` covers happy-path, break-pair invariant, transfer-to-transfer preserves pair, cross-user 404. |

**Score:** 4/4 must-haves verified

---

### Spot-Check Findings (Verification Directives)

**Spot-check 1 — Upload flow wiring (UploadWizard → PaypalCsvAdapter → ImportPipeline → ConfirmImport → Transaction)**

- `UploadWizard` exposes `issuer='paypal'` → `sourceFormat='paypal-csv'` with `availableFormats()` returning `[['value' => 'paypal-csv', 'label' => 'Activity Download (CSV)']]`. Covered by `UploadWizardPaypalTest`.
- `HeaderSniffer::sniff()` has a `sniffPaypalCsv()` arm dispatched by `PaypalCsvLanguageProfile::FORMAT`.
- `IngestionServiceProvider` registers `'paypal-csv' => $app->make(PaypalCsvAdapter::class)` in the `SourceAdapterRegistry` map.
- `PaypalCsvAdapter::parse()` calls `$this->sniffer->sniff(…)` then `$this->rollup->rollup(…)` and yields canonical DTOs.
- `ImportPipeline::preview()` runs `NormalizeStage → ClassifyTransactionType → FingerprintStage` per row (lines 101-102 confirmed in source).
- `PreviewWizard::savePaypalAccountName()` creates `Account` with `kind='paypal'`, `iban='PAYPAL'`, `default_currency='EUR'` on first upload. `needsPaypalAccountName()` gate confirmed present.
- `ConfirmImport` → `RecordTransactions::__invoke($canonical, $user)` → `insertOrIgnore` → `TransactionImported` dispatch.
- **Result: wiring is complete and traceable.**

**Spot-check 2 — PayPal "General Withdrawal" producing `type='transfer_out'`**

- The PayPal NL fixture (the user's real export) contains NO General Withdrawal / "Algemene opname" rows (Wave 0 finding (f) — "Absent in this export").
- The `PaypalCsvEventTypeMap::TRANSACTION_TYPE['nl']` deliberately does NOT include a General Withdrawal entry; only the two empirically-observed parent types (`Vooraf goedgekeurde betaling`, `Express Checkout-betaling`) are present, both mapping to `'expense'`.
- `ClassifyTransactionType` Step 2 (cross-account IBAN check) WOULD correctly classify a General Withdrawal to `transfer_out` if the counterparty IBAN is populated and matches a known own account. `ClassifyTransactionTypeTest` covers this exact case: "flips a paypal-csv row whose counterparty matches an own ASN IBAN to transfer_out (cross-account step wins over event-type map)".
- However: if a General Withdrawal row arrives with an empty `Bankrekening` (counterparty IBAN), Step 2 is bypassed, Step 3 falls through (no TRANSACTION_TYPE entry), and Step 4 applies the subtractive income rule (negative amount → `expense` default from NormalizeStage). The row would NOT be classified as `transfer_out`.
- This is a documented known gap in Phase 4: the Wave 0 findings note "Empirical NL form to be confirmed when the user's first sweep lands." The deferred-items.md and 04-WAVE-0-FINDINGS.md both document this explicitly. Phase 5's chain resolver is the intended resolution.
- **Result: transfer_out classification for PayPal withdrawals works ONLY when counterparty IBAN is populated — which is documented as the expected Phase 4 posture. No undetected regression.**

**Spot-check 3 — PairTransferCandidates subscribed synchronously in-transaction**

- `TransfersServiceProvider::boot(Dispatcher $events)` calls `$events->listen(TransactionImported::class, PairTransferCandidates::class)` — confirmed in source.
- `bootstrap/providers.php` registers `TransfersServiceProvider::class` — confirmed.
- `PairTransferCandidates` does NOT implement `ShouldHandleEventsAfterCommit` or `ShouldQueue` — confirmed.
- `RecordTransactions` dispatches `TransactionImported` inside `$this->db->connection()->transaction(…)` — confirmed; dispatch at line 83 is inside the outer transaction closure.
- `PairTransferCandidatesTest` proves same-batch pairing (both legs in one import → both linked atomically).
- **Result: listener subscription, synchrony, and in-transaction pairing all verified.**

**Spot-check 4 — ThisPeriodAtAGlanceQuery filters by `type`, not amount sign**

- SQL confirmed at lines 90-92 of `ThisPeriodAtAGlanceQuery::for()`:
  ```sql
  CASE WHEN type = 'income' THEN settled_amount_minor ELSE 0 END  -- inflow
  CASE WHEN type = 'expense' THEN -settled_amount_minor ELSE 0 END  -- outflow
  ```
- `transfer_in` (positive amount) and `transfer_out` (negative amount) are never included in these CASE expressions.
- `forByCurrency()` applies the same type filter in its `HAVING` clause.
- `DashboardIncomeTest` provides three regression tests asserting `transfer_in`, `refund`, and `transfer_out` never appear in income/expense tiles.
- **Result: VERIFIED — income tile is type-driven, not amount-sign-driven.**

**Spot-check 5 — TransactionDetail reclassify atomically breaks pair**

- `TransactionDetail::reclassify()` uses a `$db->connection()->transaction()` closure that: (a) updates `$tx->type` and clears `$tx->pair_transaction_id` when non-transfer, (b) updates the partner via `Transaction::query()->where('user_id', …)->where('id', $partnerId)->update(['pair_transaction_id' => null])` inside the same closure.
- `TransactionDetailReclassifyTest::breaksPair` verifies both sides have `pair_transaction_id = null` after reclassify.
- `TransactionDetailReclassifyTest::preservesPairOnTransferToTransfer` verifies pair is kept on transfer-to-transfer reclassify.
- **Result: VERIFIED — atomic break-pair invariant implemented and tested.**

**Spot-check 6 — BoundaryArchTest::noPaypalApiRoute**

- Test scans `routes/` and `Modules/` recursively, strips `/* … */` and `// …` comments, checks for regex `PaypalApiAdapter|PaypalReportingApi|paypal-api`.
- The arch test exists at `tests/Contracts/BoundaryArchTest.php` lines 56-95 with the exact assertions documented in the plan.
- **Result: VERIFIED — defensive boundary invariant in place.**

**Spot-check 7 — Cloudflare USD dual-amount rawPayload preservation**

- `PaypalTransactionRollupTest` test "folds a 4-row USD currency-conversion chain into ONE DTO with the dual-amount pair populated": feeds `parent(USD, -10.46) + funding(EUR, +9.27, child-fee) + fxEur(EUR, -9.27, child-fx) + fxUsd(USD, +10.46, child-fx)` → expects `dto.currency='USD', dto.amountMinor=-1046, dto.settledCurrency='EUR', dto.settledAmountMinor=-927`.
- `PaypalCsvImportTest` test "preserves the dual-amount pair for the Cloudflare USD row": runs full pipeline against fixture, retrieves `Transaction` where `counterparty_name='Cloudflare Inc' AND currency='USD' AND amount_minor=-1046`, asserts `settled_currency='EUR', settled_amount_minor=-927`.
- `rawPayload` carries `{format: 'paypal-csv', language: 'nl', events: [...]}` with parent-first event ordering; `PaypalCsvImportTest` asserts `rawPayload.format='paypal-csv'` and `rawPayload.events` is an array.
- **Result: VERIFIED — dual-amount preserved, rawPayload events manifest intact.**

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php` | Streaming CSV adapter implementing SourceAdapter | ✓ VERIFIED | `implements SourceAdapter`; BOM-stripping; rollup delegation; statementMetadata |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php` | Three-pass rollup walker | ✓ VERIFIED | Contains `rollup()`, `skippedHoldCount()`, `orphanChildCount()`, `skippedMalformedRowCount()` |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalAmountParser.php` | US-locale amount parser | ✓ VERIFIED | File exists |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php` | US-locale date parser | ✓ VERIFIED | File exists |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvColumnMap.php` | Language-keyed column lookup | ✓ VERIFIED | File exists |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php` | Language profile with `FORMAT = 'paypal-csv'` | ✓ VERIFIED | `LANGUAGE_SIGNATURES['nl']` populated with 7 empirical tokens |
| `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php` | Event-type action map | ✓ VERIFIED | `MAP['nl']` has 9 entries; `TRANSACTION_TYPE['nl']` has 2 parent types |
| `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` | Redacted PayPal fixture | ✓ VERIFIED | 87 lines, 18878 bytes, 86 data rows |
| `Modules/Import/Public/Events/TransactionImported.php` | Per-row import event | ✓ VERIFIED | `final readonly class TransactionImported`; no `ShouldHandleEventsAfterCommit` |
| `Modules/Ledger/Public/Actions/RecordTransactions.php` | Fires TransactionImported per insert | ✓ VERIFIED | `$this->events->dispatch(new TransactionImported(…))` inside outer transaction |
| `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php` | Self-FK + partial index | ✓ VERIFIED | `pair_transaction_id` FK + `transactions_unpaired_transfer_idx` partial index |
| `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php` | Type-classification stage | ✓ VERIFIED | 5-step algorithm; never queries `transactions` table (verified by grep gate test) |
| `Modules/Transfers/Providers/TransfersServiceProvider.php` | Listener subscription | ✓ VERIFIED | `$events->listen(TransactionImported::class, PairTransferCandidates::class)` |
| `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` | Deterministic pair-detection listener | ✓ VERIFIED | `WINDOW_DAYS = 3`; cross-user guard; symmetric write; no ShouldHandleEventsAfterCommit |
| `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` | Reclassify action + break-pair | ✓ VERIFIED | `reclassify()` method with atomic `pair_transaction_id` clearing |
| `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php` | Reclassify dropdown + toast | ✓ VERIFIED | `wire:click="reclassify($wire.reclassifyType)"`, Alpine toast |
| `tests/Contracts/BoundaryArchTest.php` | `noPaypalApiRoute` invariant | ✓ VERIFIED | Present at line 56; scans routes/ and Modules/ |
| `.planning/ROADMAP.md` | SC #2 rewritten as deferral | ✓ VERIFIED | SC #2 reads "PayPal Reporting API integration is documented as deferred behind a business-account upgrade trigger" |
| `.planning/REQUIREMENTS.md` | ING-09 Deferred section + traceability | ✓ VERIFIED | `## Deferred / Future-Revisit` section present; traceability row: `ING-09 | Phase 4 | Deferred` |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `IngestionServiceProvider` | `PaypalCsvAdapter` | `'paypal-csv' => $app->make(PaypalCsvAdapter::class)` in registry | ✓ WIRED | Confirmed line 40 |
| `HeaderSniffer` | `PaypalCsvLanguageProfile::FORMAT` | `sniffPaypalCsv()` arm in match switch | ✓ WIRED | `PaypalCsvLanguageProfile::FORMAT` referenced at line 62 |
| `UploadWizard` | `paypal-csv` format | `issuer 'paypal'` → `sourceFormat 'paypal-csv'` in `availableFormats()` | ✓ WIRED | Lines 112-113 confirmed |
| `PreviewWizard` | `Account (kind='paypal', iban='PAYPAL')` | `savePaypalAccountName()` + `needsPaypalAccountName()` gate | ✓ WIRED | Lines 227-267, 366-406 confirmed |
| `SourceRefRanker` | `'paypal-csv' => 1` | rank map entry | ✓ WIRED | Line 41 confirmed |
| `TransfersServiceProvider` | `PairTransferCandidates` | `events->listen(TransactionImported::class, PairTransferCandidates::class)` | ✓ WIRED | Confirmed in source |
| `bootstrap/providers.php` | `TransfersServiceProvider` | Manual registration | ✓ WIRED | Line 18 confirmed |
| `ImportPipeline` | `ClassifyTransactionType` | Stage wiring at `$this->classifier->run($normalized, $user)` line 102 | ✓ WIRED | Between NormalizeStage and FingerprintStage |
| `TransactionDetail (reclassify)` | `Transaction::pair()` relation | Atomic `pair_transaction_id` clear on both sides inside DB transaction | ✓ WIRED | Lines 128-145 confirmed |
| `ROADMAP.md §Phase 4 SC #2` | `REQUIREMENTS.md §Deferred` | Cross-reference via "ING-09" | ✓ WIRED | Both documents updated |

---

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `PaypalCsvAdapter::parse()` | `$rolledUp` (list of SourceTransactionDto) | `PaypalTransactionRollup::rollup($rawRows, $language)` — reads league/csv records from file | Yes — reads from actual CSV file on disk | ✓ FLOWING |
| `RecordTransactions::__invoke()` | `$persisted` (Transaction) | `Transaction::insertOrIgnore($attrs)` + fingerprint-lookup `firstOrFail()` | Yes — DB insert + fetch | ✓ FLOWING |
| `ThisPeriodAtAGlanceQuery::for()` | `$row->inflow_minor` | `CASE WHEN type = 'income' THEN settled_amount_minor ELSE 0 END` via raw query builder | Yes — type-filtered SQL query against transactions table | ✓ FLOWING |
| `TransactionDetail::render()` | `$transaction` | `Transaction::query()->where('id', …)->where('user_id', …)->firstOrFail()` | Yes — user-scoped Eloquent lookup | ✓ FLOWING |

---

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| PaypalCsvAdapter exists and implements SourceAdapter | `grep -q "implements SourceAdapter" Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php` | matches | ✓ PASS |
| paypal-csv registered in SourceAdapterRegistry | `grep -q "'paypal-csv'" Modules/Ingestion/Providers/IngestionServiceProvider.php` | matches | ✓ PASS |
| TransfersServiceProvider registered in bootstrap | `grep -q "TransfersServiceProvider" bootstrap/providers.php` | matches line 18 | ✓ PASS |
| PairTransferCandidates subscribes to TransactionImported | `grep -q "TransactionImported::class.*PairTransferCandidates" Modules/Transfers/Providers/TransfersServiceProvider.php` | matches | ✓ PASS |
| ThisPeriodAtAGlanceQuery uses type filter | `grep -q "type = 'income'" Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` | matches | ✓ PASS |
| BoundaryArchTest::noPaypalApiRoute exists | `grep -q "noPaypalApiRoute" tests/Contracts/BoundaryArchTest.php` | matches line 56 | ✓ PASS |
| ING-09 in REQUIREMENTS.md Deferred section | `grep -q "ING-09.*Deferred" .planning/REQUIREMENTS.md` (traceability row) | matches | ✓ PASS |
| Full wizard UI and Alpine toast | Browser interaction required | N/A | ? SKIP (human needed) |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|---------|
| ING-05 | 04-01, 04-02 | PayPal CSV ingestion with event-log rollup | ✓ SATISFIED | PaypalCsvAdapter + rollup walker + IdempotencyContractTest GREEN + PaypalCsvImportTest end-to-end |
| ING-09 | 04-05 | PayPal Reporting API (deferred) | ✓ SATISFIED (as deferred) | REQUIREMENTS.md Deferred section; ROADMAP SC #2 rewritten; BoundaryArchTest invariant |
| LED-04 | 04-03 | Internal transfers linked via `pair_transaction_id` | ✓ SATISFIED | Migration + partial index + PairTransferCandidates listener + PairTransferCandidatesTest |
| LED-05 | 04-04 | Income detector distinguishes genuine income from internal transfers | ✓ SATISFIED | ClassifyTransactionType subtractive rule + DashboardIncomeTest regression + Reclassify override |

**Orphaned requirements for Phase 4:** None. All four requirement IDs (ING-05, ING-09, LED-04, LED-05) are claimed by plans and verified.

---

### Anti-Patterns Found

| File | Pattern | Severity | Impact | Verdict |
|------|---------|----------|--------|---------|
| `PaypalCsvEventTypeMap.php` — `TRANSACTION_TYPE['nl']` | Two parent types map to `'expense'`; no `'transfer_out'` entry for General Withdrawal NL form | INFO | PayPal withdrawal classification depends on counterparty-IBAN check (Step 2) only; if IBAN absent, row defaults to expense | NOT a blocker — documented in Wave 0 findings as a known limitation; Phase 5 resolves via chain resolver |

No TODO/FIXME/placeholder patterns found in phase-4 production files (review WR-04 swept all planning-process references from PHPDocs and comments).

---

### Human Verification Required

#### 1. Full PayPal CSV Upload Wizard — Browser Flow

**Test:** Log in at `http://127.0.0.1`. Navigate to `/imports/new`. Select issuer "PayPal". Confirm only "Activity Download (CSV)" appears as a format option. Upload `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv`. On the account-naming step enter any display name (e.g. "PayPal account"). Advance to the preview table. Confirm the table shows rolled-up rows (one per logical payment, no separate fee/hold rows visible). Click Confirm. Navigate to `/transactions`. Verify PayPal rows appear with `source_format='paypal-csv'`.

**Expected:** ~41 rows in the transactions list after import (the fixture has ~41 logical-payment groups after rollup). Duplicate re-upload adds zero rows.

**Why human:** The Livewire wizard state machine (issuer → format → account-naming → preview → confirm) requires browser interaction. `UploadWizardPaypalTest` and `PaypalCsvImportTest` cover individual steps and the end-to-end pipeline, but the multi-step click-through on a live browser confirms the full UX works as intended.

#### 2. Reclassify Toast UI — Browser Verification

**Test:** Navigate to `/transactions/{id}` for any imported PayPal row. Locate the "Reclassify" section. Change the dropdown from the current type to any other value. Click "Save". Confirm an inline toast message appears with the reclassify confirmation text and disappears after ~3 seconds. Refresh the page and verify the new type persists.

**Expected:** Toast appears immediately after click, fades out within 3 seconds, type is persisted on refresh.

**Why human:** The Alpine.js `x-on:toast.window` / `x-show` / `x-transition.opacity` binding with a `setTimeout` 3000ms timer cannot be exercised by Livewire's component test harness. The Livewire test asserts `assertDispatched('toast')` but cannot verify the Alpine client-side display behaviour.

---

### Gaps Summary

No gaps found. All four Phase 4 Success Criteria are implemented and verified at the code level. Two items require human browser verification (wizard UX flow, Alpine toast display) before the phase can be marked fully passed.

**Pre-existing test failure note:** `TransactionTypeTest::it rejects an invalid transaction type at the DB layer` is a pre-existing race condition in Pest's parallel-mode SQLite trigger handling (documented in `deferred-items.md`). The underlying trigger logic is verified working outside the test harness. This failure predates Phase 4 and is not caused by Phase 4 code.

---

_Verified: 2026-05-16T00:00:00Z_
_Verifier: Claude (gsd-verifier)_
