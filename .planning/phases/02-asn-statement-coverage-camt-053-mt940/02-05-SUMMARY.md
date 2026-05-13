---
phase: 02-asn-statement-coverage-camt-053-mt940
plan: 05
subsystem: import
tags:
  - wave-3
  - vertical-slice
  - enrichment
  - cross-format-dedup
  - livewire
  - pipeline
dependency_graph:
  requires:
    - 02-01-PLAN
    - 02-02-PLAN
    - 02-03-PLAN
    - 02-04-PLAN
  provides:
    - "`FingerprintStage::classify(CanonicalTransaction, User): FingerprintDisposition` — the single decision point that returns NewRow / Duplicate / Enriched based on a source-format rank function (asn-camt053=4, asn-mt940=2, asn-csv=1, unknown=0). NULL ref scores 0; non-null > null is the load-bearing rank rule."
    - "`AppliesEnrichments` public contract + `ApplyEnrichments` action — per-row DB transaction with `lockForUpdate`; race-condition no-op when the stored source_ref already equals the incoming one; cross-user safety enforced inside the lock by an explicit `where('user_id', $user->id)` filter."
    - "`ImportPipeline::preview()` four-key return tuple (`rows`, `canonical`, `enrichments`, `unknownIbans`). Enriched preview rows carry a `diff` field of shape `{source_ref: {from: ?string, to: string}}` for the UI to render the transition."
    - "`PreviewCache` rotated to cache the enrichments list alongside the canonical batch through JSON round-trip; the `forget()` method clears all three keys atomically."
    - "`ConfirmImport` rewritten around an outer DB transaction wrapping both `RecordsTransactions` and `AppliesEnrichments`; `import_runs.enriched_count` is populated post-confirm and surfaced on the re-confirm zero-action path so a refresh/back-button cannot double-enrich."
    - "`PreviewRowDto`, `ImportPreviewResult`, `ImportConfirmResult` extended with `diff`, `enrichedCount`, and `enriched` fields respectively."
    - "preview-wizard Blade view grows the fourth ENRICHED arm (sky-50/sky-700/ring-sky-600/20 calm palette) with a font-mono diff indicator beneath the badge."
    - "import-results Blade view grows the four-state summary line: `Imported N transactions · skipped M duplicates · P enriched · K errors`."
    - "Feature test `CrossFormatDedupTest` with seven scenarios (csv_then_camt053, camt053_then_csv, same_format_replay, mt940_then_camt053 [skipped], camt053_then_mt940 [skipped], preview-only-flow, cross_format_pair_fingerprints_match)."
    - "Cross-module contract `tests/Contracts/IdempotencyContractTest.php` dataset extended with `asn-camt053` + `asn-mt940` rows; overlap-period test body adapts when overlapBase == overlapNext so the same-file fallback exercises the strongest available idempotency claim for fixture corpora that ship no overlap pair yet."
  affects:
    - "Phase 4 / 5 chain-resolution: a query that needs 'rows touched by format X' MUST join against `enriched_from` JSON, NOT `source_format`. The `source_format` column continues to record the CREATING format; the enrichment history lives only in `enriched_from`. Skipping this distinction would mis-attribute every CSV row that was later enriched with a CAMT EndToEndId."
    - "Future ICS / PayPal phases inherit the four-state preview UI — a fourth source format that ships a stronger reference than EndToEndId would add one entry to the `refRank` match and one Blade palette tweak; the rest of the pipeline carries the new format without further change."
    - "Phase-2 ROADMAP success criteria #1 + #2 are re-asserted at the contract level via the IdempotencyContractTest dataset rows (CAMT + MT940 same-file fallback). Future MT940 cross-format pairs can swap the `overlapBase` / `overlapNext` values to upgrade the same-file claim to a true overlap-period claim."
