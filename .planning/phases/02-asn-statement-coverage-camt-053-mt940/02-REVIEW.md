---
phase: 02-asn-statement-coverage-camt-053-mt940
reviewed: 2026-05-13T12:00:00Z
depth: standard
files_reviewed: 93
files_reviewed_list:
  - Modules/Categorization/tests/Feature/AssignCategoryTest.php
  - Modules/Categorization/tests/Feature/TriagePageTest.php
  - Modules/Categorization/tests/Unit/UncategorizedTriageQueryTest.php
  - Modules/Import/Internal/Http/Livewire/UploadWizard.php
  - Modules/Import/Internal/Pipeline/ImportPipeline.php
  - Modules/Import/Internal/Pipeline/PreviewCache.php
  - Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php
  - Modules/Import/Providers/ImportServiceProvider.php
  - Modules/Import/Public/Actions/ApplyEnrichments.php
  - Modules/Import/Public/Actions/ConfirmImport.php
  - Modules/Import/Public/Actions/RunImport.php
  - Modules/Import/Public/Contracts/AppliesEnrichments.php
  - Modules/Import/Public/Dto/DuplicateDisposition.php
  - Modules/Import/Public/Dto/EnrichedDisposition.php
  - Modules/Import/Public/Dto/FingerprintDisposition.php
  - Modules/Import/Public/Dto/ImportConfirmResult.php
  - Modules/Import/Public/Dto/ImportPreviewResult.php
  - Modules/Import/Public/Dto/NewRowDisposition.php
  - Modules/Import/Public/Dto/PendingEnrichment.php
  - Modules/Import/Public/Dto/PreviewRowDto.php
  - Modules/Import/Resources/views/livewire/import-results.blade.php
  - Modules/Import/Resources/views/livewire/preview-wizard.blade.php
  - Modules/Import/Resources/views/livewire/upload-wizard.blade.php
  - Modules/Import/tests/Feature/AsnCamt053ImportTest.php
  - Modules/Import/tests/Feature/AsnMt940ImportTest.php
  - Modules/Import/tests/Feature/CrossFormatDedupTest.php
  - Modules/Import/tests/Feature/PreviewWizardEnrichedStateTest.php
  - Modules/Import/tests/Feature/PreviewWizardTest.php
  - Modules/Import/tests/Unit/ApplyEnrichmentsTest.php
  - Modules/Import/tests/Unit/FingerprintDispositionTest.php
  - Modules/Import/tests/Unit/FingerprintStageClassifyTest.php
  - Modules/Import/tests/Unit/NormalizeStageTest.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053HeaderProfile.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940CounterpartyCleaner.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940HeaderProfile.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Lexer.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag61Parser.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag86Parser.php
  - Modules/Ingestion/Internal/Adapters/Asn/Dto/Mt940BalanceTuple.php
  - Modules/Ingestion/Internal/Adapters/Asn/Dto/Mt940Narrative.php
  - Modules/Ingestion/Internal/Adapters/Asn/Dto/Mt940StatementLine.php
  - Modules/Ingestion/Providers/IngestionServiceProvider.php
  - Modules/Ingestion/Public/Contracts/SourceAdapter.php
  - Modules/Ingestion/Public/Dto/SourceTransactionDto.php
  - Modules/Ingestion/Public/Services/HeaderSniffer.php
  - Modules/Ingestion/tests/Feature/HeaderSnifferTest.php
  - Modules/Ingestion/tests/Unit/AsnCamt053AdapterBatchEntryTest.php
  - Modules/Ingestion/tests/Unit/AsnCamt053AdapterNamespaceTest.php
  - Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php
  - Modules/Ingestion/tests/Unit/AsnCamt053CrossFormatFingerprintTest.php
  - Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php
  - Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php
  - Modules/Ingestion/tests/Unit/AsnMt940CounterpartyCleanerTest.php
  - Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php
  - Modules/Ingestion/tests/Unit/AsnMt940Tag61ParserTest.php
  - Modules/Ingestion/tests/Unit/AsnMt940Tag86ParserTest.php
  - Modules/Ledger/Database/Migrations/2026_05_13_010001_rederive_fingerprints_to_v3.php
  - Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php
  - Modules/Ledger/Database/Migrations/2026_05_13_010003_add_enriched_count_to_import_runs.php
  - Modules/Ledger/Database/Migrations/2026_05_13_010004_replace_transactions_fingerprint_unique_index.php
  - Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php
  - Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php
  - Modules/Ledger/Models/ImportRun.php
  - Modules/Ledger/Models/StatementSummary.php
  - Modules/Ledger/Models/Transaction.php
  - Modules/Ledger/Providers/LedgerServiceProvider.php
  - Modules/Ledger/Public/Contracts/RecordsStatementSummary.php
  - Modules/Ledger/Public/Dto/StatementSummaryData.php
  - Modules/Ledger/Public/Services/FingerprintComposer.php
  - Modules/Ledger/Public/Services/StatementSummaryWriter.php
  - Modules/Ledger/tests/Feature/DashboardTest.php
  - Modules/Ledger/tests/Feature/MoneyMinorCastTest.php
  - Modules/Ledger/tests/Feature/Phase2SchemaShapeTest.php
  - Modules/Ledger/tests/Feature/RecordTransactionsTest.php
  - Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php
  - Modules/Ledger/tests/Feature/RederiveFingerprintsHttpUnreachableArchTest.php
  - Modules/Ledger/tests/Feature/TransactionListTest.php
  - Modules/Ledger/tests/Feature/UpdateTransactionCategoryTest.php
  - Modules/Ledger/tests/TestCase.php
  - Modules/Ledger/tests/Unit/AccountModelTest.php
  - Modules/Ledger/tests/Unit/FingerprintComposerV3Test.php
  - Modules/Ledger/tests/Unit/StatementSummaryWriterTest.php
  - Modules/Ledger/tests/Unit/ThisPeriodAtAGlanceQueryTest.php
  - Modules/Ledger/tests/Unit/TransactionTypeTest.php
  - composer.json
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/IdempotencyContractTest.php
  - tests/Pest.php
  - tests/TestCase.php
