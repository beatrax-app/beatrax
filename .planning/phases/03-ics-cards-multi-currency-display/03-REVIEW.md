---
phase: 03-ics-cards-multi-currency-display
reviewed: 2026-05-15T12:00:00Z
depth: standard
files_reviewed: 65
files_reviewed_list:
  - .gitignore
  - Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php
  - Modules/Core/Internal/Http/Livewire/Dashboard.php
  - Modules/Core/Internal/Http/Livewire/SettingsPage.php
  - Modules/Core/Models/User.php
  - Modules/Core/Providers/CoreServiceProvider.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/settings-page.blade.php
  - Modules/Core/Resources/views/livewire/top-nav.blade.php
  - Modules/Core/Resources/views/settings.blade.php
  - Modules/Core/Routes/web.php
  - Modules/Core/tests/Feature/DashboardOriginalModeRenderTest.php
  - Modules/Core/tests/Feature/SettingsPageTest.php
  - Modules/Import/Internal/Http/Livewire/PreviewWizard.php
  - Modules/Import/Internal/Http/Livewire/UploadWizard.php
  - Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php
  - Modules/Import/Public/Actions/RunImport.php
  - Modules/Import/Resources/views/livewire/preview-wizard.blade.php
  - Modules/Import/Resources/views/livewire/upload-wizard.blade.php
  - Modules/Import/tests/Feature/IcsPdfImportTest.php
  - Modules/Import/tests/Feature/UploadWizardTest.php
  - Modules/Import/tests/Unit/NormalizeStageTest.php
  - Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php
  - Modules/Ingestion/Internal/Adapters/Ics/IcsDateParser.php
  - Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php
  - Modules/Ingestion/Internal/Adapters/Ics/IcsPdfExtractionMap.php
  - Modules/Ingestion/Internal/Adapters/Ics/IcsPdfHeaderProfile.php
  - Modules/Ingestion/Internal/Adapters/Ics/PdfTextExtractor.php
  - Modules/Ingestion/Providers/IngestionServiceProvider.php
  - Modules/Ingestion/Public/Dto/SourceTransactionDto.php
  - Modules/Ingestion/Public/Exceptions/PdfExtractionFailed.php
  - Modules/Ingestion/Public/Services/HeaderSniffer.php
  - Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php
  - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php
  - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsDateParserTest.php
  - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php
  - Modules/Ingestion/tests/Unit/Adapters/Ics/PdfTextExtractorTest.php
  - Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php
  - Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php
  - Modules/Ledger/Internal/Http/Livewire/TransactionsList.php
  - Modules/Ledger/Models/Transaction.php
  - Modules/Ledger/Providers/LedgerServiceProvider.php
  - Modules/Ledger/Public/Dto/CanonicalTransaction.php
  - Modules/Ledger/Public/Dto/PerCurrencyTile.php
  - Modules/Ledger/Public/Dto/TransactionRowDto.php
  - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php
  - Modules/Ledger/Public/Services/TransactionListQuery.php
  - Modules/Ledger/Public/ValueObjects/Money.php
  - Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php
  - Modules/Ledger/Resources/views/livewire/transactions-list.blade.php
  - Modules/Ledger/Routes/web.php
  - Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php
  - Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php
  - Modules/Ledger/tests/Feature/TransactionListQuerySecondaryAmountTest.php
  - Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php
  - Modules/Ledger/tests/Unit/MoneyFormatTest.php
  - README.md
  - composer.json
  - scripts/anonymize_ics_text.php
  - scripts/generate_tiny_ics_pdf.php
  - tests/Contracts/IdempotencyContractTest.php
  - tests/Feature/AnonymisedFixtureSweepTest.php
  - tests/Pest.php
  - tests/TestCase.php
  - CLAUDE.md
findings:
  blocker: 4
  warning: 7
  info: 6
  total: 17
status: issues_found
---

# Phase 3: Code Review Report

**Reviewed:** 2026-05-15T12:00:00Z
**Depth:** standard
**Files Reviewed:** 65
**Status:** issues_found

## Summary

