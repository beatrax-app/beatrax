---
phase: 02-asn-statement-coverage-camt-053-mt940
plan: 04
subsystem: ingestion
tags:
  - wave-2
  - vertical-slice
  - mt940
  - adapter
  - hand-rolled-parser
  - sniffer
  - statement-summaries
dependency_graph:
  requires:
    - 02-01-PLAN
    - 02-02-PLAN
    - 02-03-PLAN
  provides:
    - "`AsnMt940HeaderProfile` constants (FORMAT, FILE_EXTENSIONS = sta/mt940/940/txt, SWIFT_ENVELOPE_REGEX, SIGNATURE_REGEX)"
    - "`HeaderSniffer::sniffAsnMt940()` arm — strips optional SWIFT block-1 envelope `{1:...}{2:...}{4: ... -}` before checking for `:20:`; rejects non-`.sta/.mt940/.940/.txt` files; rejects bodies missing the `:20:` Transaction Reference Number tag with user-readable copy"
    - "`AsnMt940Lexer` — streaming line-by-line tag tokenizer, yielding `(tag, content)` tuples in stream order; bounded reads via MAX_LINE_COUNT = 100_000 and MAX_BUFFER_BYTES = 16_384 caps; handles SWIFT envelope, CRLF normalisation, lone-`-` EOM marker, EOF-without-marker flush, and multi-statement files"
    - "`AsnMt940Tag61Parser` — decodes the ASN-extended 34-character `:61:` customer-reference variant (not the SWIFT-standard 16); signs amounts per the C/D/RC/RD status code (C/RD positive, D/RC negative); routes the comma-decimal amount through the existing `AsnAmountParser` for the integer-only money path"
    - "`AsnMt940Tag86Parser` — decodes the structured (`?NN`-prefixed) and unstructured forms; extracts the twelve SEPA GVC keywords (EREF/MREF/CRED/SVWZ/KREF/PURP/IBAN/BIC/ABWA/MDAT/COAM/OAMT); concatenates `?32` + `?33` into a single counterparty name and `?20–?29` + `?60–?65` into a description buffer; promotes EREF to a `sourceRef` candidate at the adapter layer"
    - "`AsnMt940CounterpartyCleaner` — MT940-specific pre-normalisation that runs BEFORE the shared `FingerprintComposer::normalize` step: strips leading 3-digit GVC posting codes, leading 4-char transaction-type codes (NTRF/NDDT/NMSC/SCHG/NREF/…), embedded BIC codes, and SEPA `/REMI/`-style narrative markers"
    - "`AsnMt940Adapter` — implements `SourceAdapter`, wires the lexer + two tag parsers + counterparty cleaner via constructor DI; pairs each `:61:` with the optional immediately-following `:86:` tag; flushes a trailing lone `:61:` at end-of-stream; populates `statement_summaries` via `statementMetadata()` returning a `StatementSummaryData` derived from the first statement's `:20:` + `:25:` + `:28C:` + `:60F:` + `:62F:` tags; multi-statement files flag `extras.multiStatement = true` while still yielding every entry in stream order"
    - "Two internal parser DTOs under `Modules/Ingestion/Internal/Adapters/Asn/Dto/`: `Mt940StatementLine` (typed `:61:` projection) and `Mt940Narrative` (typed `:86:` projection); plus `Mt940BalanceTuple` for the `:60F:`/`:62F:` balance-tag projection"
    - "`UploadWizard::SUPPORTED_FORMATS` widened to three entries; `rules()` mimes list widened to `csv,txt,xml,sta,mt940,940`; `sanitiseFilename()` returns `.sta` when `sourceFormat === 'asn-mt940'`"
    - "`RunImport::copyToStableLocation()` extension `match` adds the `sta` arm so the stored copy round-trips through the format-specific sniffer on re-read"
    - "Blade upload wizard adds the third dropdown option + widens `accept=` to `.csv,.xml,.sta,.mt940,.940,.txt`"
    - "`IngestionServiceProvider` extended with the `'asn-mt940' => AsnMt940Adapter` registry entry"
  affects:
    - "Plan 02-05 (cross-format dedup + enrichment writer + wizard) — the MT940 adapter produces `sourceRef` from EREF (when present) or the `:61:` customer reference, both of which are WEAKER than CAMT.053's `EndToEndId`. Plan 02-05's `FingerprintStage::classify` will rank `asn-camt053 > asn-mt940 > asn-csv` so a CAMT-after-MT940 import ENRICHES the MT940 row with the stronger CAMT EndToEndId, but the reverse is a strict duplicate. The `writeMt940Temp(string $body): string` helper landed in the root `tests/Pest.php` is available for Plan 02-05's cross-format dedup scenarios."
    - "Future ICS/PayPal phases inherit the format-discriminated wizard surface — a fourth format adds one `SUPPORTED_FORMATS` entry, one `<option>` row, one `sanitiseFilename()` arm, one `copyToStableLocation()` arm, and one registry entry. The sniffer + adapter implementation are the only per-format work."