findings:
  critical: 6
  warning: 11
  info: 6
  total: 23
status: issues_found
---

# Phase 2: Code Review Report

**Reviewed:** 2026-05-13T12:00:00Z
**Depth:** standard
**Files Reviewed:** 93
**Status:** issues_found

## Summary

Phase 2 lands a substantial body of well-designed code: CAMT.053 and MT940 ingestion, a v3 cross-format fingerprint, an enrichment pipeline with provenance tracking, a re-derivation command, and a statement-summary writer. The architectural boundaries are largely respected (Money\Money stays inside the ASN adapter folder; arch tests are reinforced), the XXE hardening is properly scoped (file-scheme entities only, regardless of libxml defaults), and the cross-format dedup contract is enforced by both unit + integration tests.

That said, the implementation has six correctness blockers that must be resolved before this phase verifies:

1. The CAMT.053 adapter silently substitutes the **current wall-clock time** when a `<Ntry>` is missing both `<BookgDt>` and `<ValDt>` — fingerprinting becomes non-deterministic.
2. The MT940 adapter keeps incrementing `entryCount` across statement boundaries while the metadata snapshot describes only the first statement — the persisted entry_count is wrong for multi-statement files.
3. The MT940 Tag61 parser uses the *value-date year* for the entry-date — across a year boundary the entry date is silently dated to the wrong year.
4. The MT940 adapter accepts a non-empty `:25:` payload without IBAN validation; when `:25:` is missing or misordered, rows are yielded with `ownIban = ''` and the resolver is never called.
5. `ApplyEnrichments` never re-validates the rank decision at write time, so a parallel preview-then-confirm sequence can overwrite a *stronger* stored source_ref with a *weaker* one once the cached enrichment is replayed.
6. The v3 rederive migration uses `Container::getInstance()->make()` (service locator) instead of the DI-only pattern the project mandates.

In addition to the blockers, eleven warnings cover contract drift, narrative-history-style comments, schema portability, dead code in `FingerprintStage::isExistingFingerprint`, and a handful of cross-format edge cases where MT940 normalisation is not mirrored by CAMT/CSV. The info-level findings cover style nits and tests that are slightly more coupled to internals than they need to be.