Phase 3 introduces the ICS PDF ingestion adapter, multi-currency display surfaces (per-currency dashboard tiles, currency-view toggle on `/transactions`, conditional FX-rate row on `/transactions/{id}`), and a `/settings` page. The Livewire-side surfaces are well-built — DI is clean, validation is correct, cross-user 404 invariants are enforced — but the **ICS PDF statement-summary parser silently mis-parses three of six financial values on every real statement**, because the heuristic does not understand the column-aligned multi-token header layout that the empirical fixture documents. The error never surfaces because no unit test asserts numeric values for the affected tokens.

Additional concerns: the `php artisan` migration convention drift between Core (DI-aware `DatabaseManager`) and Ledger (`Schema::` facade) leaves the new `raw_payload` migration inconsistent with its siblings; multiple test files and production scripts carry GSD planning artefacts (`D-XX`, `MC-02`, `UI-06`, `Wave 2`, `plan 03-04`) in PHPDocs in violation of the codebase-stays-GSD-agnostic rule; and the dashboard / NormalizeStage / IcsPdfAdapter docstrings describe historical context ("Phase 1/2 ASN adapters leave both fields null", "an earlier draft of the parser anticipated") in violation of the docs-describe-current-state rule.

The ICS amount + date parsers, the FX-rate derivation, and the multi-currency display path are otherwise solid. The PDF extractor wrapper correctly delegates to Symfony Process via spatie/pdf-to-text (no shell-string concatenation; the hostile-path test exercises the argv-array escape boundary).

## Blocker Issues

### BLOCKER-01: `parseSummaryAmount` mis-parses closing balance, period charges, and minimum-due — silently corrupts persisted `statement_summaries` rows