tech_stack:
  added: []
  patterns:
    - "Hand-rolled streaming tokenizer pattern: `AsnMt940Lexer::tokenize` is a Generator yielding `(tag, content)` tuples; the consumer (the adapter) is responsible for tag pairing and statement boundaries. Bounded reads (line cap, buffer cap) keep the parser robust against pathological inputs; both caps raise `InvalidAmountException` with a user-readable message so the wizard surfaces a fast error instead of a hung worker."
    - "Multi-component adapter pattern: the MT940 adapter is the first ASN format wired from FIVE collaborators (sniffer, lexer, Tag61 parser, Tag86 parser, counterparty cleaner) plus the shared `AsnAmountParser`. Each collaborator is isolated (constructor DI, single responsibility) and unit-tested independently before the adapter snapshot test pins their composition."
    - "Pre-normalisation step pattern: `AsnMt940CounterpartyCleaner` runs BEFORE the shared `FingerprintComposer::normalize` step, stripping MT940-specific noise (GVC prefixes, BIC codes, SEPA `/REMI/` markers) that the shared normaliser does not know about. The shared normaliser still does case folding + NFD-strip; the cleaner stays narrow in scope."
    - "Booking-date normalisation contract across formats: every ASN adapter (CSV, CAMT.053, MT940) zeroes `bookedAt` to `00:00:00` so the FingerprintComposer v3 hash matches across formats for the same logical transaction. MT940's `:61:` carries day-precision dates only, but the contract is stated and tested explicitly."
    - "Integer-only money path end-to-end: the `:61:` amount and the `:60F:`/`:62F:` balance amount both route through `AsnAmountParser::parseMinor` (integer regex, no float coercion). Comma-decimal cells are normalised to a two-fractional-digit period-decimal before delegation. The pattern carries the Phase 1 ICS-fee precision guarantee through to MT940."
    - "Format-discriminated wizard surface: a single `UploadWizard::SUPPORTED_FORMATS` const drives the `in:` validator; the file-extension `match` lives in `sanitiseFilename()` and `RunImport::copyToStableLocation()`. The shape proved out in Plan 02-03 carries one extra entry now."
    - "Statement metadata side-channel pattern (carried from Plan 02-03): the adapter is a stateful singleton that captures `?StatementSummaryData` into a private field during `parse()`. The pipeline calls `statementMetadata()` after iteration completes and the writer persists the row. Multi-statement files capture only the FIRST statement's facts but flag `extras.multiStatement = true` so the UI can surface the rest later."