## Critical Issues

### CR-001: CAMT.053 adapter silently substitutes `new DateTimeImmutable` (wall clock) when both BookgDt and ValDt are absent

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php:268`
**Issue:** `$booking = $entry->getBookingDate() ?? $entry->getValueDate() ?? new DateTimeImmutable;`. When a `<Ntry>` lacks both `<BookgDt>` and `<ValDt>`, the adapter falls through to the wall clock. The resulting `bookedAt`/`postedAt` are non-deterministic — re-importing the same CAMT file moments later produces a *different* v3 fingerprint and the row will not deduplicate. This breaks the load-bearing idempotency claim of Phase 2.

A non-dated entry is a malformed CAMT.053 — every conformant ASN export has at least one of the two — but a defensive parser must not silently emit a row whose fingerprint depends on the wall clock. The fail-loud path is the recorder catching it later; the fail-quietly path corrupts the ledger.

**Fix:** Treat the absence of both date elements as a per-row parse error so the pipeline surfaces it as an ERROR preview row:

```php
$booking = $entry->getBookingDate() ?? $entry->getValueDate();
if ($booking === null) {
    throw new InvalidAmountException(sprintf(
        'CAMT entry at row %d is missing both BookgDt and ValDt; cannot fingerprint.',
        $rowIndex,
    ));
}
```

### CR-002: AsnMt940Adapter mis-counts `entryCount` on multi-statement files

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php:88-184`
**Issue:** `firstStatementFrozen` (line 89) is set after the FIRST statement's `:62F:`/`:62M:` (line 133) so opening/closing balance and statement number stay pinned to statement #1. However, `entryCount` (line 143) keeps incrementing across **every** subsequent `:61:` regardless of `firstStatementFrozen`. The metadata DTO then describes statement #1's balances + period but reports the *summed* entry count across all statements. The persisted `statement_summaries.entry_count` is therefore wrong on any multi-statement file.

The multi-statement test (`AsnMt940AdapterTest.php:159-175`) only asserts that the `multiStatement` extras flag is set; it does not pin `entryCount`, so the bug is currently invisible to CI.

**Fix:** Either reset/branch the counter when crossing a statement boundary, or attribute the counter to whatever statement the metadata pins to. Simplest correct path:

```php
case '61':
    // ... yield logic ...
    if (! $firstStatementFrozen) {
        $entryCount++;
    }
    break;
```

And add a test that imports a 2-statement fixture and asserts `entryCount === <statement_1_entries>`, not the total.

### CR-003: MT940 Tag61 parser uses the value-date year for the entry-date — wrong across year boundaries

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag61Parser.php:64-66`
**Issue:** The MT940 `:61:` tag encodes the value date as YYMMDD and the entry date as MMDD (no year). The current implementation hardcodes the value-date year for the entry date:

```php
$entryDate = ($m['entry_month'] !== '' && $m['entry_day'] !== '')
    ? $this->parseDate('20'.$m['year'].'-'.$m['entry_month'].'-'.$m['entry_day'])
    : null;
```

When a transaction with value date `260102` (2026-01-02) carries an entry date `1231` (Dec 31), this parses the entry as `2026-12-31` instead of the correct `2025-12-31`. Same bug applies in reverse around mid-year statements that contain pre-roll entries.

`entryDate` is persisted under `rawPayload['mt940']['entryDate']` and is meant to be audit-stable. A silently wrong year in audit metadata is a data-integrity defect.

**Fix:** Apply the standard SWIFT year-rollover rule (if entry month > value month, entry year = value year - 1; else equal):

```php
$entryDate = null;
if ($m['entry_month'] !== '' && $m['entry_day'] !== '') {
    $valueYear = 2000 + (int) $m['year'];
    $valueMonth = (int) $m['month'];
    $entryMonth = (int) $m['entry_month'];
    $entryYear = $entryMonth > $valueMonth ? $valueYear - 1 : $valueYear;
    $entryDate = $this->parseDate(sprintf('%04d-%02d-%02d', $entryYear, $entryMonth, (int) $m['entry_day']));
}
```

### CR-004: AsnMt940Adapter yields rows with `ownIban = ''` when `:25:` is missing or absent

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php:139,149,158`
**Issue:** The `:25:` IBAN tag is mandatory in MT940, but the adapter's structure does not enforce its presence. If the file is malformed (missing `:25:` entirely, or arrives after the first `:61:`), the adapter still yields DTOs with `ownIban: $ownIban ?? ''`. An empty IBAN reaches the Import pipeline, where `AccountResolver::resolve('')` returns Unknown — the wizard then prompts the user to name an account named "" which is a UX cliff. Worse, if the user has already named an account with `iban=''` (e.g. an off-bank "cash" account on a future schema), every malformed MT940 row would silently bind to it.

