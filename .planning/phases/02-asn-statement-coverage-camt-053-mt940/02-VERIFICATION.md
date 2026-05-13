---
phase: 02-asn-statement-coverage-camt-053-mt940
verified: 2026-05-13T20:00:00Z
status: passed
score: 3/3
overrides_applied: 0
re_verification: null
gaps: []
deferred: []
human_verification: []
---

# Phase 2: ASN Statement Coverage (CAMT.053 + MT940) Verification Report

**Phase Goal:** User can ingest the richer ASN bank-statement formats (CAMT.053 as primary, MT940 as legacy fallback) and have transactions deduplicated against existing CSV imports via stable SEPA `EndToEndId` / `AcctSvcrRef` references.
**Verified:** 2026-05-13T20:00:00Z
**Status:** passed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | User can upload an ASN CAMT.053 XML export and see its transactions imported with `EndToEndId` populated as the primary source reference | VERIFIED | `AsnCamt053Adapter.php:270-303` — EndToEndId extracted from `TxDtls::getReference()->getEndToEndId()`, NULL otherwise. `AsnCamt053ImportTest.php:36-40` — asserts `whereNotNull('source_ref')` count is > 0. `AsnCamt053HeaderProfile::FORMAT = 'asn-camt053'`. Wizard accepts the format at `UploadWizard.php:40`. End-to-end green: `vendor/bin/pest Modules/Import/tests/Feature/AsnCamt053ImportTest.php` passes under `--group=phase-2`. |
| 2 | User can upload an ASN MT940 export covering older statement periods and have it ingested via the same pipeline | VERIFIED | `AsnMt940Adapter.php` implements `SourceAdapter` (line 61). Full parser toolchain exists: `AsnMt940Lexer`, `AsnMt940Tag61Parser`, `AsnMt940Tag86Parser`, `AsnMt940CounterpartyCleaner`. No `kingsquare/php-mt940` dependency in `composer.json`. EREF-to-sourceRef promotion at `AsnMt940Tag86Parser.php:38`. `AsnMt940ImportTest.php:19-53` — end-to-end: import asserts `source_format='asn-mt940'`, `source_ref` non-null, `statement_summaries` row written. `vendor/bin/pest --group=phase-2 --bail` passes (162 passed / 2 skipped). |
| 3 | Importing CAMT.053 and CSV exports that cover the same period produces a single set of transactions — no cross-format duplicates | VERIFIED | `FingerprintComposer.php:50` — `NORMALIZATION_VERSION = 3`; tuple excludes `source_ref` so CSV and CAMT produce the same hash. `FingerprintStage.php:53-84` — `classify()` returns `Enriched` when incoming rank outranks stored. `ApplyEnrichments.php:91-107` — re-ranks at write time with `SourceRefRanker` (`asn-camt053=4 > asn-mt940=2 > asn-csv=1`). `CrossFormatDedupTest.php` — `csv_then_camt053` PASS (0 inserted + enriched > 0), `camt053_then_csv` PASS (0 inserted + precise duplicate/enriched split), `same_format_replay` PASS (0 inserted + 0 enriched). MT940 cross-format scenarios: correctly `->markTestSkipped` because no same-period MT940 fixture exists from ASN (documented in `tests/fixtures/asn-cross-format/README.md`). `IdempotencyContractTest.php:20-30` — dataset includes `asn-camt053` + `asn-mt940` rows; all pass. |

