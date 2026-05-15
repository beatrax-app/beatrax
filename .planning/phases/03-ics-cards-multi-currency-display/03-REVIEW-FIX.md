---
phase: 03-ics-cards-multi-currency-display
fixed_at: 2026-05-15T18:50:00Z
review_path: .planning/phases/03-ics-cards-multi-currency-display/03-REVIEW.md
iteration: 1
findings_in_scope: 17
fixed: 17
skipped: 0
status: all_fixed
---

# Phase 3: Code Review Fix Report

**Fixed at:** 2026-05-15T18:50:00Z
**Source review:** `.planning/phases/03-ics-cards-multi-currency-display/03-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 17 (4 BLOCKER, 7 WARNING, 6 INFO)
- Fixed: 17
- Skipped: 0
- Status: all_fixed

**Final test posture (worktree):**
- `vendor/bin/pest --group=phase-3 --exclude-group=integration` — **83 passed** (621 assertions). Baseline 80 → +3 new tests added during the fix pass.
- `vendor/bin/pest --exclude-group=integration` (full suite) — **472 passed**, 3 skipped, 0 failed. Baseline 469 → +3 new tests.
- `vendor/bin/pest` (full suite incl. integration) — **473 passed**, 3 skipped, 0 failed.
- `vendor/bin/pint --test` — **passed** (no formatting drift).
- `vendor/bin/phpstan analyse --memory-limit=2G` (level=max with `larastan` + `larastan-livewire` + `larastan-strict-rules` + `phpstan-strict-rules`) — **0 errors**.

**New tests added during the fix pass:**
- `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php` — *"it parses the six empirical summary amounts column-by-column from the four-token header + the two-column limit block"* (locks the empirical opening / received / charges / closing / credit-limit / minimum-due minor values on the redacted fixture).
- `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php` — *"it reads the statement sequence number from the Volgnummer column"* (locks the empirical sequence number `'2'`).
- `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php` — *"it parses a Dutch thousands+decimal amount: 1.416,50 → 141650"* (locks the empirical summary-row amount format and the `€ 2.500,00` shape).

**Architectural notes:**
- Promoted account-name validation to a `public static AccountNamer::validateName()` helper shared by both the IBAN-naming and the ICS-card-naming flows (WARNING-06). The two paths still diverge on the IBAN-shape guard (the synthetic `ICS-CARD` literal cannot pass it), but the 1..80 character + slug-body validation now stays in lock step.
- Promoted `'EUR'` literal to `IcsPdfHeaderProfile::STATEMENT_CURRENCY` (INFO-03).
- The `parseSummaryAmount()` substring-search heuristic in `IcsPdfAdapter` was replaced with two column-aware regex passes: `parseFourColumnSummary()` (opening + received + charges + closing in one match) and `parseTwoColumnLimitBlock()` (credit-limit + minimum-due in one match). A `safeParseAmount()` helper centralises the `InvalidAmountException`-to-null conversion. The old per-token `parseSummaryAmount()` method is gone; the new shape is column-aware by construction.
- The dashboard `$fmt` closure that the INFO findings flagged as candidate for promotion to `Money::format()` was **not** moved to a shared helper, because `Money::format(?string $locale = null)` already auto-selects the locale based on currency (introduced in 03-06's SUMMARY). The dashboard's inline `$fmt` is now an explicit-locale call site that documents the auto-selection at the call site; promoting it would just hide the routing behind another layer. Left in place as an intentional inline doc of the per-currency locale.

## Fixed Issues

### BLOCKER-01 + WARNING-01: ICS PDF statement-summary parser rebuilt column-by-column

**Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`, `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php`
**Commit:** `375a6e6`
**Applied fix:** Replaced the substring-search heuristic in `parseSummaryAmount()` with two column-aware regex passes: `parseFourColumnSummary()` captures opening + received + charges + closing in a single match across the four-token header on page 1, and `parseTwoColumnLimitBlock()` captures Bestedingslimiet + Minimaal te betalen bedrag in a single match across the two-column block at the foot of page 1. Tightened `parseStatementNumber()` so the regex actually matches the empirical "<date>  KLANTNUMMER  <volgnummer>  <sheet> van <total>" value line. Added two new value-asserting tests that lock the empirical minor values (opening -60696, closing -141650, period charges -141650, period received 60696, credit limit 250000, minimum due 141650) and the empirical statement sequence number `'2'`.