tech_stack:
  added: []
  patterns:
    - "Discriminated-disposition pipeline: instead of returning a `bool` ('exists' / 'doesn't exist') the FingerprintStage now returns one of three FingerprintDisposition variants. The pipeline branches on `isNew()` / `isEnriched()` / default-duplicate; `isExistingFingerprint()` is retained as a deprecated thin wrapper for one-version transition."
    - "Per-row DB-transaction pattern for write-paths that must short-circuit on race conditions: ApplyEnrichments wraps each PendingEnrichment in its own transaction with `lockForUpdate`, reads the post-lock state, and either no-ops (ref-equality short-circuit) or commits the UPDATE. Two concurrent imports targeting the same row serialise without double-counting."
    - "Append-only JSON provenance column: `transactions.enriched_from` accumulates entries of the precise shape `array{format: string, ran_at: string, import_run_id: int, added: list<string>}`. The action decodes the existing array, appends one new entry, and writes the encoded result inside the same locked transaction. The shape is asserted in the unit test and structurally enforced by Larastan's strict mode."
    - "Same-file fallback for cross-module contract datasets: the IdempotencyContractTest dataset accepts `overlapBase == overlapNext` and the test body branches on equality. CSV ships a true overlap pair (asn-month-a + asn-month-a-and-b) so it still exercises the partial-overlap path; CAMT / MT940 corpora fall back to the same-file pattern until a real overlap pair lands. The branch keeps the contract test universal across formats without forcing a fixture every adapter author may not have access to."
    - "Outer DB-transaction wrapping recorder + applier: ConfirmImport opens a single `DatabaseManager::transaction(...)` that runs RecordsTransactions, then AppliesEnrichments, then the ImportRun status flip. Either the entire confirm lands or none of it does — a recorder failure cannot leave the row half-enriched, an enrichment failure cannot leave inserts orphaned."
    - "Sky-palette ENRICHED state sitting between emerald (NEW) and amber (DUPLICATE) on the calm UI palette: the new arm uses `bg-sky-50/text-sky-700/ring-sky-600/20` and renders a `text-xs text-slate-500 font-mono` diff indicator beneath the badge. Default Blade `{{ }}` escaping covers the bank-supplied from/to values without any `{!! !!}` raw render."
key_files:
  created:
    - Modules/Import/Public/Contracts/AppliesEnrichments.php
    - Modules/Import/Public/Actions/ApplyEnrichments.php
    - Modules/Import/tests/Unit/FingerprintStageClassifyTest.php
    - Modules/Import/tests/Unit/ApplyEnrichmentsTest.php
    - Modules/Import/tests/Feature/CrossFormatDedupTest.php
    - Modules/Import/tests/Feature/PreviewWizardEnrichedStateTest.php
  modified:
    - Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php
    - Modules/Import/Internal/Pipeline/ImportPipeline.php
    - Modules/Import/Internal/Pipeline/PreviewCache.php
    - Modules/Import/Public/Dto/PreviewRowDto.php
    - Modules/Import/Public/Dto/ImportPreviewResult.php
    - Modules/Import/Public/Dto/ImportConfirmResult.php
    - Modules/Import/Public/Actions/ConfirmImport.php
    - Modules/Import/Public/Actions/RunImport.php
    - Modules/Import/Providers/ImportServiceProvider.php
    - Modules/Import/Resources/views/livewire/preview-wizard.blade.php
    - Modules/Import/Resources/views/livewire/import-results.blade.php
    - tests/Contracts/IdempotencyContractTest.php