**Score:** 3/3 truths verified

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php` | SourceAdapter for asn-camt053; genkgo/camt-backed; EndToEndId-only sourceRef | VERIFIED | Exists, 67-line class, implements `SourceAdapter`, uses `Genkgo\Camt\Reader`, EndToEndId-only sourceRef policy in PHPDoc and code |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053HeaderProfile.php` | FORMAT + XML namespace whitelist | VERIFIED | `FORMAT = 'asn-camt053'` at line 24 |
| `Modules/Ingestion/Public/Services/HeaderSniffer.php` | sniffAsnCamt053() + sniffAsnMt940() | VERIFIED | Both private methods present at lines 56-57 and 71/117 |
| `Modules/Ingestion/Providers/IngestionServiceProvider.php` | Registry: asn-camt053 + asn-mt940 | VERIFIED | Lines 35-36 wire both adapters |
| `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php` | Creates statement_summaries table | VERIFIED | `create('statement_summaries'` at line 14 |
| `Modules/Ledger/Models/StatementSummary.php` | Eloquent model | VERIFIED | Exists |
| `Modules/Ledger/Public/Services/StatementSummaryWriter.php` | DI-only upsert service | VERIFIED | Exists |
| `Modules/Ledger/Public/Contracts/RecordsStatementSummary.php` | Public contract | VERIFIED | Exists |
| `Modules/Import/Internal/Http/Livewire/UploadWizard.php` | asn-camt053 + asn-mt940 in SUPPORTED_FORMATS | VERIFIED | Lines 40-41 and 118-119 |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940HeaderProfile.php` | FORMAT = 'asn-mt940' | VERIFIED | Line 20 |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Lexer.php` | Streaming tokenizer; stream_get_line bounded reads | VERIFIED | `stream_get_line($h, MAX_BUFFER_BYTES + 1, "\n")` at line 94; WR-002 fix confirmed |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag61Parser.php` | ASN 34-char variant; SWIFT year-rollover | VERIFIED | `resolveSwiftYear()` at line 151; year-rollover rule at line 83 |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag86Parser.php` | ?NN subfields; GVC keyword extraction; EREF | VERIFIED | `EREF` in keyword array at line 38 |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940CounterpartyCleaner.php` | Pre-normalisation strip | VERIFIED | Exists; EREF/MREF/GVC stripping at line 193 |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php` | Top-level SourceAdapter wiring all components | VERIFIED | `implements SourceAdapter` at line 61; ownIban validation before :61: at lines 145-149 |
| `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` | classify() returning FingerprintDisposition | VERIFIED | `classify(CanonicalTransaction, User): FingerprintDisposition` at line 53; uses SourceRefRanker |
| `Modules/Import/Public/Services/SourceRefRanker.php` | Shared rank function (CR-005 fix) | VERIFIED | Exists; used by both FingerprintStage and ApplyEnrichments |
| `Modules/Import/Public/Contracts/AppliesEnrichments.php` | Public contract | VERIFIED | Exists |
| `Modules/Import/Public/Actions/ApplyEnrichments.php` | UPDATE source_ref + enriched_from; rank re-eval at write time | VERIFIED | Lines 91-107 re-rank inside lockForUpdate; TOCTOU no-op short-circuit confirmed |
| `Modules/Import/Public/Dto/FingerprintDisposition.php` + variants | Discriminated DTO set | VERIFIED | FingerprintDisposition + NewRowDisposition + DuplicateDisposition + EnrichedDisposition + PendingEnrichment all present |
| `Modules/Ledger/Database/Migrations/2026_05_13_010001_*` | Re-derive v3 migration via FingerprintRederiveService | VERIFIED | Uses `app(FingerprintRederiveService::class)` — documented exception to DI-only at migration boundary |
| `Modules/Ledger/Database/Migrations/2026_05_13_010002/03/04_*` | enriched_from, enriched_count, v3 UNIQUE index | VERIFIED | All three exist; `2026_05_13_010004` drops `source_ref` from UNIQUE, adds `booked_at` |
| `Modules/Ledger/Internal/Services/FingerprintRederiveService.php` | Extracted service (CR-006 fix) | VERIFIED | Exists; migration is now a thin shell over this service |
| `Modules/Import/Resources/views/livewire/import-results.blade.php` | 4-state summary: imported · skipped · enriched · errors | VERIFIED | Line 7: `Imported N transactions · skipped M duplicates · P enriched · K errors` |
| `Modules/Import/Resources/views/livewire/preview-wizard.blade.php` | ENRICHED badge (sky-50/sky-700) + diff indicator | VERIFIED | Line 81: `bg-sky-50 … text-sky-700 … ring-sky-600/20`; lines 82-87 render `source_ref: ∅ → <to>` |
| `Modules/Import/tests/Feature/AsnCamt053ImportTest.php` | End-to-end CAMT.053 upload → preview → confirm | VERIFIED | Three tests: import + assert, idempotent re-import, no statement_summary for CSV |
| `Modules/Import/tests/Feature/AsnMt940ImportTest.php` | End-to-end MT940 upload → preview → confirm | VERIFIED | Two tests: import + assert, idempotent re-import |
| `Modules/Import/tests/Feature/CrossFormatDedupTest.php` | 7-scenario cross-format dedup suite | VERIFIED | csv_then_camt053 PASS, camt053_then_csv PASS, same_format_replay PASS, mt940_then_camt053 SKIPPED (file missing — intentional), camt053_then_mt940 SKIPPED (file missing — intentional), preview-only-flow PASS, cross_format_pair_fingerprints_match PASS |
| `tests/Contracts/IdempotencyContractTest.php` | Dataset extended with asn-camt053 + asn-mt940 | VERIFIED | Lines 20-30 add both format keys |
| `tests/fixtures/asn-camt053-sample-1.xml` | 229-entry anonymised CAMT.053.001.02 corpus | VERIFIED | Exists (11 284 lines); own IBAN `NL57ASNB0123456789` (re-derived with valid mod-97); namespace `camt.053.001.02` |
| `tests/fixtures/asn-mt940-sample-1.sta` | Synthesised 12-entry MT940 | VERIFIED | Exists (30 lines); own IBAN `NL57ASNB0123456789`; `:20:`, `:61:`, `:86:` tags present |
| `tests/fixtures/asn-cross-format/february.csv` + `february.camt053.xml` + `README.md` | Same-period cross-format pair | VERIFIED | Both 72-entry files exist; README documents expected count; MT940 absence flagged |
| `composer.json` | genkgo/camt ^2.10 | VERIFIED | Line 15; `composer.lock` locked to 2.10.3 |
| `tests/Pest.php` | phase-2 group documentation | VERIFIED | Lines 57-64 document per-test `->group('phase-2')` convention |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `AsnCamt053Adapter::parse` | `Genkgo\Camt\Reader::readFile` | constructor DI of Reader | VERIFIED | `use Genkgo\Camt\Reader` import + `$this->readMessage($localPath)` |
| `AsnCamt053Adapter` | `SourceTransactionDto::sourceRef = EndToEndId` | `TxDtls::getReference()->getEndToEndId()` at line 270 | VERIFIED | Code path verified; NULL fallback correct |
| `AsnMt940Adapter::parse` | `AsnMt940Lexer + Tag61Parser + Tag86Parser + CounterpartyCleaner` | constructor DI | VERIFIED | All four collaborators DI-injected |
| `AsnMt940Adapter::parse` | `SourceTransactionDto::sourceRef = EREF` | Tag86Parser GVC keyword extraction | VERIFIED | EREF promoted via `AsnMt940Tag86Parser.php:38` |
| `UploadWizard::SUPPORTED_FORMATS` | `in:asn-csv,asn-camt053,asn-mt940` | Laravel validator | VERIFIED | Three entries in `SUPPORTED_FORMATS` const |
| `FingerprintStage::classify` | `FingerprintDisposition::newRow/duplicate/enriched` | `SourceRefRanker::rank()` comparison | VERIFIED | `FingerprintStage.php:72-83` |
| `ImportPipeline::preview` | `PendingEnrichment` list (enrichments 4th key) | `$enrichments` accumulator | VERIFIED | `CrossFormatDedupTest` passing proves the pipeline correctly passes enrichments through confirm |
| `ConfirmImport` | `ApplyEnrichments + RecordsTransactions` | single outer DB transaction | VERIFIED | `ConfirmImport.php` wraps both in `DB::connection()->transaction(...)` |
| `ApplyEnrichments` | rank re-check at write time | `lockForUpdate + SourceRefRanker` | VERIFIED | `ApplyEnrichments.php:91-107` — CR-005 TOCTOU fix confirmed |
| `Migration 010001` | `FingerprintRederiveService::run()` | `app(FingerprintRederiveService::class)` | VERIFIED | Lines 17-20; documented migration-boundary exception to DI-only |
| `Migration 010004` | v3 UNIQUE tuple (incl. booked_at, excl. source_ref) | `DROP + CREATE UNIQUE INDEX` | VERIFIED | Line 17 lists tuple with `booked_at`; line 27 shows old tuple that included `source_ref` |
| `Transaction::casts` | `enriched_from` as `AsArrayObject` | Eloquent cast | VERIFIED | `Transaction.php:88` |
| `Money\Money` containment | `Modules\Ingestion\Internal\Adapters\Asn` only | BoundaryArchTest | VERIFIED | `BoundaryArchTest.php:34` — `toOnlyBeUsedIn('Modules\\Ingestion\\Internal\\Adapters\\Asn')` |