### BLOCKER-04: TransactionDetail blade scales FX rate via BigDecimal, not float

**Files modified:** `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php`
**Commit:** `fb0d1af`
**Applied fix:** Replaced `number_format((float) $transaction->fx_rate_used, 3, '.', '')` with `(string) BigDecimal::of($transaction->fx_rate_used)->toScale(3, RoundingMode::HALF_UP)` inside the `@php` block, then rendered `$fxRateDisplay` directly in the markup. The persisted column is `decimal(18,8)`; staying in string-land via BigDecimal honours the integer-only money rule. Display output (`€0.929 / USD`) is unchanged — all existing TransactionDetailFxRateTest cases stay green.

### WARNING-02: raw_payload migration uses schema() helper

**Files modified:** `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php`
**Commit:** `b8ea6a4`
**Applied fix:** Swapped `Schema::table()` for the DI-aware `$this->schema()->table()` shape its three sibling migrations under the same `Modules/Ledger/Database/Migrations/` directory use. Migrations remain the standing exception to the DI-only rule (anonymous migrations cannot accept constructor arguments), but the helper convention is now consistent within the module. Also reworded the docstring to describe what the column does today rather than reference historical adapter phases.

### WARNING-03: extractCounterpartyName docstring matches behaviour

**Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`
**Commit:** `6dc63d8`
**Applied fix:** Rewrote the docstring to describe what the method actually does — strips the trailing two-letter country code and collapses whitespace, leaving city / street fragments intact. The previous docstring claimed "just the merchant token" which was a doc-vs-implementation drift.

### WARNING-04: thousands-separator amount parser test added

**Files modified:** `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php`
**Commit:** `3eabb4e`
**Applied fix:** Added an explicit `it(...)` case that asserts `1.416,50 → 141650`, `€ 1.416,50 → 141650`, and `€ 2.500,00 → 250000`. These are the empirical summary-row shapes from `ics-sample-1.txt`. A regression on `str_replace('.', '')` would now fail loudly.

### WARNING-05: PdfExtractionFailed propagates from IcsPdfAdapter::parse

**Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`
**Commit:** `06edece`
**Applied fix:** Dropped the `try { ... } catch (Throwable)` shell around `$this->extractor->extract(...)` that rethrew as `InvalidAmountException`. The extractor wraps every underlying error into `PdfExtractionFailed` already, so the catch was redundant on top of being misleading. Callers (the wizard, the importer) now see the typed `PdfExtractionFailed` and can render tailored "install poppler" / "PDF too large" messages.

### WARNING-06: shared name-validation between IBAN and ICS-card naming flows

**Files modified:** `Modules/Import/Public/Services/AccountNamer.php`, `Modules/Import/Internal/Http/Livewire/PreviewWizard.php`
**Commit:** `89c2dde`
**Applied fix:** Promoted the length-bound + slug-body validation in `AccountNamer::__invoke()` to a public static `AccountNamer::validateName(string $name): array{0: string, 1: string}` helper that returns `[trimmedName, slugBody]`. `PreviewWizard::saveIcsAccountName()` now calls the static helper instead of re-implementing the same length-bound and slug-body checks inline. The two flows still diverge on the IBAN-shape guard (the synthetic `'ICS-CARD'` literal cannot pass the ISO 13616 structural check), but the name half stays in lock step.

### WARNING-07: dead reset_() method removed