**File:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php:547-583`
**Issue:** The empirical Mijn ICS statement (see `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt:12-13`) renders the four-token summary header inline on a single line, with the four corresponding amounts on the value line directly below. `parseSummaryAmount` finds the FIRST `strpos` hit for each token then scans for the first `€ <amount>` after that offset. Because every summary token's match position is followed by the SAME value line, every token after the first sees `€ 606,96` (the opening balance) as its "first € amount". Concretely on the empirical fixture:

- `Vorig openstaand saldo` → `€ 606,96` (correct, opening = 60696 minor)
- `Totaal ontvangen betalingen` → `€ 606,96` (accidentally correct — credits also `606,96` in this fixture, but the algorithm would give the wrong value on any statement where credits ≠ opening)
- `Totaal nieuwe uitgaven` → `€ 606,96` (**WRONG** — should be `€ 1.416,50`)
- `Nieuw openstaand saldo` → `€ 606,96` (**WRONG** — should be `€ 1.416,50`)
- `Bestedingslimiet` → `€ 2.500,00` (correct on page 1, where Min-due is to the right of Limit on line 64)
- `Minimaal te betalen bedrag` → `€ 2.500,00` (**WRONG** — should be `€ 1.416,50`; the token appears on line 63 to the right of `Bestedingslimiet`, and the first `€` after it is the value below `Bestedingslimiet`)

Result: the persisted `statement_summaries` row carries `closing_balance_minor = -60696` (negated by the sign rule on line 578) when the true value is `-141650`, and `extras.totalChargesMinor` and `extras.minimumDueMinor` are similarly wrong. This corrupts ledger reconciliation against the statement.

No test catches the bug because `IcsPdfAdapterTest` line 201-202 only asserts non-null:

```php
expect($metadata->openingBalanceMinor)->not->toBeNull();
expect($metadata->closingBalanceMinor)->not->toBeNull();
```

**Fix:** Replace the substring-search heuristic with column-aware parsing. Two viable approaches:

1. Build a single regex anchored on the full summary-row sequence and capture all four amounts in order:
   ```php
   if (preg_match(
       '/Vorig openstaand saldo.+Totaal ontvangen betalingen.+Totaal nieuwe uitgaven.+Nieuw openstaand saldo[\s\S]*?€\s+([\d.,]+)\s+(?:Af|Bij)?\s*€\s+([\d.,]+)\s+(?:Af|Bij)?\s*€\s+([\d.,]+)\s+(?:Af|Bij)?\s*€\s+([\d.,]+)/u',
       $text,
       $m,
   ) === 1) {
       [$opening, $received, $charges, $closing] = [$m[1], $m[2], $m[3], $m[4]];
   }
   ```
2. Or split the page-1 summary value-line on `€` separators and rely on positional order (tokens always appear in the same order as the documented `SUMMARY_TOKENS` constant). Then add explicit assertions in `IcsPdfAdapterTest`:
   ```php
   expect($metadata->openingBalanceMinor)->toBe(-60696);
   expect($metadata->closingBalanceMinor)->toBe(-141650);
   expect($extras['totalChargesMinor'])->toBe(-141650);
   expect($extras['totalReceivedMinor'])->toBe(60696);
   expect($extras['creditLimitMinor'])->toBe(250000);
   expect($extras['minimumDueMinor'])->toBe(141650);
   ```

### BLOCKER-02: GSD planning artefacts leak into production code, scripts, and tests

**File:** Multiple — `scripts/generate_tiny_ics_pdf.php:9,22,40`; `scripts/anonymize_ics_text.php:70`; `Modules/Core/tests/Feature/SettingsPageTest.php:10,13`; `Modules/Import/tests/Feature/IcsPdfImportTest.php:18,20`; `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php:11`; `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php:13`; `Modules/Ledger/tests/Feature/TransactionListQuerySecondaryAmountTest.php:15`; `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md:126,137,163,165,170,194,202,208,212,214,257,262,272,286,310,312,342,344,348,351,359,361,370`; `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.md:5,56,64,68`
**Issue:** The user's project memory (CLAUDE.md → MEMORY.md) explicitly mandates: *"No `.planning/` / PLAN.md / RESEARCH.md references in code, PHPDocs, or comments"* and *"No `D-XX` decision IDs in code, PHPDocs, or comments"*. Phase 3 introduces dozens of these references — `D-31`, `D-34`, `D-35`, `D-37`, `D-39`, `D-40`, `D-47`, `D-49`, `D-51`, `D-52`, `D-53`, `D-56`, `MC-02`, `UI-06`, `Wave 0`, `Wave 1`, `Wave 2`, `plan 03-02`, `plan 03-03`, `plan 03-04`, `plan 03-05`, `CONTEXT.md`. Tests, fixture markdowns, AND production scripts (`scripts/generate_tiny_ics_pdf.php`, `scripts/anonymize_ics_text.php`) carry these. The codebase is no longer GSD-agnostic.

**Fix:** Remove every GSD identifier from non-`.planning/` content. For each occurrence, rewrite the comment to describe what the code does, not which plan / decision drove the design. Example:

```php
// BEFORE (Modules/Core/tests/Feature/SettingsPageTest.php)
/*
 * Feature tests for the minimal /settings page (plan 03-04). Covers
 * MC-02's storage half (the round-trip of `default_currency_view` into
 * the users row) and discharges Phase 1's deferred `period_start_day`
 * Settings surface. Plan 03-05 owns the consumer side (TransactionsList
 * default-mode fallback).
 */

// AFTER
/*
 * Feature tests for the /settings page. Covers the round-trip of
 * `default_currency_view` and `period_start_day` into the users row
 * via the SettingsPage Livewire component.
 */
```

```php
// BEFORE (scripts/generate_tiny_ics_pdf.php)
 * Generates `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` —
 * a tiny, deterministically parseable, synthetic PDF used by the
 * Wave 2 ICS PDF idempotency-contract test to exercise the real

// AFTER
 * Generates `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` —
 * a tiny, deterministically parseable, synthetic PDF used by the ICS
 * PDF idempotency-contract test to exercise the real