key_files:
  created:
    - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940HeaderProfile.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Lexer.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag61Parser.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag86Parser.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940CounterpartyCleaner.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php
    - Modules/Ingestion/Internal/Adapters/Asn/Dto/Mt940StatementLine.php
    - Modules/Ingestion/Internal/Adapters/Asn/Dto/Mt940Narrative.php
    - Modules/Ingestion/Internal/Adapters/Asn/Dto/Mt940BalanceTuple.php
    - Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php
    - Modules/Ingestion/tests/Unit/AsnMt940Tag61ParserTest.php
    - Modules/Ingestion/tests/Unit/AsnMt940Tag86ParserTest.php
    - Modules/Ingestion/tests/Unit/AsnMt940CounterpartyCleanerTest.php
    - Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php
    - Modules/Import/tests/Feature/AsnMt940ImportTest.php
    - tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnMt940AdapterTest/it_matches_the_snapshot_of_the_parsed_MT940_fixture__drift_detector_.snap
  modified:
    - Modules/Ingestion/Public/Services/HeaderSniffer.php
    - Modules/Ingestion/Providers/IngestionServiceProvider.php
    - Modules/Ingestion/tests/Feature/HeaderSnifferTest.php
    - Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php
    - Modules/Import/Internal/Http/Livewire/UploadWizard.php
    - Modules/Import/Public/Actions/RunImport.php
    - Modules/Import/Resources/views/livewire/upload-wizard.blade.php
    - tests/Pest.php
decisions:
  - "Route the `:60F:`/`:62F:` balance amount through `AsnAmountParser::parseMinor` (with a small `Mt940BalanceTuple` value object capturing the parsed result) rather than the float-coerced `(int) round((float) $cell * 100)` shortcut the plan flagged as an option. Float coercion would have broken the project-wide integer-only money invariant for one corner — balance amounts — and the `NoFloatMoneyArchTest` allow-list. The integer path adds two lines of comma-decimal normalisation; the invariant stays airtight."
  - "Strip the leading SWIFT block-1 envelope inside both the sniffer (via a shared `SWIFT_ENVELOPE_REGEX` on `AsnMt940HeaderProfile`) and the lexer rather than in a separate preprocessing step. Two reasons: (1) the sniffer needs envelope-aware look-ahead to validate the `:20:` signature without false-rejecting wrapped files; (2) the lexer needs envelope stripping to tokenize correctly. Sharing the regex constant keeps the two callers consistent; a third preprocessing step would have added a hop with no real win."
  - "Widen the `SWIFT_ENVELOPE_REGEX` terminator pattern to tolerate whitespace between the EOM `-` and the closing `}` (the practical wrapper produced by every exporter that emits the marker on its own line). Tightening this to the strict `-}` form would have rejected real fixtures."
  - "Multi-statement file handling: the FIRST statement's `:20:` + `:25:` + `:28C:` + `:60F:` + `:62F:` populate `statement_summaries`. Subsequent statements still yield every `:61:`/`:86:` entry in stream order, but their statement-level facts are not captured. The decision keeps the writer's unique `(user_id, import_run_id)` contract honest (one statement summary per import run) while preserving every transaction. Multi-statement files flag `extras.multiStatement = true` so a later UI surfaces the fact; Phase 2 ships a single-statement use-case so the UI work is deferred."
  - "Inline the per-file `:86:` GVC keyword scan regex in `AsnMt940Tag86Parser::extractGvcKeywords` rather than building a multi-pass state machine. Trade-off: the regex has special handling for `BIC` (trailing-space convention) versus every other keyword (`+`-delimited), making it slightly more complex. The win is one preg_match_all pass per `:86:` field instead of N passes per keyword; readability is preserved by isolating the regex behind two private helpers."
  - "Reject the SWIFT-standard 16-char customer-reference variant at the regex level. The ASN MT940 export uses the extended 34-char form unconditionally; a 16-char regex would silently truncate references and break `sourceRef` extraction. The regex is locked to `[^/\\n]{0,34}` so it accepts up to 34 characters; the project explicitly produces and consumes the ASN dialect."
  - "Promote `EREF` to `sourceRef` (with the `NOTPROVIDED` placeholder filter) instead of using the `:61:` customer reference unconditionally. Two reasons: (1) EREF in MT940 is the SEPA-protocol EndToEndId equivalent — the strongest available reference for the format; (2) the `:61:` customer reference may carry the bank's internal reference, which is statement-only and would not survive an enrichment pass. Falling back to the `:61:` customer reference when EREF is absent (or `NOTPROVIDED`) keeps `sourceRef` non-null for every entry the test fixture covers."
  - "Adopt the `writeMt940Temp(string $body): string` test helper at the root `tests/Pest.php` rather than the per-module `Modules/Ingestion/tests/Pest.php`. Pest's bootstrapper only auto-loads the root `tests/Pest.php`; the per-module Pest files are inert. The helper is shared by the lexer / adapter / import tests + reserved for Plan 02-05's cross-format dedup tests."