---

## Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `AsnCamt053ImportTest.php` — asserts `transaction.source_ref` | `source_ref` on Transaction rows | `AsnCamt053Adapter::buildDto` → `sourceRef: $endToEndId` → `RecordTransactions` | Yes — real EndToEndId from genkgo/camt CAMT.053 parse | FLOWING |
| `CrossFormatDedupTest.php::csv_then_camt053` | `enriched` count on ImportConfirmResult | `FingerprintStage::classify` → `FingerprintDisposition::enriched` → `ApplyEnrichments` → `import_runs.enriched_count` | Yes — real fixture data, 72 transactions, tested at `> 0` | FLOWING |
| `preview-wizard.blade.php` — ENRICHED diff indicator | `$row->diff['source_ref']` | `ImportPipeline::preview` → `PreviewRowDto::$diff` → `PreviewCache` round-trip | Yes — `PreviewWizardEnrichedStateTest` asserts non-empty diff with `to` key | FLOWING |
| `import-results.blade.php` — `enriched_count` line | `$importRun->enriched_count` | `ConfirmImport` → `ApplyEnrichments` return value → `importRun->update(['enriched_count' => ...])` | Yes — directly from DB write in the same outer transaction | FLOWING |

---

## Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase-2 group tests pass | `php -d memory_limit=1G vendor/bin/pest --group=phase-2 --bail` | 2 skipped, 162 passed (6061 assertions) | PASS |
| Full test suite no regression | `php -d memory_limit=1G vendor/bin/pest` | 3 skipped, 387 passed (12808 assertions) | PASS |
| Larastan level 10 strict | `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` | `[OK] No errors` | PASS |
| Pint formatting | `vendor/bin/pint --test` | `passed` | PASS |