**Files modified:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php`
**Commit:** `2536318`
**Applied fix:** Deleted the `reset_()` method. It had no callers in Blade, JavaScript, or tests. `toggleFullHistory()` already clears the cursor on toggle; a future explicit clear-cursor action can land cleanly when a UI surface needs it.

### BLOCKER-02 + BLOCKER-03 + INFO-04: GSD artefacts and historical narration stripped

**Files modified (21 files):**
- Production: `Modules/Core/Resources/views/livewire/dashboard.blade.php`, `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php`, `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php`, `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`, `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfExtractionMap.php`, `Modules/Ledger/Public/Contracts/RecordsStatementSummary.php`
- Tests: `Modules/Core/tests/Feature/DashboardOriginalModeRenderTest.php`, `Modules/Core/tests/Feature/SettingsPageTest.php`, `Modules/Import/tests/Feature/IcsPdfImportTest.php`, `Modules/Import/tests/Unit/NormalizeStageTest.php`, `Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php`, `Modules/Ledger/tests/Feature/Phase2SchemaShapeTest.php`, `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php`, `Modules/Ledger/tests/Feature/TransactionListQuerySecondaryAmountTest.php`, `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php`, `Modules/Ledger/tests/Unit/MoneyFormatTest.php`, `tests/Pest.php`
- Fixtures: `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md`, `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.md`
- Scripts: `scripts/anonymize_ics_text.php`, `scripts/generate_tiny_ics_pdf.php`

**Commit:** `5f439e2`
**Applied fix:** Stripped every `D-XX` / `MC-XX` / `UI-XX` / `LED-XX` / `Wave N` / `plan 03-XX` / `Phase 1/2/3` / `CONTEXT.md` / `PLAN.md` / `RESEARCH.md` / `UI-SPEC.md` / `VALIDATION.md` reference from production source, scripts, tests, and fixture markdown. Rewrote the docstrings flagged by BLOCKER-03 (dashboard.blade.php, NormalizeStage.php, IcsPdfAdapter.php, IcsPdfExtractionMap.php, RecordsStatementSummary.php) so they describe what the code does today rather than narrate previous drafts or historical phases. The fixture markdowns kept their empirical layout documentation (statement-summary tokens, FX-row shape, amount/date formats, masked-card metadata) but dropped the decision-ID labels and the plan-history retrospective at the foot of `ics-sample-1.md`. `tests/Pest.php` dropped the two phase-group commentary blocks; the `->group('phase-N')` chains on individual tests remain self-documenting.

Final verification grep confirms zero remaining GSD references outside `.planning/`.

### INFO-01: IcsAmountParser currency-strip regex constrained to known ISO codes

**Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php`
**Commit:** `d8b614a`
**Applied fix:** Replaced `\b[A-Z]{3}\b` with `\b(?:EUR|USD|GBP|JPY|CHF|CAD|AUD)\b`. The parser only receives the trailing amount cell today so the broader regex was harmless, but the narrower form is defensive and matches the intent.

### INFO-02: statementMetadata() iterator-exhaustion contract documented

**Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`
**Commit:** `588e024`
**Applied fix:** Added a PHPDoc block on `statementMetadata()` explicitly noting that the method returns null until `parse()` has been iterated to completion, with example call-site shapes (`iterator_to_array($generator, false)` or a `foreach` walk).

### INFO-03: STATEMENT_CURRENCY constant on IcsPdfHeaderProfile

**Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfHeaderProfile.php`, `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`
**Commit:** `114de15`
**Applied fix:** Promoted the inline `'EUR'` literal in `buildStatementMetadata()` to `IcsPdfHeaderProfile::STATEMENT_CURRENCY`. The assumption is now named at the type level alongside the format identifier and the upload-size cap.

### INFO-05: accounts->resolve() fire-and-forget annotated

**Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`
**Commit:** `b81c8ea`
**Applied fix:** Rewrote the inline comment on the `$accounts->resolve($ownIban)` call to make the deliberate fire-and-forget visible. The returned AccountResolution is discarded because ParseStage re-resolves per row downstream; the call exists solely to trigger the wizard's UnknownAccount branching.

### INFO-06: sweep test routes through PdfTextExtractor

**Files modified:** `tests/Feature/AnonymisedFixtureSweepTest.php`
**Commit:** `946610b`
**Applied fix:** Replaced the inline `Symfony\Component\Process\Process` invocation with a direct `(new PdfTextExtractor)->extract($fixtureTinyPdf)` call. Any future change to the extractor's flag set is now automatically reflected in the sweep assertion, strengthening the "the extractor's flag set produces zero PII" contract.

## Skipped Issues

None — all 17 findings were fixed.

---

_Fixed: 2026-05-15T18:50:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