Source-format integrity should be enforced at the adapter boundary, not delegated to the wizard.

**Fix:** Require `$ownIban` to be set before yielding any DTO; throw `InvalidAmountException` at first `:61:` if `:25:` has not been seen.

```php
case '61':
    if ($ownIban === null) {
        throw new InvalidAmountException('MT940 :61: encountered before :25:; file is malformed.');
    }
    // ... existing yield logic ...
```

Add a feature test for a synthetic file where `:25:` is omitted entirely.

### CR-005: ApplyEnrichments does not re-validate rank ordering at write time — TOCTOU overwrite possible

**File:** `Modules/Import/Public/Actions/ApplyEnrichments.php:54-93`
**Issue:** The action loads `row->source_ref`, compares it for *exact equality* against the incoming new ref, and otherwise overwrites. The cached `PendingEnrichment` was computed at preview time against the row's source_ref as it was *then*. Between preview and confirm, a concurrent import (or a re-preview) can have already enriched the same row with a stronger reference. ApplyEnrichments will then **overwrite the now-stronger stored ref with the cached, weaker, incoming ref** — because the only short-circuit is `=== $enrichment->newSourceRef`, not rank-based.

Cross-format dedup ordering is contract rule #8 (CAMT > MT940 > CSV) and must be preserved at write time too. SQLite WAL gives single-writer semantics for concurrent processes, but the user can preview the same file twice in two browser tabs and confirm the older one second.

**Fix:** Snapshot the source-format rank on the `PendingEnrichment` (write `fromSourceFormat` + `fromRank` at preview time) and re-evaluate in `applyOne`:

```php
$existingRef = is_string($row->source_ref) ? $row->source_ref : null;
$existingFormat = is_string($row->source_format) ? $row->source_format : '';
$incomingRank = $this->rank($enrichment->sourceFormat, $enrichment->newSourceRef);
$existingRank = $this->rank($existingFormat, $existingRef);

if ($incomingRank <= $existingRank) {
    // Stored ref is already at least as strong — no-op.
    return false;
}
```

The `rank()` helper should be extracted from `FingerprintStage::refRank` to a single shared utility so both call sites agree.

### CR-006: Rederive migration uses service-locator (`Container::getInstance()->make(Kernel::class)`)

**File:** `Modules/Ledger/Database/Migrations/2026_05_13_010001_rederive_fingerprints_to_v3.php:13-15`
**Issue:** Project rule #1 forbids `Container::getInstance()`, `resolve()`, `app()`, and other global service-locator calls in new code. The migration calls `Container::getInstance()->make(Kernel::class)` to invoke the artisan command. Even granting that migrations are an awkward place for constructor DI, Laravel provides `Artisan::call(...)` (a facade — also banned) AND `Illuminate\Contracts\Console\Kernel` via the migration's `$this->getConnection()` chain — but the simplest DI-friendly path is to inline the rederive logic into a dedicated migration step or instantiate the command directly with its constructor.

**Fix:** Either (a) inline the algorithm into the migration (one DB connection inject via `Schema::connection()`), or (b) use the `Migrator` callback hook that exposes the kernel via the migration runner's own DI. Concrete pattern that uses constructor DI within the migration scope:

```php
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Internal\Console\RederiveFingerprintsCommand;
use Illuminate\Database\DatabaseManager;

public function up(): void
{
    $composer = new FingerprintComposer();
    $db = app(DatabaseManager::class); // last-resort container access acceptable in a Migration class
    $command = new RederiveFingerprintsCommand($composer, $db);
    $command->setLaravel(app());
    $exitCode = $command->run(/* input */, /* output */);
    // ...
}
```

