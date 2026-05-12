---
phase: 01-foundation-asn-csv-vertical-slice
plan: 04
subsystem: ingestion
tags:
  - csv
  - asn
  - adapter
  - di-only
  - empirical-fixture
  - utf-8
  - snapshot
dependency_graph:
  requires:
    - 01-01-PLAN
    - 01-02-PLAN
    - 01-03-PLAN
  provides:
    - "`Modules\\Ingestion\\Public\\Contracts\\SourceAdapter` — stable Generator-based parse contract"
    - "`Modules\\Ingestion\\Public\\Contracts\\AccountResolver` — IBAN → Known/Unknown discriminated DTO"
    - "`Modules\\Ingestion\\Public\\Dto\\SourceTransactionDto` — typed source row with raw-payload audit (ING-08)"
    - "`Modules\\Ingestion\\Public\\Dto\\{AccountResolution, KnownAccount, UnknownAccount, SniffResult}` — discriminated DTOs"
    - "`Modules\\Ingestion\\Public\\Exceptions\\{InvalidAmountException, UnsupportedFormatException, SniffMismatchException}`"
    - "`Modules\\Ingestion\\Public\\Services\\HeaderSniffer` — pre-parse upload-wizard validator"
    - "`Modules\\Ingestion\\Public\\Services\\SourceAdapterRegistry` — stable-format → adapter map"
    - "`Modules\\Ingestion\\Internal\\Adapters\\Asn\\AsnCsvAdapter` — streaming ASN CSV adapter"
    - "`Modules\\Ingestion\\Internal\\Adapters\\Asn\\AsnCsvColumnMap` — empirical 20-column index map"
    - "`Modules\\Ingestion\\Internal\\Adapters\\Asn\\AsnCsvHeaderProfile` — empirical 2026 format profile"
    - "`Modules\\Ingestion\\Internal\\Adapters\\Asn\\AsnAmountParser` — integer-only amount parser (Pitfall 1)"
    - "First Pest snapshot of the real fixture under `tests/.pest/snapshots/`"
  affects:
    - "Plan 05 (Import) injects SourceAdapterRegistry::for('asn-csv') into the import pipeline; AccountResolver gets its real EloquentAccountResolver implementation; SniffResult is rendered in the wizard preview"
    - "Plan 05 (Import) ships RunsImports which closes the two RED-by-design IdempotencyContractTest dataset rows"
tech_stack:
  added: []
  patterns:
    - "Streaming Generator-based adapter — never materialises the file"
    - "league/csv Reader::from() (the post-9.27 replacement for the deprecated createFromPath) + CharsetConverter::addTo for encoding normalisation"
    - "AsnAmountParser uses pure integer arithmetic via regex capture groups — `($whole*100 + $fractional) * $sign`. No (float), no round(), no intval(float)."
    - "Discriminated-union DTO via abstract Spatie\\LaravelData\\Data with static factories and final variants (KnownAccount / UnknownAccount)"
    - "Header sniff = extension + first-line column count + first-two-header signature ('Datum', 'Je rekening')"
    - "Source-format string ('asn-csv') is the public coupling key — the registry is the single seam between Ingestion and Import"
key_files:
  created:
    - Modules/Ingestion/Public/Contracts/SourceAdapter.php
    - Modules/Ingestion/Public/Contracts/AccountResolver.php
    - Modules/Ingestion/Public/Services/HeaderSniffer.php
    - Modules/Ingestion/Public/Services/SourceAdapterRegistry.php
    - Modules/Ingestion/Public/Dto/SourceTransactionDto.php
    - Modules/Ingestion/Public/Dto/SniffResult.php
    - Modules/Ingestion/Public/Dto/AccountResolution.php
    - Modules/Ingestion/Public/Dto/KnownAccount.php
    - Modules/Ingestion/Public/Dto/UnknownAccount.php
    - Modules/Ingestion/Public/Exceptions/InvalidAmountException.php
    - Modules/Ingestion/Public/Exceptions/UnsupportedFormatException.php
    - Modules/Ingestion/Public/Exceptions/SniffMismatchException.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php
    - Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php
    - Modules/Ingestion/tests/Unit/AsnAmountParserTest.php
    - Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php
    - Modules/Ingestion/tests/Feature/HeaderSnifferTest.php
    - tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCsvAdapterTest/it_matches_the_snapshot_of_the_parsed_fixture__drift_detector_.snap
  modified:
    - Modules/Ingestion/Providers/IngestionServiceProvider.php
    - .planning/phases/01-foundation-asn-csv-vertical-slice/01-VALIDATION.md