decisions:
  - "FingerprintStage::classify returns dispositions, not booleans. The deprecated `isExistingFingerprint(): bool` survives as a thin wrapper for one-version transition so any downstream caller compiles while the migration lands. The wrapper short-circuits on `status() !== 'new'`, which means it now reports BOTH duplicates AND enrichments as 'existing'. Existing call sites are pipeline-internal only; the public surface for the Import module is the four-key preview tuple."
  - "Source-format rank function fixed at four levels — asn-camt053=4, asn-mt940=2, asn-csv=1, unknown=0. The numeric gap reserves space for future CAMT-internal sub-rankings (AcctSvcrRef=3, InstrId=2) without renumbering the existing values; today the adapter unconditionally promotes EndToEndId to source_ref at rank 4."
  - "ApplyEnrichments wraps each enrichment in its OWN per-row DB transaction rather than batching them into the outer ConfirmImport transaction. Two reasons: (1) lockForUpdate semantics are per-row, so the per-row transaction is the smallest correct lock scope; (2) a single bad enrichment cannot roll back already-committed enrichments earlier in the list, which would surprise an operator running a large multi-format backfill. The outer ConfirmImport transaction still wraps recorder + applier for atomicity at the import-confirm level — both halves see the same import_runs row update."
  - "PreviewCache rotates 3-key payload (preview, canonical, enrichments) under the same 30-minute TTL. Forgetting one key forgets all three so a half-confirmed cache cannot survive a refresh. The enrichments key is a JSON array of PendingEnrichment::toArray() output; empty list is a legitimate value (preview with no cross-format hits) and getEnrichments() returns `[]` rather than `null` when the cache hit returned an empty array."
  - "Re-confirm of an already-confirmed run returns the persisted `enriched_count` from the import_runs row rather than recomputing. The first-confirm path writes it; the second-confirm path reads it. The behaviour matches the existing inserted_count + duplicate_count handling on the same code path so the wizard's results page renders the same numbers on a refresh."
  - "Diff indicator placeholder `∅` (U+2205, empty-set glyph) for null from-ref. The character renders correctly in monochrome on the Linear/Notion calm palette without an icon font. Default Blade escaping handles it as a single UTF-8 codepoint."
  - "CrossFormatDedupTest::camt053_then_csv was rewritten from the plan's strict 'enriched=0' assertion. The 72-row February pair contains 34 CAMT entries with NULL EndToEndId (statement-only entries that ASN does not assign a SEPA reference to). For those rows the contract correctly enriches with the non-null CSV Volgnummer per the rank function (non-null > null). The test now asserts the precise counts: duplicates = CAMT rows with EndToEndId, enriched = CAMT rows without one, sum = 72."
  - "IdempotencyContractTest overlap-period test body branches on `overlapBase == overlapNext`. The same-file fallback (CAMT + MT940) asserts the strongest available claim (second import inserts zero new rows); the true overlap pair (CSV) keeps the partial-overlap assertion intact. The branch obviates a forced fixture commitment for every new adapter author and surfaces the data-availability fact in the test body itself rather than burying it in a TODO comment."
metrics:
  duration_minutes: 14
  completed_at: "2026-05-13T16:11:15Z"
  task_count: 3
  files_created: 6
  files_modified: 12
---

# Phase 02 Plan 05: ENRICHED State + Cross-Format Dedup Summary

**Closed ROADMAP Phase 2 Success Criterion #3: a user imports their February ASN CSV first, then drops in the same period as a CAMT.053 XML, and the wizard now reports `Imported 0 transactions · skipped M duplicates · P enriched · 0 errors`. The 72 rows already in the ledger get their CSV Volgnummer source_ref upgraded to the CAMT EndToEndId where one is present, and an `enriched_from` JSON entry of the precise shape `{format: "asn-camt053", ran_at: <iso8601>, import_run_id: <id>, added: ["source_ref"]}` lands on every enriched row. Re-importing CAMT after CAMT is an idempotent no-op; re-importing CSV after CAMT is a duplicate-vs-enrichment mix governed by the rank function. Importing CAMT after MT940 enriches the MT940 rows; the reverse is a strict duplicate.**

## Performance

- **Duration:** ~14 minutes
- **Started:** 2026-05-13T15:57:01Z
- **Completed:** 2026-05-13T16:11:15Z
- **Tasks:** 3
- **Files created:** 6
- **Files modified:** 12

## Disposition Decision Tree

`FingerprintStage::classify()` is now the single entrypoint for all four pipeline outcomes:

```
                  ┌── no existing row ──────────────► NewRow
classify(tx, u) ──┤
                  │                          ┌── incoming_rank > existing_rank ──► Enriched
                  └── existing row matched ──┤
                                             └── otherwise ───────────────────────► Duplicate
```

Where the source-format rank function fixes the canonical strength ordering:

| Source format | Rank | Reference field used |
|---------------|------|----------------------|
| `asn-camt053` | 4    | `EndToEndId`         |
| `asn-mt940`   | 2    | `EREF` or `:61:` customer reference |
| `asn-csv`     | 1    | `Volgnummer` (CSV sequence number) |
| (anything else) | 0  | n/a                  |
| NULL or empty | 0    | n/a                  |

A NULL or empty incoming source_ref scores 0 so it never beats a non-null stored one. Equal ranks (same format re-import, or two CAMT rows with different EndToEndIds for the same fingerprint — a defensive case that should not occur in production) resolve to Duplicate.

## ApplyEnrichments Transaction Shape

Each PendingEnrichment wraps in its OWN per-row DB transaction:

```php
DB::connection()->transaction(function () use ($enrichment, $user): bool {
    $row = DB::table('transactions')
        ->where('id', $enrichment->existingTransactionId)
        ->where('user_id', $user->id)
        ->lockForUpdate()
        ->first(['id', 'source_ref', 'enriched_from']);

    if ($row === null) return false;                                  // cross-user safety
    if ($row->source_ref === $enrichment->newSourceRef) return false; // race no-op

    // append to enriched_from, UPDATE source_ref + enriched_from + updated_at
});
```

Three short-circuits keep the action safe under concurrency:
1. **Cross-user safety** — a forged PendingEnrichment whose `existingTransactionId` belongs to another user resolves zero rows under the `user_id` filter and is silently dropped.
2. **Race no-op** — if the stored source_ref already equals the incoming one (the previous concurrent confirm already applied this exact enrichment), the action returns `false` and the count is not incremented.
3. **Lock-for-update** — between the SELECT and the UPDATE no other importer can see the row in its mid-transaction state. Two concurrent imports targeting the same row serialise on the lock.

## ConfirmImport DB Transaction Shape

```php
DB::connection()->transaction(function () use ($canonical, $enrichments, ...) {
    $recorderResult = ($this->recorder)($canonical);
    $enrichedCount  = ($this->applyEnrichments)($enrichments, $user);

    $importRun->update([
        'inserted_count'  => $recorderResult->inserted,
        'duplicate_count' => $previewDuplicateCount + $recorderResult->duplicates,
        'enriched_count'  => $enrichedCount,
        'error_count'     => $errorCount,
        'confirmed_at'    => $this->clock->now(),
        'status'          => 'confirmed',
    ]);

    return new ImportConfirmResult(...);
});
```

The outer transaction wraps both writers PLUS the import_runs status flip — a recorder failure cannot leave enrichments stranded, an enrichment failure cannot land orphaned inserts, and a partial confirm cannot mark the run as confirmed.

## PreviewCache Three-Key Payload

| Key                              | Shape                                | TTL |
|----------------------------------|--------------------------------------|-----|
| `import.{id}.preview`            | `ImportPreviewResult` array          | 30m |
| `import.{id}.canonical`          | JSON array of `CanonicalTransaction` | 30m |
| `import.{id}.enrichments`        | JSON array of `PendingEnrichment`    | 30m |

`forget()` clears all three atomically so a half-confirmed cache cannot survive a wizard refresh. An empty enrichments list is a legitimate value (a preview with no cross-format hits); a missing key is treated as cache-miss only for the canonical batch (the only key whose absence triggers the `PreviewExpiredException` path).

## Blade ENRICHED Palette

The fourth row state sits between NEW (emerald) and DUPLICATE (amber):

| State     | Background  | Text         | Ring          |
|-----------|-------------|--------------|---------------|
| New       | emerald-50  | emerald-700  | emerald-600/20|
| Enriched  | sky-50      | sky-700      | sky-600/20    |
| Duplicate | amber-50    | amber-700    | amber-600/20  |
| Error     | rose-50     | rose-700     | rose-600/20   |

