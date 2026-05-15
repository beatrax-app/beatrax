---
phase: 03-ics-cards-multi-currency-display
verified: 2026-05-15T22:30:00Z
status: human_needed
score: 11/11 must-haves verified
overrides_applied: 0
human_verification:
  - test: "End-to-end real PDF import via the browser"
    expected: "Logged-in user visits /imports/new, picks Source=ICS, Format=PDF, uploads a Mijn ICS export with at least one FX charge; preview renders rows; first-time upload prompts for ICS card account name (verbatim copy 'Name your ICS card account.'); after Save name, rows preview shows; Confirm import lands rows; subsequent ICS upload skips the naming step"
    why_human: "Automated tests substitute the PdfTextExtractor with a fixture stub. A real-PDF round-trip exercises poppler 26.04.0 on the user's machine, the wizard cascade UI, the naming-step Blade branch, and the import pipeline together in a single flow."
  - test: "Dashboard at / in 'original' currency mode (per-currency tile rows)"
    expected: "After Settings → Default view = 'Original currency', dashboard / renders one tile-row per currency present in the period (EUR + USD + GBP alphabetical), each captioned with the ISO code, with In/Out/Net values formatted as €68,86 (nl_NL) for EUR and $74.43 (en_US) for USD/GBP. Zero-activity currencies are omitted. Switching the preference back to 'EUR only' collapses to the Phase 1 single-row layout"
    why_human: "Visual layout, ordering, spacing, and Tailwind chrome of the per-currency tile section can be programmatically asserted (DashboardOriginalModeRenderTest does), but the calm-aesthetic feel and the user's eyeball check that the captions/grouping land right is human-only."
  - test: "Transactions list at /transactions in 'original' currency mode with FX rows"
    expected: "/transactions renders the Flux segmented control 'EUR only / Original currency'. When the toggle is 'Original currency' (or user default is 'original'), foreign-currency rows render as two stacked lines: native primary (e.g. '$50.00') in slate-900, settled-EUR secondary (e.g. '€ 43,71') in mt-1 slate-500 text-xs. EUR-native rows render as a single line. Toggle clicks update the URL ?currency=eur / ?currency=original; clean URL means the user preference is in effect. Page refresh preserves the toggle state"
    why_human: "Dual-line render visual stack, segmented-control affordance, URL behavior on refresh and back-button, and Flux component appearance need human eyeball verification. Automated assertSeeText covers the strings but not the layout."
  - test: "Transaction detail at /transactions/{id} for a USD transaction Effective-rate row"
    expected: "Visiting /transactions/{id} for one of the imported FX transactions (e.g. AUGMENT CODE 50 USD → 43,71 EUR) renders the calm two-column metadata block AND below it an 'Effective rate' <dl> row showing '€0.874 / USD' (rate scaled to 3 decimals via BigDecimal) and a slate-500 12px helper line 'Includes any ICS markup.' For an EUR-native row, the Effective rate <dl> is absent."
    why_human: "Layout of the <dl>, the helper text positioning, and the EUR-symbol-and-slash rendering at three decimals are visual contracts."
  - test: "Settings page round-trip (two preferences) UX"
    expected: "Visiting /settings renders the calm form with two fields: Default view on the transactions list (EUR only / Original currency) and Period starts on day (1..28). Submit shows inline 'Saved.' in emerald-700 that auto-dismisses after ~4s via wire:transition. Validation errors render verbatim 'Choose a day from 1 to 28.' and 'Pick one of the available options.' in rose-600 below each field"
    why_human: "Auto-dismiss animation, inline confirmation timing, and validation copy positioning need a human spot-check beyond the automated assertion that the strings appear in the rendered HTML."
---

# Phase 3: ICS Cards Multi-Currency Display Verification Report

**Phase Goal (post-PDF-pivot):** User can import ICS Cards PDF statements (Mijn ICS consumer-portal export) with non-EUR charges preserved as both original-currency and settled-EUR, and switch transaction views between EUR-only and dual-currency.

