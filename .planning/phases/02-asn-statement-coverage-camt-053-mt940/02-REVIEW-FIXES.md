---
phase: 02-asn-statement-coverage-camt-053-mt940
fixed_at: 2026-05-13T19:00:00Z
review_path: .planning/phases/02-asn-statement-coverage-camt-053-mt940/02-REVIEW.md
findings_in_scope: 23
fixed: 21
deferred: 2
status: all_addressed
---

# Phase 2: Code Review Fix Report

**Fixed at:** 2026-05-13T19:00:00Z
**Source review:** `.planning/phases/02-asn-statement-coverage-camt-053-mt940/02-REVIEW.md`

**Summary:**

- Findings in scope: 23 (6 Critical, 11 Warning, 6 Info)
- Fixed: 21 (6 Critical, 11 Warning, 4 Info)
- Deferred with rationale: 2 Info

All quality gates green at the end of the fix pass:

- `vendor/bin/pest --group=phase-2 --bail` — 2 skipped, 162 passed (6,061 assertions); baseline was 147 passed
- `vendor/bin/pest` (full) — 3 skipped, 387 passed (12,808 assertions); baseline was 372 passed
- `vendor/bin/phpstan analyse --memory-limit=2G` — `[OK] No errors` (level 10 strict)
- `vendor/bin/pint --test` — `passed`

## Fixed Issues

### CR-001: CAMT.053 wall-clock fallback when BookgDt and ValDt are both absent

**Commit:** `234a3cc`
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php`, `Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php`
**Applied fix:** A CAMT entry without either booking or value date is now rejected with `InvalidAmountException` instead of falling through to `new DateTimeImmutable`. The class PHPDoc records the new constraint; a fixture-driven unit test pins the rejection.

### CR-002: AsnMt940Adapter entry_count miscount across statement boundaries

**Commit:** `396d80f`
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php`, `Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php`
**Applied fix:** `entryCount` is now gated by `firstStatementFrozen` so it pins to statement #1, matching the statement metadata snapshot. A new test asserts `entryCount === 2` on a 2-statement fixture whose first statement carries 2 entries.

### CR-003: MT940 Tag61 year-boundary entry-date bug

**Commit:** `383853c`
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag61Parser.php`, `Modules/Ingestion/tests/Unit/AsnMt940Tag61ParserTest.php`
**Applied fix:** When the entry month is later than the value month, the entry year rolls back by one (SWIFT convention). Combined with WR-009 below, the two-digit year is resolved via the SWIFT sliding-window rule (`closest year within +/-50 of now`). New tests pin both the year-rollover and same-year cases.

### CR-004: AsnMt940Adapter yields rows with `ownIban = ''`

**Commit:** `396d80f`
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php`, `Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php`
**Applied fix:** A `:61:` tag observed before `:25:` now throws `InvalidAmountException` with a clear message. New test pins the failure mode against a synthetic body where `:25:` is omitted.

### CR-005: ApplyEnrichments TOCTOU overwrite of stronger stored ref

**Commit:** `09132f6`
**Files modified:** `Modules/Import/Public/Services/SourceRefRanker.php` (new), `Modules/Import/Public/Actions/ApplyEnrichments.php`, `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php`, `Modules/Import/tests/Unit/ApplyEnrichmentsTest.php`, `Modules/Import/tests/Unit/FingerprintStageClassifyTest.php`
**Applied fix:** Source-format rank lives in a shared `SourceRefRanker` service so both call sites agree. Inside the locked transaction `ApplyEnrichments` re-reads the stored `source_ref` + `source_format` and re-ranks; if the stored ref now outranks (or ties) the incoming ref the enrichment is skipped as a no-op with a debug log entry. Two new tests pin the rank-no-op (weaker incoming) and same-rank-different-value cases.

### CR-006: Service-locator in v3 rederive migration

**Commit:** `6403281`
**Files modified:** `Modules/Ledger/Internal/Services/FingerprintRederiveService.php` (new), `Modules/Ledger/Internal/Services/FingerprintRederiveOutcome.php` (new), `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php`, `Modules/Ledger/Database/Migrations/2026_05_13_010001_rederive_fingerprints_to_v3.php`, `Modules/Ledger/Providers/LedgerServiceProvider.php`
**Applied fix:** The collision-detection + write loop now lives in `FingerprintRederiveService` with constructor-injected `FingerprintComposer` + `DatabaseManager`. The artisan command became a thin presenter over the service. The migration still resolves the service from the container at the migration boundary (anonymous migrations cannot receive constructor injection by Laravel design); the single `app(...)` call is documented in the migration body as the standing Laravel-migration exception.

