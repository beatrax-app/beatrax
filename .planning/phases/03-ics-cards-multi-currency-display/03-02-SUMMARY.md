---
phase: 03-ics-cards-multi-currency-display
plan: 02
subsystem: ingestion
tags: [ics-pdf, pdftotext, brick-math, fx-rate, multi-currency, statement-summaries, pest, phase-3-group]

# Dependency graph
requires:
  - phase: 03-ics-cards-multi-currency-display
    provides: spatie/pdf-to-text 1.55.0 + poppler 26.04.0 + redacted ICS text fixture + tiny synthetic PDF + 55 Red scaffolds across nine files
  - phase: 02-asn-second-source-and-statement-summaries
    provides: StatementSummaryData DTO on SourceAdapter contract; stateful-adapter pattern (parse() + statementMetadata()); FingerprintComposer v3; SourceAdapterRegistry; HeaderSniffer match-arm shape
  - phase: 01-foundation-asn-csv-vertical-slice
    provides: dual-amount + fx_rate_used schema (settled_amount_minor / settled_currency / fx_rate_used columns; MC-01); BIGINT minor-units convention (FND-04); idempotent imports + sha256 UNIQUE on import_runs (ING-06)
provides:
  - SourceTransactionDto extended with nullable settledAmountMinor / settledCurrency / fxRateUsed (D-42 contract)
  - NormalizeStage D-42 substitution + D-39 BigDecimal fx_rate_used derivation at scale 8 / HALF_UP
  - PdfTextExtractor service wrapping spatie/pdf-to-text with the locked option set [-layout -enc UTF-8 -eol unix -nopgbrk] + typed PdfExtractionFailed exception
  - IcsAmountParser + IcsDateParser nl_NL helpers (comma decimal / period thousands / EUR-prefix / ISO-suffix / Dutch month names; zero global locale mutation)
  - IcsPdfAdapter + IcsPdfHeaderProfile + IcsPdfExtractionMap (empirical anchor tokens + six revolving-credit summary tokens + per-page noise patterns + Wisselkoers FX-line folding)
  - SourceAdapterRegistry binding 'ics-pdf' => IcsPdfAdapter; HeaderSniffer arm dispatching on IcsPdfHeaderProfile::FORMAT
  - transactions.raw_payload JSON column + CanonicalTransaction rawPayload field + Transaction model 'array' cast
  - seedFixtureUserAndAccount() now seeds both an ASN account and a synthetic ICS account (kind='ics_card', iban='ICS-CARD')
  - IdempotencyContractTest extended with 'ics-pdf' dataset row exercising real pdftotext binary path
  - Integration smoke test (Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php) tagged ->group('integration')
  - 7 of 9 IcsPdfImportTest scaffolds Green at the wire-level