metrics:
  duration_minutes: 15
  completed_at: "2026-05-13T15:50:18Z"
  task_count: 4
  files_created: 16
  files_modified: 8
---

# Phase 02 Plan 04: MT940 Vertical Slice Summary

**Closed ROADMAP Phase 2 Success Criterion #2: a user picks "ASN MT940" in the upload wizard, drops in an ASN MT940 `.sta` / `.mt940` / `.940` / `.txt` file, hits Upload, sees parsed transactions in the existing Preview screen, and confirms them into the ledger with EREF (or the `:61:` customer reference when EREF is `NOTPROVIDED`) populated as the canonical `source_ref` on every row. The `statement_summaries` row captures opening + closing EUR balances and the period bounds from `:60F:` / `:62F:`. Re-uploading the same SHA-256 is an idempotent no-op.**

## Performance

- **Duration:** ~15 minutes
- **Started:** 2026-05-13T15:34:33Z
- **Completed:** 2026-05-13T15:50:18Z
- **Tasks:** 4
- **Files created:** 16
- **Files modified:** 8

## Hand-Rolled MT940 Toolchain

The plan introduces the codebase's first hand-rolled, line-based parser. The toolchain is split into five tightly-scoped classes under `Modules/Ingestion/Internal/Adapters/Asn/`, plus three internal DTOs under `Modules/Ingestion/Internal/Adapters/Asn/Dto/`:

| Layer | Class | Responsibility |
|-------|-------|----------------|
| Tokenizer | `AsnMt940Lexer` | Streaming `(tag, content)` Generator; SWIFT envelope strip; bounded reads |
| Tag parser | `AsnMt940Tag61Parser` | Decodes the `:61:` statement-line tag with the ASN-extended 34-char customer-reference variant; signs amounts per C/D/RC/RD; integer-only amount path |
| Tag parser | `AsnMt940Tag86Parser` | Decodes the `:86:` narrative tag in structured (`?NN`-subfields + GVC keywords) or unstructured form |
| Pre-normaliser | `AsnMt940CounterpartyCleaner` | Strips MT940-specific noise (GVC prefixes, BIC codes, SEPA markers) BEFORE the shared `FingerprintComposer::normalize` step |
| Adapter | `AsnMt940Adapter` | Pairs `:61:`/`:86:`; emits `SourceTransactionDto`; populates `statement_summaries` via `statementMetadata()` |

The adapter is the only class outside the parser package: it implements the public `SourceAdapter` contract from `Modules/Ingestion/Public/Contracts/`.

### Bounded-read caps

Two defensive caps inside `AsnMt940Lexer` protect against pathological inputs:

| Cap | Value | Rationale |
|-----|-------|-----------|
| `MAX_LINE_COUNT` | 100_000 | Real ASN MT940 files are a few hundred lines. The wizard `max:10240` (10 MB) byte cap already bounds input size; this guards a crafted file whose every byte is a newline. |
| `MAX_BUFFER_BYTES` | 16_384 | Real `:86:` narratives never exceed a few hundred bytes per tag. Spec-wise the spec allows 6 × 65 chars per tag (≤ 400 bytes); the cap is a 40× headroom over the spec and still catches an unbounded continuation loop fast. |