decisions:
  - "AsnCsvHeaderProfile::SOURCE_ENCODING is the literal string 'UTF-8' (not 'us-ascii' as the plan's pseudo-code suggested). PHP's mbstring filter does not recognise 'us-ascii'; the committed fixture is UTF-8 (per `file -bI`); us-ascii is a subset of UTF-8 so the filter is still a safe no-op for the empirically-typed export."
  - "AsnCsvAdapter uses league/csv Reader::from() instead of the plan's pseudo-code Reader::createFromPath() — createFromPath is deprecated as of league/csv 9.27.0 and would trip PHPStan's strict-rules deprecation guard on first call."
  - "Statement::process() was dropped in favour of Reader::getRecords() — the Statement wrapper adds no behaviour for this adapter (no select/filter/limit) and PHPStan can infer the iteration type more cleanly without it."
  - "rawPayload type widened from the plan's strict `array<string,string>` to `array<int|string,string>` in the DTO and re-keyed to integer-positional inside the adapter — guarantees `count($rawPayload) === EXPECTED_COLUMN_COUNT` regardless of whether the source file had headers."
  - "HeaderSniffer accepts only files that pass extension + column-count + header-signature checks; the `if (HAS_HEADER)` wrap was removed because PHPStan correctly flagged the const-true branch as dead code — the sniffer is intentionally ASN-specific once the format dispatch fires."
  - "IngestionServiceProvider binds SourceAdapterRegistry through a `static fn (Container $app)` closure — adapters are constructed lazily inside `for()` rather than at provider boot, so adding a new format is a single-line edit and a failing adapter constructor never crashes module boot."
metrics:
  duration: "~30 minutes wall-clock (single executor)"
  completed_date: "2026-05-12"
  tasks_completed: 3
  files_created: 20
  files_modified: 2
  commits: 4
---

# Phase 1 Plan 04: ASN CSV Adapter + Ingestion Public Surface Summary

**One-liner:** Lands the `Modules\Ingestion` module's Phase-1 surface — the streaming `AsnCsvAdapter`, its empirical 20-column column map, the integer-only `AsnAmountParser` (Pitfall 1), the `HeaderSniffer` pre-parse validator, the `SourceAdapter` / `AccountResolver` public contracts, and the `SourceAdapterRegistry` — built test-first against the real anonymized ASN export and pinned in a Pest snapshot.

## What this plan delivered

### Task 1 — empirical fixture verification (no commit)

All four artifacts pinned in Wave 0 were verified:

| Path                                       | Lines | Bytes | Role                                                    |
| ------------------------------------------ | -----:| -----:| ------------------------------------------------------- |
| `tests/fixtures/asn-sample-1.csv`          |   230 | 59,487 | Gold fixture (1 header + 229 data rows)                |
| `tests/fixtures/asn-month-a.csv`           |    73 | 18,677 | February 2026 only — idempotency baseline               |
| `tests/fixtures/asn-month-a-and-b.csv`     |   144 | 37,263 | February + March 2026 — overlap test                    |
| `tests/fixtures/asn-sample-1.md`           |   104 |  5,950 | Empirical column map + anonymisation protocol           |

`file -bI tests/fixtures/asn-sample-1.csv` reports `text/csv; charset=utf-8` (the audit Markdown distinguishes "Encoding (source) us-ascii" from "Encoding (committed fixture) UTF-8" — both true). The two derived fixtures intersect (73 common rows) and the and-b file strictly extends (71 March rows on top), proving the idempotency-test slicing.

No files needed to change in Task 1 — the resume-signal pre-satisfied condition held.

### Task 2 — Ingestion Public surface + AsnAmountParser + HeaderSniffer