affects: [03-03, 03-04, 03-05, 03-06, 03-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Subclassable concrete service for unit-test substitution — PdfTextExtractor declared non-final so tests can extend with an anonymous class returning fixture text verbatim. The class still owns the exec() boundary; production wiring uses the concrete implementation via constructor DI."
    - "Singleton-forget cascade for container-swapped extractor: $this->app->forgetInstance(SourceAdapterRegistry::class) + IcsPdfAdapter + ImportPipeline + ParseStage before re-resolving RunsImports so the substituted extractor flows through transitive constructor wiring."
    - "BigDecimal-derived fx_rate_used: NormalizeStage uses brick/math BigDecimal::of((string) $settled)->dividedBy(BigDecimal::of((string) $native), 8, RoundingMode::HALF_UP) so the decimal(18,8) column is exact. Float arithmetic forbidden on the money path."
    - "Per-transaction rawPayload JSON column on transactions — archive-only metadata mirroring statement_summaries.extras shape. Phase 3 never reads the column at the query layer; the future multi-card / FX-markup phases consume it without re-import."
    - "Card-number scrubbing literal '<discarded per security policy>' replaces canonical masked-card placeholders (****-****-****-XXXX) AND any 12+ contiguous digit run at the adapter boundary before raw_payload is assembled — defence-in-depth against the rare future statement variant that prints a full PAN."
    - "Synthetic own-IBAN literal 'ICS-CARD' for credit-card accounts (AccountResolver already user-scopes lookups via where('user_id', $user->id), so a single instance-wide literal is unambiguous and avoids cross-module reach for user_id introspection)."
    - "Two-line FX-row folding: an immediately-following 'Wisselkoers <CCY> <rate>' line is folded into the previous transaction's block so a foreign-currency charge yields exactly one canonical DTO; the displayed rate sits in rawPayload['fxRateDisplayed'] for future markup-detection phases."

key-files:
  created:
    - Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php
    - Modules/Ingestion/Internal/Adapters/Ics/IcsPdfHeaderProfile.php
    - Modules/Ingestion/Internal/Adapters/Ics/IcsPdfExtractionMap.php
    - Modules/Ingestion/Internal/Adapters/Ics/PdfTextExtractor.php
    - Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php
    - Modules/Ingestion/Internal/Adapters/Ics/IcsDateParser.php
    - Modules/Ingestion/Public/Exceptions/PdfExtractionFailed.php
    - Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php
    - Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php
    - tests/.pest/snapshots/Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest/it_parses_the_redacted__txt_fixture_into_the_expected_SourceTransactionDto_stream.snap
  modified:
    - Modules/Ingestion/Public/Dto/SourceTransactionDto.php
    - Modules/Ingestion/Public/Services/HeaderSniffer.php
    - Modules/Ingestion/Providers/IngestionServiceProvider.php
    - Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php
    - Modules/Import/Public/Actions/RunImport.php
    - Modules/Ledger/Public/Dto/CanonicalTransaction.php
    - Modules/Ledger/Models/Transaction.php
    - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Ics/PdfTextExtractorTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsDateParserTest.php
    - Modules/Import/tests/Unit/NormalizeStageTest.php
    - Modules/Import/tests/Feature/IcsPdfImportTest.php
    - Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf
    - scripts/generate_tiny_ics_pdf.php
    - tests/Contracts/IdempotencyContractTest.php
    - tests/TestCase.php
    - tests/Pest.php

key-decisions:
  - "spatie/pdf-to-text option array shape is the flag-string list form ['layout', 'enc UTF-8', 'eol unix', 'nopgbrk'] — confirmed against vendor/spatie/pdf-to-text/src/Pdf.php parseOptions(). The parser auto-prefixes each entry with '-' and splits on the first space."
  - "D-35 FX-row shape (b) two-line block implemented as: detect a merchant row by leading transactiedatum + trailing Af|Bij marker; fold an immediately-following 'Wisselkoers ' line into the same block; one canonical DTO per logical transaction. sourceRowIndex stays monotonic because the index advances per logical DTO, not per source line."
  - "IcsPdfExtractionMap constants finalised against the empirical fixture: TRANSACTIONS_TABLE_ANCHOR='transactie boeking'; PAGE_NOISE_PATTERNS = seven line-anchored regexes (cardholder banner / card watermark / statement-summary header / Apple Pay banner / depositogarantiestelsel / Het minimaal te betalen / Uw betalingen aan); FX_LINE_ANCHOR='Wisselkoers '; six SUMMARY_TOKENS in revolving-credit nomenclature."
  - "FX assertion path: Path (b) — container-overridden PdfTextExtractor returning the redacted ics-sample-1.txt fixture verbatim, with SourceAdapterRegistry / IcsPdfAdapter / ImportPipeline / ParseStage singletons all forgotten before re-resolving RunsImports. The redacted fixture carries three real FX rows (Augment Code USD/EUR, Audible UK GBP/EUR, Vitrus USD/EUR); no second tiny synthetic FX PDF was needed."
  - "Tier-2 byte-mutation path: flip the xref free-list head from '0000000000 65535 f' to '0000000001 65535 f' inside a tempnam'd .pdf copy. Pdftotext does not dereference the free-list head so the extracted text stays byte-identical; SHA-256 still differs because the literal '0' → '1' is a structural byte change. Fingerprint-v3 dedup catches the second import."
  - "Path A chosen for the IdempotencyContractTest seeding helper: seedFixtureUserAndAccount() seeds BOTH an ASN account and a synthetic ICS account (kind='ics_card', iban='ICS-CARD'). The synthetic IBAN is instance-wide (not per-user) because the EloquentAccountResolver scopes lookups by (iban, user_id) already; per-user IBAN was unnecessary complexity."
  - "Two PreviewWizard naming scaffolds in IcsPdfImportTest stay Red ('prompts the user to name the ICS Account on the first ICS upload' / 'skips the name-your-account step on subsequent ICS uploads') — plan 03-03 owns those wizard-UI changes per the original plan scope discipline."
  - "Integration smoke test confirmed Green on the executor's macOS Herd host (poppler 26.04.0 at /opt/homebrew/bin/pdftotext) — both cases pass: extraction round-trips the SYNTHETIC + KAARTHOUDER literals AND the -layout column structure is preserved (the trailing 'Af' direction marker appears in the extracted text)."

patterns-established:
  - "Per-module Integration directory binding in tests/Pest.php — modules can ship Integration/ tests that exec external binaries; the binding inherits the module's TestCase + a booted Laravel app. Tagged ->group('integration') so CI hosts without the external binary --exclude-group=integration cleanly."
  - "BigDecimal-on-the-money-path convention for derived ratio columns (decimal(18,8)) — the NormalizeStage FX rate derivation establishes the shape; future percent-based or basis-point columns should follow the same scale-rounding pattern."
  - "transactions.raw_payload column convention: ['format' => '<source-format-key>', 'extractedText' => '<per-row source data>'] envelope. Discriminator-first keys mirror how the v3 fingerprint composer tags rows by source_format and how statement_summaries.extras tags by provenance."

requirements-completed:
  - ING-04
  - LED-03

# Metrics
duration: 27min
completed: 2026-05-15
---

# Phase 3 Plan 02: ICS PDF Wire-Level Slice Summary

**ICS PDF ingestion end-to-end functional: PdfTextExtractor → IcsPdfAdapter (parse + statementMetadata) → NormalizeStage (D-42 substitution + D-39 BigDecimal-derived fx_rate_used) → transactions persist with both legs + statement_summaries with masked-card extras. 37 driven Green / 2 Red intentionally for plan 03-03.**

## Performance

- **Duration:** ~27 min
- **Started:** 2026-05-15T16:35:54Z
- **Completed:** 2026-05-15T17:02:42Z (approximately, post-final verification)
- **Tasks:** 6 atomic task commits
- **Files created:** 10 (adapters/parsers/typed-exception/migration/snapshot)
- **Files modified:** 17 (DTOs, model, sniffer, registry, normalisation stage, tests, fixtures, Pest bootstrap)

## Accomplishments

1. **SourceTransactionDto carries the D-42 nullable settled pair + fxRateUsed.** Three new readonly fields appended in this exact order so every Phase 1/2 ASN adapter call site stays binary-compatible (defaults = null).
2. **NormalizeStage substitutes settled = native when omitted (D-42) AND derives fx_rate_used via brick/math BigDecimal at scale 8 / HALF_UP when both legs are present and currencies differ (D-39).** Four new assertions Green; NoFloatMoneyArchTest stays Green.
3. **PdfTextExtractor service wraps the spatie/pdf-to-text exec() boundary** with the locked flag set `[-layout -enc UTF-8 -eol unix -nopgbrk]`. Typed `PdfExtractionFailed` exception lives under `Public/Exceptions/`. The 10 MiB MAX_BYTES guard matches the wizard's `max:10240` upload rule (kilobytes).
4. **IcsAmountParser + IcsDateParser** handle the empirical Dutch shapes (comma decimal / period thousands / EUR prefix / ISO suffix; numeric `dd-mm-yyyy` / abbreviated `j M Y` / full-month `j MMMM Y`). Zero global locale mutation.
5. **IcsPdfAdapter** lands as the project's first text-extraction-driven adapter. Statefully exposes `statementMetadata()` after `parse()` exhausts the generator. Card-number text is scrubbed at the adapter boundary via the literal `'<discarded per security policy>'` (both `****-****-****-XXXX` placeholders and any 12+ contiguous digit run). Empirical D-35 shape (b) two-line FX-row folding implemented.
6. **HeaderSniffer arm + SourceAdapterRegistry binding** for `'ics-pdf'` wired through with the two locked user-facing error strings (`.pdf` extension miss / `%PDF-` magic-byte miss).
7. **transactions.raw_payload JSON column added** (Rule 3 - blocking fix: the success criteria required raw_payload persistence but the schema lacked the column). CanonicalTransaction gains nullable rawPayload; Transaction model casts as 'array'; NormalizeStage threads the field through.
8. **IdempotencyContractTest extended** with the `'ics-pdf'` dataset row pointing at `ics-sample-tiny.pdf`. Both parametric assertions pass for ICS PDF — same-file re-import yields zero new rows (Tier 1 SHA dedup + Tier 2 fingerprint-v3 dedup).
9. **seedFixtureUserAndAccount() helper extended** to seed both an ASN account AND an ICS account (kind='ics_card', iban='ICS-CARD'). All Phase 1/2 test files inherit the change transparently.
10. **Integration smoke test** at `Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php` exec's the real `pdftotext` binary against the tiny PDF; both cases Green on this host (poppler 26.04.0). Tagged `->group('integration')` for CI exclusion.
11. **Tiny synthetic PDF regenerated** (`scripts/generate_tiny_ics_pdf.php`) to embed a transaction row matching the empirical Mijn ICS layout shape (`12 apr. 12 apr. SYNTHETIC ICS TINY 1,00 Af`) plus a statement header date (`15 april 2026`) so the production parser yields exactly one canonical DTO from it. Output is 981 bytes — under the 10 KB budget.
12. **Per-module Integration test directory registered** in `tests/Pest.php` so the smoke-test directory inherits the module's TestCase + booted Laravel app.

## Final Test Posture

| Verification | Result |
|---|---|
| `vendor/bin/pest --group=phase-3 --filter='IcsPdf\|PdfTextExtractor\|IcsAmountParser\|IcsDateParser\|NormalizeStage\|Idempotency'` | **35 Green / 2 Red** (the two PreviewWizard naming scaffolds for plan 03-03) |
| `vendor/bin/pest tests/Contracts/IdempotencyContractTest.php` | **8 Green** (all four datasets × 2 assertions) |
| `vendor/bin/pest --filter='AsnCsv\|AsnCamt053\|AsnMt940\|UploadWizard'` | **97 Green** (Phase 1/2 regression suite untouched) |
| `vendor/bin/pest --filter='NoFloatMoney\|BoundaryArch\|UserIdColumnArch'` | **10 Green** (architecture invariants intact) |
| `vendor/bin/phpstan analyse Modules/Ingestion Modules/Import` | **0 errors** (Larastan level 10 strict + extension + strict-rules clean) |
| `vendor/bin/pest Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php` | **2 Green** (real pdftotext exec'd) |
| Pint on every new + modified file | clean |

## Task Commits

Each task was committed atomically:

1. **Task 1: SourceTransactionDto + NormalizeStage extension** — `ae64557` (feat)
2. **Task 2: PdfTextExtractor + PdfExtractionFailed** — `018d2b4` (feat)
3. **Task 3: IcsAmountParser + IcsDateParser** — `1d8ce22` (feat)
4. **Task 4: IcsPdfAdapter family + HeaderSniffer arm + SourceAdapterRegistry binding** — `8267286` (feat, amended to include the PdfTextExtractor `final` → `class` change required for the test-double substitution pattern)
5. **Task 5: raw_payload column + CanonicalTransaction/Transaction extension + IdempotencyContractTest + IcsPdfImportTest + seedFixtureUserAndAccount ICS arm + tiny PDF regen + RunImport extension map** — `09f9611` (feat)
6. **Task 6: Integration smoke test + per-module Integration directory binding in tests/Pest.php** — `73312b6` (test)

**Plan metadata commit:** to follow (this SUMMARY.md + STATE.md + ROADMAP.md + REQUIREMENTS.md).

## Files Created/Modified

**Created (10):**

- `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php` — Stateful adapter; pipeline strip-noise → anchor-find → row-iterate → fold-FX-line → yield-DTO; statementMetadata() exposes opening/closing/period totals + masked-card extras
- `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfHeaderProfile.php` — FORMAT='ics-pdf', MIME_MAGIC='%PDF-', MAX_BYTES=10 MiB, SOURCE_ENCODING='UTF-8'
- `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfExtractionMap.php` — TRANSACTIONS_TABLE_ANCHOR + 7 PAGE_NOISE_PATTERNS + FX_LINE_ANCHOR + 6 SUMMARY_TOKENS (empirical, revolving-credit nomenclature)
- `Modules/Ingestion/Internal/Adapters/Ics/PdfTextExtractor.php` — Concrete (non-final) exec()-boundary wrapper around spatie/pdf-to-text; injectable binary path; 10 MiB cap; typed exception
- `Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php` — nl_NL amount parser; integer-only minor units
- `Modules/Ingestion/Internal/Adapters/Ics/IcsDateParser.php` — nl_NL date parser; startOfDay normalisation for fingerprint v3 day-precision
- `Modules/Ingestion/Public/Exceptions/PdfExtractionFailed.php` — Typed RuntimeException subclass; user-facing-safe message
- `Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php` — Real pdftotext exec; `->group('integration')`
- `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` — Nullable JSON column on transactions
- `tests/.pest/snapshots/.../it_parses_the_redacted__txt_fixture....snap` — 38-row canonical DTO stream snapshot

**Modified (17):**

- `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` — D-42 nullable settled pair + fxRateUsed
- `Modules/Ingestion/Public/Services/HeaderSniffer.php` — sniffIcsPdf() arm + locked error strings
- `Modules/Ingestion/Providers/IngestionServiceProvider.php` — registry map entry `'ics-pdf' => IcsPdfAdapter`
- `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` — D-42 substitution + D-39 BigDecimal derivation
- `Modules/Import/Public/Actions/RunImport.php` — file-extension map `'ics-pdf' => 'pdf'`
- `Modules/Ledger/Public/Dto/CanonicalTransaction.php` — nullable rawPayload field + toAttributes() JSON serialisation
- `Modules/Ledger/Models/Transaction.php` — raw_payload fillable + 'array' cast
- Five test files — IcsPdfAdapterTest, PdfTextExtractorTest, IcsAmountParserTest, IcsDateParserTest, IcsPdfImportTest, NormalizeStageTest (six total — driven Green)
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` — regenerated (981 bytes; new layout shape)
- `scripts/generate_tiny_ics_pdf.php` — updated content lines to match empirical layout
- `tests/Contracts/IdempotencyContractTest.php` — `'ics-pdf'` dataset row
- `tests/TestCase.php` — seedFixtureUserAndAccount() seeds the ICS account too
- `tests/Pest.php` — per-module Integration directory binding alongside Feature/Unit

## Decisions Made

See **key-decisions** in the frontmatter for the full list. Highlights:

- **`spatie/pdf-to-text` option-array shape** — flag-string list (`['layout', 'enc UTF-8', 'eol unix', 'nopgbrk']`) form, confirmed against vendor code.
- **Empirical D-35 shape (b)** — two-line block; folded into one canonical DTO via line look-ahead in `iterateTransactionBlocks()`.
- **Path (b) for the FX-row wire-level assertion** — container-overridden extractor returning the redacted .txt fixture verbatim (the redacted fixture already carries three real FX rows; no second tiny synthetic FX PDF needed).
- **Tier-2 byte-mutation approach** — flip the xref free-list head sentinel (`0000000000` → `0000000001`); pdftotext does not dereference it so extracted text stays byte-identical while SHA-256 differs.
- **Path A** chosen for seeding (extend `seedFixtureUserAndAccount()` to seed both accounts) — cleanest API; the modified helper is forward-compatible with the future plan 03-03 wizard-naming step.
- **Synthetic own-IBAN literal** — instance-wide `'ICS-CARD'` rather than per-user `'ICS-CARD-{user-id}'`. AccountResolver scopes lookups by `(iban, user_id)` so per-user uniqueness was redundant complexity that would have required cross-module reach into `EloquentAccountResolver::user()` from the Ingestion module.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `transactions.raw_payload` column was missing from the schema**

- **Found during:** Task 5 (driving the `it('persists rawPayload.format = ics-pdf and a non-empty extractedText per row')` scaffold Green)
- **Issue:** The plan's success criteria #2/#6 and the IcsPdfImportTest scaffold required `transactions.raw_payload` to carry the per-row source data, but the `transactions` table migration did not declare such a column. CanonicalTransaction's `toAttributes()` also did not carry a `raw_payload` field. Without the column, the insertOrIgnore would silently drop the payload and the assertion `expect($payload)->toBeArray()` would fail with `null`.
- **Fix:** Added a new migration `2026_05_15_010001_add_raw_payload_to_transactions.php` declaring `$table->json('raw_payload')->nullable()->after('source_ref')`. Extended CanonicalTransaction with a nullable `array $rawPayload = null` constructor argument and updated `toAttributes()` to JSON-encode it for the column. Extended Transaction model `$fillable` + cast `raw_payload => 'array'`. NormalizeStage threads `source->rawPayload === [] ? null : source->rawPayload` through to CanonicalTransaction (the empty-array fallback maps to null so Phase 1/2 ASN rows stay untouched).
- **Files modified:** `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` (new), `Modules/Ledger/Public/Dto/CanonicalTransaction.php`, `Modules/Ledger/Models/Transaction.php`, `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php`
- **Verification:** `vendor/bin/pest Modules/Import/tests/Feature/IcsPdfImportTest.php --filter='persists rawPayload'` exits zero; `vendor/bin/pest --filter='RecordTransactions|MoneyMinorCast|AsnCsv|AsnCamt053|AsnMt940'` exits zero (Phase 1/2 ledger / record / model regression intact).
- **Committed in:** `09f9611` (Task 5)

**2. [Rule 3 - Blocking] `PdfTextExtractor` declared `final` could not be substituted for unit-test extraction-text fixtures**

- **Found during:** Task 4 (adapter unit tests against the redacted .txt fixture)
- **Issue:** The plan's Task 4 step 6 directs the executor to "test-double `PdfTextExtractor` … via container substitution" so the adapter is unit-testable without ever shelling out. Final classes cannot be extended (and `Mockery::mock()` refuses to mock final classes that need type-hint compatibility with the adapter's `private readonly PdfTextExtractor` constructor argument).
- **Fix:** Dropped the `final` keyword from `PdfTextExtractor` and added a docblock paragraph explaining the rationale (`Subclassable for unit-test substitution`). Production wiring is unchanged — the SourceAdapterRegistry still resolves the concrete class via constructor DI; only the unit-test substitution pattern depends on the relaxed declaration.
- **Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/PdfTextExtractor.php`
- **Verification:** All ten IcsPdfAdapterTest cases Green; all four PdfTextExtractorTest cases Green; Pint clean; PHPStan clean. No production callsite affected.
- **Committed in:** `8267286` (Task 4, amended)

**3. [Rule 3 - Blocking] tiny synthetic PDF transaction row did not match the empirical layout shape**

- **Found during:** Task 5 (IdempotencyContractTest 'ics-pdf' dataset row first-import assertion)
- **Issue:** The committed tiny PDF (built by plan 03-01 against the CONTEXT.md aspirational tokens) embedded one transaction row in the shape `12-04-2026 SYNTHETIC ICS TINY EUR 1,00` — a single date column, no `Af`/`Bij` direction marker, currency code embedded mid-row. This adheres to neither the empirical layout (`23 jan. 24 jan. AUGMENT CODE … 50,00 USD 43,71 Af`) nor any production statement shape. The adapter's `looksLikeTransactionRow()` predicate (`leading transactiedatum + trailing Af|Bij marker`) correctly rejected the row, yielding zero DTOs and failing the IdempotencyContractTest's `expect($first->inserted)->toBeGreaterThan(0)` assertion.
- **Fix:** Regenerated the tiny PDF via `scripts/generate_tiny_ics_pdf.php` with an extended content-line list embedding a transaction row matching the empirical layout shape (`12 apr. 12 apr. SYNTHETIC ICS TINY  1,00  Af`) plus a statement-header date line (`15 april 2026`) so the adapter's year inference resolves cleanly. Six summary tokens preserved (Bestedingslimiet + Minimaal te betalen bedrag added to the original four). Output is 981 bytes (under the 10 KB budget).
- **Files modified:** `scripts/generate_tiny_ics_pdf.php`, `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf`
- **Verification:** `pdftotext -layout -enc UTF-8 -eol unix -nopgbrk` round-trips the SYNTHETIC + KAARTHOUDER literals; `tests/Feature/AnonymisedFixtureSweepTest.php` (all five cases including the integration sweep) stays Green; IdempotencyContractTest now Green for ics-pdf; the integration smoke test passes both cases.
- **Committed in:** `09f9611` (Task 5)

**4. [Rule 1 - Bug] Larastan `nullsafe.neverNull` + `method.notFound` errors on Task 4 introspection probe**

- **Found during:** Task 4 (Larastan analysis on the new IcsPdfAdapter)
- **Issue:** Initial implementation attempted to probe the `AccountResolver` for a `user()` accessor via `method_exists()` + reflection, then derive `'ICS-CARD-{user-id}'` per-user. Three problems: (a) the `AccountResolver` interface declares no `user()` method, so PHPStan flagged the call site; (b) the introspection required cross-module reach into `Modules\Import\Public\Services\EloquentAccountResolver` from the Ingestion module, violating the module-boundary architecture rule; (c) `$statementDate?->year ?? (int) date('Y')` used unnecessary nullsafe on a branch that PHPStan correctly narrowed as non-null.
- **Fix:** Replaced the per-user IBAN derivation with an instance-wide literal `'ICS-CARD'`. The AccountResolver scopes lookups by `(iban, user_id)` already, so per-user uniqueness was redundant. Replaced the `?->` nullsafe with an explicit `if-else` narrowing. Reverted the `EloquentAccountResolver::user()` accessor that briefly landed in support of the introspection probe.
- **Files modified:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`, `Modules/Import/Public/Services/EloquentAccountResolver.php` (revert)
- **Verification:** PHPStan level max + strict-rules clean on Modules/Ingestion and Modules/Import; no facade slippage; no cross-module reach. Updated the seedFixtureUserAndAccount() helper IBAN to `'ICS-CARD'` to match.
- **Committed in:** `8267286` (Task 4, amended) + `09f9611` (Task 5 — seeding helper update)

---

**Total deviations:** 4 auto-fixed (3 blocking, 1 bug fix). All fixes were necessary for the plan's success criteria to hold:
- The raw_payload column was an inferred dependency the plan's success criteria require but the migration suite did not declare. Adding it discharges acceptance gate #6 ("rawPayload.format = ics-pdf and a non-empty extractedText per row").
- The PdfTextExtractor `final` relaxation discharges Task 4's "test-double PdfTextExtractor … via container substitution" directive.
- The tiny PDF regeneration discharges the IdempotencyContractTest's first-import-greater-than-zero parametric.
- The PHPStan probe-removal preserves the project's module-boundary rule + Larastan level 10 strict clean.

**Impact on plan:** All four fixes essential for correctness. No scope creep — every fix was inside the plan's stated wire-level scope (no wizard UI, no dashboard, no chain resolution).

## Known Stubs

None. Every code path landed by this plan is wired end-to-end and exercised by at least one Green test.

## Issues Encountered

- **Test-double substitution interacts with the Laravel container's singleton bindings.** The SourceAdapterRegistry is bound as a singleton at IngestionServiceProvider boot; ImportPipeline and ParseStage are singletons via ImportServiceProvider. Binding a new PdfTextExtractor with `$this->app->instance(...)` does NOT propagate to already-constructed singletons. Workaround: `$this->app->forgetInstance(SourceAdapterRegistry::class)` + `forgetInstance(IcsPdfAdapter::class)` + `forgetInstance(ImportPipeline::class)` + `forgetInstance(ParseStage::class)` before re-resolving `RunsImports::class` so the doubled extractor flows through the transitive constructor wiring. Documented in IcsPdfImportTest's FX assertion case so a future contributor doesn't trip over the same gotcha.
- **SQLite normalises stored `decimal(18,8)` values by trimming trailing zeros.** The BigDecimal-derived `'0.87420000'` round-trips through the column as `'0.8742'` (or rather the SQLite-native floating-point shape). Test assertion uses `(string) $augment->fx_rate_used === '0.8742'`. For production Postgres (a v2 target), the column would round-trip verbatim; the test would need a tolerance-based comparison if we cared about exact decimal-zero preservation across both engines.
- **HeaderSniffer + redacted-text fixture mismatch in unit tests.** The redacted .txt fixture cannot pass the production magic-byte sniff (`%PDF-` check). The unit tests therefore pass the tiny synthetic .pdf as the input path so HeaderSniffer accepts it, then the substituted extractor returns the .txt content verbatim. This is the cleanest split — HeaderSniffer is exercised in production code, and the redacted .txt drives the parser logic.

## User Setup Required

None — poppler 26.04.0 was already installed by plan 03-01 (`brew install poppler` + README documentation).

## Next Phase Readiness

**Plan 03-03 (Wave 2 wizard UI slice) is ready to start** with a complete behavioural target:

- IcsPdfAdapter, PdfTextExtractor, IcsAmountParser, IcsDateParser, IcsPdfExtractionMap all wired into the SourceAdapterRegistry and exercised by 35 Green tests.
- The two PreviewWizard-naming scaffolds in `Modules/Import/tests/Feature/IcsPdfImportTest.php` (`prompts the user to name the ICS Account on the first ICS upload` / `skips the name-your-account step on subsequent ICS uploads`) await Plan 03-03's wizard refactor.
- UploadWizard's `mimes` validator currently does NOT accept `.pdf` and the format dropdown does NOT include `ics-pdf` — plan 03-03 ships both changes plus the two-step issuer→format picker (D-33). The HeaderSniffer arm and SourceAdapterRegistry binding are already in place; plan 03-03 only needs to extend the UI surface.
- The `transactions.raw_payload` column landed cleanly; any future adapter (PayPal Phase 4, Google Play Phase 7) can adopt the `['format' => '<source-key>', 'extractedText' => '<source-row-block>']` envelope without further schema work.

**No CONTEXT.md addendum required for plan 03-03.** The plan's scope (wizard UI refactor + naming flow + sniff-error rendering) is already aligned with the existing CONTEXT.md D-33, D-38, D-54 entries.

## Self-Check: PASSED

Verified all committed artefacts exist on disk and all commit hashes resolve in the git tree:

- ae64557 (Task 1): FOUND
- 018d2b4 (Task 2): FOUND
- 1d8ce22 (Task 3): FOUND
- 8267286 (Task 4 amended): FOUND
- 09f9611 (Task 5): FOUND
- 73312b6 (Task 6): FOUND
- `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`: FOUND
- `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfHeaderProfile.php`: FOUND
- `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfExtractionMap.php`: FOUND
- `Modules/Ingestion/Internal/Adapters/Ics/PdfTextExtractor.php`: FOUND
- `Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php`: FOUND
- `Modules/Ingestion/Internal/Adapters/Ics/IcsDateParser.php`: FOUND
- `Modules/Ingestion/Public/Exceptions/PdfExtractionFailed.php`: FOUND
- `Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php`: FOUND
- `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php`: FOUND
- `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest/it_parses_the_redacted__txt_fixture_into_the_expected_SourceTransactionDto_stream.snap`: FOUND
- `vendor/bin/phpstan analyse Modules/Ingestion Modules/Import` exits zero
- `vendor/bin/pest --group=phase-3 --filter='IcsPdf|PdfTextExtractor|IcsAmountParser|IcsDateParser|NormalizeStage|Idempotency'` exits non-zero ONLY for the two intentionally-Red PreviewWizard naming scaffolds (35 Green / 2 Red)
- `vendor/bin/pest --filter='AsnCsv|AsnCamt053|AsnMt940|UploadWizard'` exits zero (97 Phase 1/2 tests Green)

---
*Phase: 03-ics-cards-multi-currency-display*
*Plan: 02*
*Completed: 2026-05-15*