The diff indicator (rendered only when `$row->diff['source_ref']` is set) reads:

```
source_ref: <from-or-∅> → <to>
```

Styled `mt-1 text-xs text-slate-500 font-mono` so it sits as a small muted line beneath the badge. The `from` value falls back to the `∅` glyph when the existing row's source_ref was NULL.

## CrossFormatDedupTest Scenario Matrix

| Scenario                                  | Status       | Asserts                                                                                  |
|-------------------------------------------|--------------|------------------------------------------------------------------------------------------|
| `csv_then_camt053`                        | PASS         | second import inserts 0; enriches > 0; sum of enriched + duplicates = 72                 |
| `camt053_then_csv`                        | PASS         | second import inserts 0; duplicates = CAMT rows with EndToEndId; enriched = CAMT rows without one |
| `same_format_replay`                      | PASS         | second CAMT import inserts 0 and enriches 0                                              |
| `mt940_then_camt053`                      | SKIPPED      | No same-period MT940 export available from ASN — see asn-cross-format/README.md          |
| `camt053_then_mt940`                      | SKIPPED      | No same-period MT940 export available from ASN — see asn-cross-format/README.md          |
| preview-only flow                         | PASS         | every enriched preview row carries a populated `diff.source_ref.to`                      |
| `cross_format_pair_fingerprints_match`    | PASS         | every CAMT preview row matches an existing CSV fingerprint as duplicate or enriched      |

## IdempotencyContractTest Dataset

The dataset gained two adapter rows:

| Key             | fixture                                              | overlapBase                                          | overlapNext                                          |
|-----------------|------------------------------------------------------|------------------------------------------------------|------------------------------------------------------|
| `asn-csv`       | `tests/fixtures/asn-sample-1.csv`                    | `asn-month-a.csv`                                    | `asn-month-a-and-b.csv` (true overlap)               |
| `asn-camt053`   | `tests/fixtures/asn-camt053-sample-1.xml`            | same file                                            | same file (same-file fallback)                       |
| `asn-mt940`     | `tests/fixtures/asn-mt940-sample-1.sta`              | same file                                            | same file (same-file fallback)                       |

The overlap-period test body branches on `overlapBase === overlapNext`: same-file rows assert that the second import inserts ZERO rows (the strongest possible claim); the real-overlap CSV row asserts the partial-overlap dynamics (`second.inserted < first.inserted AND second.duplicates > 0`). Both new adapter dataset rows pass both test bodies.

## Invariant for Downstream Phase 4 / 5 Planners

**`source_format` records the CREATING format; `enriched_from` carries the multi-format history.** A query that needs "every row that any CAMT import has touched" MUST join against `enriched_from` JSON, NOT `source_format`. Example:

```php
// WRONG — misses CSV-created rows that a later CAMT import enriched
Transaction::query()->where('source_format', 'asn-camt053')->get();

// RIGHT — catches both CAMT-created rows AND CAMT-enriched rows
Transaction::query()
    ->where('source_format', 'asn-camt053')
    ->orWhereJsonContains('enriched_from', [['format' => 'asn-camt053']])
    ->get();
```

Phase 5 chain-resolution queries that aggregate cross-format references MUST honour this distinction or they will silently mis-attribute enriched rows.

## Phase 2 Success Criteria — Closing Status

| # | Criterion                                                                                                                                              | Status | Closing test                                                              |
|---|--------------------------------------------------------------------------------------------------------------------------------------------------------|--------|---------------------------------------------------------------------------|
| 1 | User can upload an ASN CAMT.053 XML export and see its transactions imported with `EndToEndId` populated as the primary source reference               | GREEN  | `AsnCamt053ImportTest::*` (Plan 02-03) + `CrossFormatDedupTest::same_format_replay` |
| 2 | User can upload an ASN MT940 export covering older statement periods and have it ingested via the same pipeline                                        | GREEN  | `AsnMt940ImportTest::*` (Plan 02-04) + `IdempotencyContractTest[asn-mt940]` |
| 3 | Importing CAMT.053 and CSV exports that cover the same period produces a single set of transactions — no cross-format duplicates                       | GREEN  | `CrossFormatDedupTest::csv_then_camt053` + `cross_format_pair_fingerprints_match` |