The Public surface follows the project's DI-only contract:

```
Public/
├── Contracts/
│   ├── SourceAdapter.php           ← public lazy-parse contract
│   └── AccountResolver.php         ← IBAN → AccountResolution
├── Services/
│   ├── HeaderSniffer.php           ← pre-parse upload-wizard guard
│   └── SourceAdapterRegistry.php   ← stable-format-id → SourceAdapter
├── Dto/
│   ├── SourceTransactionDto.php    ← typed parsed row + rawPayload (ING-08)
│   ├── SniffResult.php             ← wizard-renderable sniff outcome
│   ├── AccountResolution.php       ← abstract discriminated union
│   ├── KnownAccount.php            ← final variant — accountId
│   └── UnknownAccount.php          ← final variant — iban
└── Exceptions/
    ├── InvalidAmountException.php
    ├── UnsupportedFormatException.php
    └── SniffMismatchException.php
```

**`AsnAmountParser`** (Internal — ASN-specific):

```php
public function parseMinor(string $raw): int
{
    $normalized = str_replace(['+', ' ', "\u{A0}"], '', trim($raw));
    if (preg_match('/^(-?)(\d+)\.(\d{2})$/', $normalized, $m) !== 1) {
        throw new InvalidAmountException(...);
    }
    $sign = $m[1] === '-' ? -1 : 1;
    return $sign * ((int) $m[2] * 100 + (int) $m[3]);
}
```

Integer-only construction by design — `parseMinor('0.29')` returns exactly `29`, never the `28` that `(int)((float) '0.29' * 100)` produces on IEEE-754 (Pitfall 1). The Pest dataset exercises 11 valid + 9 invalid cases; the Pitfall-1 case is the explicit `'0.29' → 29` row.

**`HeaderSniffer`** (Public — injected into the upload wizard and the adapter):

- Validates the file extension (`.csv$/i`), reads the first 8 KB, splits the first line on `,`, asserts exactly **20** columns, and asserts the first two header cells are `Datum` and `Je rekening`.
- Rejects non-`.csv` extensions with `"That file doesn't look like a CSV. Drop in the ASN CSV export you downloaded from the ASN portal."` (UI-SPEC §Error states quote).
- Rejects wrong column counts with `"Expected 20 columns, got N. This file does not match the ASN CSV layout."`
- Rejects header signature mismatch with `"This CSV doesn't match the expected ASN column layout (header starts with 'Datum,Je rekening', got 'X,Y'). If ASN changed their export format, file an issue."`
- Returns a `SniffResult { format, delimiter, hasHeader, encoding, columnCount }` for the wizard to render.

**`SourceAdapterRegistry`**:

```php
public function for(string $format): SourceAdapter
{
    return $this->byFormat[$format] ?? throw new UnsupportedFormatException(...);
}
```

The plan's pseudo-code wired the registry inside Task 2's IngestionServiceProvider, but `Modules\Ingestion\Internal\Adapters\Asn\AsnCsvAdapter` doesn't exist until Task 3 — PHPStan flagged `class.notFound` at module boot. Resolution: the registry binding landed in Task 3 alongside the adapter class. Task 2 only registers `HeaderSniffer`. Functionally identical; the registry is still part of Task 2's Public surface.

### Task 3 — AsnCsvAdapter + AsnCsvColumnMap + snapshot test

**`AsnCsvColumnMap`** — the empirical 20-column index table:

| Index | ASN header           | Notes                                        |
| ----: | -------------------- | -------------------------------------------- |
|     0 | `Datum`              | `dd-mm-yyyy` — posted date                   |
|     1 | `Je rekening`        | own IBAN                                     |
|     2 | `Van / naar`         | counterparty IBAN (may be empty)             |
|     3 | `Naam`               | counterparty name (may be empty)             |
|   4–6 | `Adres / Postcode / Woonplaats` | counterparty address (often blanked) |
|     7 | `Valuta saldo`       | saldo currency (`EUR`)                       |
|     8 | `Saldo voor boeking` | running balance before this entry            |
|     9 | `Valuta`             | mutation currency (`EUR`)                    |
|    10 | `Bedrag bij / af`    | signed period-decimal amount                 |
|    11 | `Verwerkingsdatum`   | processing/journal date                      |
|    12 | `Valutadatum`        | value date                                   |
|    13 | `Code`               | internal transaction code (e.g. `BEA`)       |
|    14 | `Type`               | global transaction type                      |
|    15 | `Volgnummer`         | sequence number → `source_ref`               |
|    16 | `Betalingskenmerk`   | payment reference                            |
|    17 | `Omschrijving`       | free-text description                        |
|    18 | `Afschriftnummer`    | statement number (added 2026)                |
|    19 | `Categorie`          | ASN-side category label (added 2026)         |