**Verified:** 2026-05-15T22:30:00Z
**Status:** human_needed (all automated must-haves PASSED, 5 visual / end-to-end items routed to human UAT)
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (derived from ROADMAP Success Criteria + 7-plan PLAN frontmatter merge)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | A Mijn ICS PDF statement can be imported end-to-end (PDF upload → text extraction → adapter parse → normalize → transactions persist) | VERIFIED | `Modules/Import/tests/Feature/IcsPdfImportTest.php` 9 tests Green; first test imports every parsed row from the redacted fixture on the first import; `Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php` exercises real `pdftotext` against `ics-sample-tiny.pdf` |
| 2 | The wizard surfaces a two-step Source → Format cascading picker; choosing ICS reveals only PDF; ASN keeps CSV/CAMT.053/MT940 | VERIFIED | `Modules/Import/Internal/Http/Livewire/UploadWizard.php` lines 99–108 define `availableFormats()` match expression returning the three ASN leaves or the single `ics-pdf` leaf; `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` lines 14–35 render two cascading selects with `wire:model.live="issuer"` + `aria-live="polite"`; 6 UploadWizardTest cascade-behaviour cases Green |
| 3 | First-ICS-upload triggers a Name-your-ICS-card-account prompt; subsequent uploads skip it | VERIFIED | `Modules/Import/Internal/Http/Livewire/PreviewWizard.php` lines 134–177 define `saveIcsAccountName()` + `needsIcsAccountName()` predicate keyed off ImportRun.source_format='ics-pdf' AND absence of any Account row with kind='ics_card'; locked copy 'Name your ICS card account.' / "first time you've imported ICS data" rendered in `preview-wizard.blade.php` lines 20–21; both naming scaffolds Green in IcsPdfImportTest |
| 4 | Foreign-currency rows persist native + settled-EUR + fx_rate_used; rawPayload.format='ics-pdf' archived per row | VERIFIED | `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` declares `settled_amount_minor BIGINT`, `settled_currency CHAR(3)`, `fx_rate_used DECIMAL(18,8) NULLABLE`; `2026_05_15_010001_add_raw_payload_to_transactions.php` adds `raw_payload JSON NULLABLE`; `IcsPdfImportTest::it persists native + settled + fx_rate_used for a foreign-currency row` Green; `IcsPdfImportTest::it persists rawPayload.format = ics-pdf and a non-empty extractedText per row` Green |
| 5 | NormalizeStage substitutes settled = native when source omits the pair AND derives fx_rate_used via BigDecimal at scale 8 / HALF_UP when both legs differ | VERIFIED | `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` lines 68–87 implement the D-42 substitution + D-39 BigDecimal derivation; NoFloatMoneyArchTest stays Green; 4 NormalizeStageTest assertions Green |
| 6 | SourceTransactionDto carries D-42 nullable settledAmountMinor / settledCurrency / fxRateUsed without breaking Phase 1/2 ASN call sites | VERIFIED | `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` lines 47–49 append three nullable readonly properties with `= null` defaults; Phase 1/2 AsnCsv/AsnCamt053/AsnMt940 tests stay Green (no Phase 1/2 adapter call sites modified) |
| 7 | HeaderSniffer dispatches IcsPdfHeaderProfile::FORMAT to a sniffIcsPdf() arm with `%PDF-` magic-byte check | VERIFIED | `Modules/Ingestion/Public/Services/HeaderSniffer.php` line 59 adds the match arm; lines 81–91 implement the magic-byte check via `IcsPdfHeaderProfile::MIME_MAGIC = '%PDF-'` |
| 8 | SourceAdapterRegistry binds 'ics-pdf' → IcsPdfAdapter via constructor wiring in IngestionServiceProvider | VERIFIED | `Modules/Ingestion/Providers/IngestionServiceProvider.php` line 38: `'ics-pdf' => $app->make(IcsPdfAdapter::class)` |
| 9 | Settings page persists default_currency_view + period_start_day; dashboard + /transactions read the preference | VERIFIED | `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` adds `default_currency_view STRING(16) DEFAULT 'eur_only'`; `SettingsPage.php` save() persists both fields; 6 SettingsPageTest cases Green; `TransactionsList.php` line 55 reads `$currentUser->user()->default_currency_view` in mount(); `Dashboard.php` line 87 reads same pref for forByCurrency() branching |
| 10 | /transactions toggles between EUR-only and Original modes via Flux segmented control + #[Url] binding; FX rows render dual-line stack in Original mode | VERIFIED | `TransactionsList.php` line 49: `#[Url(as: 'currency', except: '')]`; `transactions-list.blade.php` lines 22–25 render the Flux segmented control; lines 65–66 render the optional mt-1 text-xs slate-500 secondary line when `$currency === 'original' && $row->secondaryAmount !== null`; 7 TransactionsListCurrencyToggleTest cases Green |
| 11 | Transaction detail at /transactions/{id} renders an Effective-rate <dl> row when fx_rate_used IS NOT NULL; cross-user 404 invariant holds | VERIFIED | `TransactionDetail.php` mount() uses raw `DatabaseManager::table('transactions')` exists() with `user_id` scoping (line ~46–58); `transaction-detail.blade.php` lines 18–22 scale rate via `BigDecimal::of(...)->toScale(3, HALF_UP)` (NOT float — BLOCKER-04 fix); locked copy 'Effective rate' (line 56) + 'Includes any ICS markup.' (line 60); 5 TransactionDetailFxRateTest cases Green (4 from scaffolds + 1 cross-user 404 guard) |