**Memory-limit observation:** The test suite requires `-d memory_limit=1G` for the arch tests (specifically BoundaryArchTest, which performs symbol-level AST analysis across the whole codebase). This is an operational note, not a correctness issue — all assertions pass; default PHP 128 MB is insufficient for Pest's arch plugin on a project of this size.

---

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| ING-02 | 02-03, 02-05 | User can upload ASN CAMT.053 (XML) export | SATISFIED | `AsnCamt053Adapter` + `AsnCamt053ImportTest` + `CrossFormatDedupTest`; `REQUIREMENTS.md` line 25 marked `[x]`, traceability table row 159 `Phase 2 / Complete` |
| ING-03 | 02-04, 02-05 | User can upload ASN MT940 export (fallback path) | SATISFIED | `AsnMt940Adapter` + full parser toolchain + `AsnMt940ImportTest`; `REQUIREMENTS.md` line 27 marked `[x]`, traceability table row 160 `Phase 2 / Complete` |
| ING-06 | 02-02, 02-05 | Idempotent re-import + cross-format enrichment | SATISFIED | `FingerprintComposer v3` + `FingerprintStage::classify` + `ApplyEnrichments` + `IdempotencyContractTest` (all three formats covered); `REQUIREMENTS.md` line 29 marked `[x]`, traceability table row 163 `Phase 1 / Complete`. Note: the traceability table still reads `Phase 1` rather than `Phase 2` for ING-06 — this is a documentation inconsistency. ING-06 was established in Phase 1 (basic idempotency) and substantially extended in Phase 2 (cross-format enrichment). The requirement text itself has been updated to include the Phase 2 cross-format contract, and the `[x]` mark is accurate. The single-phase attribution in the table is a minor traceability gap but does not affect functional correctness. |