Or, preferred: extract the collision-detection + write loop into a `FingerprintRederiveService` and call it directly with explicit dependencies. The artisan command then becomes a thin shell over the service, and the migration calls the service with its DI'd dependencies.

## Warnings

### WR-001: Two test files contain `Plan 02-01` GSD references in comments

**File:** `Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php:147`, `Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php:167`
**Issue:** Both files include the literal token `Plan 02-01` in code comments. Rule #2 says no `.planning/`, `PLAN.md`, `RESEARCH.md`, `CONTEXT.md`, or `D-NN` tokens may leak into committed source — Plan refs fall under the same "code stays agnostic from GSD" principle.

**Fix:**
- `RederiveFingerprintsCommandTest.php:147` — replace "The count mirrors the 3-month ASN CAMT corpus committed in Plan 02-01" with "The count mirrors the 3-month ASN CAMT fixture corpus committed under `tests/fixtures/`".
- `AsnCamt053AdapterTest.php:167` — replace "The Plan 02-01 fixture has all BookgDt entries as date-only" with "The committed CAMT fixture has all BookgDt entries as date-only".

### WR-002: MT940 single-tag buffer cap (16 KB) is enforced — but the line cap (100 K lines) leaves a 1.6 GB tag-line attack surface

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Lexer.php:38-40,168-176`
**Issue:** `MAX_BUFFER_BYTES = 16_384` caps a single tag buffer at 16 KB. `MAX_LINE_COUNT = 100_000` caps line count. But the lexer reads via `fgets($handle)` (default buffer), which can return arbitrarily long lines — `fgets` only stops at `\n` or EOF. A crafted file with one 100 MB line containing `:86:` followed by junk would be allowed through the per-line read, then truncated to 16 KB at `checkBufferSize`. The check happens AFTER the buffer is concatenated, so a single very long continuation line could allocate hundreds of MB before the check fires.

The validator at the wizard layer caps the file at 10 MB, so this is bounded in practice — but the lexer should refuse to read a single line longer than its own buffer cap to fail fast and predictably.

**Fix:** Read with a bounded chunk size:

```php
while (($raw = stream_get_line($handle, self::MAX_BUFFER_BYTES + 1, "\n")) !== false) {
    if (strlen($raw) > self::MAX_BUFFER_BYTES) {
        throw new InvalidAmountException(sprintf(
            'MT940 line exceeds buffer cap (%d bytes).',
            self::MAX_BUFFER_BYTES,
        ));
    }
    // ... rest of loop ...
}
```

### WR-003: AsnCamt053Adapter `extras['createdOn']` is non-deterministic across re-imports

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php:152-155`
**Issue:** `$stmt->getCreatedOn()->format('c')` includes the CAMT statement's creation timestamp, which is bank-provided and stable across the same exported file — so this isn't a wall-clock leak. But `format('c')` produces an ISO-8601 string *with timezone offset*; if the bank exports with `+02:00` vs `+01:00` across DST boundaries the same logical statement can produce different `extras` JSON.

This isn't a fingerprint problem (extras is not in the fingerprint) but it does break the `upsert` semantics: a second preview of the same file under a different timezone interpretation would re-write `extras.createdOn` even though the underlying statement is unchanged.

**Fix:** Normalise to UTC before formatting:

```php
'createdOn' => $stmt->getCreatedOn()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
```

### WR-004: FingerprintStage classify→isExistingFingerprint deprecated shim is dead but still tested

**File:** `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php:105-113`, `Modules/Import/tests/Unit/FingerprintStageClassifyTest.php:220-253`
**Issue:** The PHPDoc says `@deprecated Use classify()` and the method now wraps `classify()`. A grep across the repo shows no live caller — `ImportPipeline` calls `classify()` directly. The shim adds a public surface that exists solely to keep one backward-compat test green. The compat note says "retained for one-version transition" — but there is no other version. Carrying dead code post-merge is a maintenance hazard (a Larastan suggestion to "narrow return type" or a Pint formatting pass would touch code that has no consumers).