**`AsnCsvAdapter`** — streaming Generator over the ASN export:

```php
public function parse(string $localPath, AccountResolver $accounts): Generator
{
    $this->sniffer->sniff($localPath, AsnCsvHeaderProfile::FORMAT);  // throws on bad header

    $reader = Reader::from($localPath, 'r');
    $reader->setDelimiter(',');
    $reader->setEscape('');
    $reader->setHeaderOffset(0);
    CharsetConverter::addTo($reader, 'UTF-8', 'UTF-8');

    $index = 0;
    foreach ($reader->getRecords() as $record) {
        $row = $this->normaliseRow($record);                          // → array<int,string>

        try {
            $postedAt    = $this->parseDate($row[POSTED_DATE]);
            $valueDate   = $this->parseDate($row[VALUE_DATE]);
            $amountMinor = $this->amounts->parseMinor($row[AMOUNT]);
        } catch (Throwable $e) {
            throw new InvalidAmountException("Row {$index}: " . $e->getMessage(), 0, $e);
        }

        $accounts->resolve($row[OWN_IBAN]);                            // wizard's branch point

        yield new SourceTransactionDto(...);
        $index++;
    }
}
```

Per the threat register (T-04-02, T-04-03) the adapter is a generator — large files never land in memory in one block — and it never echoes the user-supplied path back into any error message.

### Snapshot test

`expect($serialized)->toMatchSnapshot()` pins the parsed shape (formatted dates + typed fields, not the whole DTO including raw payload — that would make the snapshot 200 KB+). The snapshot landed at:

```
tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCsvAdapterTest/
└── it_matches_the_snapshot_of_the_parsed_fixture__drift_detector_.snap (~2.7k lines)
```

To regenerate intentionally: `vendor/bin/pest Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php -d --update-snapshots` (or simply delete the file and re-run — Pest creates it as `incomplete` on first absence, then asserts on every subsequent run).

### Differences from the [ASSUMED] AsnCsvHeaderProfile

The plan's pseudo-code constants were guesses. After empirical confirmation:

| Constant                | [ASSUMED]      | Actual     | Delta                                                        |
| ----------------------- | -------------- | ---------- | ------------------------------------------------------------ |
| `DELIMITER`             | `,`            | `,`        | —                                                            |
| `HAS_HEADER`            | `false`        | `true`     | the 2026 export ships a header row                           |
| `SOURCE_ENCODING`       | `windows-1252` | `UTF-8`    | bank moved to ASCII; the committed fixture is UTF-8 (with `Café`)|
| `EXPECTED_COLUMN_COUNT` | `18`           | `20`       | `Afschriftnummer` + `Categorie` added at the tail            |

The full audit lives at `tests/fixtures/asn-sample-1.md`.

## Contract test colour matrix (end of Plan 04)