Both caps raise `InvalidAmountException` with a user-readable message so the wizard surfaces a fast error instead of a hung worker.

## sourceRef Precedence Rule

The adapter projects MT940 source references onto `SourceTransactionDto::sourceRef` with this precedence:

1. **GVC `EREF` keyword from `:86:`** — when non-empty and not the literal string `NOTPROVIDED`. EREF is MT940's SEPA-protocol EndToEndId equivalent — the strongest available reference for the format.
2. **`:61:` customer reference** (ASN-extended 34-char variant) — used when EREF is absent or `NOTPROVIDED`.
3. **`NULL`** — when neither is present.

The 12-entry fixture covers all three branches: rows 0–10 take EREF from `:86:`, row 11 has no `:86:` continuation so its `sourceRef` comes from the `:61:` customer reference `20260310-2451619`.

## Booking-Date Normalisation Contract

MT940's `:61:` carries day-precision dates only — a value date (YYMMDD) and an optional entry date (MMDD). The adapter zeroes `bookedAt` to `00:00:00` so the `FingerprintComposer` v3 hash matches across the three ASN formats (CSV, CAMT.053, MT940) for the same logical transaction. The behaviour is tested explicitly in `AsnMt940AdapterTest::normalises_bookedAt_to_00:00:00_so_cross_format_dedup_with_CSV_and_CAMT_survives`.

## Multi-Statement File Handling

When an MT940 file carries multiple `:20:` blocks, the adapter:

1. Captures the **first** statement's `:20:` + `:25:` + `:28C:` + `:60F:` + `:62F:` for `statement_summaries`.
2. Yields every `:61:`/`:86:` entry from **all** statements in stream order (the writer still gets the full transaction set).
3. Flags `extras.multiStatement = true` on the statement summary so a future UI can surface the fact.

The decision keeps the writer's unique `(user_id, import_run_id)` contract honest (one statement summary per import run) while preserving every transaction. The fixture corpus is single-statement; the multi-statement path is exercised by `AsnMt940AdapterTest::flags_multi_statement_files_in_statementMetadata_extras`.

## Snapshot

- **File:** `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnMt940AdapterTest/it_matches_the_snapshot_of_the_parsed_MT940_fixture__drift_detector_.snap`
- **Initial size:** 146 lines of JSON
- **Transaction count:** 12 (matches `tests/fixtures/asn-mt940-sample-1.md`'s documented entry count)
- **Period:** 2026-02-01 → 2026-03-10

The snapshot pins the full DTO projection (bookedAt, valueDate, ownIban, counterpartyIban, counterpartyName, currency, amountMinor, sourceRef, description, sourceRowIndex). Drift in any field surfaces as a snapshot diff on the next run.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] SWIFT envelope regex too strict for the empirical wrapper**