```

### BLOCKER-03: Production code carries historical-context PHPDocs in violation of the "docs describe current state, not history" rule

**File:** `Modules/Core/Resources/views/livewire/dashboard.blade.php:53`; `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php:64-66`; `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php:44`; `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfExtractionMap.php:17-19`; `Modules/Ledger/Public/Contracts/RecordsStatementSummary.php:16`
**Issue:** The user's project memory explicitly states *"Docs describe current state, never history — no 'I changed this because X' comments; PHPDocs reflect what code does now"*. Phase 3 introduces multiple historical references:

- `dashboard.blade.php:53`: *"the single Phase 1 row of In / Out / Net tiles"* — references a now-historic phase, not the current behaviour
- `NormalizeStage.php:64-66`: *"Phase 1/2 ASN adapters leave both fields null; the ICS PDF adapter and future PayPal adapter fill them in only for genuine foreign-currency rows"* — narrates historical and future state
- `IcsPdfAdapter.php:44`: *"the same posture Phase 2's MT940 adapter takes when an `EREF` keyword is absent"* — cross-references a phase
- `IcsPdfExtractionMap.php:17-19`: *"current-account nomenclature an earlier draft of the parser anticipated"* — narrates a previous draft

**Fix:** Rewrite each docblock to describe what the code does right now. Example:

```php
// BEFORE (NormalizeStage.php:62-66)
        // Substitute settled = native when the source did not supply a
        // settled pair. Phase 1/2 ASN adapters leave both fields null;
        // the ICS PDF adapter and future PayPal adapter fill them in only
        // for genuine foreign-currency rows.

// AFTER
        // Substitute settled = native when the source did not supply a
        // settled pair. EUR-native source rows leave the settled fields
        // null and inherit the native pair here; foreign-currency rows
        // (where the source supplies a settled-EUR leg) carry both pairs
        // verbatim.
```

### BLOCKER-04: `TransactionDetail` blade casts `fx_rate_used` to float for display, breaking the integer-only money rule

**File:** `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php:47`
**Issue:** The detail page renders the effective FX rate via:
```php
€{{ number_format((float) $transaction->fx_rate_used, 3, '.', '') }} / {{ $transaction->currency }}
```

The `(float)` cast crosses the boundary the project's PITFALLS / "What NOT to Use" rule was designed to prevent: *"Plain floats / cents-as-int for money — Floating-point silently corrupts FX conversions"* (CLAUDE.md). While the visible output is only 3 decimal places (and the persisted `fx_rate_used` is `decimal(18,8)`), the float cast is the exact ingress pattern the rule prohibits. Real risk: a future caller copies this snippet for a different display context where 8-decimal precision matters, and the float coercion silently loses bits.

**Fix:** Use the persisted decimal string directly, with PHP-level truncation that stays in string-land:

```blade
@php
    use Brick\Math\BigDecimal;
    use Brick\Math\RoundingMode;

    $displayRate = (string) BigDecimal::of($transaction->fx_rate_used)
        ->toScale(3, RoundingMode::HALF_UP);
@endphp
…
€{{ $displayRate }} / {{ $transaction->currency }}
```

Or expose a `fxRateDisplay(int $decimals)` method on the Transaction model that does the BigDecimal scaling, so the Blade never sees the conversion.

## Warning Issues

### WARNING-01: `parseStatementNumber` regex never matches the empirical fixture — statementNumber silently NULL

**File:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php:590-603`
**Issue:** The regex `'/Volgnummer\s+Bladnummer\s*\n[^0-9]*\S+\s+\d+\s+(\d+)\s+\d+\s+van\s+\d+/m'` requires the value-line to start with `[^0-9]*\S+\s+\d+\s+(\d+)\s+\d+\s+van\s+\d+`. Tracing against the empirical fixture line 11 `"15 februari 2026                            KLANTNUMMER                                 2                                           1 van 2"`:

- `[^0-9]*` consumes leading whitespace (only) — stops at `1` of `15`
- `\S+` matches `15`
- `\s+` matches one space
- `\d+` must match next, but next token is `februari` (letters) → **no match**

`parseStatementNumber` silently returns null on every real statement. No test verifies the actual statement number value. The `statement_summaries.statement_number` column is silently NULL on every ICS import.

**Fix:** Rewrite the regex to match the actual fixture layout, e.g. by allowing the `\S+\s+` sequence to span multiple tokens:

```php
if (
    preg_match(
        '/Volgnummer\s+Bladnummer\s*\n\s*\d{1,2}\s+\S+\s+\d{4}\s+\S+\s+(\d+)\s+\d+\s+van\s+\d+/m',
        $text,
        $m,
    ) === 1
) {
    return $m[1];
}
```

And add a value-asserting test:
```php
expect($metadata->statementNumber)->toBe('2');
```

### WARNING-02: `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` uses `Schema::` facade, diverging from sibling migrations

**File:** `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php:26,33`
**Issue:** The sibling Ledger migrations `2026_05_13_010002_add_enriched_from_to_transactions.php` and `2026_05_13_010003_add_enriched_count_to_import_runs.php` (and the new Core migration `2026_05_13_010001_add_default_currency_view_to_users.php` reviewed here) use the DI-aware `$this->schema()` helper backed by `app(DatabaseManager::class)`. This new migration uses `Schema::table()` directly. Migrations are the *one* documented exception to the DI-only rule (per CLAUDE.md), but every other recent migration in the module follows the helper pattern. The new migration diverges silently.

**Fix:** Adopt the same `private function schema(): Builder { … }` helper used by the immediately-preceding migrations:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->json('raw_payload')->nullable()->after('source_ref');
        });
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('raw_payload');
        });
    }

    private function schema(): Builder
    {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);

        return $db->connection($this->getConnection())->getSchemaBuilder();
    }
};
```

### WARNING-03: `IcsPdfAdapter::extractCounterpartyName` doc claims "merchant token only" but implementation retains city + address fragments

**File:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php:405-434`
**Issue:** The docstring says: *"Drops country-code suffix and any trailing whitespace runs to leave just the merchant token from the Mijn ICS 'Omschrijving' column."* The implementation only strips the trailing two-letter country code and compresses internal whitespace; the merchant `description` after dates is `"GELDMAAT ROELANTDREEF 239   UTRECHT   NL"` → counterparty = `"GELDMAAT ROELANTDREEF 239 UTRECHT"`. That's merchant + street + city, not "just the merchant token".

This is a doc-vs-implementation drift. Either the doc is wrong, or the parser needs to also strip the city column.

**Fix:** Rewrite the docstring to describe the actual behaviour:

```php
/**
 * Strips the trailing two-letter country code and collapses internal
 * multi-space runs from the Mijn ICS "Omschrijving" column. Returns
 * the cleaned counterparty token (which may still include city /
 * address fragments — the upstream column merges them into a single
 * free-text field). The full original description still lives in the
 * DTO's `description` field; this is only the trimmed counterparty
 * variant used for FingerprintComposer normalisation.
 */
```

### WARNING-04: `IcsAmountParser` thousands-separator handling is documented but not unit-tested

**File:** `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php`
**Issue:** The amount parser's class docstring lists `1.416,50` → `141650` as a supported shape and the test file's preamble mentions *"period thousands separator (`1.416,50`)"*. No `it(...)` case actually parses a thousands-separator amount. The empirical fixture's summary row uses this shape (`€ 1.416,50`, `€ 2.500,00`) so a regression on `str_replace('.', '')` would silently break statement-summary persistence.

**Fix:** Add an explicit dataset / `it(...)` case:

```php
it('parses a Dutch thousands+decimal amount: 1.416,50 → 141650', function (): void {
    $parser = new IcsAmountParser;

    expect($parser->parse('1.416,50'))->toBe(141650);
    expect($parser->parse('€ 2.500,00'))->toBe(250000);
})->group('phase-3');
```

### WARNING-05: `IcsPdfAdapter` swallows the original exception type at the extraction boundary, replacing `PdfExtractionFailed` with `InvalidAmountException`