| Test                                                                | Requirement   | Status                                                                                  |
| ------------------------------------------------------------------- | ------------- | --------------------------------------------------------------------------------------- |
| `tests/Contracts/NoExtImapTest`                                     | PLT-05        | GREEN (regression preserved)                                                            |
| `tests/Contracts/BoundaryArchTest`                                  | D-02, D-03    | GREEN (regression preserved)                                                            |
| `tests/Contracts/UserIdColumnArchTest`                              | FND-03        | GREEN (regression preserved)                                                            |
| `tests/Contracts/NoFloatMoneyArchTest`                              | FND-04        | GREEN (regression preserved)                                                            |
| `tests/Contracts/MoneyColumnsArchTest`                              | MC-01         | GREEN (regression preserved)                                                            |
| `tests/Contracts/IdempotencyContractTest` (×2 dataset rows)         | ING-06        | RED — by design (waits on Plan 05's `RunsImports`). DB-layer idempotency already proven via `RecordTransactionsTest::test_treats_a_re_insertion_of_the_same_canonical_as_a_duplicate` in Plan 03. |
| `Modules/Ingestion/tests/Unit/AsnAmountParserTest`                  | FND-04 + Pitfall 1 | **GREEN** (new) — 20 cases (11 valid + 9 invalid) including explicit `'0.29' → 29` row |
| `Modules/Ingestion/tests/Feature/HeaderSnifferTest`                 | ING-01        | **GREEN** (new) — 5 cases (accepts fixture, rejects extension / column-count / missing / format) |
| `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest`                    | ING-01 + ING-08 + Pitfall 5 + Pitfall 10 | **GREEN** (new) — 13 cases incl. snapshot |

Full suite at the close of Plan 04: **140 passed · 3 failed.** The 3 failures are:

1. `Tests\Contracts\IdempotencyContractTest::it produces zero new rows when the same file is imported twice` — **RED-by-design** (Plan 05 ships `Modules\Import\Public\Contracts\RunsImports`)
2. `Tests\Contracts\IdempotencyContractTest::it produces zero new rows when an overlapping period is imported` — same
3. `Tests\Feature\Auth\LoginFlowTest::it renders the calm login page on GET /login` — pre-existing Plan 02 baseline (`public/build/manifest.json` missing in this worktree; resolved by `npm install && npm run build`)

## Per-task commit log

| Task | Name                                                                | Commit    | Key files                                                                                      |
| ---- | ------------------------------------------------------------------- | --------- | ---------------------------------------------------------------------------------------------- |
| 1    | Verify Wave-0 fixture artifacts (no code change, no commit)         | —         | `tests/fixtures/asn-sample-1.csv`, `asn-sample-1.md`, `asn-month-a.csv`, `asn-month-a-and-b.csv` |
| 2    | RED — AsnAmountParser + HeaderSniffer failing tests                 | `f103da4` | `Modules/Ingestion/tests/Unit/AsnAmountParserTest.php`, `Modules/Ingestion/tests/Feature/HeaderSnifferTest.php` |
| 2    | GREEN — Ingestion public surface + AsnAmountParser + HeaderSniffer  | `f87538e` | Public/{Contracts,Services,Dto,Exceptions}/*.php, Internal/Adapters/Asn/{AsnAmountParser,AsnCsvHeaderProfile}.php, Providers/IngestionServiceProvider.php |
| 3    | RED — AsnCsvAdapter failing tests                                   | `0385b3f` | `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php`                                            |
| 3    | GREEN — AsnCsvAdapter + AsnCsvColumnMap + snapshot                  | `eecdd7f` | `Modules/Ingestion/Internal/Adapters/Asn/{AsnCsvAdapter,AsnCsvColumnMap}.php`, `Modules/Ingestion/Providers/IngestionServiceProvider.php`, `tests/.pest/snapshots/.../*.snap`, `.planning/.../01-VALIDATION.md` |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] `SOURCE_ENCODING` set to `'UTF-8'` instead of plan's `'us-ascii'`**

- **Found during:** Task 2 (initial smoke test on the fixture before writing the parser).
- **Issue:** `CharsetConverter::addTo($reader, 'us-ascii', 'utf-8')` throws `OutOfRangeException: The submitted charset us-ascii is not supported by the mbstring extension.` PHP's mb_list_encodings exposes `ASCII` but not `us-ascii` — yet `file -bI tests/fixtures/asn-sample-1.csv` reports `charset=us-ascii`. The committed fixture is genuinely UTF-8 (it contains `Café Plein` for the Pitfall-10 test) but `file` treats files with only ASCII-range bytes as us-ascii.
- **Fix:** Set `AsnCsvHeaderProfile::SOURCE_ENCODING = 'UTF-8'`. UTF-8 → UTF-8 is a safe no-op through the mbstring filter; legacy windows-1252 exports would change the constant when those land. The audit Markdown distinguishes the two encodings explicitly.
- **Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php`
- **Commit:** `f87538e`

**2. [Rule 1 — Bug] Adapter uses `Reader::from()` not deprecated `createFromPath()`**

- **Found during:** Task 3 (smoke test of the plan's pseudo-code).
- **Issue:** `Reader::createFromPath()` is deprecated as of `league/csv` 9.27.0 ("use League\\Csv\\AbstractCsv::from() instead"). The strict-rules PHPStan extension flags `#[Deprecated]` calls at level max.
- **Fix:** Used `Reader::from($localPath, 'r')` which is the documented replacement.
- **Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php`
- **Commit:** `eecdd7f`

**3. [Rule 1 — Bug] `Carbon::createFromFormat()` returns `null` on failure, not `false`**

- **Found during:** Task 3 (first PHPStan run after adapter authored).
- **Issue:** The plan's pseudo-code wrote `if ($parsed === false)`. Nesbot Carbon 3's signature is `createFromFormat(...): ?static` — failure is `null`, not `false`. PHPStan flagged `identical.alwaysFalse` for the `=== false` check and `return.type` because the method then returned `CarbonImmutable|null`.
- **Fix:** Switched to `if (! $parsed instanceof CarbonImmutable)`, which both narrows the type for PHPStan and matches Carbon's actual API.
- **Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php`
- **Commit:** `eecdd7f`

**4. [Rule 1 — Bug] `Statement::process()` dropped in favour of `Reader::getRecords()` for cleaner PHPStan inference**

- **Found during:** Task 3 (PHPStan).
- **Issue:** `(new Statement)->process($reader)` returns a `TabularDataReader` whose iterator-value type is `array<array-key, TValue>` with `TValue = mixed`. PHPStan flagged every column access as `argument.type` (`mixed given, string expected`). Wrapping the row in `array_values((array) $record)` then triggered `cast.useless` (PHPStan sees `$record` is already array) and `cast.string` on `(string) $row[...]`.
- **Fix:** Dropped Statement entirely — `foreach ($reader->getRecords() as $record)` is the canonical league/csv pattern. A new `normaliseRow(mixed $record): array<int,string>` method handles each cell explicitly (`is_string`, `is_null`, `is_scalar` branches, throw on objects). The result is statically `array<int,string>`, which threads through to `SourceTransactionDto::rawPayload` cleanly.
- **Files modified:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php`
- **Commit:** `eecdd7f`

**5. [Rule 3 — Blocker] `IngestionServiceProvider::register()` simplified in Task 2 (registry binding moved to Task 3)**

- **Found during:** Task 2 (first PHPStan run).
- **Issue:** The plan instructed binding `SourceAdapterRegistry` in Task 2 with `'asn-csv' => $app->make(AsnCsvAdapter::class)`. PHPStan flagged `class.notFound` because `AsnCsvAdapter` is created in Task 3. Even with the FQN-as-string-literal escape hatch the registry's typed constructor (`array<string, SourceAdapter>`) couldn't be satisfied at Task 2 time.
- **Fix:** Task 2 registers only `HeaderSniffer`. Task 3 — when `AsnCsvAdapter` exists — adds the `SourceAdapterRegistry` binding through a `static fn (Container $app)` closure. The public surface is functionally complete by end-of-Task-2; the registry's actual map population is appended in Task 3 alongside the adapter class.
- **Files modified:** `Modules/Ingestion/Providers/IngestionServiceProvider.php`
- **Commits:** `f87538e` (HeaderSniffer-only), `eecdd7f` (registry + adapter wiring)

**6. [Rule 2 — Missing Critical Functionality] HeaderSniffer dropped dead-code `if (HAS_HEADER)` and `=== ''` branches**

- **Found during:** Task 2 (PHPStan after first authoring).
- **Issue:** PHPStan correctly flagged the const-true branch (`if (AsnCsvHeaderProfile::HAS_HEADER)`) and the `$firstLine === ''` check (PHPStan knows `strtok()` returns `string|false`, never empty-string).
- **Fix:** Removed the dead branches. The sniffer is intentionally ASN-specific once the format dispatch fires inside the `match()`; the `HAS_HEADER` constant still flows through into `SniffResult` for the wizard to display.
- **Files modified:** `Modules/Ingestion/Public/Services/HeaderSniffer.php`
- **Commit:** `f87538e`

**7. [Rule 2 — Missing Critical Functionality] Worktree environment bootstrap (.env, sqlite, migrations)**

- **Found during:** Task 2 (first PHPStan run failed because `database/database.sqlite` didn't exist; Pest emitted `file_get_contents(.env)` warnings).
- **Issue:** The Claude-Code worktree is checked out without local development artifacts — `.env`, `database/database.sqlite`, etc. Both are gitignored. Neither blocks tests from passing, but they block Larastan from booting Laravel (which it needs to enumerate classes).
- **Fix:** `cp .env.example .env && php artisan key:generate --force && touch database/database.sqlite && php artisan migrate --force`. None of these files end up in the commit (all gitignored); they just unblock the per-task validation gates.
- **Files modified:** none committed
- **Commit:** N/A

### Notes (out of Plan 04 scope)

- **`Tests\Feature\Auth\LoginFlowTest::it renders the calm login page on GET /login`** stays RED in this worktree because `public/build/manifest.json` is gitignored and `npm install && npm run build` was not run during this plan's execution. Plan 03 SUMMARY's "Notes" already flagged this as a pre-existing Plan 02 baseline; not introduced or worsened here.
- The "Plan 04 binds…" agentic-narrative comment that Plan 02's IngestionServiceProvider carried (a "codebase-agnostic" violation flagged in Plan 03 SUMMARY's "Notes") is now naturally removed — the provider's docstring describes current state only, no plan-history references.

## Known Stubs

None. Every public surface introduced in this plan has a real implementation and at least one Pest assertion exercising it. The `SourceAdapterRegistry` map currently contains only `'asn-csv'` — that's not a stub, that's the empirical state at end-of-Phase-1.

## Threat Flags

No new surface beyond the threat model already mapped in the plan's `<threat_model>` block:

- **T-04-02** (path traversal) — adapter receives `$localPath` from upstream; never echoed back in exception messages.
- **T-04-03** (DoS via large CSV) — Generator-based streaming; `Reader::from()` does not load the whole file.
- **T-04-04** (silent wrong column map) — empirical fixture + snapshot test + column-signature sniff detect drift loudly.
- **T-04-05** (encoding mojibake) — `CharsetConverter::addTo` unconditional; Pitfall-10 test asserts a diacritic survives without `Ã` mojibake.
- **T-04-06** (future adapter skips contract) — `IdempotencyContractTest` is a Pest dataset; new adapters add rows uniformly. The `BoundaryArchTest` flags non-conforming adapter placements.
- **T-04-07** (audit trail loss) — `SourceTransactionDto::rawPayload` preserves the full 20-cell row; `sourceRowIndex` indexes back to the source line.

## Self-Check: PASSED

**Files exist (Read-tool-style sanity check):**

- `Modules/Ingestion/Public/Contracts/{SourceAdapter,AccountResolver}.php` ✓
- `Modules/Ingestion/Public/Services/{HeaderSniffer,SourceAdapterRegistry}.php` ✓
- `Modules/Ingestion/Public/Dto/{SourceTransactionDto,SniffResult,AccountResolution,KnownAccount,UnknownAccount}.php` ✓
- `Modules/Ingestion/Public/Exceptions/{InvalidAmountException,UnsupportedFormatException,SniffMismatchException}.php` ✓
- `Modules/Ingestion/Internal/Adapters/Asn/{AsnCsvAdapter,AsnCsvColumnMap,AsnCsvHeaderProfile,AsnAmountParser}.php` ✓
- `Modules/Ingestion/tests/Unit/{AsnAmountParserTest,AsnCsvAdapterTest}.php` ✓
- `Modules/Ingestion/tests/Feature/HeaderSnifferTest.php` ✓
- `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCsvAdapterTest/it_matches_the_snapshot_of_the_parsed_fixture__drift_detector_.snap` ✓

**Commits exist in `git log --oneline`:**

- `f103da4 test(01-04): add failing tests for AsnAmountParser + HeaderSniffer` ✓
- `f87538e feat(01-04): Ingestion public surface + AsnAmountParser + HeaderSniffer` ✓
- `0385b3f test(01-04): add failing tests for AsnCsvAdapter` ✓
- `eecdd7f feat(01-04): AsnCsvAdapter + AsnCsvColumnMap + snapshot test` ✓

**End-of-plan invariants:**

- `vendor/bin/pest Modules/Ingestion/tests` reports **38 passed (0 failed)** ✓
- `vendor/bin/pest` (full suite) reports **140 passed · 3 failed** — the 3 failures are the 2 RED-by-design IdempotencyContractTest rows + the pre-existing LoginFlowTest baseline ✓
- `vendor/bin/phpstan analyse Modules --memory-limit=1G` reports `[OK] No errors` at level max ✓
- `vendor/bin/pint --test` reports `passed` ✓
- DI grep over `Modules/Ingestion/Public Modules/Ingestion/Internal` for `auth(|config(|now(|abort(|view(|session(|cache(` exits 1 (no matches) ✓
- BoundaryRule clean: same-module references to `Modules\Ingestion\Internal\Adapters\Asn\AsnCsvAdapter` from `Modules\Ingestion\Providers\IngestionServiceProvider` are allowed; no cross-module Internal imports anywhere in this plan ✓
- `01-VALIDATION.md` Status: ING-01 ✅ green (adapter level), ING-08 ✅ green ✓
- All 4 fixture artifacts present at `tests/fixtures/` ✓

## Open Questions Surfaced

- **Apostrophe-wrapped Omschrijving and Categorie cells.** The 2026 ASN export emits the description and category columns as `'...'` literal strings (e.g. `'Europese incasso: ...'`, `'Vervoer'`). These are NOT standard CSV double-quote enclosures — they're literal apostrophes that survive `league/csv` parsing intact. The adapter preserves them verbatim in `rawPayload` and includes the apostrophes in the joined `description` field. If Plan 05's NormalizeStage wants clean text, it should strip a single leading + trailing `'` after combining the payment-ref and Omschrijving. Flagged here; not auto-fixed because the plan-checker may prefer to preserve fidelity (the apostrophes are a documented bank quirk and may signal something downstream).
- **`Categorie` column at index 19 contains ASN-assigned labels** (e.g. `'Vervoer'`, `'Pensioen'`, `'Overig'`). The adapter parses this into `rawPayload[19]` but does NOT map it to `categoryHint` on the DTO — Phase 1 categorization is manual-only (CAT-01/CAT-03/CAT-05) per ROADMAP, and Phase 7 will introduce merchant-memory learning. Whether to plumb ASN's hint through as a "merchant default category" is a Phase-7 design question; the data is already there in `rawPayload` if needed.
- **Volgnummer is the only reliable per-row source_ref.** Across the 229-row sample, `Volgnummer` is unique within the account (no collisions). The empirical 2026 export does NOT have empty `Volgnummer` values in this fixture, but the adapter still handles the null case (`nullIfEmpty`) per the plan's Pitfall-5 sentinel handoff design. If a future export starts emitting blank Volgnummers, the composite UNIQUE on `transactions` still catches duplicates via `counterparty_normalized` NOT NULL + FingerprintComposer's `?? ''` coercion (Plan 03 design).
- **Snapshot path lives under `tests/.pest/` not `tests/__snapshots__/`.** Pest 4's native `expect()->toMatchSnapshot()` writes to `tests/.pest/snapshots/...` instead of the spatie plugin's `tests/__snapshots__` default. Both work. The snapshot file IS committed to the repo (not gitignored). When the adapter changes intentionally, regenerate via the standard `-d --update-snapshots` flag or delete the file.
- **`Saldo voor boeking` running balance is captured in `rawPayload[8]` but not surfaced on the DTO.** Could be useful for the dashboard to cross-check balances against ASN's view. Out of Phase 1 scope; flagged for Phase 6 if balance reconciliation comes up.