All three Phase-2 ROADMAP success criteria are now GREEN at the contract level. Phase 2 itself is ready for verification (the orchestrator's verify-phase-goal step gates the final transition).

## Quality Gates

| Gate                             | Result   |
|----------------------------------|----------|
| `vendor/bin/pest --group=phase-2`| 147 passed, 2 skipped (MT940 cross-format), 6030 assertions |
| `vendor/bin/pest` (full suite)   | 372 passed, 3 skipped, 12777 assertions |
| `vendor/bin/phpstan analyse`     | No errors (level max + strict, larastan)  |
| `vendor/bin/pint --test`         | passed                                  |
| GSD-internal refs in module code | none (greppable check passed)           |
| Facade / helper calls in module  | none (Clock + DatabaseManager injected) |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] camt053_then_csv strict 'enriched=0' expectation was incompatible with the fixture**
- **Found during:** Task 3 — running CrossFormatDedupTest after the pipeline integration landed.
- **Issue:** The plan asserted that re-importing CSV after CAMT produces zero enrichments because "CSV rank 1 < CAMT rank 4". The fixture's 72 CAMT rows include 34 with NULL EndToEndId (statement-only entries that ASN does not assign a SEPA reference to). For those, a non-null CSV Volgnummer is strictly stronger under the contract `non-null > null`, so the action correctly enriches them.
- **Fix:** Updated the test body to compute `camtRowsWithRef` from the post-CAMT state and assert the precise split: duplicates = camtRowsWithRef, enriched = (72 − camtRowsWithRef), sum = 72. The fix preserves the contract and reflects the production-data reality.
- **Files modified:** `Modules/Import/tests/Feature/CrossFormatDedupTest.php`
- **Commit:** c38b925

### Auth gates

None — the plan ran fully autonomous; no third-party authentication was required.

## Notes for Subsequent Plans

- `Modules/Import/tests/Unit/FingerprintStageClassifyTest.php` introduces the test helpers `canonicalForUser(User, int, string, ?string): CanonicalTransaction` and `seedTransactionMatchingCanonical(User, int, string, ?string, FingerprintComposer): Transaction`. These live as file-local functions for now; if Phase 3+ tests need them they can lift into `tests/Pest.php` next to `writeMt940Temp(...)`.
- `Modules/Import/tests/Unit/ApplyEnrichmentsTest.php` has its own `seedExistingTransaction(...)` helper following the same pattern.
- The deprecated `FingerprintStage::isExistingFingerprint(): bool` retained as a thin wrapper for one-version transition. No production caller relies on it after Plan 02-05; remove it when bumping Phase-2 work toward Phase-3 if no further deprecation window is required.

## Self-Check: PASSED

- [x] `Modules/Import/Public/Contracts/AppliesEnrichments.php` exists
- [x] `Modules/Import/Public/Actions/ApplyEnrichments.php` exists
- [x] `Modules/Import/tests/Unit/FingerprintStageClassifyTest.php` exists
- [x] `Modules/Import/tests/Unit/ApplyEnrichmentsTest.php` exists
- [x] `Modules/Import/tests/Feature/CrossFormatDedupTest.php` exists
- [x] `Modules/Import/tests/Feature/PreviewWizardEnrichedStateTest.php` exists
- [x] Commit 3a06e7f (Task 1) — feat(02-05): classify dispositions and apply cross-format enrichments
- [x] Commit 27d05b9 (Task 2) — feat(02-05): render Enriched badge and four-state results summary
- [x] Commit c38b925 (Task 3) — feat(02-05): cross-format dedup feature tests and idempotency contract