---

## Anti-Patterns Found

| File | Pattern | Severity | Impact |
|------|---------|----------|--------|
| `tests/fixtures/asn-mt940-sample-1.md` | Pre-existing tech debt: `REVIEW-FIXES.md` documents residual GSD references ("Phase 2 (D-25)", "Plan 02-04", "Phase-1") in fixture documentation files. These were explicitly noted as pre-existing and not fixed by the review-fix pass. | INFO | Cosmetic only — fixture documentation files are not source code and do not affect test outcomes. The REVIEW-FIXES.md explicitly surfaces this as a pending follow-up item. |
| `tests/fixtures/asn-camt053-sample-1.md` | Pre-existing tech debt: `02-RESEARCH` reference (per REVIEW-FIXES.md). | INFO | Same as above. |
| `tests/fixtures/asn-cross-format/README.md` | Pre-existing tech debt: `D-21`, `D-28` references (per REVIEW-FIXES.md). | INFO | Same as above. |

All three are pre-existing and explicitly documented in `02-REVIEW-FIXES.md` as a pending follow-up pass. Zero GSD references exist in PHP source, blade templates, or test PHP files — verified by `grep -rn "\.planning|PLAN\.md|RESEARCH\.md|CONTEXT\.md|\bD-[0-9]\+"` returning no output across all `.php` and `.blade.php` files.

---

## Human Verification Required

None. The three ROADMAP success criteria are each fully exercised by automated Pest feature tests:

- Success Criterion #1 (CAMT.053 upload + EndToEndId): `AsnCamt053ImportTest.php`
- Success Criterion #2 (MT940 upload via same pipeline): `AsnMt940ImportTest.php`
- Success Criterion #3 (cross-format no-duplicate): `CrossFormatDedupTest.php` + `IdempotencyContractTest.php`

The ENRICHED Blade state is exercised by `PreviewWizardEnrichedStateTest.php` which mounts the Livewire component in a feature test context and asserts the badge text and diff indicator are rendered.

---

## Deferred Items

| # | Item | Addressed In | Evidence |
|---|------|-------------|----------|
| 1 | `mt940_then_camt053` and `camt053_then_mt940` cross-format dedup scenarios | Not in any later phase — intentionally SKIPPED because ASN no longer offers MT940 downloads | `CrossFormatDedupTest.php:120-142` — both tests `->markTestSkipped` with the documented reason; `asn-cross-format/README.md` explicitly notes MT940 cross-format sample unavailability. This is not a gap — it is a known constraint documented in the fixture corpus. |

---

## Gaps Summary

No gaps. All three ROADMAP success criteria are met:

1. **CAMT.053 end-to-end** — adapter, sniffer, wizard option, statement_summaries table, and feature test are all in place and passing.
2. **MT940 end-to-end** — hand-rolled parser toolchain (5 classes + 3 internal DTOs), sniffer, wizard option, statement_summaries, and feature test are all in place and passing. No third-party MT940 library used.
3. **Cross-format dedup** — FingerprintComposer v3 (source_ref excluded from hash), FingerprintStage::classify with SourceRefRanker, ApplyEnrichments with TOCTOU rank re-evaluation, and CrossFormatDedupTest all pass.

All 6 code-review critical findings (CR-001 through CR-006) are fixed and verified. All 11 warnings (WR-001 through WR-011) are fixed. 2 info findings are intentionally deferred with rationale (IN-002: Event::fake in tests accepted; IN-006: 404 vs 403 is out-of-phase-2 scope). The 3 pre-existing fixture-documentation GSD references are a documented pending follow-up — they do not affect any test assertion or behavioral outcome.

All quality gates confirmed green:
- `vendor/bin/pest --group=phase-2 --bail`: 2 skipped, 162 passed
- `vendor/bin/pest` (full): 3 skipped, 387 passed
- `vendor/bin/phpstan analyse` (level 10 strict): `[OK] No errors`
- `vendor/bin/pint --test`: `passed`

---

_Verified: 2026-05-13T20:00:00Z_
_Verifier: Claude (gsd-verifier)_