### WR-001: GSD "Plan 02-01" references in two test files

**Commit:** `6403281` (folded into the rederive-service commit because it touched the same test file)
**Files modified:** `Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php`, `Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php`
**Applied fix:** Re-worded both comments to cite `tests/fixtures/` paths rather than planning-artifact identifiers.

### WR-002: MT940 lexer line-cap not bounded at read time

**Commit:** `0f82c27`
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Lexer.php`, `Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php`
**Applied fix:** Switched from `fgets` to `stream_get_line($h, MAX_BUFFER_BYTES + 1, "\n")` and added an explicit length check that rejects any single line exceeding the cap. The existing single-tag-buffer test was renamed; a new test pins the multi-continuation-line case so both growth paths are covered.

### WR-003: CAMT `extras.createdOn` timezone drift

**Commit:** `b1a9b8e`
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php`
**Applied fix:** The bank-provided creation timestamp is now formatted as UTC `Y-m-d\TH:i:s\Z` so DST shifts and host-timezone differences cannot change `extras.createdOn` between re-imports of the same statement.

### WR-004: Dead `isExistingFingerprint` deprecated shim

**Commit:** `09132f6` (folded into the CR-005 commit since both touched FingerprintStage)
**Files modified:** `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php`, `Modules/Import/tests/Unit/FingerprintStageClassifyTest.php`
**Applied fix:** Deleted the deprecated `isExistingFingerprint()` method and its compat test (no live callers remain; `ImportPipeline` calls `classify()` directly).

### WR-005: Loose cross-format-fingerprint match floor

**Commit:** `b1a9b8e`
**Files modified:** `Modules/Ingestion/tests/Unit/AsnCamt053CrossFormatFingerprintTest.php`
**Applied fix:** Tightened the match threshold from `> 50%` to `>= 95%` so any regression that mis-normalises counterparty names or shifts booking precision fails loud.

### WR-006: AsnMt940Adapter defaults `currency = 'EUR'`

**Commit:** `396d80f`
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php`, `Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php`
**Applied fix:** A `:61:` tag observed before any `:60F:`/`:60M:` opening-balance tag has set a currency now throws `InvalidAmountException`. New test pins the failure mode.

### WR-007: Schema/DB facade usage in four new phase-2 migrations

**Commit:** `aaaeffc`
**Files modified:** `Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php`, `2026_05_13_010003_add_enriched_count_to_import_runs.php`, `2026_05_13_010004_replace_transactions_fingerprint_unique_index.php`, `2026_05_13_010005_create_statement_summaries_table.php`
**Applied fix:** Each migration exposes a private helper (`schema()` or `resolveConnection()`) that resolves the Schema Builder / Connection from the `DatabaseManager` via a single container access at the migration boundary. The body of `up()`/`down()` no longer references `Schema::` or `DB::` directly. The container access is documented in each helper's comment as the standing Laravel-migration exception to the DI-only rule. CLAUDE.md was not updated (the user owns that file; the per-migration comments document the exception locally).

### WR-008: PreviewCache missing-vs-malformed conflation

**Commit:** `c1c2d34`
**Files modified:** `Modules/Import/Internal/Pipeline/PreviewCache.php`, `Modules/Import/Public/Exceptions/PreviewCacheCorruptedException.php` (new), `Modules/Import/tests/Unit/PreviewCacheTest.php` (new)
**Applied fix:** New `PreviewCacheCorruptedException` is thrown when the cache key is present but its payload is the wrong type or fails JSON decode. Missing keys (TTL eviction) still return null. New unit tests pin all three paths (null on never-set, exception on non-string, exception on non-JSON).

### WR-009: Hardcoded `20xx` century in MT940 Tag61

**Commit:** `383853c` (folded into the CR-003 commit since both touched the same parser)
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag61Parser.php`, `Modules/Ingestion/tests/Unit/AsnMt940Tag61ParserTest.php`
**Applied fix:** New `resolveSwiftYear()` helper applies the SWIFT sliding-window rule. The parser docstring records the new behaviour.

### WR-010: CAMT remittance falls back to deprecated `getMessage`

