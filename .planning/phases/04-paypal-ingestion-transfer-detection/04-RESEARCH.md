# Phase 4: PayPal Ingestion + Transfer Detection — Research

**Researched:** 2026-05-15
**Domain:** PayPal Activity-Download CSV ingestion with Transaction-ID rollup + new `Modules/Transfers/` bounded module (deterministic `pair_transaction_id` linker) + first-pass income detector
**Confidence:** HIGH for existing pipeline shape / patterns / migration discipline / event-listener mechanics (verified against shipped code + Laravel 12 docs). MEDIUM for PayPal CSV column layout & event-type vocabulary (intentionally Wave 0's job per D-57/D-59/D-60). HIGH for transfer-pair query shape (verified against existing schema + indexing patterns).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**PayPal CSV Format + Wave 0**

- **D-57:** **PayPal Activity Download CSV is the canonical input format.** The user uses PayPal's default "Activity Download" CSV (Activity → Statements → Custom report → "Activity download" / "All transactions"). One row per event (payment / fee / currency conversion / hold / transfer). Comma-separated UTF-8. Includes `Transaction ID` and `Reference Txn ID` columns — these are the rollup keys per `research/PITFALLS.md` Pitfall 3. The legacy "Statement of Account" report and any business-only variants are NOT supported in Phase 4.
- **D-58:** **User has a real recent Activity Download export available for Wave 0.** Mirrors the Phase 2 / Phase 3 enablement-wave pattern: the raw CSV stays under `local/paypal/` (gitignored — see Phase 3's `local/ics/` precedent); Wave 0 anonymises it and commits the redacted result as `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv`. Anonymisation is OUR job, not the user's. Redaction targets: email addresses → `kaarthouder@example.test`-style placeholders, names → `KAARTHOUDER`, IBANs → `NL00ASNB0000000000` (the same anonymised IBAN form Phase 2's CAMT fixture uses), Transaction IDs → fixed-length zero-padded synthetic IDs that preserve `Reference Txn ID` parent-child links, addresses dropped, ZIPs zeroed; **dates / amounts / currencies / event-type strings / merchant strings preserved verbatim** so the rollup parser is exercised against truth.
- **D-59:** **Wave 0 fingerprints the CSV language from the header row.** PayPal localises both column headers and event-type values (e.g. `Express Checkout Payment` vs `Express Checkout-betaling`, `Currency Conversion` vs `Valutaomrekening`). User is unsure which language their export shipped in (the account may have switched at some point). Wave 0 detects language by header-row token match and locks the empirical token vocabulary into a `PaypalCsvLanguageProfile` (parallel to Phase 2's `AsnCsvHeaderProfile` / Phase 3's `IcsPdfHeaderProfile`). If a future export ships under an unrecognised language, the adapter raises an explicit `UnsupportedPaypalCsvLanguageException` rather than silently mis-parsing. Phase 4 implements **whichever language Wave 0 finds in the committed fixture**; supporting both EN and NL is a follow-on if a second-language sample arrives.
- **D-60:** **Wave 0 empirical reporting set (mirrors Phase 3 Wave 0).** The Wave 0 plan reports back on:
  (a) language profile (EN / NL / other) and the empirical column-header tokens used,
  (b) the empirical event-type vocabulary present in this user's history (which subset of `Express Checkout Payment` / `Currency Conversion` / `Refund` / `General Withdrawal` / `Transfer to bank` / `Hold` / `Authorization` / `Reversal of General Account Hold` / `Mass Pay` / `Subscription Payment` etc. actually appears, in the user's language),
  (c) `Reference Txn ID` chain shapes (depth, fan-out — does a single payment have one fee child or many? Are currency-conversion pairs always two rows with a shared `Reference Txn ID`?),
  (d) presence/absence of explicit `Funding Source` column,
  (e) FX representation (single row with both legs vs two paired rows under a shared `Reference Txn ID`),
  (f) "Transfer to bank" row shape (counterparty IBAN populated? bank name? memo line carrying the destination IBAN?),
  (g) one-period reconciliation check: `sum(net_amount) == closing_balance - opening_balance` per `research/PITFALLS.md` Pitfall 3.

**Transaction ID Rollup Contract**

- **D-61:** **Rollup is keyed by `Transaction ID`; children walk via `Reference Txn ID`.** Parent row is the row whose `Reference Txn ID` is null (or self-equal). Children whose `Reference Txn ID` equals another row's `Transaction ID` enrich the parent. Orphan child rows whose parent is NOT in the file become standalone canonical rows under their own `Transaction ID`; the adapter logs them via `import_runs.extras.orphanChildCount`.
- **D-62:** **Filtered event types — skip at the adapter boundary.** `Hold`, `Authorization`, `Reserve`, `Reversal of General Account Hold` are filtered entirely; the count surfaces via `import_runs.extras.skippedHoldCount`.
- **D-63:** **Currency Conversion pairs roll up into one canonical row** (foreign-currency leg → `amountMinor`/`currency`; EUR leg → `settledAmountMinor`/`settledCurrency`; `fxRateUsed` derived per Phase 3 D-39).
- **D-64:** **`source_format = 'paypal-csv'`; `source_ref = Transaction ID`.** Rank function gains `'paypal-csv' => 1` (same band as `asn-csv`). PayPal rows never collide with ASN/ICS rows under the v3 fingerprint tuple (disjoint `account_id`).
- **D-65:** **rawPayload stores the per-canonical-transaction event manifest:** `{ "format": "paypal-csv", "events": [ { "type": "<eventType>", "row": <raw_row_dict> }, ... ] }`. Skipped Holds/Authorizations are NOT stored in rawPayload.

**PayPal Account Modeling**

- **D-66:** **One Account row of `kind='paypal'`, `default_currency='EUR'`, synthetic IBAN `'PAYPAL'`.** Mirrors Phase 3's `'ICS-CARD'` synthetic-IBAN pattern.
- **D-67:** **Wizard prompts to name the PayPal Account on first upload** (generalised from Phase 3 D-38 ICS naming step).
- **D-68:** **Multi-currency PayPal balance is implicit, not modeled.** No `paypal_balances` subtable.

**Wizard + Routing**

- **D-69:** **Three-issuer wizard: ASN / ICS / PayPal.** Leaf format key: `paypal-csv`. `HeaderSniffer::sniffPaypalCsv()` extends the existing CSV-header-sniff pattern.
- **D-70:** **SourceAdapterRegistry maps `'paypal-csv' => PaypalCsvAdapter::class`.** Adapter at `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php`. Composition: `PaypalCsvLanguageProfile` + `PaypalCsvEventTypeMap` + `PaypalAmountParser` (US-locale: dot decimal, comma thousands) + `PaypalDateParser` (US-locale).
- **D-71:** **`PaypalCsvAdapter` is stateful like the CAMT/MT940/ICS-PDF adapters.** `statementMetadata()` returns a `StatementSummaryData` row captured during iteration.

**Transfer-Pair Detection (LED-04)**

- **D-72:** **`pair_transaction_id` self-FK migration ships with this phase.** ON DELETE SET NULL. No DB-level CHECK constraint (cross-currency cases allowed; invariant enforced in the listener).
- **D-73:** **Deterministic Layer-1 only auto-links in Phase 4.** Match candidates whose `counterparty_iban` equals another `Account.iban` (same user) AND `amount_minor` equal-and-opposite AND `booked_at` within ±3 days. Synthetic IBANs (`'ICS-CARD'`, `'PAYPAL'`) participate as ordinary `Account.iban` values.
- **D-74:** **Half-pair state is observable.** Row typed `transfer_out`/`transfer_in` immediately, `pair_transaction_id` stays NULL until partner lands.
- **D-75:** **Pair detection runs as a post-load event listener** subscribed to `TransactionImported`. Listener lives in a new bounded module `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php`.
- **D-76:** **Typing happens BEFORE pair detection.** New `ClassifyTransactionType` step in `NormalizeStage` (or pre-Load step) classifies `type` based on (a) source-format event-type map for PayPal, (b) for ASN/ICS, the `counterparty_iban_matches_own_account` predicate flips `expense`/`income` to `transfer_out`/`transfer_in`.

**Income Detection (LED-05)**

- **D-77:** **v1 income detector: positive `amount_minor` AND NOT `transfer_in`/`transfer_out` AND NOT `refund` AND NOT `fee` → `type='income'`.** Applied during `ClassifyTransactionType`. Counterparty-heuristic salary detection is Phase 8.
- **D-78:** **Manual override on transaction detail page.** Reclassify dropdown; reclassifying one side of a pair breaks the pair (clears `pair_transaction_id` on BOTH rows).

**Reporting API (ING-09)**

- **D-79:** **ING-09 is deferred to backlog.** PayPal Transaction Search / Reporting API is gated behind a business account. ROADMAP SC #2 is rewritten by the planner.

**Cross-Module Boundaries**

- **D-80:** **`Modules/Transfers/` is the new bounded module — Internal-only in Phase 4.** Only the listener + `TransfersServiceProvider` ship; `Public/` stays empty.
- **D-81:** **No new `Modules/Income/` module.** Income detector lives on the `ClassifyTransactionType` step under `Modules/Import/Internal/Pipeline/Stages/`.

### Claude's Discretion

- Exact wizard "name your PayPal account" prompt copy (Default to the same calm Linear/Notion phrasing as ICS).
- Wave 0 commits ONE fixture by default; extend to two if user surfaces a second-language sample.
- Exact migration timestamp slot after `2026_05_15_010001` (planner locks).
- Exact Layer-2 tolerance values (default ±€5 OR ±2% across ±10-day window — but Phase 4 does not ship Layer 2 surfaces).
- Whether override action lives on detail page only or also as inline list-row action (default: detail page only).
- Reclassify-breaks-pair UX confirmation (default: single-click with toast).
- PayPal CSV reconciliation soft-warning wording (default: warning, not blocker — same posture as Phase 2 multi-statement MT940 flag).

### Deferred Ideas (OUT OF SCOPE)

- PayPal Reporting API / Transaction Search (ING-09) — until business-account upgrade.
- Counterparty-heuristic salary / recurring-income detection (LED-06) — Phase 8.
- PayPal balance-by-currency surface — defer until use case appears.
- PayPal Subscription Payment / Mass Pay / Recurring Billing event-type handling — only if Wave 0 finds them.
- EN + NL dual-language fixture — only when second-language sample lands.
- Review queue for tolerant-window pair candidates — Phase 5.
- `Modules/Transfers/Public/` surface — until Phase 5 chain resolver needs it.
- Multi-PayPal-account support — single-PayPal v1.
- PayPal "Funding Source" column for direct chain hints — captured in rawPayload only; Phase 5 wires the chain resolver.
- PayPal `.eml` receipt ingestion — Phase 7.
- Manual transfer-pair UI — Phase 5 carry-over.
- Refund-of-prior-outflow income-detector improvement — Phase 8/9.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ING-05 | User can upload a PayPal Activity CSV and have transactions imported with `Transaction ID` / `Reference Txn ID` rollup so fees, holds, and currency-conversion rows enrich a single canonical transaction | New `PaypalCsvAdapter` under `Modules/Ingestion/Internal/Adapters/Paypal/`, registered in `SourceAdapterRegistry` as `'paypal-csv'`; `PaypalTransactionRollup` walker (D-61); event-type map (D-62); `HeaderSniffer::sniffPaypalCsv()` (D-69); Wave 0 fixture commits the empirical column layout per D-60 |
| ING-09 | PayPal Reporting API (Transaction Search) | **DEFERRED to backlog (D-79).** Personal-account user is gated out of business-only API. CSV path remains the supported entry. ROADMAP Phase 4 SC #2 is rewritten during plan-phase to reflect this. REQUIREMENTS.md gains a "Deferred / future-revisit" entry. |
| LED-04 | Internal transfers (ASN↔ICS, PayPal→bank) are linked via `pair_transaction_id` so they are never double-counted | New migration adds `pair_transaction_id` self-FK (D-72); new `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` subscribes to `TransactionImported` (D-75) and runs the deterministic Layer-1 match (D-73); `ClassifyTransactionType` step (D-76) types pre-pair so half-pair state is honest |
| LED-05 | Income detector flags genuine inflows vs internal moves | `ClassifyTransactionType` step (D-76, D-77) — subtractive rule: positive AND NOT transfer/refund/fee → income; manual override on transaction detail page (D-78) |

</phase_requirements>

## Summary

Phase 4 sits squarely on top of three shipped capabilities — the four-state preview pipeline (Phase 1/2), the dual-amount native+settled DTO contract (Phase 3 D-42), and the two-step grouped wizard picker (Phase 3 D-33) — and adds **two distinct units of work** that share a single phase because they're tested together end-to-end:

1. **PayPal CSV ingestion (ING-05).** A new `PaypalCsvAdapter` lives at `Modules/Ingestion/Internal/Adapters/Paypal/`. Composition mirrors the existing `IcsPdfAdapter` shape (stateful — exposes `statementMetadata()`; D-71) and the `AsnCsvAdapter` shape (lazy-Generator over `league/csv`; D-70). The novelty is the **Transaction-ID rollup walker** (`PaypalTransactionRollup`, D-61) that buffers the parent + children for one logical payment and emits ONE `SourceTransactionDto` carrying the dual-amount pair (D-63) and a `rawPayload` event-manifest (D-65). Filtered event types (`Hold` / `Authorization` / `Reserve` / `Reversal of General Account Hold`) are skipped at the adapter boundary (D-62) with counts surfaced through `import_runs.extras.skippedHoldCount`. PayPal's column-header + event-type vocabulary is language-localised (EN vs NL) and varies historically (D-59); Wave 0 fingerprints the user's actual export and locks the empirical token vocabulary into `PaypalCsvLanguageProfile`. The Wave 0 reporting set (D-60 a–g) is the **canonical sanity-check** for the whole adapter — its reconciliation check (`sum(net) == closing - opening`) is the project-wide acceptance gate for "did the rollup work."

2. **Cross-account transfer-pair detection + income detector (LED-04 + LED-05).** Two new pieces of cross-cutting infrastructure: (a) a `pair_transaction_id` self-referential nullable FK migration on `transactions` (D-72); (b) a new bounded module `Modules/Transfers/` whose only Phase-4 surface is a `PairTransferCandidates` listener (D-75) subscribed to a new `TransactionImported` event. The listener runs deterministic Layer-1 matches (D-73) — counterparty-IBAN equals own-account-IBAN, amount equal-and-opposite, ±3-day window — and writes both sides' `pair_transaction_id` atomically. Layer 2 (tolerant-window match) is recognised as a Phase 5 surface. Pre-pair typing happens in a new `ClassifyTransactionType` step (D-76) so a half-pair (sweep imported before its partner) is never visible as expense/income; the listener only links, never re-types. The income detector is a subtractive rule on the same classification step (D-77): positive amount AND NOT transfer/refund/fee → `type='income'`.

**Key discovery from code-base inspection:** the `TransactionImported` event D-75 depends on **does not yet exist**. Confirmed by `grep -rn TransactionImported Modules/`: zero matches. The Phase 1 `ConfirmImport` action persists rows via `RecordTransactions` (which calls `insertOrIgnore`) inside a single outer DB transaction, but never raises a per-row event. **Wave 0 must therefore add the event before the listener can subscribe** — the new event is a tiny carrier-DTO Public surface under `Modules/Ledger/Public/Events/` (or `Modules/Import/Public/Events/`), and `RecordTransactions` (or `ConfirmImport`) fires it for each inserted row. Because the listener queries the same connection inside the same outer transaction (the Phase 1 pattern), the listener implements `ShouldHandleEventsAfterCommit` is **the wrong choice** — the listener MUST run inside the outer transaction so the partner-row lookup sees the just-inserted partner if it exists in the same import batch. Verified against Laravel 12 events docs: omitting `ShouldHandleEventsAfterCommit` is the synchronous in-transaction default.

The architectural responsibility map below pins ownership tier-by-tier so the planner can verify task assignments. The "Standard Stack" table confirms zero new composer dependencies (every PayPal-CSV concern is solvable with `league/csv` + `brick/money` + Carbon + native PHP, all already present). The "Code Examples" section pins the exact `PaypalTransactionRollup` algorithm shape, the `pair_transaction_id` migration shape, the `TransactionImported` event DTO, and the listener's ±3-day window query against the existing `(account_id, posted_at)` index.

**Primary recommendation:** Plan as five waves — **Wave 0** (fixture-first enablement: anonymisation script, redacted fixture, fixture-record `.md`, `PaypalCsvLanguageProfile` skeleton with empirical token map, `TransactionImported` event scaffold, contract-test scaffold, language/event-type/orphan/skipped-hold/funding-source/FX-shape/reconciliation findings committed); **Wave 1** (vertical PayPal CSV slice: `PaypalCsvAdapter` + composition pieces + rollup walker + wizard option + IdempotencyContractTest dataset row + end-to-end Pest feature test — Success Criterion #1); **Wave 2** (`pair_transaction_id` migration + `Modules/Transfers/` bounded module + `TransactionImported` event fire-site + `PairTransferCandidates` listener + `ClassifyTransactionType` step + end-to-end ASN→ICS settlement pair feature test — Success Criterion #3); **Wave 3** (income detector wired through `ClassifyTransactionType` + transaction-detail "Reclassify" action + paired-row-break invariant test — Success Criterion #4); **Wave 4** (ROADMAP SC #2 rewrite + REQUIREMENTS.md "Deferred" entry for ING-09 — atomic doc-edit plan mirroring Phase 3's ICS-pivot pattern). Each wave is end-to-end demoable per the project's vertical-slice constraint.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| PayPal CSV parsing (raw rows → `SourceTransactionDto[]`) | Ingestion module (`Internal/Adapters/Paypal/PaypalCsvAdapter`) | — | Adapter contract is Ingestion's; mirrors `AsnCsvAdapter`, `IcsPdfAdapter`. |
| Transaction-ID rollup walker | Ingestion module (`Internal/Adapters/Paypal/PaypalTransactionRollup`) | — | Pure source-format concern — buffering and event-type semantics live inside the adapter composition. Tested independently via Pest dataset against the redacted fixture. |
| Language-profile / event-type-map data | Ingestion module (`PaypalCsvLanguageProfile`, `PaypalCsvEventTypeMap`) | — | Format-shape config — never imported outside the Paypal adapter. |
| Synthetic IBAN `'PAYPAL'` constant + adapter `ownIban()` | Ingestion module (Paypal adapter) | Ledger module (`Account.iban` storage) | Adapter emits the synthetic literal; the Account row is owned by Ledger and seeded via the wizard naming step. Same shape as Phase 3 `'ICS-CARD'`. |
| `pair_transaction_id` schema column | Ledger module (new migration) | — | Schema is owned by Ledger per D-04. Forward-only migration; no backfill needed (column defaults NULL). |
| `TransactionImported` event | Import module (`Public/Events/TransactionImported`) | — | The event carries the persisted-Transaction model — Import's pipeline is the natural origin. Public surface so cross-module listeners (Transfers, future Categorization-rules, future Chain resolver) can subscribe. |
| Post-load pair-detection listener | New `Modules/Transfers/` bounded module (`Internal/Listeners/PairTransferCandidates`) | — | Bounded module per D-80 keeps `ImportPipeline` clean of cross-account concerns. Module ships with only the listener + `TransfersServiceProvider`. |
| Transaction-type classification (`expense`/`income`/`transfer_*`/`refund`/`fee`) | Import module (new `ClassifyTransactionType` step under `Internal/Pipeline/Stages/`) | Ingestion module (source-format event-type map provides the input) | The classification is canonical-row scope, not source-row scope — lives downstream of Normalize. The PayPal event-type map is queried by reference; ASN/ICS get the predicate-only branch. |
| Income detector (subtractive rule) | Import module (`ClassifyTransactionType` step) | — | Per D-81, lives as a method on the classification step. Promote to a bounded `Modules/Income/` module only when Phase 8 recurring-income detector adds enough surface. |
| Manual "Reclassify" action on transaction detail page | Ledger module (`Internal/Http/Livewire/TransactionDetail`) | — | The Phase 3 D-48 TransactionDetail SFC is the host. Reclassify mutates `type` and (if was paired) clears `pair_transaction_id` on both sides — Ledger-owned because it's a domain-row write, not a cross-module orchestration. |
| Three-issuer wizard (ASN / ICS / PayPal) | Import module (`Internal/Http/Livewire/UploadWizard`) | — | Existing Phase 3 D-33 two-step picker is extended; Import-owned. |
| Generalised "name your <kind> account" wizard step | Import module (`Internal/Http/Livewire/PreviewWizard`) | — | Existing Phase 3 D-38 ICS naming branch is generalised from `kind='ics_card'` to "the kind appropriate to the declared format". |
| Anonymisation script `scripts/anonymize_paypal_csv.php` | Repo-root `scripts/` | — | Same convention as Phase 3 (`scripts/anonymize_ics_text.php`); zero composer deps; idempotent regex-driven passes. |

## Standard Stack

### Core (no new dependencies)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `league/csv` | ^9.28 (installed) | PayPal CSV streaming reader + BOM-aware header handling | [VERIFIED: composer.json line 13] Already used by `AsnCsvAdapter`; PayPal CSV ships UTF-8 (sometimes BOM-prefixed); `Reader::from()` + `setHeaderOffset(0)` is the existing pattern. |
| `brick/money` | ^0.11 (installed) | Multi-currency arithmetic surfacing only when the dashboard renders USD/GBP PayPal rows | [VERIFIED: composer.json line 9] Phase 3 already wired this for ICS foreign-currency rows; PayPal multi-currency uses the same integer-cent path through `SourceTransactionDto`'s native+settled pair. |
| `brick/math` (transitive) | (via brick/money) | `BigDecimal` for the `fxRateUsed = settled/native` derivation | Already used by Phase 3 `NormalizeStage`; PayPal currency-conversion pairs reuse that exact derivation unchanged. |
| `nesbot/carbon` | (Laravel 13 default) | Date parsing + ±3-day-window arithmetic on the listener | Already used everywhere; `CarbonImmutable::subDays(3)` / `addDays(3)` is the canonical pair-window arithmetic. |
| `spatie/laravel-data` | ^4.0 (installed) | `TransactionImported` event payload DTO if we choose a typed-DTO event; `StatementSummaryData` reuse | [VERIFIED: composer.json line 17] Already used for `SourceTransactionDto`, `StatementSummaryData`, etc. |
| Native PHP `hash_file('sha256', …)` | 8.5 (installed) | File-layer idempotency on PayPal CSV (no change from existing Phase 1 wiring) | Re-uses the `import_runs.sha256` UNIQUE guard from `RunImport::runFromUpload`. |
| `nwidart/laravel-modules` | ^13.0 (installed) | New bounded `Modules/Transfers/` module discovery | [VERIFIED: composer.json line 16] Existing Core/Ledger/Ingestion/Import/Categorization modules are registered via `bootstrap/providers.php` (NOT via composer auto-discovery — see "Pattern 5: Bounded-module provider registration" below). |

### Supporting (existing — pattern reuse)

| Asset | Purpose | When to Use |
|-------|---------|-------------|
| `SourceAdapter` contract | Adapter shape: `format(): string` + `parse(): Generator<int, SourceTransactionDto>` + `statementMetadata(): ?StatementSummaryData` | Always — `PaypalCsvAdapter` implements this exact contract. |
| `SourceAdapterRegistry` | Constructor-mapped lookup; new entry `'paypal-csv' => PaypalCsvAdapter::class` in `IngestionServiceProvider::register()` | Single-line addition. |
| `HeaderSniffer` | Pre-parse validation; new `sniffPaypalCsv()` arm in the existing `match` switch | The sniff is column-header-token-based; Wave 0 locks the empirical token vocabulary. |
| `SourceTransactionDto` | Native + nullable settled-pair DTO (D-42 already shipped) | Currency-conversion rollup populates `settledAmountMinor`/`settledCurrency`; EUR-only rows leave them null. |
| `NormalizeStage` | Already substitutes `settled = native` when source leaves it null AND derives `fxRateUsed = settled/native` via `BigDecimal` scale 8 HALF_UP | Reused unchanged; the `ClassifyTransactionType` step lands adjacent. |
| `FingerprintStage` + v3 tuple | `(user_id, account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)` | Unchanged; PayPal rows fingerprint identically. |
| `SourceRefRanker` | Cross-format ref ranking | Gains `'paypal-csv' => 1` line (same band as `asn-csv`) per D-64. |
| `RecordTransactions` (Ledger Public) | Idempotent `insertOrIgnore` upsert | Reused unchanged. The `TransactionImported` event fires here per inserted row (or in `ConfirmImport`'s outer transaction loop — planner picks). |
| `import_runs` table | Audit row with `sha256` UNIQUE, `enriched_count`, etc. | Phase 4 reuses; the planner extends the `extras` JSON column with `skippedHoldCount`, `orphanChildCount`, `language` per D-60. |
| `statement_summaries` table | Adapter-supplied period + opening/closing balance row | PayPal CSV writes one row per import (D-71); `extras` JSON gains PayPal-specific fields. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Hand-rolled rollup walker | A composer library like `cdax/paypal-payment-history` | No actively-maintained PHP library covers the Activity Download CSV's rollup semantics; bespoke walker is one file (~80 LOC) and is fully testable against the redacted fixture. Adding a dependency for a single-purpose 80-line walker fails the project's `brick/money` precedent (only add deps with strong long-term value). |
| `ShouldHandleEventsAfterCommit` listener interface | Plain synchronous listener inside the outer transaction | Plain synchronous is correct here: the listener queries for the partner row, and the partner may have been just inserted earlier in the same import batch's outer transaction. `AfterCommit` would mean the listener can't see those rows until the import has fully landed — half-pair within one import would never pair. The outer transaction's atomicity is preserved either way (if the import rolls back, the listener's writes roll back with it). [CITED: Laravel 12 events docs] |
| Per-row event in `RecordTransactions` | Single bulk event in `ConfirmImport` after the recorder returns | Per-row event is the right shape: the listener pairs row-by-row, not batch-by-batch, and the listener is cheap (one indexed lookup per row). A bulk event would force the listener to re-iterate the inserted-rows list — strictly more code for no win. Verified: `RecordTransactions::__invoke()` already iterates row-by-row; firing the event from inside the loop is one line. |
| Separate `Modules/Income/` module for income detector | Method on `ClassifyTransactionType` step | Per D-81: the rule is 4 lines of PHP. Promoting to a module is overhead the project explicitly avoids until a second consumer exists. Phase 8's recurring-income detector is the trigger to promote. |
| Storing rollup state in a `paypal_transaction_groups` table | rawPayload JSON manifest per canonical row | Per D-65 + Phase 3 D-49 rawPayload convention: never duplicate denormalized statement state into a separate table. The rawPayload manifest contains the per-row provenance the future chain resolver needs. |

**Installation:**
```bash
# Zero new composer requires. Existing dependencies cover every Phase 4 concern.
# Confirmed against composer.json:
# - league/csv ^9.28  ← PayPal CSV reader
# - brick/money ^0.11  ← Multi-currency rounding/derivation (already used by NormalizeStage)
# - spatie/laravel-data ^4.0  ← Event payload DTO + StatementSummaryData
# - nwidart/laravel-modules ^13.0  ← New Modules/Transfers/ module registration
```

**Version verification:** All packages above are already pinned in `composer.json` and installed; no `composer require` runs in Phase 4. [VERIFIED: composer.json on 2026-05-15]

## Architecture Patterns

### System Architecture Diagram

```
                              ┌──────────────────────────────────────────────┐
                              │   User uploads PayPal Activity CSV via       │
                              │   the three-issuer wizard (Phase 4 D-69)     │
                              │   Step 1: Issuer = "PayPal"                  │
                              │   Step 2: Format = "Activity Download (CSV)" │
                              └────────────────┬─────────────────────────────┘
                                               │
                                               ▼
              ┌────────────────────────────────────────────────────┐
              │   Modules/Import/Internal/Http/Livewire/           │
              │   UploadWizard (extended for paypal-csv)           │
              └────────────────┬───────────────────────────────────┘
                               │ runFromUpload()
                               ▼
              ┌─────────────────────────────────────────────────────┐
              │   Modules/Import/Public/Actions/RunImport            │
              │   - sha256 UNIQUE on import_runs (file-layer idem)  │
              │   - HeaderSniffer::sniffPaypalCsv()                  │
              │   - PreviewCache fills with canonical batch          │
              └────────────────┬────────────────────────────────────┘
                               │
                               ▼
              ┌─────────────────────────────────────────────────────┐
              │   Modules/Import/Internal/Pipeline/ImportPipeline    │
              │     ParseStage → NormalizeStage → ClassifyTxType*   │
              │     → FingerprintStage                              │
              │   (*new in Phase 4 — D-76)                          │
              └────────────────┬────────────────────────────────────┘
                               │ parse()
                               ▼
              ┌─────────────────────────────────────────────────────┐
              │   Modules/Ingestion/Internal/Adapters/Paypal/        │
              │     PaypalCsvAdapter (lazy Generator)               │
              │       composes:                                     │
              │         PaypalCsvLanguageProfile (D-59)             │
              │         PaypalCsvEventTypeMap (D-62)                │
              │         PaypalAmountParser (US locale)              │
              │         PaypalDateParser (US locale)                │
              │         PaypalTransactionRollup (D-61 walker)       │
              │     yields: SourceTransactionDto per ROLLED-UP      │
              │             logical payment (not per raw CSV row)   │
              │     post-iter: lastStatementSummary() (D-71)        │
              └────────────────┬────────────────────────────────────┘
                               │ User clicks "Confirm"
                               ▼
              ┌─────────────────────────────────────────────────────┐
              │   Modules/Import/Public/Actions/ConfirmImport         │
              │     - DB::transaction(...)                          │
              │       - RecordTransactions::__invoke($canonical)    │
              │           insertOrIgnore() per row                  │
              │           **fires TransactionImported(Tx, User)**   │ ← Wave 0/1 deliverable
              │       - ApplyEnrichments (existing Phase 2 path)    │
              └────────────────┬────────────────────────────────────┘
                               │ TransactionImported event
                               ▼
              ┌─────────────────────────────────────────────────────┐
              │   Modules/Transfers/Internal/Listeners/              │
              │     PairTransferCandidates (D-75)                   │
              │       - if type IN ('transfer_out','transfer_in'):  │
              │           Layer-1 deterministic match:              │
              │           same-user, counterparty_iban matches      │
              │           another Account.iban, amount equal-and-   │
              │           opposite, booked_at within ±3 days,       │
              │           partner.pair_transaction_id IS NULL       │
              │       - DB::transaction(): write both pair_         │
              │           transaction_id columns atomically         │
              └─────────────────────────────────────────────────────┘
```

A reader can trace a PayPal CSV upload from the wizard to the final `pair_transaction_id` write by following the arrows. The half-pair case (D-74) is the same path but the listener's lookup query returns zero rows and the listener exits without writing; when the partner upload lands later, that import's `TransactionImported` event finds THIS earlier row via the same query and links both.

### Recommended Project Structure

```
Modules/
├── Ingestion/
│   └── Internal/
│       └── Adapters/
│           └── Paypal/                     ← NEW directory
│               ├── PaypalCsvAdapter.php
│               ├── PaypalCsvLanguageProfile.php
│               ├── PaypalCsvEventTypeMap.php
│               ├── PaypalCsvColumnMap.php           # if language profile resolves to fixed positions
│               ├── PaypalAmountParser.php
│               ├── PaypalDateParser.php
│               └── PaypalTransactionRollup.php     # single-purpose walker
│   └── tests/
│       └── fixtures/
│           └── paypal/                     ← NEW directory
│               ├── paypal-sample-1.csv     # committed redacted fixture (D-58)
│               └── paypal-sample-1.md      # fixture-record doc (mirrors ics-sample-1.md)
│
├── Import/
│   ├── Internal/Pipeline/Stages/
│   │   └── ClassifyTransactionType.php     ← NEW (D-76)
│   ├── Public/Events/
│   │   └── TransactionImported.php         ← NEW (Wave 0 / Wave 1)
│   ├── Internal/Http/Livewire/
│   │   ├── UploadWizard.php                ← MODIFIED: third issuer 'paypal'
│   │   └── PreviewWizard.php               ← MODIFIED: generalised naming branch
│   └── Public/Actions/
│       └── ConfirmImport.php (or RecordTransactions) ← MODIFIED: fires TransactionImported per row
│
├── Ledger/
│   ├── Database/Migrations/
│   │   └── 2026_05_15_010002_add_pair_transaction_id_to_transactions.php  ← NEW (D-72)
│   ├── Models/
│   │   └── Transaction.php                 ← MODIFIED: $fillable += pair_transaction_id, casts, pair() relation
│   └── Internal/Http/Livewire/
│       └── TransactionDetail.php           ← MODIFIED: Reclassify action (D-78)
│
├── Transfers/                              ← NEW bounded module
│   ├── composer.json                       # module manifest only (modules.php is unused; see Pattern 5)
│   ├── Internal/
│   │   └── Listeners/
│   │       └── PairTransferCandidates.php
│   ├── Providers/
│   │   └── TransfersServiceProvider.php
│   ├── Public/                             # stays empty in Phase 4 (D-80)
│   └── tests/
│       ├── Unit/
│       └── Feature/
│           └── PairTransferCandidatesTest.php
│
local/
└── paypal/                                 ← NEW gitignored directory (Phase 3 local/ics/ precedent)
    └── raw-paypal-activity.csv             # raw user export, never committed

scripts/
└── anonymize_paypal_csv.php                ← NEW (mirrors scripts/anonymize_ics_text.php)

bootstrap/
└── providers.php                           ← MODIFIED: TransfersServiceProvider::class added
```

### Pattern 1: Transaction-ID Rollup Walker (D-61)

**What:** Single-purpose class that ingests the flat list of raw PayPal rows (already parsed via `league/csv`) and emits one canonical `SourceTransactionDto` per logical payment.

**When to use:** Strictly inside `PaypalCsvAdapter::parse()`. The walker is itself testable against the redacted fixture via Pest dataset — independently of league/csv I/O.

**Algorithm (verified against PITFALLS.md Pitfall 3):**

```php
// Source: this research + research/PITFALLS.md Pitfall 3 + D-61
// Location: Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php

final class PaypalTransactionRollup
{
    public function __construct(
        private readonly PaypalCsvEventTypeMap $events,
        private readonly PaypalAmountParser $amounts,
        // …
    ) {}

    /**
     * @param  iterable<int, array<string, string>>  $rawRows  one entry per CSV record
     * @return list<SourceTransactionDto>
     *
     * Sketch:
     *   1. First pass — build $byTxnId index keyed by `Transaction ID`.
     *      Skip filtered event types (D-62) up front; collect their count for
     *      import_runs.extras.skippedHoldCount.
     *   2. Second pass — partition rows into parent vs child groups:
     *        - parent: $row['Reference Txn ID'] is null/empty/self-equal
     *        - child:  $row['Reference Txn ID'] === some other row's Transaction ID
     *      Orphan children (parent absent from file) become standalone parents;
     *      count surfaces via import_runs.extras.orphanChildCount.
     *   3. Third pass — fold each parent's children into ONE SourceTransactionDto:
     *        - amountMinor / currency come from parent's "Gross" column
     *          (or from foreign-currency leg if a Currency Conversion sibling
     *           shares the parent's Transaction ID)
     *        - settledAmountMinor / settledCurrency come from EUR leg of the
     *          Currency Conversion pair OR null when EUR-native
     *        - fxRateUsed stays null (NormalizeStage derives per Phase 3 D-39)
     *        - description: parent's description, optionally augmented with
     *          merchant from a sibling event if parent lacks it
     *        - rawPayload: { format: 'paypal-csv', events: [{ type, row }, …] }
     *        - sourceRef: parent's Transaction ID
     *        - sourceRowIndex: monotonically increasing 0..N (canonical-row scope,
     *          NOT raw-row scope)
     */
    public function rollup(iterable $rawRows): array { /* … */ }
}
```

### Pattern 2: Stateful adapter exposing `statementMetadata()` (D-71)

**What:** PayPal adapter mirrors Phase 2 CAMT/MT940 + Phase 3 ICS-PDF: holds a private `?StatementSummaryData $lastStatementMetadata = null;` and exposes it via `statementMetadata()` after `parse()` exhausts.

**When to use:** Always for PayPal CSV — Wave 0 confirms whether the CSV has explicit opening/closing balance rows; if not, compute opening = closing − sum(net) per Pitfall 3's reconciliation check.

**Example (analog: `IcsPdfAdapter`):**

```php
// Source: Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php (lines 80–106, 130–159)

private ?StatementSummaryData $lastStatementMetadata = null;

public function statementMetadata(): ?StatementSummaryData
{
    return $this->lastStatementMetadata;
}

public function parse(string $localPath, AccountResolver $accounts): Generator
{
    $this->sniffer->sniff($localPath, PaypalCsvLanguageProfile::FORMAT);
    $this->lastStatementMetadata = null;
    // …
    foreach ($rollup as $dto) {
        yield $dto;
    }
    $this->lastStatementMetadata = new StatementSummaryData(
        importRunId: 0,                // pipeline calls withImportRunId() later
        accountId: 0,                  // pipeline calls withAccountId() later
        ibanOwner: 'PAYPAL',
        statementNumber: null,
        periodStart: $minBookedAt,
        periodEnd: $maxBookedAt,
        openingBalanceMinor: $openingMinor,
        openingBalanceCurrency: 'EUR',
        openingBalanceDate: $minBookedAt,
        closingBalanceMinor: $closingMinor,
        closingBalanceCurrency: 'EUR',
        closingBalanceDate: $maxBookedAt,
        entryCount: $count,
        extras: [
            'language' => $this->languageProfile->detected(),
            'skippedHoldCount' => $skippedHoldCount,
            'orphanChildCount' => $orphanChildCount,
            'reconciliationStatus' => $reconciliationStatus,   // 'ok' | 'mismatch'
            'reconciliationGap' => $reconciliationGapMinor,
        ],
    );
}
```

### Pattern 3: `TransactionImported` event (Wave 0 / Wave 1)

**What:** A new typed Public event under `Modules/Import/Public/Events/TransactionImported.php`. Fired by `RecordTransactions` (or `ConfirmImport`) for each row that was actually inserted (not for duplicates — `insertOrIgnore` distinguishes via the `Effected === 1` return).

**Why Import-owned, not Ledger-owned:** the event is part of the **import pipeline contract** (other modules subscribe to "an import landed a new row"). Ledger raises model events automatically (`created`, `updated`); the project-wide convention from the existing code is to keep Ledger model events out of cross-module event-listener wiring (no Eloquent model events are subscribed to in the existing modules — all cross-module flow is through Public actions). Putting `TransactionImported` on Import's Public surface is consistent with that convention.

**Example shape:**

```php
// Source: this research + Laravel 12 events docs

namespace Modules\Import\Public\Events;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

/**
 * Fired once per Transaction row INSERTED by the import pipeline (not for
 * duplicates that insertOrIgnore silently dropped, not for enriched rows
 * that ApplyEnrichments updated).
 *
 * The event is intentionally synchronous and dispatched INSIDE the outer
 * DB transaction (no ShouldHandleEventsAfterCommit): listeners that need
 * to query for newly-inserted partner rows within the same import batch
 * (PairTransferCandidates) require the in-transaction read visibility.
 */
final readonly class TransactionImported
{
    public function __construct(
        public Transaction $transaction,
        public User $user,
    ) {}
}
```

**Fire site (RecordTransactions extension):**

```php
// Modify Modules/Ledger/Public/Actions/RecordTransactions.php
// Add Dispatcher injection + dispatch inside the foreach after a confirmed insert:

if ($effected === 1) {
    $inserted++;
    $persisted = Transaction::query()
        ->where('user_id', $row->userId)
        ->where('fingerprint', $fingerprint)
        ->firstOrFail();
    $this->events->dispatch(new TransactionImported($persisted, $userModelByIdLookup));
}
```

Note the firing site detail: `RecordTransactions` already runs inside `$this->db->connection()->transaction(…)`. The dispatcher delivers the event synchronously inside that transaction. The listener (`PairTransferCandidates`) inherits the transaction frame and its queries see the just-inserted row plus every prior insert in the same batch.

⚠ **CLAUDE.md DI constraint:** The dispatcher injection MUST be `Illuminate\Contracts\Events\Dispatcher` via constructor DI — no `event()` helper, no `Event::dispatch()` facade. Pattern: `private readonly Dispatcher $events`.

### Pattern 4: Self-referential FK migration for `pair_transaction_id` (D-72)

**What:** Single forward-only migration adds one nullable self-FK column with ON DELETE SET NULL.

**When to use:** Once, in Wave 2. Phase 4's only schema change.

**Example (analog: existing `2026_05_13_010002_add_enriched_from_to_transactions.php`):**

```php
// Source: this research + Laravel 13 Schema docs + Phase 2 enriched_from migration pattern
// Location: Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // Self-referential FK so a paired row points to its partner. ON
            // DELETE SET NULL preserves the surviving row's existence when a
            // partner is hard-deleted (rare — but the orphan stays in the
            // ledger as a regular row rather than vanishing).
            $table->foreignId('pair_transaction_id')
                ->nullable()
                ->after('settled_amount_minor')
                ->constrained('transactions')
                ->nullOnDelete();

            // The listener's lookup query: WHERE account_id = ?
            //                            AND amount_minor = ?
            //                            AND currency = ?
            //                            AND booked_at BETWEEN ? AND ?
            //                            AND pair_transaction_id IS NULL
            //                            AND type IN ('transfer_out','transfer_in')
            // The existing (account_id, posted_at) index from create_transactions
            // covers the account+date scan; adding a partial index on the
            // unpaired-transfer subset keeps the post-load listener cheap
            // even when the transactions table grows to 100k+ rows.
        });

        // Partial index: only rows that are unpaired transfers
        // (the listener's query candidate set).
        DB::statement('CREATE INDEX transactions_unpaired_transfer_idx ON transactions(user_id, account_id, booked_at) WHERE pair_transaction_id IS NULL AND type IN (\'transfer_out\', \'transfer_in\')');
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['pair_transaction_id']);
            $table->dropColumn('pair_transaction_id');
        });
        DB::statement('DROP INDEX IF EXISTS transactions_unpaired_transfer_idx');
    }
};
```

**No fingerprint version bump.** `pair_transaction_id` is NOT in the v3 fingerprint tuple, so adding it does not affect dedup. The composite UNIQUE on `(user_id, account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)` is unchanged.

### Pattern 5: Bounded-module provider registration

**What:** New `Modules/Transfers/` follows the existing `Modules/Ingestion`, `Modules/Import` shape.

**Critical discovery (verified against code):** the project does NOT use `nwidart/laravel-modules`' composer-auto-discovery mechanism. Module service providers are registered MANUALLY in `bootstrap/providers.php`:

```php
// File: bootstrap/providers.php (verified 2026-05-15)
return [
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
    IngestionServiceProvider::class,
    ImportServiceProvider::class,
    CategorizationServiceProvider::class,
    // ADD: TransfersServiceProvider::class,
];
```

The `bootstrap/cache/modules.php` is empty `[]`. The CONTEXT.md hint about `composer.json` `extra.laravel.providers` does NOT apply here — the planner MUST add the line to `bootstrap/providers.php` instead.

**`TransfersServiceProvider` shape (analog: `ImportServiceProvider`):**

```php
// Source: this research + Modules/Import/Providers/ImportServiceProvider.php

namespace Modules\Transfers\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Transfers\Internal\Listeners\PairTransferCandidates;

final class TransfersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings to add in Phase 4 — listener is constructed via DI
        // when the dispatcher resolves it.
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(TransactionImported::class, PairTransferCandidates::class);
    }
}
```

The module's own `composer.json` exists for `psr-4` autoload only (matches the existing `Modules/Ingestion/composer.json` shape) — no `extra.laravel.providers` entry, no merge-plugin trickery.

### Pattern 6: Generalised "name your <kind> account" wizard step

**What:** Extend the Phase 3 D-38 ICS-naming branch in `PreviewWizard` to also fire for `kind='paypal'` when the user uploads `paypal-csv` and no PayPal Account exists yet.

**Existing predicate site:** `PreviewWizard::needsIcsAccountName()` (lines 241–266) checks `source_format === 'ics-pdf'` AND `accounts.kind === 'ics_card'` count zero. The planner generalises this to a small method `needsAccountNameForKind(string $sourceFormat): ?string` that returns either `'ics_card'`, `'paypal'`, or null — preserving the existing IBAN-naming branch's separate path.

**Locked Blade copy** (planner refines during UI-SPEC pass):
- Heading: "Name your PayPal account."
- Helper: "This is the first time you've imported PayPal data. Give this wallet a name so it shows up consistently across the app."
- Input: "Account name" / placeholder "e.g. PayPal"
- Button: "Save name"

**Account-creation site (analog: `PreviewWizard::saveIcsAccountName`, lines 134–175):**

The PayPal save method bypasses `NamesAccounts` for the same reason ICS does — the synthetic IBAN `'PAYPAL'` fails AccountNamer's ISO 13616 structural guard. Validate name + slug inline via `AccountNamer::validateName()`; insert directly with `kind='paypal'`, `iban='PAYPAL'`, `default_currency='EUR'`.

### Anti-Patterns to Avoid

- **Do NOT use `ShouldHandleEventsAfterCommit` on `PairTransferCandidates`.** The listener must run inside the outer import transaction so it can read the partner row when it was just inserted by an earlier step in the same `RecordTransactions` loop. AfterCommit would push the listener past the transaction boundary, defeating the half-pair-becomes-full-pair-within-one-import case.
- **Do NOT type rows in the listener.** The listener LINKS. Typing (`transfer_out` / `transfer_in` / `income` / `refund` / `fee`) is done upstream by `ClassifyTransactionType` (D-76) so a half-pair is honestly typed `transfer_out` and never silently inflates expenses while waiting for its partner.
- **Do NOT add `pair_transaction_id` to the fingerprint tuple.** It's a post-load enrichment — including it in the dedup key would mean "row's pair status changes its fingerprint", which contradicts the v3 fingerprint contract (re-importing the same row produces the same hash, regardless of cross-account context).
- **Do NOT auto-detect format by inspecting CSV bytes.** Per the existing `SourceAdapterRegistry` contract, the user declares the format via the wizard's three-issuer picker. The `HeaderSniffer` validates AFTER declaration; it never sniffs to choose.
- **Do NOT swallow `UnknownPaypalEventTypeException`.** When Wave 0's empirical event-type vocabulary misses a row, raise loudly (typed exception, ERROR row in preview wizard with the unknown event-type string surfaced verbatim) rather than fall through to an "ignore" default. Silent mis-parse is the Pitfall 3 trap.
- **Do NOT roll up Hold/Authorization/Reserve rows into rawPayload.** They're dropped at the adapter boundary per D-62 — never reach the rollup walker, never reach rawPayload. The `skippedHoldCount` extras-bag counter is the only artifact.
- **Do NOT use facades / global helpers.** Per CLAUDE.md constraint and the user memory feedback: every dispatcher / cache / config / log injected via constructor DI. No `event(…)`, `Event::dispatch(…)`, `auth()`, `now()`, `cache()`, etc.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| CSV streaming + BOM handling + character-set conversion | Hand-rolled `fgetcsv()` parser | `league/csv` `Reader::from()` + `CharsetConverter::addTo()` | Already used by `AsnCsvAdapter`. Handles UTF-8 BOM transparently, supports `setHeaderOffset(0)` for associative records, lazy iteration via `getRecords()`. Hand-rolling repeats the Phase 1 mistakes. |
| Cross-currency arithmetic for `fxRateUsed` derivation | Float `/` | `Brick\Math\BigDecimal::of(…)->dividedBy(…, 8, RoundingMode::HALF_UP)` (already in `NormalizeStage`) | Float drift on FX is exactly Pitfall 1; the NoFloatMoneyArchTest already gates this. |
| Synchronous event dispatch inside a transaction | Manual `if (!$rolledBack) doStuff()` ceremony | Laravel's default sync dispatcher (no `ShouldHandleEventsAfterCommit`) | Default semantics are exactly what we need (run synchronously, inside the transaction frame); if the outer transaction rolls back, the listener's writes roll back atomically. [CITED: Laravel 12 events docs] |
| `pair_transaction_id` zero-sum invariant enforcement | DB CHECK constraint | Listener-side invariant + Pest test asserting both legs sum to zero in same currency (or are linked via fx_rate_used for cross-currency pairs) | Per D-72: SQLite cannot ALTER TABLE ADD CHECK after the fact, and cross-currency pairs (PayPal USD sweep → ASN EUR receive) don't satisfy a zero-sum check at the minor-unit level. The listener owns the invariant. |
| Anonymisation of the raw PayPal CSV | Manual sed/awk passes the user runs | `scripts/anonymize_paypal_csv.php` — idempotent regex-driven passes, in-repo, runnable on any future export | Same convention as Phase 3 (`scripts/anonymize_ics_text.php`). Re-runnable, version-controlled, audited. The user hands over the raw CSV; the anonymisation script is OUR canonical redaction tool. |
| Synthetic Transaction-ID generation that preserves parent-child links | Random replacement IDs | Deterministic fixed-length zero-padded counter mapping in a one-pass replacement (e.g., real ID `O-5SR21849FD1278524` → synthetic `O-00000000000000001`; same real ID anywhere in the file maps to the same synthetic) | Per D-58: anonymisation MUST preserve `Reference Txn ID` parent-child relationships verbatim. Random replacement breaks the rollup walker's test against the redacted fixture. |

**Key insight:** the project's policy from PROJECT.md/CLAUDE.md ("DI-only, no facades, no helpers, Larastan level 10 strict, no float on money, idempotent imports") forces every shortcut to be re-built as a proper class. The Phase 1/2/3 codebase has already done the hard work for CSV streaming, BOM stripping, integer-cent money arithmetic, BigDecimal FX derivation, and Brick\Money currency handling. Phase 4 is a composition phase, not a foundations phase.

## Runtime State Inventory

Phase 4 is greenfield work — not a rename / refactor / migration phase. Section omitted per the research template.

## Common Pitfalls

### Pitfall 1: Listener runs AFTER commit and never sees the partner row

**What goes wrong:** The user uploads ASN with a PayPal sweep counterparty row first, then uploads PayPal with the matching outflow second. If the listener uses `ShouldHandleEventsAfterCommit`, it fires after the import transaction commits — and the ASN partner row IS visible at that point (it's been committed by the previous import). So far so good.

But within ONE import (e.g., a CAMT.053 file that contains both legs of an ASN→ICS settlement on the same day), the listener fires per-row inside the outer transaction. With `ShouldHandleEventsAfterCommit`, both listeners are delayed to the post-commit hook; both fire after the transaction; both query for the partner; both find it; both write `pair_transaction_id` — potentially racing each other and producing a partial pair.

**Why it happens:** Laravel's `ShouldHandleEventsAfterCommit` was designed for "send an email after a DB commit" use cases. It's the wrong primitive for "atomically pair two rows that were just written in the same transaction."

**How to avoid:** Plain synchronous in-transaction dispatch. The listener queries for the partner; if absent, returns without writing (half-pair state — D-74); if present, writes both `pair_transaction_id` columns inside the SAME outer transaction (no new `DB::transaction(…)` nesting needed — Laravel's nested transaction handling via savepoints is fine but unnecessary here). The next firing of the listener (e.g., for the partner's `TransactionImported`) will find the now-paired row and exit early because of the `pair_transaction_id IS NULL` filter.

**Warning signs:**
- Listener implements `ShouldHandleEventsAfterCommit` or has `public bool $afterCommit = true`.
- Listener wraps its writes in `DB::transaction(…)` (unnecessary — it's already inside one).
- Test that imports both legs in one file produces unpaired rows.
- Two parallel `pair_transaction_id` writes for the same row.

### Pitfall 2: Currency-Conversion rollup misclassifies which leg is the "native"

**What goes wrong:** The PayPal Activity Download CSV ships `Currency Conversion` event rows in pairs (one row OUT of foreign currency, one row INTO settlement currency, both sharing a `Reference Txn ID`). If the rollup walker arbitrarily picks the second row as "native", a $9.99 charge ends up with `amountMinor = 907` and `currency = 'EUR'` — the FX information is lost. The settled-EUR amount silently overrides the original-amount preservation contract (LED-03).

**Why it happens:** The CSV doesn't label which leg is "native" vs "settled". Either row could conceptually be either side. The walker must inspect the `currency` column and pick the non-account-currency leg as `currency` / `amountMinor` and the EUR leg as `settledCurrency` / `settledAmountMinor`.

**How to avoid:**
- Walker explicitly checks: `if ($row['Currency'] !== 'EUR') { /* this is the native leg */ }`.
- Wave 0 fixture MUST include at least one foreign-currency payment (USD or GBP) so the walker is exercised on the FX path.
- Snapshot test (`spatie/pest-plugin-snapshots`) on the rolled-up DTO stream pins both legs verbatim.
- The reconciliation gate (D-60 g) catches systematic miscounts: if `sum(net) ≠ closing - opening` on the user's real upload, the bug is in the rollup walker.

### Pitfall 3: Half-pair gets re-typed when the partner lands

**What goes wrong:** First import lands the ASN side of a PayPal sweep, typed `transfer_out` (counterparty_iban = `'PAYPAL'` matches a PayPal account). `pair_transaction_id` stays NULL. Second import lands the PayPal side, typed `transfer_out` (the PayPal `General Withdrawal` event-type maps to `transfer_out`). The listener finds the orphan and writes both `pair_transaction_id` columns.

So far correct. But if the second import's `ClassifyTransactionType` step also re-runs against the now-paired ASN row, it could re-type the ASN row from `transfer_out` to something else — destroying the half-pair invariant.

**Why it happens:** The `ClassifyTransactionType` step is intended to run during NORMALIZE — once per row, on its way IN to the ledger. If a later step (or a later refactor) ever re-classifies an already-persisted row, the invariant breaks.

**How to avoid:**
- `ClassifyTransactionType` is part of `NormalizeStage` (pre-load), and it ONLY reads from the source DTO + the counterparty-IBAN predicate. It NEVER queries already-persisted transactions.
- The listener ONLY writes `pair_transaction_id`; it NEVER updates `type`.
- The manual override action (D-78) is the only path that mutates `type` on a persisted row; it clears `pair_transaction_id` on both sides when triggered (per the canonical_refs constraint).
- Pest test: import partner X, partner Y; assert types are stable AFTER pairing.

### Pitfall 4: `import_runs.sha256` UNIQUE blocks re-upload after a rollup-walker bug

**What goes wrong:** The user uploads a PayPal CSV; the rollup walker (D-61) has a bug that drops a row; the import status confirms with wrong row count; the user notices the missing row in the dashboard. They want to re-upload the same CSV after the planner pushes a walker fix.

The existing Phase 1 `import_runs.sha256` UNIQUE short-circuits a re-upload of an already-confirmed file (`RunImport::runFromUpload` line 67–76). So the user can't re-import — they're blocked.

**Why it happens:** D-58's tier-1 dedup is the right policy for byte-identical re-uploads (the common case). But after a parser bug-fix, the user legitimately needs to re-import the SAME bytes because the parser interpretation has changed.

**How to avoid:** The Phase 1 design already partially handles this via the `diederik:rederive-fingerprints` artisan command — but that command re-derives fingerprints, not rollup output. For Phase 4: defer this to the operational hardening phase (Phase 11). For now, document that walker bug-fixes need a manual `DELETE FROM import_runs WHERE …` step. Acceptable for a single-user local app.

The planner should NOT make the SHA-256 UNIQUE conditionally bypassable in Phase 4 — that's a foundational change that lands later. Mention in `04-PATTERNS.md` so the team knows the operational workaround.

### Pitfall 5: Anonymised fixture loses the parent-child relationship

**What goes wrong:** The anonymiser script replaces each `Transaction ID` with a random synthetic ID. But the same real Transaction ID appears in multiple rows (parent's `Transaction ID` matches children's `Reference Txn ID`). If random replacement maps each occurrence independently, the parent-child link is broken in the fixture — the rollup walker test passes against the broken fixture and silently misses bugs against real input.

**Why it happens:** Naive `preg_replace_callback` runs ONCE per match position. Anonymisation requires a **two-pass deterministic counter map**.

**How to avoid:**
- Pass 1: scan the entire CSV, build `$realToSynthetic` mapping (`'O-5SR21849FD1278524' => 'O-00000000000000001'`).
- Pass 2: rewrite every occurrence of any real ID via the mapping.
- Same approach for `Reference Txn ID` column — it MUST resolve through the same map, so parent-child links survive.
- Pest test asserts that for every redacted row, every `Reference Txn ID` value either is empty OR points to a `Transaction ID` value also present in the file.

## Code Examples

Verified patterns from official sources + shipped diederik code:

### Example 1: PayPal `SourceTransactionDto` for a USD currency-conversion pair

```php
// Source: diederik Modules/Ingestion/Public/Dto/SourceTransactionDto.php +
// Phase 3 D-42 contract + Phase 4 D-63

// User makes a $74.43 USD purchase; PayPal converts to €68.86 EUR.
// CSV shows two rows under shared Reference Txn ID:
//   row A: type='Express Checkout Payment', Gross='-74.43', Currency='USD',
//          Transaction ID='O-00000000000000010', Reference Txn ID=''
//   row B: type='Currency Conversion', Gross='-68.86', Currency='EUR',
//          Transaction ID='C-00000000000000011', Reference Txn ID='O-00000000000000010'
// PayPalTransactionRollup folds the pair into one DTO:

new SourceTransactionDto(
    bookedAt:           CarbonImmutable::parse('2026-03-15 12:34:56'),
    postedAt:           CarbonImmutable::parse('2026-03-15')->startOfDay(),
    valueDate:          CarbonImmutable::parse('2026-03-15')->startOfDay(),
    ownIban:            'PAYPAL',
    counterpartyIban:   null,                       // PayPal merchants have no IBAN
    counterpartyName:   'Netflix.com',
    currency:           'USD',                      // ← FROM ROW A (the foreign leg)
    amountMinor:        -7443,                      // ← FROM ROW A
    sourceRef:          'O-00000000000000010',      // ← parent's Transaction ID (D-64)
    description:        'Express Checkout Payment / Netflix.com',
    rawPayload:         [                            // ← D-65 event manifest
        'format' => 'paypal-csv',
        'events' => [
            ['type' => 'Express Checkout Payment', 'row' => $rawRowA],
            ['type' => 'Currency Conversion',      'row' => $rawRowB],
        ],
    ],
    sourceRowIndex:     0,                          // canonical-row index, not raw-row
    settledAmountMinor: -6886,                      // ← FROM ROW B (D-63)
    settledCurrency:    'EUR',                      // ← FROM ROW B (D-63)
    fxRateUsed:         null,                       // NormalizeStage derives via Phase 3 D-39
);
```

### Example 2: `PairTransferCandidates` listener — the deterministic ±3-day query

```php
// Source: this research + existing FingerprintStage.php query pattern (lines 56-62)
// Location: Modules/Transfers/Internal/Listeners/PairTransferCandidates.php

namespace Modules\Transfers\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

final class PairTransferCandidates
{
    /**
     * ±3-day window per D-73. Stored as an integer constant so the
     * tolerance is greppable and reusable in tests.
     */
    private const WINDOW_DAYS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function handle(TransactionImported $event): void
    {
        $tx = $event->transaction;
        $user = $event->user;

        // Layer-1 only fires when this row is itself a transfer leg.
        if (! in_array($tx->type, ['transfer_out', 'transfer_in'], true)) {
            return;
        }

        // Skip if already paired (defensive — could happen if a re-import
        // triggers the listener for an already-linked row).
        if ($tx->pair_transaction_id !== null) {
            return;
        }

        // The counterparty IBAN must match one of the user's other accounts
        // (synthetic IBANs 'ICS-CARD', 'PAYPAL' participate as ordinary
        // Account.iban values per D-73).
        if ($tx->counterparty_iban === null) {
            return;
        }

        $partnerAccount = Account::query()
            ->where('user_id', $user->id)
            ->where('iban', $tx->counterparty_iban)
            ->first();
        if ($partnerAccount === null) {
            return;
        }

        $windowStart = $tx->booked_at->subDays(self::WINDOW_DAYS);
        $windowEnd   = $tx->booked_at->addDays(self::WINDOW_DAYS);

        // Equal-and-opposite signed amount, same currency, partner-account,
        // unpaired, within window. Uses the partial index from the migration
        // for cheap scan even at 100k+ rows.
        /** @var Transaction|null $partner */
        $partner = Transaction::query()
            ->where('user_id', $user->id)
            ->where('account_id', $partnerAccount->id)
            ->where('amount_minor', -$tx->amount_minor)
            ->where('currency', $tx->currency)
            ->whereBetween('booked_at', [$windowStart, $windowEnd])
            ->whereNull('pair_transaction_id')
            ->whereIn('type', ['transfer_out', 'transfer_in'])
            // Don't pair to self (paranoia — should never happen since
            // account_id differs, but defensive):
            ->where('id', '!=', $tx->id)
            ->orderBy('booked_at')
            ->first();

        if ($partner === null) {
            return;   // half-pair state per D-74; partner may land later
        }

        // Symmetric write inside the outer import transaction. Pattern 1
        // (Pitfall 1) — NO new DB::transaction wrapper.
        $tx->pair_transaction_id = $partner->id;
        $tx->save();
        $partner->pair_transaction_id = $tx->id;
        $partner->save();
    }
}
```

⚠ **Cross-currency caveat:** the `where('currency', $tx->currency)` filter assumes Layer-1 pairs are always same-currency. For a PayPal USD outflow → ASN EUR receive, the counterparty IBANs match but currencies differ. **Wave 0 verifies** whether PayPal's "Transfer to bank" rows surface in EUR or USD — if they land in USD (which is the common case for non-EUR balances), Layer-1 misses cross-currency sweeps and they remain unpaired in Phase 4 (Layer-2 ships in Phase 5). The planner notes this explicitly in the limits doc.

### Example 3: `ClassifyTransactionType` step inserted between Normalize and Fingerprint

```php
// Source: this research + Phase 3 NormalizeStage.php (the analog step)
// Location: Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php

namespace Modules\Import\Internal\Pipeline\Stages;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

final class ClassifyTransactionType
{
    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction
    {
        // Already-classified rows (rare but defensive): if the source format
        // emitted an explicit refund/fee, preserve it.
        if (in_array($tx->type, ['refund', 'fee', 'adjustment'], true)) {
            return $tx;
        }

        // Step 1: cross-account-IBAN check (universal — applies to every
        // source format including PayPal). When the counterparty IBAN
        // matches one of the user's own Account rows, the row is a transfer.
        if ($tx->counterpartyIban !== null) {
            $isOwnAccount = Account::query()
                ->where('user_id', $user->id)
                ->where('iban', $tx->counterpartyIban)
                ->where('id', '!=', $tx->accountId)   // not self-transfer
                ->exists();
            if ($isOwnAccount) {
                return $tx->withType($tx->amountMinor < 0 ? 'transfer_out' : 'transfer_in');
            }
        }

        // Step 2: source-format event-type map (PayPal only — ASN/ICS
        // adapters don't emit event-type metadata).
        // …PayPal-specific branch using $tx->sourceFormat and a lookup
        // against PaypalCsvEventTypeMap…

        // Step 3: subtractive income detector (D-77):
        if ($tx->amountMinor > 0
            && ! in_array($tx->type, ['transfer_in','transfer_out','refund','fee'], true)) {
            return $tx->withType('income');
        }

        // Default: keep what NormalizeStage assigned (expense for negative,
        // income for positive — D-77's subtractive rule produces income
        // implicitly).
        return $tx;
    }
}
```

`CanonicalTransaction::withType()` is a clone-with-override (the DTO is already a `spatie/laravel-data` `Data` class — pattern matches `StatementSummaryData::withImportRunId()` lines 42–60 verbatim).

### Example 4: `HeaderSniffer::sniffPaypalCsv()` arm

```php
// Source: existing HeaderSniffer::sniffAsnCsv (lines 173-215) +
// PaypalCsvLanguageProfile (Wave 0 deliverable)

// Inside HeaderSniffer::sniff()'s match expression:
PaypalCsvLanguageProfile::FORMAT => $this->sniffPaypalCsv($localPath, $head),

private function sniffPaypalCsv(string $path, string $head): SniffResult
{
    if (preg_match('/\.csv$/i', $path) !== 1) {
        throw new SniffMismatchException(
            "That file doesn't look like a CSV. Drop in the PayPal Activity Download CSV."
        );
    }

    $firstLine = strtok($head, "\r\n");
    if ($firstLine === false) {
        throw new SniffMismatchException('The file is empty.');
    }

    $columns = str_getcsv($firstLine, PaypalCsvLanguageProfile::DELIMITER, '"', '');

    // Language detection: try each registered language profile until one
    // recognises the header signature. Raise typed exception on miss.
    $profile = PaypalCsvLanguageProfile::detect($columns);
    if ($profile === null) {
        throw new UnsupportedPaypalCsvLanguageException(
            "PayPal CSV header is in an unsupported language. Supported: "
            .implode(', ', PaypalCsvLanguageProfile::supported())
            ." (got: ".implode(',', array_slice($columns, 0, 3)).' …)'
        );
    }

    return new SniffResult(
        format: PaypalCsvLanguageProfile::FORMAT,
        delimiter: PaypalCsvLanguageProfile::DELIMITER,
        hasHeader: true,
        encoding: PaypalCsvLanguageProfile::SOURCE_ENCODING,
        columnCount: count($columns),
    );
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `ext-imap` for any email path | Pure-PHP `webklex/php-imap` | PHP 8.4 unbundled `ext-imap` (Sep 2024) | Phase 4 doesn't touch email — no change here. |
| Eloquent model events for cross-module wiring | Explicit Public events in the originating module | Phase 1 module-boundary contract | `TransactionImported` lives in `Modules/Import/Public/Events/`, not as a Transaction model event. |
| Hand-rolled `nwidart/laravel-modules` auto-discovery | Manual `bootstrap/providers.php` registration | Phase 1 project shape (verified 2026-05-15) | New `TransfersServiceProvider` is added to `bootstrap/providers.php`; no composer auto-discovery. |
| `ShouldHandleEventsAfterCommit` everywhere "to be safe" | Synchronous in-transaction listener for cross-row invariants | Laravel 12 events docs — `ShouldHandleEventsAfterCommit` is for "after-the-fact" side-effects (email, queued jobs), NOT for paired-row writes that need to see uncommitted siblings | `PairTransferCandidates` is synchronous in-transaction; same-import pairing works correctly. |
| Per-row JSON CHECK constraints for invariants | Listener-side enforcement + Pest invariant tests | SQLite limitation: cannot `ALTER TABLE ADD CHECK` after table creation | `pair_transaction_id` zero-sum invariant lives in the listener, asserted by tests. |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The PayPal Activity Download CSV ships its core columns as `Date`, `Time`, `Type`, `Currency`, `Gross`, `Fee`, `Net`, `Transaction ID`, `Reference Txn ID`, `From Email Address`, `To Email Address`, `Status` in some order, with header row present | Standard Stack / Code Examples | Wave 0 confirms empirically. If header schema deviates, language profile + column-map adjusts; no architectural change. |
| A2 | "Transfer to bank" PayPal rows expose the destination IBAN somewhere — either in a `Counterparty Email` analogue, or in the memo field | Common Pitfalls (Pitfall — cross-currency caveat) | If the destination IBAN is absent / scrubbed by PayPal: Layer-1 pair detection misses every PayPal→ASN sweep until Phase 5 lights up the fuzzy matcher. Phase 4 still ships ING-05; LED-04 only auto-pairs the ASN↔ICS axis until then. Wave 0 D-60 (f) is the canonical verification. |
| A3 | The user's PayPal export ships in one consistent language (either fully EN or fully NL) — not a mid-history language switch within ONE export | D-59 | If mixed: language detection on the header row is still correct (header is one row), but event-type values would mix. Wave 0 D-60 (b) catches this and the planner amends D-62/D-77 accordingly. |
| A4 | The PayPal Activity Download CSV does NOT contain a `Closing Balance` row separate from transactions (i.e., no explicit opening/closing balance entries) | Pattern 2 / Wave 0 D-60 (g) | If explicit balance rows exist: skip them at the adapter boundary (like Holds), use them as the authoritative opening/closing balance. Wave 0 confirms. |
| A5 | `Currency Conversion` event rows share a `Reference Txn ID` with the parent payment (the rollup-walker contract D-61 depends on this) | Pattern 1 + Example 1 | If currency-conversion rows reference a DIFFERENT parent (e.g., their own Transaction ID and the parent payment references THEM), the walker's parent-detection logic flips. Wave 0 D-60 (e) is the canonical verification. |
| A6 | PayPal's `Hold` / `Authorization` rows always have a `Reversal of General Account Hold` partner so the net economic impact is zero — and Wave 0 confirms both are filtered | D-62 + Pitfall 3 (PITFALLS.md) | If a Hold row has no later Reversal in the user's history (e.g., a still-open authorization at export time), filtering it loses information. Acceptable because Phase 4 is import-time scope; a future "open auth holds" surface can replay rawPayload to recover them. |
| A7 | The user's PayPal account is single-currency-balanced in EUR (the wallet's default currency) so the listener's same-currency Layer-1 filter holds for PayPal→ASN sweeps | Example 2 caveat | If the user has a USD-balanced PayPal that sweeps USD-to-USD to an ASN USD sub-account (uncommon): same-currency match still works. If a USD PayPal sweeps to a EUR ASN with FX: Layer-1 misses, deferred to Phase 5. |
| A8 | The `import_runs.extras` JSON column is unused for PayPal-specific fields in Phases 1–3 — and Wave 0 can safely write `skippedHoldCount`, `orphanChildCount`, `language` keys without colliding with existing Phase 2 `multiStatement` / Phase 3 `cardLast4` keys | Pattern 2 / Code Examples | Verified by codebase grep on `extras['` — Phase 2 uses `multiStatement`; Phase 3 uses `cardLast4` on `statement_summaries.extras`, not `import_runs.extras`. The keys do not collide. Planner verifies during Wave 0. |

**Total assumptions: 8.** Five are Wave-0 verifiable directly from the user's fixture (A1, A4, A5, A6, A7). Three depend on user-account specifics that Wave 0 still surfaces (A2, A3, A8).

## Open Questions

1. **PayPal "Transfer to bank" destination IBAN visibility (D-60 f)**
   - What we know: PayPal's CSV exports a `Type` value like `General Withdrawal` or `Transfer to bank` for the sweep row; the destination ASN IBAN MAY appear in a memo/Note column or in `To Email Address` (which sometimes carries the destination IBAN literal for SEPA sweeps).
   - What's unclear: which exact column the destination IBAN lands in (or whether it's absent entirely, requiring Phase 5's fuzzy matcher).
   - Recommendation: Wave 0 reports verbatim what's in the user's actual sweep rows; if destination IBAN is absent, the planner accepts that PayPal→ASN pair detection is Phase 5 work and the Phase 4 success criterion #3 demoability test exercises only the ASN↔ICS pair axis.

2. **Manual override breaks-pair UX granularity (D-78 Claude's discretion)**
   - What we know: reclassifying one side of a pair MUST clear `pair_transaction_id` on BOTH rows atomically.
   - What's unclear: whether the user wants a confirmation modal ("You're about to break the link to this transaction's pair — proceed?") or a single-click + toast.
   - Recommendation: default to single-click + Filament-notifications toast ("Pair removed"); add a confirmation modal only if user feedback in Phase 4 UAT raises concerns.

3. **Three-issuer wizard ordering**
   - What we know: D-69 says ASN / ICS / PayPal as the three issuer groups.
   - What's unclear: the visual order in the picker — alphabetical? Most-recently-used? Frequency-of-use?
   - Recommendation: ASN first (most rows in this user's history), ICS second, PayPal third — same alphabetical ordering the existing wizard uses, and the user mentioned ASN is their primary source. UI-SPEC pass during plan-phase locks this.

4. **PayPal CSV reconciliation soft-warning copy**
   - What we know: when `sum(net) ≠ closing - opening` the wizard surfaces a soft warning.
   - What's unclear: exact copy.
   - Recommendation: `"PayPal reconciliation gap: expected balance change of {expected} but the imported rows sum to {actual} (difference: {delta}). Some events may not have rolled up correctly. Continue with confirm?"` — UI-SPEC locks during plan-phase.

5. **Wave 0 fixture coverage breadth**
   - What we know: a single fixture suffices initially (D-58, Claude's discretion).
   - What's unclear: whether the user can provide one CSV that contains examples of every event type the parser handles (especially `Refund`, foreign-currency `Currency Conversion`, `General Withdrawal` / `Transfer to bank`).
   - Recommendation: if the user's natural recent activity doesn't cover all event types, Wave 0 commits a SECOND tiny synthetic fixture (`paypal-sample-tiny.csv`) that exercises the parser-walker corner cases without leaking real-world data. Same pattern as Phase 3's `ics-sample-tiny.pdf`.

## Environment Availability

Phase 4 has no new external dependencies. The audit:

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | Runtime | ✓ | ^8.5 (composer.json) | — |
| Laravel | Runtime | ✓ | ^13.0 | — |
| SQLite | Storage | ✓ | (Laravel default; WAL enabled Phase 1) | — |
| `league/csv` | PayPal CSV reader | ✓ | ^9.28 | — |
| `brick/money` + `brick/math` | FX derivation | ✓ | ^0.11 | — |
| `nwidart/laravel-modules` | `Modules/Transfers/` registration | ✓ | ^13.0 | — |
| `spatie/laravel-data` | Event payload DTO + StatementSummaryData | ✓ | ^4.0 | — |
| `pestphp/pest` + `pestphp/pest-plugin-arch` | Tests | ✓ | ^4.0 | — |
| `spatie/pest-plugin-snapshots` | Rollup snapshot tests | ✓ | ^2.0 | — |
| `larastan/larastan` level 10 strict | Static analysis | ✓ | ^3.0 | — |
| `laravel/pint` | Formatting | ✓ | ^1.18 | — |
| `pdftotext` (CLI) | NOT used by Phase 4 | n/a | n/a | — |

**Missing dependencies with no fallback:** None.

**Missing dependencies with fallback:** None.

Phase 4 is pure code + schema + fixture work; no new tools, daemons, services, or runtimes are required.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.0 (PHPUnit 11 engine), pest-plugin-laravel ^4.0, pest-plugin-arch ^4.0, pest-plugin-snapshots ^2.0 |
| Config file | `phpunit.xml` (project root); module-local `Modules/<m>/tests/Pest.php` per Phase 1 convention |
| Quick run command | `vendor/bin/pest --filter "PaypalCsvAdapter\|PaypalTransactionRollup\|PairTransferCandidates"` |
| Full suite command | `composer test` (alias for `pest --parallel`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ING-05 | PayPal CSV row → ONE `SourceTransactionDto` per rolled-up logical payment | unit | `vendor/bin/pest --filter PaypalTransactionRollupTest` | ❌ Wave 0 |
| ING-05 | Filtered event types skipped at adapter boundary | unit | `vendor/bin/pest --filter "PaypalCsvAdapterTest::skipsHoldRows"` | ❌ Wave 0 |
| ING-05 | Currency-conversion pair rolls up into one canonical row with both legs | unit | `vendor/bin/pest --filter "PaypalTransactionRollupTest::foldsCurrencyConversion"` | ❌ Wave 0 |
| ING-05 | Re-importing the same PayPal CSV produces zero new rows | contract | `vendor/bin/pest --filter "IdempotencyContractTest" --group phase-4` | ✓ extends tests/Contracts/IdempotencyContractTest.php (add `paypal-csv` dataset row) |
| ING-05 | Reconciliation gate: `sum(net) == closing - opening` | unit | `vendor/bin/pest --filter "PaypalCsvAdapterTest::reconciles"` | ❌ Wave 0 |
| ING-05 | Sniffer rejects non-PayPal-CSV upload at the boundary | feature | `vendor/bin/pest --filter "HeaderSnifferTest::rejectsBadPaypalCsv"` | ❌ Wave 1 |
| ING-05 | End-to-end: upload via wizard → preview → confirm → row count matches fixture | feature | `vendor/bin/pest --filter "PaypalCsvImportTest"` | ❌ Wave 1 |
| ING-09 | API path is documented as deferred; no `paypal-api` route exists | arch | `vendor/bin/pest --filter "BoundaryArchTest::noPaypalApiRoute"` (lightweight grep-style arch test) OR a one-line REQUIREMENTS.md test that the "Deferred" section contains ING-09 | ❌ Wave 4 |
| LED-04 | After both legs of an ASN→ICS settlement land, `pair_transaction_id` is set on both rows | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::pairsAsnIcsSettlement"` | ❌ Wave 2 |
| LED-04 | When only one leg has landed, the row has `type='transfer_out'` AND `pair_transaction_id IS NULL` (half-pair state) | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::halfPair"` | ❌ Wave 2 |
| LED-04 | When the partner lands later, both `pair_transaction_id` columns are populated atomically | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::partnerLandsLater"` | ❌ Wave 2 |
| LED-04 | Listener does NOT re-type already-classified rows | feature | `vendor/bin/pest --filter "PairTransferCandidatesTest::doesNotRetype"` | ❌ Wave 2 |
| LED-04 | Listener writes BOTH sides' `pair_transaction_id` symmetrically | arch | `vendor/bin/pest --filter "BoundaryArchTest::listenerWritesBothSides"` OR coverage via feature test | ❌ Wave 2 |
| LED-04 | Listener cannot self-pair (`pair_transaction_id != id`) | feature | covered by `pairsAsnIcsSettlement` assertion | ❌ Wave 2 |
| LED-04 | Reclassifying one side via D-78 clears `pair_transaction_id` on BOTH rows | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::breaksPair"` | ❌ Wave 3 |
| LED-04 | Schema enforces `pair_transaction_id` → `transactions.id` FK with ON DELETE SET NULL | feature | `vendor/bin/pest --filter "Schema::pairTransactionFk"` (raw SQLite pragma query) | ❌ Wave 2 |
| LED-05 | Positive amount NOT transfer / refund / fee → `type='income'` | unit | `vendor/bin/pest --filter "ClassifyTransactionTypeTest::detectsIncome"` | ❌ Wave 3 |
| LED-05 | Positive amount that IS a transfer_in → stays `transfer_in`, NOT `income` | unit | `vendor/bin/pest --filter "ClassifyTransactionTypeTest::transferInIsNotIncome"` | ❌ Wave 3 |
| LED-05 | PayPal `Refund` event-type row → `type='refund'`, NOT `income` | unit | `vendor/bin/pest --filter "PaypalCsvAdapterTest::classifiesRefund"` | ❌ Wave 1 |
| LED-05 | Manual override changes `type` and persists | feature | `vendor/bin/pest --filter "TransactionDetailReclassifyTest::changesType"` | ❌ Wave 3 |
| LED-05 | Dashboard "income" rollup never includes a `transfer_in` row | feature | `vendor/bin/pest --filter "DashboardIncomeTest::excludesTransfers"` | ❌ Wave 3 |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest --filter "<task scope>"` (e.g., `PaypalTransactionRollupTest` during walker work)
- **Per wave merge:** `composer test` (full parallel suite) + `composer analyse` (Larastan level 10 strict) + `composer format:check` (Pint)
- **Phase gate:** Full suite green before `/gsd-verify-work` — same posture as Phases 1–3.

### Wave 0 Gaps

- [ ] `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` — committed redacted PayPal Activity Download fixture
- [ ] `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md` — fixture-record documentation (mirrors `ics-sample-1.md`)
- [ ] `scripts/anonymize_paypal_csv.php` — idempotent regex-driven anonymisation script
- [ ] `local/paypal/` directory + `.gitignore` entry (verify: `/local/` already covers it — confirmed by grep — but the planner should re-verify during Wave 0)
- [ ] `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php` skeleton populated with Wave-0-empirical token vocabulary
- [ ] `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvEventTypeMap.php` skeleton populated with Wave-0-empirical event-type → classification map
- [ ] `Modules/Import/Public/Events/TransactionImported.php` event class (Wave 0 deliverable — listener depends on it)
- [ ] Contract-test scaffold extension: add `'paypal-csv'` row to `tests/Contracts/IdempotencyContractTest.php` dataset (failing red baseline)
- [ ] `04-WAVE-0-FINDINGS.md` — committed empirical reporting set per D-60 (a–g)

*(If nothing maps to a gap above, the planner has missed a sub-deliverable.)*

## Security Domain

> The Phase 4 surface touches user financial data (PayPal CSV containing emails, names, transaction IDs) and adds a new module — both have ASVS implications. Security enforcement is treated as enabled (no `security_enforcement: false` flag in `.planning/config.json` per project convention).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (no new auth surface) | n/a — Phase 1 Fortify-backed login is unchanged |
| V3 Session Management | no | n/a |
| V4 Access Control | yes | `BelongsToUser` trait + explicit `user_id` filtering on every query — pattern already established. The new `PairTransferCandidates` listener filters all candidate-partner queries by `where('user_id', $user->id)` (verified in Example 2). The `TransactionDetail` Reclassify action MUST scope to `Transaction::where('user_id', $user->id)->where('id', $id)` per the existing Phase 3-07 cross-user 404 test pattern. |
| V5 Input Validation | yes | PayPal CSV input: `HeaderSniffer::sniffPaypalCsv()` validates extension + header signature before parsing; `PaypalAmountParser` rejects malformed amounts with typed `InvalidAmountException`; `PaypalCsvLanguageProfile::detect()` returns null on unrecognised language → wizard surfaces `UnsupportedPaypalCsvLanguageException`. Same posture as Phase 2/3 sniffers. |
| V6 Cryptography | no (no secrets, no encryption) | n/a |
| V7 Error Handling & Logging | yes | Typed exceptions surface as user-facing wizard error messages (precedent set in Phase 2 `SniffMismatchException`). No PII (emails, names, IBANs) is logged at INFO level — `dd($rawCsv)` / `Log::info($paypalRow)` are forbidden and would trigger Larastan strict-rules warnings. |
| V8 Data Protection | yes | Raw PayPal CSV file is stored under `storage/app/imports/{user_id}/{sha256}.csv` (Phase 1 RunImport pattern) — chmod-600 on the storage tree per Laravel default. No raw CSV bytes leak into git: `local/paypal/` is gitignored under `/local/` (verified). |
| V12 Communications | no (localhost-only) | n/a — `127.0.0.1` bind enforced by Phase 1 |
| V13 API and Web Service | partial — not adding APIs; ING-09 deferred | n/a in Phase 4. The deferral preserves the no-new-API surface posture. |
| V14 Configuration | yes | New `Modules/Transfers/` module registered explicitly in `bootstrap/providers.php` (not via auto-discovery). No new env vars. No new config files. |

### Known Threat Patterns for {Laravel 13 + SQLite + Livewire 4 + Pest stack}

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-user transaction access (T-04-01) | Information disclosure | Every query in the new code MUST filter on `user_id`. The Phase 3-07 cross-user 404 test pattern (a second user's transaction URL returns 404) extends to the `TransactionDetail` reclassify action. The pair-detection listener's all queries already include `where('user_id', $user->id)` per Example 2. |
| Listener writes to another user's transaction via crafted event payload (T-04-02) | Tampering | `TransactionImported` carries a `User $user` payload; the listener filters partner queries by THAT user id, so a forged event with a Transaction belonging to user A but `user` = user B would still scope all partner queries to user B and find nothing. Defensive: assert in listener that `$event->transaction->user_id === $event->user->id` and throw on mismatch. |
| SQL injection via PayPal CSV cell content (T-04-03) | Tampering | All CSV values flow through prepared statements via Eloquent / DatabaseManager. No string concatenation into SQL. Verified pattern from Phase 1–3. |
| CSV injection (formula injection) when re-exporting fixture | Tampering / Code injection | Phase 4 does NOT re-export the fixture to any consumer outside test fixtures. If a future phase displays the rawPayload in a UI, the Blade `{{ }}` escaping suffices. |
| Race condition: two parallel imports both writing `pair_transaction_id` to the same row | Tampering | The outer `DB::transaction(…)` in `ConfirmImport` is SQLite-serialized in WAL mode (single writer). Two parallel imports queue at the SQLite level; the second sees the first's writes. Not a concern in the single-user local-only deployment. |
| Cross-user IBAN matching: listener pairs user A's transfer to user B's account | Authorization bypass | Every Account lookup is `where('user_id', $user->id)` filtered. Even when two users have an `Account` with `iban = 'PAYPAL'` (which is allowed per the per-user UNIQUE on `(user_id, iban)`), the listener never matches across users. Verified in Example 2. |
| Larastan level 10 strict pre-deployment regression | n/a | `composer analyse` is the canonical gate. New code must pass with zero new errors. Pattern: existing modules already at level 10 strict. |

## Sources

### Primary (HIGH confidence)
- [VERIFIED] `Modules/Ingestion/Public/Contracts/SourceAdapter.php` — adapter contract shape
- [VERIFIED] `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` — DTO with Phase 3 D-42 settled-pair fields
- [VERIFIED] `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` — registry pattern
- [VERIFIED] `Modules/Ingestion/Public/Services/HeaderSniffer.php` — sniff extension point (lines 55–65 `match` block)
- [VERIFIED] `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` — CSV-adapter reference shape (lines 36–199)
- [VERIFIED] `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php` — stateful-summary reference shape (lines 80–159)
- [VERIFIED] `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` — settled-substitution + BigDecimal FX derivation site (lines 64–88)
- [VERIFIED] `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` — fingerprint-lookup query pattern (lines 53–84)
- [VERIFIED] `Modules/Import/Public/Actions/ConfirmImport.php` — outer transaction shape (lines 92–127)
- [VERIFIED] `Modules/Import/Public/Actions/RunImport.php` — sha256 idempotency (lines 56–110)
- [VERIFIED] `Modules/Import/Public/Services/SourceRefRanker.php` — rank function extension site (lines 22–37)
- [VERIFIED] `Modules/Ledger/Public/Actions/RecordTransactions.php` — insertOrIgnore per-row loop site (lines 44–69)
- [VERIFIED] `Modules/Ledger/Public/Dto/StatementSummaryData.php` — full DTO shape
- [VERIFIED] `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` — base schema + existing UNIQUE indexes + type triggers
- [VERIFIED] `Modules/Ledger/Models/Transaction.php` — TYPES constant (line 61), $fillable, casts
- [VERIFIED] `Modules/Ledger/Models/Account.php` — Account shape and `BelongsToUser`
- [VERIFIED] `Modules/Import/Internal/Http/Livewire/UploadWizard.php` — three-issuer extension point (lines 49–113)
- [VERIFIED] `Modules/Import/Internal/Http/Livewire/PreviewWizard.php` — generalised naming-branch extension point (lines 134–266)
- [VERIFIED] `bootstrap/providers.php` — module provider registration site (5 lines)
- [VERIFIED] `composer.json` — dependency set
- [VERIFIED] `tests/Contracts/IdempotencyContractTest.php` — dataset extension site
- [VERIFIED] `.planning/REQUIREMENTS.md` — ING-05, ING-09, LED-04, LED-05 + traceability table
- [VERIFIED] `.planning/ROADMAP.md` Phase 4 — Goal + SC #2 rewrite target
- [VERIFIED] `.planning/research/PITFALLS.md` Pitfall 3 — PayPal CSV reconciliation contract (load-bearing)
- [VERIFIED] `.planning/research/ARCHITECTURE.md` — pair_transaction_id intent + listener-via-events pattern
- [VERIFIED] `.planning/phases/01-foundation-asn-csv-vertical-slice/01-SKELETON.md` (in canonical_refs) — Phase 4 forward-declaration
- [VERIFIED] `.planning/phases/03-ics-cards-multi-currency-display/03-RESEARCH.md` — Phase 3 wizard + dual-amount precedent
- [VERIFIED] `.planning/phases/03-ics-cards-multi-currency-display/03-PATTERNS.md` — pattern-assignment / analog-classification methodology
- [VERIFIED] `./CLAUDE.md` — DI-only, no facades, no helpers, Larastan level 10 strict project constraints
- [VERIFIED] `$HOME/.claude/projects/.../memory/MEMORY.md` — user feedback memory items (DI-only, codebase-stays-agnostic, docs-describe-current-state, fix-all-severities, ICS PDF-only)

### Secondary (MEDIUM confidence)
- [CITED: developer.paypal.com/docs/reports/online-reports/activity-download/] — PayPal Activity Download CSV column list (verified via WebFetch: includes `Transaction ID`, `Reference Txn ID`, `Date`, `Time`, `Type`, `Status`, `Currency`, `Gross`, `Fee`, `Net`, `From/To Email Address` among 87 fields)
- [CITED: laravel.com/docs/12.x/events] — `ShouldHandleEventsAfterCommit` semantics + default sync-in-transaction dispatch
- [CITED: laravel-news.com/laravel-10-30-0] — Dispatch Events after a DB Transaction (used to confirm we want OPPOSITE behavior here)
- [CITED: github.com/laravel/framework/issues/52440] — listener afterCommit behavior nuance
- [VERIFIED: WebSearch 2026-05-15] — PayPal Activity Download CSV (one row per event), CSV/TAB capped at 50k records, splits into ZIP for larger; same source confirms language localisation of headers in non-EN accounts

### Tertiary (LOW confidence)
- WebSearch results on PayPal Currency Conversion two-row vs single-row representation are inconclusive — definitive answer comes from Wave 0 D-60 (e). Assumption A5 logged.

## Metadata

**Confidence breakdown:**
- Standard stack (zero new deps): HIGH — composer.json + grep over codebase
- Architecture (extension points): HIGH — direct file reads of every named extension site
- `TransactionImported` event mechanics: HIGH — Laravel 12 docs + existing transaction-frame patterns in `ConfirmImport.php`
- `PairTransferCandidates` listener query design: HIGH — verified against existing `FingerprintStage` query pattern + Phase 3 `Modules/Ledger/Internal/Http/Livewire/TransactionDetail` cross-user filtering
- `pair_transaction_id` migration shape: HIGH — verified against existing `2026_05_13_010002_add_enriched_from_to_transactions.php` pattern + SQLite ALTER TABLE limitations
- PayPal CSV column layout / event-type vocabulary: MEDIUM — official PayPal docs confirm column NAMES exist but not exact row-grouping semantics; intentionally Wave 0's job (D-57, D-59, D-60)
- Half-pair → full-pair listener flow: HIGH — algorithm derived from existing same-DB-frame patterns; no novel mechanics
- Income detector subtractive rule: HIGH — three-line classification rule (D-77) is unambiguous

**Research date:** 2026-05-15
**Valid until:** 2026-06-15 (stable codebase; PayPal CSV format historically stable but Wave 0 is the canonical empirical check)

## RESEARCH COMPLETE