**Fix:** Delete `isExistingFingerprint()` and the corresponding test case (`FingerprintStageClassifyTest.php:220-253`). If a true compat hook is required for some out-of-tree consumer, document it explicitly.

### WR-005: AsnMt940Adapter does not normalise CAMT counterparty names through `AsnMt940CounterpartyCleaner`-equivalent rules — cross-format dedup brittle on noisy CAMT names

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php:351-362`
**Issue:** MT940 routes counterparty names through `AsnMt940CounterpartyCleaner` (strips GVC prefix, transaction-type code, embedded BIC, SEPA markers) before `FingerprintComposer::normalize`. CAMT.053 names are passed through unchanged. In practice ASN CAMT exports the SEPA-cleaned name in `<Cdtr><Nm>` so this rarely matters — but if any CAMT row carries a name like "STARBUCKS ABNANL2A AMSTERDAM" (BIC inline), the v3 fingerprint will not match the same MT940 row.

The cross-format-fingerprint test (`AsnCamt053CrossFormatFingerprintTest.php:106`) asserts only `matched > 50%` of CAMT rows hit a CSV twin — so a 49% mismatch rate would pass. This is exactly the threshold under which a regression would silently slip.

**Fix:** Either (a) tighten the cross-format threshold to `> 95%` so any regression is caught loud, or (b) run CAMT counterparty names through a CAMT-aware cleaner (BIC stripping is the only obvious case that would actually trigger).

### WR-006: AsnMt940Adapter falls back to currency='EUR' for `:61:` rows when no `:60F:` was seen — silent default for non-EUR exports

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php:139,149,158`
**Issue:** `$currency ?? 'EUR'` defaults to EUR if no opening-balance tag has been parsed. For an ASN export that only carries `:62F:` (closing balance, no opening) of a non-EUR statement, every row would land with `currency = 'EUR'`. This is a silent data-corruption path. The CLAUDE.md explicitly requires multi-currency tracking from v1.

**Fix:** Either extract currency from `:62F:` as a fallback before defaulting, or refuse to yield rows without a parsed currency:

```php
case '61':
    if ($currency === null) {
        throw new InvalidAmountException('MT940 :61: encountered before any balance tag set a currency.');
    }
    // ... yield logic ...
```

### WR-007: Phase-2 migrations all `use Illuminate\Support\Facades\Schema` / `\DB` — facade rule explicitly forbids this

**File:** `Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php:7`, `2026_05_13_010003_add_enriched_count_to_import_runs.php:7`, `2026_05_13_010004_replace_transactions_fingerprint_unique_index.php:6`, `2026_05_13_010005_create_statement_summaries_table.php:7`
**Issue:** Rule #1 ("No facades or globals in new code") applies to all new code. The arch test `tests/Contracts/BoundaryArchTest.php:28-30` even explicitly forbids `Illuminate\Support\Facades` inside `Modules\\`. All four Phase-2 schema migrations import and use `Schema::table()` / `Schema::create()` / `Schema::dropIfExists()` / `DB::statement()`. Pre-existing Phase-1 migrations do the same (so the arch test must be passing some other way), but Phase 2 should not extend that habit.

Looking at the existing arch rule more carefully, it does say `->not->toBeUsedIn('Modules')`, which should catch these. Either the arch test is currently a noop on migrations (Laravel discovers them via path, not via PSR-4 classloader, so the arch plugin may not see them) or the test isn't running them — either way the *intent* of the rule is being bypassed in Phase 2 just as it was in Phase 1.

**Fix:** Replace `Schema::` with the migration's own `$this->getConnection()->getSchemaBuilder()->table(...)` pattern in all four new migrations, or change the rule's wording to explicitly exempt the `Database/Migrations` directory. The honest fix is to exempt migrations (Laravel idioms here are entrenched) and write that exemption into both `CLAUDE.md` and the arch test.

### WR-008: PreviewCache silently fails closed on a corrupt cache entry, then `ConfirmImport` re-throws PreviewExpiredException with no diagnostic