**Commit:** `fb2cfd7`
**Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php`, `Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php`
**Applied fix:** Dropped the `getMessage()` fallback. A TxDtls carrying only structured remittance now yields a null description. New test pins the structured-only case.

### WR-011: No test for TOCTOU race condition

**Commit:** `09132f6` (folded into CR-005 — the new tests in `ApplyEnrichmentsTest` cover the race-coverage gap WR-011 flagged).
**Files modified:** `Modules/Import/tests/Unit/ApplyEnrichmentsTest.php`
**Applied fix:** The "rank no-op" test simulates the preview-then-parallel-stronger-ref-lands-then-confirm sequence and asserts the stored ref is preserved.

### IN-001: `auth()->logout()` inside a skipped test body

**Commit:** `5cf7f98`
**Files modified:** `Modules/Ledger/tests/Feature/DashboardTest.php`
**Applied fix:** Dropped the body so the literal `auth()` call no longer sits in tracked source; the skip reason still cites the Fortify default-route layer.

### IN-003: PreviewWizardEnrichedStateTest synthetic seed

**Commit:** `5cf7f98`
**Files modified:** `Modules/Import/tests/Feature/PreviewWizardEnrichedStateTest.php`
**Applied fix:** Extracted the in-test source_ref nullification into a named helper (`clearSourceRefOnSeededCsvRows`) with a PHPDoc that explains why the synthetic mutation is necessary (the CSV format never produces a NULL Volgnummer in practice).

### IN-004: Mis-described sniff-rejection test

**Commit:** `396d80f` (folded into the MT940 adapter integrity commit since the same test file received new tests)
**Files modified:** `Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php`
**Applied fix:** Renamed the test to describe the actual rule that fires ("rejects a CSV body in a .sta file at the signature stage").

### IN-005: Style nit on underscore-delimited integer literals

**Disposition:** No code change. The review itself says "no fix needed" — the `100_000`/`16_384` style is both readable and supported by PHP 8.5 / strict_types. Recording here for completeness.

## Deferred Findings (with rationale)

### IN-002: `Event::fake` / `Event::assertDispatched` (facades) in test code

**Disposition:** Deferred. The review explicitly says "No required action. The pattern is widely accepted in Pest/Laravel test suites and the rule technically constrains production code." Converting the four test cases to a Mockery / contract-based fake is non-trivial, would re-write a stable test surface, and trades a single legible idiom (`Event::fake`) for a custom test double that no contributor will recognise on sight. The current `AssignCategoryTest` continues to use `Illuminate\Support\Facades\Event` exclusively in the test layer; production code remains facade-free. Will reconsider if the team adopts a project-wide no-facades-in-tests policy.

### IN-006: 404 vs 403 for cross-user import access

**Disposition:** Deferred. The review classifies this as out-of-phase-2 scope and recommends a dedicated security pass. The current behaviour throws `ModelNotFoundException` (404-style), which is functionally secure (the user cannot reach another user's import run); the upgrade to a 403 with an explicit exception type belongs in a future security-pass review where the auth surface can be looked at end-to-end.

## Pre-existing tech debt surfaced during this pass (not in review)

The fixture documentation files under `tests/fixtures/` contain extensive GSD references (`Phase 2`, `D-21`, `D-25`, `D-28`, `Plan 02-01`, `Plan 02-04`, `02-RESEARCH`, etc.). These were already on disk before this fix pass and are not flagged by the review. The user's `feedback_codebase_gsd_agnostic` memory says fixture files may not carry these references. The cleanup is mechanical but extensive (5 fixture README/MD files); recording here so a follow-up pass can address it without re-discovering the issue:

- `tests/fixtures/asn-mt940-sample-1.md` — references "Phase 2 (D-25)", "Plan 02-04", "Phase-1"
- `tests/fixtures/asn-camt053-sample-1.md` — references "02-RESEARCH"
- `tests/fixtures/asn-cross-format/README.md` — references "D-21", "D-28"

No source code or test code under `Modules/` or `tests/Unit|Feature|Contracts/` references any planning artifact after this fix pass.

## Test gate status

```
$ vendor/bin/pest --group=phase-2 --bail
Tests:    2 skipped, 162 passed (6,061 assertions)

$ vendor/bin/pest (full)
Tests:    3 skipped, 387 passed (12,808 assertions)

$ vendor/bin/phpstan analyse --memory-limit=2G
 [OK] No errors

$ vendor/bin/pint --test
{"tool":"pint","result":"passed"}
```

Baselines at the start of the fix pass:

- `--group=phase-2`: 2 skipped, 147 passed
- full: 3 skipped, 372 passed

New tests added: 15 (across CR-001, CR-002, CR-003, CR-004, CR-005, WR-002, WR-006, WR-010, and PreviewCache).

---

_Fixed: 2026-05-13T19:00:00Z_
_Reviewer source: `02-REVIEW.md`_