**Score:** 11/11 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php` | Adapter family — parse() + statementMetadata() | VERIFIED | 686 lines, implements `SourceAdapter`, registered in `SourceAdapterRegistry`, exercised by 10 IcsPdfAdapterTest cases + 9 IcsPdfImportTest cases + IdempotencyContractTest 'ics-pdf' dataset row |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfHeaderProfile.php` | FORMAT='ics-pdf', MIME_MAGIC='%PDF-', MAX_BYTES, STATEMENT_CURRENCY | VERIFIED | 39 lines; all five constants present (FORMAT + MIME_MAGIC + MAX_BYTES + SOURCE_ENCODING + STATEMENT_CURRENCY 'EUR') |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfExtractionMap.php` | Anchor tokens, per-page noise, FX-line anchor, summary tokens | VERIFIED | 100 lines; revolving-credit nomenclature per Wave-0 empirical addendum (D-51): SUMMARY_OPENING='Vorig openstaand saldo' + 5 others; PAGE_NOISE_PATTERNS = 7 line-anchored regexes; FX_LINE_ANCHOR='Wisselkoers '; CARD_LAST4_LINE_PREFIX='Uw Card met als laatste vier cijfers ' |
| `Modules/Ingestion/Internal/Adapters/Ics/PdfTextExtractor.php` | exec() wrapper with locked flags + typed exception | VERIFIED | 120 lines; PDFTOTEXT_OPTIONS = ['layout', 'enc UTF-8', 'eol unix', 'nopgbrk']; is_file/is_readable/filesize/MAX_BYTES guards; throws `PdfExtractionFailed`; non-final so unit tests can substitute |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php` | nl_NL amount parser | VERIFIED | 80 lines; 6 IcsAmountParserTest cases Green (incl. thousands+decimal `1.416,50 → 141650` added by WARNING-04 fix) |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsDateParser.php` | nl_NL date parser | VERIFIED | 98 lines; 5 IcsDateParserTest cases Green |
| `Modules/Ingestion/Public/Exceptions/PdfExtractionFailed.php` | Typed RuntimeException | VERIFIED | 21 lines; extends RuntimeException; lives under Public/Exceptions/ so Import can catch it |
| `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` | Extended with D-42 nullable trio | VERIFIED | settledAmountMinor + settledCurrency + fxRateUsed all `= null` defaults |
| `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` | D-42 substitution + D-39 BigDecimal | VERIFIED | BigDecimal + RoundingMode imports; scale 8 HALF_UP division; 4 NormalizeStage assertions Green |
| `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` | users.default_currency_view column | VERIFIED | STRING(16) DEFAULT 'eur_only' AFTER period_start_day; reversible down() |
| `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` | transactions.raw_payload JSON column | VERIFIED | json nullable AFTER source_ref; reversible down() |
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` | Settings Livewire SFC | VERIFIED | 92 lines; method-DI on mount/save/render; #[Validate] attrs; messages() locked error copy |
| `Modules/Core/Resources/views/livewire/settings-page.blade.php` | Calm form view | VERIFIED | Locked UI-SPEC copy verbatim ('Default view on the transactions list' / 'Save settings' / 'Saved.') |
| `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php` | Url-bound currency property + mount() fallback | VERIFIED | `#[Url(as: 'currency', except: '')]`; mount() reads `$currentUser->user()->default_currency_view` to resolve the empty sentinel |
| `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` | Flux segmented control + dual-line render | VERIFIED | flux:radio.group variant="segmented"; locale-aware $fmt closure (EUR → nl_NL, else en_US); conditional secondary line guard |
| `Modules/Ledger/Public/Dto/PerCurrencyTile.php` | Per-currency dashboard DTO | VERIFIED | 33 lines; Spatie Data final class; currency + inflow + outflow + net Money fields |
| `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` | forByCurrency() sibling method | VERIFIED | New method ALONGSIDE existing for(); GROUP BY settled_currency, HAVING non-zero, ORDER BY alphabetical; returns array_values() for list<PerCurrencyTile> Larastan inference |
| `Modules/Ledger/Public/ValueObjects/Money.php` | Locale-aware format() default | VERIFIED | format(?string $locale = null); EUR → nl_NL, else en_US; backward-compatible (every Phase 1/2 explicit `format('nl_NL')` call still works) |
| `Modules/Core/Internal/Http/Livewire/Dashboard.php` | render() branches on default_currency_view | VERIFIED | tiles=null in eur_only mode; tiles=list<PerCurrencyTile> in original mode via $glance->forByCurrency() |
| `Modules/Core/Resources/views/livewire/dashboard.blade.php` | Conditional KPI tile render | VERIFIED | @if ($tiles === null) branches to Phase 1 single-row OR per-currency captioned tile rows |
| `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` | Detail page Livewire SFC | VERIFIED | 86 lines; final class, zero constructor; method-DI via mount(int, CurrentUser, DatabaseManager) + render(CurrentUser, ViewFactory); raw query builder for exists() check + Eloquent firstOrFail() for typed model read |
| `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php` | Conditional FX-rate <dl> | VERIFIED | BigDecimal-scaled rate display (BLOCKER-04 fix — was `(float)` cast); locked copy 'Effective rate' + 'Includes any ICS markup.' |
| `Modules/Ledger/Routes/web.php` | /transactions/{transactionId} route | VERIFIED | Route::get → TransactionDetail::class with whereNumber + name('transactions.show') |
| `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt` | Anonymised PDF text fixture | VERIFIED | 15571 bytes, 102 lines; three real FX rows (Augment Code USD, Audible UK GBP, Vitrus USD) |
| `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` | Synthetic anonymised tiny PDF | VERIFIED | 981 bytes; pdftotext round-trips `SYNTHETIC` literal; under 10 KB budget |
| `tests/Contracts/IdempotencyContractTest.php` | 'ics-pdf' dataset row | VERIFIED | Both Tier 1 (SHA dedup) + Tier 2 (v3 fingerprint dedup) Green for 'ics-pdf' |
| `tests/Feature/AnonymisedFixtureSweepTest.php` | PII guard | VERIFIED | 5 cases Green (4 default + 1 integration); zero 12+ digit runs, zero IBAN-shaped tokens, KAARTHOUDER placeholder present, card-number placeholder present |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| SourceAdapterRegistry | IcsPdfAdapter | `'ics-pdf' => $app->make(IcsPdfAdapter::class)` | WIRED | IngestionServiceProvider.php line 38 |
| HeaderSniffer | IcsPdfHeaderProfile | match arm `IcsPdfHeaderProfile::FORMAT => sniffIcsPdf()` | WIRED | HeaderSniffer.php line 59 |
| NormalizeStage | Brick\Math\BigDecimal | `BigDecimal::of(...)->dividedBy(..., 8, HALF_UP)` | WIRED | NormalizeStage.php lines 82–87 |
| IcsPdfAdapter | PdfTextExtractor | Constructor-DI'd `private readonly PdfTextExtractor` | WIRED | IcsPdfAdapter.php constructor; non-final extractor enables unit-test substitution |
| TransactionsList.mount() | users.default_currency_view | `$currentUser->user()->default_currency_view` empty-sentinel fallback | WIRED | TransactionsList.php line 55 |
| Dashboard.render() | ThisPeriodAtAGlanceQuery::forByCurrency() | Branched on `$user->default_currency_view === 'original'` | WIRED | Dashboard.php lines 85–87 |
| TransactionDetail blade | transactions.fx_rate_used column | `@if ($transaction->fx_rate_used !== null)` conditional dl | WIRED | transaction-detail.blade.php lines 18, 56–60 |
| transactions-list.blade | TransactionsList.$currency | `wire:model.live="currency"` + #[Url(as: 'currency')] | WIRED | transactions-list.blade.php line 22 |
| upload-wizard.blade | UploadWizard.$issuer | `wire:model.live="issuer"` + #[Validate('required|in:asn,ics')] | WIRED | upload-wizard.blade.php line 14; UploadWizard.php Validate attribute |
| PreviewWizard.saveIcsAccountName() | accounts table | `kind='ics_card'`, `iban='ICS-CARD'`, server-built; AccountNamer::validateName() shared helper | WIRED | PreviewWizard.php lines 134–177 |
| upload-wizard.blade | settings route | `<a href="{{ route('settings') }}">Settings</a>` in top-nav | WIRED | top-nav.blade.php lines 43–45 |