**File:** `Modules/Import/Internal/Pipeline/PreviewCache.php:92-104,114-127`
**Issue:** `getCanonical()` returns `null` when `is_string($raw)` is false — i.e. when the cache returns a non-string (theoretically: an array if the cache backend rotated under load, or a corrupt value). The same null return is used to mean "absent / expired". `ConfirmImport` then throws `PreviewExpiredException` with no distinction between "the 30-minute TTL elapsed" and "the cache returned garbage". The user sees "Re-upload the file"; debugging takes a Telescope dive.

**Fix:** Use `json_decode(... , flags: JSON_THROW_ON_ERROR)` and let it throw on garbage; explicitly distinguish "missing" (the key was never set / has expired) from "malformed" by checking `$this->cache->has($key)` first. A typed exception with a more specific message helps the wizard render the right UX.

### WR-009: AsnMt940Tag61Parser hardcodes the `20xx` century — silently breaks in 2100

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag61Parser.php:62,65`
**Issue:** `'20'.$m['year']` and `'20'.$m['year']` (entry date). MT940's YY century is implementation-defined per the spec; the de facto SWIFT convention is "the closest year within ±50 years of today", but ASN's actual exports always read 20xx. The current shortcut works for 75 years, but it's a future-time-bomb that would silently mis-date 2100-01-01 to 2000-01-01.

This is not a Phase 2 ship blocker, but should be flagged.

**Fix:** Use the SWIFT sliding-window convention:

```php
$yy = (int) $m['year'];
$today = CarbonImmutable::now();
$century = (int) ($today->year / 100) * 100;
$candidate = $century + $yy;
$year = $candidate - $today->year > 50 ? $candidate - 100 : $candidate;
```

### WR-010: AsnCamt053Adapter description fallback to `getMessage()` on `RemittanceInformation` masks the legitimate "no remittance" case

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php:395-407`
**Issue:** When `getUnstructuredBlocks()` returns `[]`, the adapter falls back to `getMessage()` (called "deprecated"). The fallback runs even if the actual `<RmtInf>` is a structured block (`<Strd>` instead of `<Ustrd>`) — in that case the description ends up null *or* spuriously populated from a stringified structured fallback. The two outcomes are indistinguishable to the caller.