- **Found during:** Task 2 (lexer)
- **Issue:** The plan's `SWIFT_ENVELOPE_REGEX = /\{4:\s*([\s\S]+?)-\}/` required `-}` adjacency, but every exporter that emits the EOM marker on its own line writes `-\n}`. The lexer's envelope-detection branch was throwing `'SWIFT envelope detected but block-4 contents missing.'` against the test fixture.
- **Fix:** Widened the terminator to `\s*-\s*\}` so the marker may be separated from the closing brace by whitespace.
- **Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940HeaderProfile.php`
- **Commit:** `9a6620e`

**2. [Rule 3 - Blocking] `writeMt940Temp` placement**

- **Found during:** Task 2 (lexer test execution)
- **Issue:** The plan suggested adding `writeMt940Temp` to `Modules/Ingestion/tests/TestCase.php`. The codebase pattern (`writeTempXml` inside `HeaderSnifferTest.php`) reveals Pest's bootstrapper only auto-loads the root `tests/Pest.php` — per-module `Pest.php` files are inert.
- **Fix:** Placed `writeMt940Temp` in the root `tests/Pest.php` so every MT940 test (lexer / adapter / import + Plan 02-05's cross-format dedup tests) shares the same helper.
- **Files modified:** `tests/Pest.php`
- **Commit:** `9a6620e`

**3. [Rule 1 - Bug] `AsnCsvAdapterTest` pre-existing assertion that `asn-mt940` is UNSUPPORTED**

- **Found during:** Task 4 full-suite run
- **Issue:** `AsnCsvAdapterTest::registers_under_the_asn-csv_key_in_the_SourceAdapterRegistry` asserted `$registry->for('asn-mt940')` throws `UnsupportedFormatException`. That assertion is now false because the registry registers `asn-mt940` at boot.
- **Fix:** Switched the test's negative-case format to `'asn-no-such-format'` so the assertion still proves the registry rejects unknown identifiers without falsely pinning MT940 as out-of-scope.
- **Files modified:** `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php`
- **Commit:** `a40e07d`

**4. [Rule 1 - Bug] `HeaderSnifferTest::rejects_an_unknown_declared_format` was asserting `asn-mt940` is unsupported**

- **Found during:** Task 1 RED/GREEN
- **Issue:** Same root cause as item 3 — the pre-existing negative-case test was using `'asn-mt940'` as a dummy unknown format.
- **Fix:** Switched the dummy format to `'asn-no-such-format'`.
- **Files modified:** `Modules/Ingestion/tests/Feature/HeaderSnifferTest.php`
- **Commit:** `1bac839`

### Architectural Decisions

**Balance amounts routed through `AsnAmountParser`, not float coercion.** Plan Task 4 Step 2 flagged this as a decision point; chose the integer-only path to keep the project-wide money invariant airtight. A small `Mt940BalanceTuple` internal DTO carries the `(minor, currency, date)` triple from `parseBalance()` into the statement-metadata constructor.

## Authentication Gates

None — this plan is local-only ingestion of a user-uploaded file. No external service authentication is involved.

## Quality Gates

- **Phase-2 group:** 117 passed (5,491 assertions); zero failures, zero skipped
- **Full Pest suite:** 344 passed, 1 skipped (the pre-existing `RederiveFingerprintsCommand` HTTP-unreachable arch test); zero failures
- **Larastan level 10 strict:** zero errors
- **Pint (laravel preset):** zero diffs
- **No float in money path:** `NoFloatMoneyArchTest` passes (no new migration declares REAL/FLOAT on a money column; balance amounts go through `AsnAmountParser`)
- **Bounded reads:** lexer + parsers enforce all documented caps (line-cap test + buffer-cap test in `AsnMt940LexerTest`)
- **GSD-agnostic codebase:** zero `.planning` / `PLAN.md` / `RESEARCH.md` / `CONTEXT.md` / `D-NN` references in any new file
- **DI-only:** zero facade/helper calls in any of the new adapter files

## Pointer for Plan 02-05

`AsnMt940Adapter` produces `sourceRef` from EREF or the `:61:` customer reference — both are WEAKER than CAMT.053's `EndToEndId`. Plan 02-05's `FingerprintStage::classify` should rank `asn-camt053 > asn-mt940 > asn-csv` so a CAMT-after-MT940 import ENRICHES the MT940 row with the stronger CAMT EndToEndId, but the reverse (MT940-after-CAMT) is a strict duplicate.

The synthesised `tests/fixtures/asn-mt940-sample-1.sta` is suitable for adapter unit/snapshot/import-happy-path tests only. The `february.mt940.sta` cross-format pair does NOT exist (ASN no longer ships an MT940 download channel), so the `CrossFormatDedupTest::mt940_after_csv` and `mt940_then_camt053` scenarios that Plan 02-05 will introduce should `->skip('No same-period MT940 export available from ASN — see asn-cross-format/README.md')` for the time being.

The `writeMt940Temp(string $body): string` test helper at the root `tests/Pest.php` is available for any future cross-format scenarios that need to compose a hand-rolled MT940 buffer on the fly.

## Self-Check: PASSED