**File:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php:105-113`
**Issue:** When `PdfTextExtractor::extract()` throws `PdfExtractionFailed` (a custom typed exception specifically for extraction failures), `IcsPdfAdapter::parse` catches `Throwable` and re-throws as `InvalidAmountException`:

```php
} catch (Throwable $e) {
    throw new InvalidAmountException(
        sprintf('Failed to extract ICS PDF text: %s', $e->getMessage()),
        0,
        $e,
    );
}
```

This obscures the failure mode: callers (e.g. the wizard) that want to render a tailored "pdftotext binary missing — install poppler" message cannot distinguish a parser failure from an extraction failure. Worse, `InvalidAmountException` is semantically about *amount cells*, not the entire extraction pipeline. The named exception type carries no information about its actual source.

**Fix:** Let `PdfExtractionFailed` propagate, or wrap into a more general `SourceParseFailed` type. Either way, do not coerce extraction failures into the amount-parser exception:

```php
} catch (PdfExtractionFailed) {
    throw;  // let the importer's outer Throwable catch render it
} catch (Throwable $e) {
    // Non-extraction adapter-internal failures only.
    throw new InvalidAmountException(
        sprintf('Failed to parse ICS PDF: %s', $e->getMessage()),
        0,
        $e,
    );
}
```

### WARNING-06: `PreviewWizard::saveIcsAccountName` duplicates validation logic that lives in `AccountNamer` for the IBAN path

**File:** `Modules/Import/Internal/Http/Livewire/PreviewWizard.php:131-179`
**Issue:** The IBAN-naming path (line 88-94) delegates name validation to the injected `NamesAccounts` service for a *single* authoritative validator. The ICS-card-naming path re-implements length-bound and slug-body validation inline (line 140-152) because *"the synthetic IBAN doesn't satisfy the ISO 13616 structural guard the AccountNamer enforces for real IBANs"*. The validation rules drift between the two paths is now possible — bump the max length on `AccountNamer` and the ICS-card path silently keeps the old 80-char limit.

Additionally, the constructed slug `$slugBody.'-ics-card'` is not checked for global uniqueness, so two users could collide if they pick the same account name (note: `accounts.slug` does carry a unique index per the Phase-2 migrations; insertion will fail at the DB layer with a generic SQL error rather than a friendly form error).

**Fix:** Extract an `IcsAccountNamer` (or extend `AccountNamer` to handle synthetic IBANs) so the validation is centralised:

```php
public function saveIcsAccountName(
    NamesIcsAccount $icsNamer,
    RunsImports $importer,
    CurrentUser $currentUser,
): void {
    $this->resetErrorBag('icsAccountName');

    $user = $currentUser->user();

    try {
        $icsNamer->name($this->icsAccountName, $user);
    } catch (InvalidAccountNameException $e) {
        $this->addError('icsAccountName', $e->getMessage());
        return;
    }
    // … re-run importer …
}
```

### WARNING-07: `TransactionsList::reset_()` is dead code — never called from Blade or wire-level entry

**File:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php:73-77`
**Issue:** The `reset_()` method (with trailing underscore, presumably to dodge Livewire's built-in `reset()` method) is declared as public but no Blade view or test references it. `toggleFullHistory()` already resets the cursor on toggle. The method is unreferenced.

**Fix:** Delete the dead method:

```php
// Remove lines 73-77 entirely. If a future caller needs cursor reset
// it can call toggleFullHistory() or a new explicit clearCursor()
// action on first use.
```

## Info Issues

### INFO-01: `IcsAmountParser` regex `\b[A-Z]{3}\b` would consume legitimate three-uppercase merchant tokens

**File:** `Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php:41`
**Issue:** The currency-strip regex `'/[€$£¥]|\b[A-Z]{3}\b/u'` removes any three-letter uppercase token, not just ISO 4217 codes. The parser only receives the trailing amount cell, so in practice it never sees merchant text — but a defensive narrower regex (`/(?:EUR|USD|GBP|JPY|CHF|CAD|AUD|...)/`) would be more precise.

**Fix:** Constrain to the known ISO codes the project supports:

```php
$stripped = preg_replace('/[€$£¥]|\b(?:EUR|USD|GBP|JPY|CHF|CAD|AUD)\b/u', '', $trimmed);
```

### INFO-02: `IcsPdfAdapter::statementMetadata()` returns null until `parse()` has been iterated to completion

**File:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php:95-98,145-151`
**Issue:** The generator-based contract means `statementMetadata()` returns `null` if the caller never exhausts the iterator. Tests use `iterator_to_array(... , false)` to force exhaustion; production callers (the import pipeline) also iterate to completion. But the method has no signalling for "you haven't iterated yet" vs "this PDF has no metadata". A typed result wrapper or a guard exception would make the misuse loud.

**Fix:** Either document the contract verbosely on `statementMetadata()` or throw a `StatementMetadataNotReadyException` when called before parse exhaustion:

```php
public function statementMetadata(): ?StatementSummaryData
{
    if ($this->parseCalled && ! $this->parseCompleted) {
        throw new StatementMetadataNotReadyException(
            'Iterate parse() to completion before reading statementMetadata().'
        );
    }
    return $this->lastStatementMetadata;
}
```

### INFO-03: `IcsPdfAdapter::buildStatementMetadata` hard-codes `'EUR'` as opening/closing balance currency

**File:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php:521-526`
**Issue:** The ICS Cards consumer portal settles every statement in EUR, so this is correct today. But the literal `'EUR'` is hard-coded inline, and the multi-currency principle that the rest of the codebase honours (transaction.settled_currency stored per-row) is not extended here. If ICS introduces a non-EUR statement variant (unlikely but possible), this silently miscategorises.

**Fix:** Either extract a constant `ICS_STATEMENT_CURRENCY = 'EUR'` on `IcsPdfHeaderProfile` or read it from the statement header so the assumption is named:

```php
public const STATEMENT_CURRENCY = 'EUR';
```

### INFO-04: `tests/Pest.php` carries Phase-2 / Phase-3 group-convention narrative blocks that are workflow scaffolding

**File:** `tests/Pest.php:60-91`
**Issue:** Two large block comments document the focused dev-loop command `vendor/bin/pest --group=phase-N --bail`. These describe development workflow rather than what the bootstrap does. Per the codebase-stays-GSD-agnostic rule, even "phase" naming is a planning concept. The reference to "Phase 1/Phase 2" anchors the boot file to a planning timeline.

**Fix:** Strip the narrative blocks; the `->group('phase-3')` chains on individual tests are self-documenting. If a development loop reference is needed, move it to a `README.md` under a CI / Dev section.

### INFO-05: `IcsPdfAdapter` has an empty `accounts->resolve($ownIban)` side-effect call whose purpose is documented but the discarded return value silently swallows resolution errors

**File:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php:130-134`
**Issue:** `$accounts->resolve($ownIban);` is called solely for its UnknownAccount-branching side effect (per the comment). The return value is discarded. If the resolver throws (e.g. database connection drop), the exception propagates correctly, but the deliberate discard means anyone reading this in isolation might assume the call is dead code.

**Fix:** Annotate the discard with a `// phpcs:ignore` or rebind to a named variable to make the deliberate ignore visible:

```php
// Single resolve() call so the wizard's UnknownAccount branching
// path still fires for ICS imports (matches every other adapter's
// shape). The return value is intentionally ignored: the
// importer's ParseStage re-resolves per row.
$accounts->resolve($ownIban);  // intentional fire-and-forget
```

### INFO-06: `tests/Feature/AnonymisedFixtureSweepTest.php` runs `pdftotext` via direct `Process` invocation rather than the project's `PdfTextExtractor`

**File:** `tests/Feature/AnonymisedFixtureSweepTest.php:79-87`
**Issue:** The sweep test re-implements the pdftotext invocation (flags + path) inline, bypassing `PdfTextExtractor`. If the flag set on `PdfTextExtractor::PDFTOTEXT_OPTIONS` changes, this sweep test silently keeps the old flag set, weakening the "the extractor's flag set produces zero PII" contract.

**Fix:** Inject `PdfTextExtractor` and call `extract()` directly:

```php
it('the tiny synthetic ICS PDF, after pdftotext extraction, contains zero PII-shaped strings', function () use ($fixtureTinyPdf): void {
    $extractor = new PdfTextExtractor;
    $extracted = $extractor->extract($fixtureTinyPdf);

    expect(preg_match_all('/[0-9]{12,}/', $extracted))->toBe(0, '…');
    // … rest …
})->group('phase-3')->group('integration');
```

---

_Reviewed: 2026-05-15T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