**Fix:** Either drop the legacy `getMessage()` fallback entirely (relying on the library's canonical unstructured-blocks path), or explicitly document that `description` is null whenever the source carries only structured remittance — and add a test that exercises the structured-only case.

### WR-011: FingerprintStage classify is non-transactional; ApplyEnrichments DB transaction does not span the cache read

**File:** `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php:55-59`, `Modules/Import/Public/Actions/ApplyEnrichments.php:56-93`
**Issue:** The preview pass reads `transactions.source_ref` outside any DB transaction (line 55-59); the confirm pass reads it again inside a row-locked transaction (line 57-62) and writes. Between the two reads, the row can change. The applied UPDATE will use the *new* `source_ref` value as the diff base, but the cached `PendingEnrichment.newSourceRef` was decided against the *old* `source_ref`. This is the read-time race condition variant of CR-005. The fix in CR-005 (re-evaluate rank at write time) closes the data window; this warning is the test-coverage gap — no test currently exercises a "preview → race-insert → confirm" sequence.

**Fix:** After implementing CR-005's re-evaluation, add a unit test that seeds a transaction, builds a `PendingEnrichment` with a CSV (rank 1) ref, then manually overwrites `transactions.source_ref` with a CAMT (rank 4) value, then calls `ApplyEnrichments` and asserts the row is NOT overwritten and the action returns 0.

## Info

### IN-001: Tests reference Phase-1's `auth()->logout()` helper inside a `->skip()` annotation

**File:** `Modules/Ledger/tests/Feature/DashboardTest.php:99-104`
**Issue:** The test body calls `auth()->logout()` (global helper) and is then skipped via `->skip('auth() helper banned by DI-only — verified via Fortify default behaviour at the route layer')`. The body never executes, but the literal `auth()` call sits in source. Tools like grep / Larastan / Pint will still surface it. Since the body is dead, the assertion adds no coverage and only noise.

**Fix:** Convert the test body to a comment-only stub (or delete the test):

```php
it('redirects unauthenticated visitors away from the dashboard', function (): void {
    // Verified at the Fortify default-route layer; no DI-clean way to drive
    // unauthenticated state from inside this Pest test today.
})->skip();
```

### IN-002: AssignCategoryTest uses `Event::fake` / `Event::assertDispatched` (facades) in tests

**File:** `Modules/Categorization/tests/Feature/AssignCategoryTest.php:6,81,92,105,114,125,134,141,156`
**Issue:** The test file imports `Illuminate\Support\Facades\Event` and calls `Event::fake([...])` + `Event::assertDispatched(...)`. The project rule is "Laravel DI only — no facade calls or global helpers in production code", but tests are a grey area. The pattern is widely accepted in Pest/Laravel test suites and the rule technically constrains production code. Out of scope as a blocker, surfaced as info so the team can decide whether to standardize on `app(Dispatcher::class)->fake()` in test code too.

**Fix:** No required action. If the team adopts a no-facades-in-tests policy, swap to:

```php
$dispatcher = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);
// Use Mockery or a fake implementation instead of Event::fake.
```

### IN-003: PreviewWizardEnrichedStateTest mixes "test the contract" with "test internal seeding tricks"

**File:** `Modules/Import/tests/Feature/PreviewWizardEnrichedStateTest.php:65-110`
**Issue:** The test imports CSV, then calls `Transaction::query()->where('source_format', 'asn-csv')->update(['source_ref' => null])` to *manually* invalidate the seeded `source_ref` so the subsequent CAMT preview produces a null-from-ref diff. This mutates persisted state directly to reproduce a scenario the production path cannot reach. It tests Blade rendering of a corner case (`∅` symbol when from-ref is null) which is fine, but the seeding mechanism is heavily coupled to internal column shape.

**Fix:** Replace the in-test mutation with a factory or builder that constructs a Transaction with `source_ref=null` and a v3 fingerprint that matches a known CAMT row. The factory belongs in the Ledger test TestCase (where the `seedV2Row` helper already lives) so the pattern is reusable.

### IN-004: AsnMt940AdapterTest 'rejects a non-MT940 file at the sniff stage' covers the wrong assertion path

**File:** `Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php:177-187`
**Issue:** The test writes a CSV body to a `.sta`-extension file and expects `SniffMismatchException`. The HeaderSniffer rejects on signature regex, not extension — the `.sta` extension would pass the extension check (`/\.(sta|mt940|940|txt)$/i`) and the rejection comes from `:20:` regex mismatch. The test passes because the sniffer happens to reject for the right ultimate reason, but the test description misrepresents *which* rule fired. Future readers will mis-attribute.

**Fix:** Rename the test to "rejects a CSV body in a .sta file at the signature stage" and (optionally) add a separate test that exercises the extension-only rejection (a CSV body in a `.csv` file declared `asn-mt940`).

### IN-005: `MAX_LINE_COUNT = 100_000` underscore-delimited integer requires PHP 7.4+ — fine on 8.5 but absent in the file's strict_types declaration

**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Lexer.php:38`
**Issue:** Pure stylistic note: PHP 8.5 fully supports underscore-delimited integer literals so this is correct. The wider style nit: the file uses `100_000` and `16_384` (good — readable) but the constant names don't describe the unit. `MAX_LINE_COUNT` is fine; `MAX_BUFFER_BYTES` is clearer than `MAX_BUFFER_SIZE`. Both are good — no fix needed.

### IN-006: PreviewWizardTest skipped `cross-user import access is blocked` uses `->throws(ModelNotFoundException::class)`

**File:** `Modules/Import/tests/Feature/PreviewWizardTest.php:90-112`
**Issue:** The test triggers `confirm()` on a Livewire component from a different user and uses `->throws(ModelNotFoundException::class)`. The exception flow is correct, but a 404-style throw from a Livewire component is not the user-facing security boundary you want — it leaks the existence of import-run IDs by status code. A 403 / explicit "not yours" exception type would be cleaner. Out of Phase-2 scope.

**Fix:** Defer to a security-pass review.

---

_Reviewed: 2026-05-13T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