### Data-Flow Trace (Level 4 — verifies upstream data is real, not stub/empty)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|--------------|--------|--------------------|----|
| TransactionsList | $rows | TransactionListQuery via DatabaseManager builder | Real SQL with WHERE user_id, posted_at, conditional projection of secondary_minor/currency | FLOWING |
| Dashboard tiles | $tiles | ThisPeriodAtAGlanceQuery::forByCurrency() with GROUP BY settled_currency + HAVING non-zero + ORDER BY alphabetical | Live SQL aggregation against `transactions` (not array of test fixtures) | FLOWING |
| TransactionDetail | $transaction | Eloquent `Transaction::query()->where('id', ...)->where('user_id', ...)->firstOrFail()` after a raw exists()-check gate | Real row read; the empirical fixture run produces three FX rows with real fx_rate_used values | FLOWING |
| SettingsPage | $defaultCurrencyView / $periodStartDay | `$currentUser->user()->default_currency_view` / `period_start_day` pre-filled in mount(); save() writes back to user row | Real DB column round-trip; persistence test asserts the second mount() picks up the saved value | FLOWING |
| PreviewWizard ICS-naming branch | $needsIcsAccountName | Raw DatabaseManager count() over `accounts` filtered by user_id + kind='ics_card' AND ImportRun.source_format='ics-pdf' | Live SQL predicate (not constant true/false); 'prompts the user…' test seeds-then-deletes-the-ICS-account; 'skips…' test relies on the seeded account | FLOWING |
| IcsPdfAdapter | StatementSummaryData | parseFourColumnSummary() + parseTwoColumnLimitBlock() regex passes on extracted PDF text via PdfTextExtractor wrapper | Real text extraction (or test-double for unit tests); the BLOCKER-01 fix added value-asserting tests pinning empirical minor values (opening -60696, closing -141650, charges -141650, received 60696, credit limit 250000, minimum due 141650) and sequence number '2' | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| phase-3 tests Green | `vendor/bin/pest --group=phase-3 --exclude-group=integration` | 83 passed, 621 assertions, 1.58s | PASS |
| Full suite Green | `vendor/bin/pest --exclude-group=integration` | 472 passed, 3 skipped, 13434 assertions | PASS |
| PHPStan strict clean | `vendor/bin/phpstan analyse --memory-limit=2G --no-progress` | No errors | PASS |
| Pint clean | `vendor/bin/pint --test` | passed | PASS |
| End-to-end ICS PDF import | `vendor/bin/pest Modules/Import/tests/Feature/IcsPdfImportTest.php` | 9 passed, 35 assertions | PASS |
| Currency-toggle + dashboard + detail tests | `vendor/bin/pest Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php DashboardCurrencyModeTest.php TransactionDetailFxRateTest.php` | 17 passed, 47 assertions | PASS |
| Anonymisation sweep Green | `vendor/bin/pest tests/Feature/AnonymisedFixtureSweepTest.php` | 5 passed, 6 assertions (incl. integration case that exec's real pdftotext) | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| ING-04 | 03-01, 03-02, 03-03 | User can upload ICS PDF (Mijn ICS export) and have transactions imported, with original-currency + settled-EUR preserved per line where applicable | SATISFIED | 9 IcsPdfImportTest cases Green (incl. EUR-native + FX-row persistence, rawPayload archive, card-number scrub guard, two PreviewWizard naming cases); HeaderSniffer + SourceAdapterRegistry + UploadWizard cascade all wired; REQUIREMENTS.md line 27 marks Complete |
| LED-03 | 03-01, 03-02, 03-07 | Each transaction stores both original-currency amount and settled-EUR amount where the source provides it, plus the FX rate when available | SATISFIED | Schema columns settled_amount_minor / settled_currency / fx_rate_used all populated by NormalizeStage's D-42 substitution + D-39 BigDecimal derivation; IcsPdfImportTest asserts the FX row persists with 1.14390 displayed and a BigDecimal-derived fx_rate_used; TransactionDetailFxRateTest asserts the surface; REQUIREMENTS.md line 49 marks Complete |
| MC-02 | 03-01, 03-04, 03-05, 03-06 | User can switch between EUR-only and dual-currency views on transaction lists and reports | SATISFIED | Storage half: users.default_currency_view column + SettingsPage. List half: TransactionsList #[Url] + Flux segmented control + dual-line FX render. Dashboard half: forByCurrency() + per-currency tile rows with alphabetical ordering. 7 TransactionsListCurrencyToggleTest + 5 DashboardCurrencyModeTest + 6 SettingsPageTest + 3 DashboardOriginalModeRenderTest cases Green; REQUIREMENTS.md line 103 marks Complete |
| UI-06 | 03-01, 03-05, 03-07 | All currency amounts surface their original currency when different from settled (e.g. "$12.99 USD → €12.07 EUR") | SATISFIED | TransactionsList dual-line render in original mode (native primary + settled-EUR secondary) Green; TransactionDetailFxRateTest 5 cases Green incl. format '€{rate} / {ISO}' with 3-decimal BigDecimal precision; REQUIREMENTS.md line 98 marks Complete |

No orphaned requirements: every ID declared in plan frontmatter (ING-04, LED-03, MC-02, UI-06) maps cleanly to REQUIREMENTS.md and to Green tests.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none) | — | — | — | All 17 code-review findings (BLOCKER-01..04, WARNING-01..07, INFO-01..06) closed by 03-REVIEW-FIX commits |

Scope-targeted scans for slippage came back clean:
- `grep -rE "\.planning/|D-3[0-9]|D-4[0-9]|D-5[0-9]|PLAN\.md|RESEARCH\.md|SUMMARY\.md" Modules/ app/ bootstrap/` → 0 matches (GSD-agnostic invariant intact after BLOCKER-02/03 fix)
- `grep -rE "auth\(\)|Auth::user\(|\\\\Auth\\\\Facades"` on every Livewire SFC + adapter + pipeline-stage modified by Phase 3 → 0 production matches (the only hit is a docblock in TransactionDetail.php line 31 spelling out what's forbidden)
- `grep -rE "\(float\)" Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php` → 0 matches (BLOCKER-04 closed; rate scaling now via BigDecimal)
- Test posture invariants: NoFloatMoneyArchTest, BoundaryArchTest, UserIdColumnArchTest all Green (architecture invariants intact)

### Empirical Wave-0 Addendum Compliance (D-51 / D-53 / D-37)

The Wave-0 plan (03-01) surfaced three CONTEXT.md major deviations during empirical PDF extraction. The verification confirms 03-02 honored each:

| Addendum | Required Behaviour | Implementation | Status |
|----------|-------------------|----------------|--------|
| D-51 (statement-summary tokens) | Use revolving-credit nomenclature, NOT current-account nomenclature | `IcsPdfExtractionMap::SUMMARY_TOKENS` = ['Vorig openstaand saldo', 'Totaal ontvangen betalingen', 'Totaal nieuwe uitgaven', 'Nieuw openstaand saldo', 'Bestedingslimiet', 'Minimaal te betalen bedrag'] (line 92–) | VERIFIED |
| D-53 (per-page noise) | Strip cardholder banner / card watermark / statement-summary header — NOT `Pagina X van Y` (does not exist) | `PAGE_NOISE_PATTERNS` (line 38–53) anchors on KAARTHOUDER, 'Uw Card met als laatste vier cijfers ', 'Datum ICS-klantnummer Volgnummer Bladnummer' header line, plus Apple Pay banner, depositogarantiestelsel disclaimer, body paragraphs — no `Pagina \d+ van \d+` regex present | VERIFIED |
| D-37 (card last-four) | Parse the last-four from 'Uw Card met als laatste vier cijfers <FOUR_DIGITS>' body line and write to `statement_summaries.extras.cardLast4`; never persist the full PAN | `IcsPdfAdapter::parseCardLast4()` (line 181) regex captures the four-digit group; `extras` (line 557–562) carries `issuer => 'Mastercard'`, `cardLast4`, `cardholderName => 'STRIPPED'`; adapter scrubs any 12+ digit run or card-number placeholder before persisting `raw_payload` | VERIFIED |

### Human Verification Required

Five end-to-end UAT items are routed to human verification (see frontmatter for the structured list). They are NOT blocking gaps — every automated must-have passes — but the visual/feel/round-trip aspects can only be confirmed by the user on the real Mijn ICS PDF with their own browser session.

### Gaps Summary

No gaps. Every Phase 3 success criterion is observably true in the codebase:
- The user CAN import a Mijn ICS PDF; an end-to-end test exercises the path with the redacted full-fixture text (`ics-sample-1.txt` via container-substituted extractor) AND the integration smoke test exec's real `pdftotext` against the tiny synthetic PDF.
- The schema CAN store dual-currency + fx_rate_used; the migration suite declares the columns and NormalizeStage actively populates them (BigDecimal-precise, no float).
- The user CAN toggle EUR-only / Original mode on /transactions AND set a global default on /settings; both surfaces are wired to the same `users.default_currency_view` column.
- The dashboard renders per-currency tile rows in Original mode AND collapses to the Phase 1 single row in EUR-only mode.
- The transaction-detail page renders the Effective rate row when fx_rate_used is non-null, with the rate scaled to three decimals via BigDecimal (BLOCKER-04 fix), and omits the row when fx_rate_used IS NULL.

The phase is **goal-achieved end-to-end** for the automated surface. Status is `human_needed` only because five visual/UAT items require a real human eyeball; they are not failures, they are out-of-band confirmations of layouts and animations the test harness can assert text-presence for but not visual quality.

---

*Verified: 2026-05-15T22:30:00Z*
*Verifier: Claude (gsd-verifier)*
