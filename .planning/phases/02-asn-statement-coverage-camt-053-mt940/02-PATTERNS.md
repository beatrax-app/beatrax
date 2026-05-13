# Phase 2: ASN Statement Coverage (CAMT.053 + MT940) - Pattern Map

**Mapped:** 2026-05-13
**Phase:** 02 — ASN Statement Coverage (CAMT.053 + MT940)
**Files analyzed:** 30 new + 8 modified
**Analogs found:** 33 / 38 (5 have NO direct analog — flagged below)

---

## File Classification

### NEW Files

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php` | adapter (parser) | streaming Generator | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` | exact (role + flow) |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php` | adapter (parser) | streaming Generator | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` | exact (role + flow) |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053HeaderProfile.php` | config / constants carrier | n/a | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` | exact |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940HeaderProfile.php` | config / constants carrier | n/a | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` | exact |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Lexer.php` | internal helper (tokenizer) | streaming Generator over `fopen()` | **NO ANALOG** — see "No Analog Found" | partial |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag61Parser.php` | internal helper (line parser) | pure function | `Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php` | role-match (small parser class) |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Tag86Parser.php` | internal helper (narrative decoder) | pure function | `Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php` | role-match (small parser class) |
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940CounterpartyCleaner.php` | internal helper (pre-normaliser) | pure function | `Modules/Ledger/Public/Services/FingerprintComposer.php` (the `normalize()` method) | role-match (string-cleaner) |
| `Modules/Ledger/Database/Migrations/2026_05_13_XXXXXX_add_enriched_from_to_transactions.php` | migration (alter column) | DDL | `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` | role-match (migration in same module/directory) |
| `Modules/Ledger/Database/Migrations/2026_05_13_XXXXXX_add_enriched_count_to_import_runs.php` | migration (alter column) | DDL | `Modules/Ledger/Database/Migrations/2026_05_12_010004_create_import_runs_table.php` | role-match |
| `Modules/Ledger/Database/Migrations/2026_05_13_XXXXXX_create_statement_summaries_table.php` | migration (create table) | DDL | `Modules/Ledger/Database/Migrations/2026_05_12_010004_create_import_runs_table.php` | exact (create table with user FK) |
| `Modules/Ledger/Database/Migrations/2026_05_13_XXXXXX_rederive_fingerprints_v3.php` | migration (data backfill, invokes console command) | DML | `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` (`DB::statement` pattern) | partial (no existing data-backfill migration in the codebase) |
| `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php` | artisan command | batch CPU + DB write | `Modules/Core/Internal/Console/InstallCommand.php` | exact (only `Console` command pattern in repo) |
| `Modules/Ledger/Public/Models/StatementSummary.php` | Eloquent model | persistence (single row per import_run) | `Modules/Ledger/Models/ImportRun.php` | exact (role + same FK/timestamps shape) |
| `Modules/Ledger/Public/Services/StatementSummaryWriter.php` | service (writes a row) | DB write | `Modules/Import/Public/Actions/ConfirmImport.php` (`->update([...])` pattern) | role-match (write-once service) |
| `Modules/Import/Public/Dto/FingerprintDisposition.php` | discriminated DTO | n/a | `Modules/Ingestion/Public/Dto/AccountResolution.php` (+ `KnownAccount` / `UnknownAccount`) | exact (discriminated-union pattern) |
| `Modules/Import/Public/Dto/PendingEnrichment.php` | DTO (value object) | n/a | `Modules/Import/Public/Dto/UnknownIban.php` | exact |
| `Modules/Import/Public/Contracts/AppliesEnrichments.php` | contract interface | n/a | `Modules/Import/Public/Contracts/ConfirmsImports.php` | exact |
| `Modules/Import/Public/Actions/ApplyEnrichments.php` | action (writes enrichment) | DB write inside transaction | `Modules/Import/Public/Actions/ConfirmImport.php` | exact (action wraps DB transaction) |
| `Modules/Ingestion/Internal/Adapters/Asn/Camt/SepaFragmentSerialiser.php` (or inline helper) | helper (struct serialiser) | pure function | **NO ANALOG** — first sub-structure of `rawPayload` in the codebase | partial |
| `Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php` | unit test (snapshot + structural) | n/a | `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php` | exact |
| `Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php` | unit test (snapshot + structural) | n/a | `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php` | exact |
| `Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php` | unit test (tokenisation) | n/a | `Modules/Ingestion/tests/Unit/AsnAmountParserTest.php` | role-match (small parser test) |
| `Modules/Ingestion/tests/Unit/AsnMt940Tag61ParserTest.php` | unit test | n/a | `Modules/Ingestion/tests/Unit/AsnAmountParserTest.php` | role-match |
| `Modules/Ingestion/tests/Unit/AsnMt940Tag86ParserTest.php` | unit test | n/a | `Modules/Ingestion/tests/Unit/AsnAmountParserTest.php` | role-match |
| `Modules/Ingestion/tests/Unit/AsnMt940CounterpartyCleanerTest.php` | unit test | n/a | `Modules/Ledger/tests/Unit/FingerprintComposerTest.php` (normalize-method tests) | role-match |
| `Modules/Ledger/tests/Unit/FingerprintComposerV3Test.php` | unit test | n/a | `Modules/Ledger/tests/Unit/FingerprintComposerTest.php` | exact |
| `Modules/Ledger/tests/Unit/FingerprintRederiveCommandTest.php` | feature test (artisan) | n/a | `Modules/Core/tests/Feature/InstallCommandTest.php` | exact |
| `Modules/Ledger/tests/Unit/StatementSummaryWriterTest.php` | unit test | n/a | `Modules/Ledger/tests/Unit/FingerprintComposerTest.php` | role-match |
| `Modules/Import/tests/Unit/FingerprintStageClassifyTest.php` | unit test | n/a | `Modules/Import/tests/Unit/NormalizeStageTest.php` | role-match (stage unit test) |
| `Modules/Import/tests/Unit/EnrichmentPersistenceTest.php` | unit test | n/a | `Modules/Ledger/tests/Feature/RecordTransactionsTest.php` | role-match |
| `Modules/Import/tests/Feature/AsnCamt053ImportTest.php` | feature test (end-to-end) | n/a | `Modules/Import/tests/Feature/AsnCsvImportTest.php` | exact |
| `Modules/Import/tests/Feature/AsnMt940ImportTest.php` | feature test (end-to-end) | n/a | `Modules/Import/tests/Feature/AsnCsvImportTest.php` | exact |
| `Modules/Import/tests/Feature/CrossFormatDedupTest.php` | feature test (idempotency) | n/a | `tests/Contracts/IdempotencyContractTest.php` | exact |
| `Modules/Import/tests/Feature/PreviewWizardEnrichedStateTest.php` | feature test (Livewire) | n/a | `Modules/Import/tests/Feature/PreviewWizardTest.php` | exact |
| `tests/fixtures/asn-camt053-sample-1.xml`, `asn-mt940-sample-1.sta`, `*.md` audit files, cross-format pair | test fixtures | n/a | `tests/fixtures/asn-sample-1.csv` + `asn-sample-1.md` | exact |
| `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest/*.snap` + `.../AsnMt940AdapterTest/*.snap` | snapshot artefacts | n/a | `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCsvAdapterTest/*.snap` | exact |

### MODIFIED Files

| Modified File | Role | Nature of Change | Reference Lines |
|---------------|------|------------------|-----------------|
| `Modules/Ingestion/Public/Services/HeaderSniffer.php` | service | extend `match()` with two new arms; add `sniffCamt053()` + `sniffMt940()` private methods | lines 51–58 (`match` dispatcher), 60–102 (`sniffAsnCsv` template) |
| `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` | service | no change to class — only the wiring in `IngestionServiceProvider` populates two new keys | n/a |
| `Modules/Ingestion/Providers/IngestionServiceProvider.php` | provider | extend `register()` closure with two new registry entries | lines 29–34 (closure body) |
| `Modules/Ledger/Public/Services/FingerprintComposer.php` | service | bump `NORMALIZATION_VERSION` 2 → 3; rewrite `compose()` tuple (drop `sourceRef`, add `bookedAt`) | lines 28–57 |
| `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` | pipeline stage | replace `isExistingFingerprint(): bool` with `classify(): FingerprintDisposition`; keep deprecated method one version | lines 27–44 |
| `Modules/Import/Internal/Pipeline/ImportPipeline.php` | pipeline orchestrator | call `classify()` instead of `isExistingFingerprint()`; emit `enriched` rows; grow return tuple from 3 to 4 keys | lines 45, 102–117, 136–141 |
| `Modules/Import/Internal/Http/Livewire/UploadWizard.php` | Livewire component | widen `mimes:` whitelist; widen `in:asn-csv` to `in:asn-csv,asn-camt053,asn-mt940`; widen `messages()` copy | lines 38–55 |
| `Modules/Import/Public/Dto/PreviewRowDto.php` | DTO | add `?array $diff` parameter | lines 14–28 |
| `Modules/Import/Public/Dto/ImportConfirmResult.php` | DTO | add `public readonly int $enriched` | lines 14–22 |
| `Modules/Import/Public/Actions/ConfirmImport.php` | action | invoke `AppliesEnrichments` after recorder; surface `enriched` count | lines 56–105 |
| `Modules/Import/Internal/Pipeline/PreviewCache.php` | service | cache grows from 2 keys to 3 (`preview`, `canonical`, `enrichments`) | lines 33–96 |
| `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` | Blade view | two new `<option>` rows | lines 17–18 |
| `Modules/Import/Resources/views/livewire/preview-wizard.blade.php` | Blade view | new `@elseif ($row->status === 'enriched')` arm + diff indicator | lines 76–82 |
| `Modules/Import/Resources/views/livewire/import-results.blade.php` | Blade view | render `enriched_count` when > 0 | (file is `import-results.blade.php`, ~10 lines) |
| `Modules/Ledger/Models/Transaction.php` | model | add `'enriched_from' => AsArrayObject::class` cast; add `enriched_from` to `$fillable` | lines 60–89 |
| `Modules/Ledger/Models/ImportRun.php` | model | add `'enriched_count'` to `$fillable` + integer cast | lines 32–55 |
| `composer.json` | dependency manifest | add `"genkgo/camt": "^2.10"` to `require` block | lines 7–17 |
| `tests/Contracts/IdempotencyContractTest.php` | dataset extension | append 2 more dataset rows (`asn-camt053` + `asn-mt940`) | lines 7–16 |

---

## Pattern Assignments

### Adapter Pattern (CAMT.053 + MT940)

**Analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php`

**Class structure** (lines 35–46 — apply verbatim, swapping CSV-isms):
```php
namespace Modules\Ingestion\Internal\Adapters\Asn;

use Generator;
// ... (format-specific imports)
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;

final class AsnCsvAdapter implements SourceAdapter
{
    public function __construct(
        private readonly AsnAmountParser $amounts,
        private readonly HeaderSniffer $sniffer,
    ) {}

    public function format(): string
    {
        return AsnCsvHeaderProfile::FORMAT;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
```

**Sniff-first guard** (line 51 — both new adapters must replicate):
```php
$this->sniffer->sniff($localPath, AsnCsvHeaderProfile::FORMAT);
```

**Generator-based row yielding with monotone index** (lines 59–106):
```php
$index = 0;
foreach ($reader->getRecords() as $record) {
    // ... per-row parse + dto build ...
    yield new SourceTransactionDto(
        bookedAt: $postedAt->startOfDay(),
        postedAt: $postedAt,
        valueDate: $valueDate,
        ownIban: $ownIban,
        counterpartyIban: $this->nullIfEmpty($row[AsnCsvColumnMap::COUNTERPARTY_IBAN]),
        counterpartyName: $this->nullIfEmpty(trim($row[AsnCsvColumnMap::COUNTERPARTY_NAME])),
        currency: $currency,
        amountMinor: $amountMinor,
        sourceRef: $this->nullIfEmpty($row[AsnCsvColumnMap::SEQUENCE_NUMBER]),
        description: $this->joinDescription($row),
        rawPayload: $row,
        sourceRowIndex: $index,
    );
    $index++;
}
```

**Per-row error wrapping** (lines 66–76 — wrap library exceptions as `InvalidAmountException` with row index):
```php
try {
    $postedAt = $this->parseDate($row[AsnCsvColumnMap::POSTED_DATE]);
    // ...
} catch (Throwable $e) {
    throw new InvalidAmountException(
        sprintf('Row %d: %s', $index, $e->getMessage()),
        0,
        $e,
    );
}
```

**AccountResolver call point** (lines 78–83 — must run once per statement / IBAN):
```php
$ownIban = $row[AsnCsvColumnMap::OWN_IBAN];
$accounts->resolve($ownIban);
```

**For CAMT specifically** — RESEARCH.md §"AsnCamt053Adapter skeleton" (02-RESEARCH.md lines 497–599) supplies the per-Entry / per-TxDtls iteration; the SAME shape (final class, constructor DI, `format()` returns profile constant, `parse()` is a Generator) is mandatory.

**For MT940 specifically** — RESEARCH.md §"AsnMt940Adapter skeleton" (02-RESEARCH.md lines 636–714) supplies the state-machine that pairs `:61:` with the optional following `:86:`. The adapter delegates tokenisation to `AsnMt940Lexer` (Generator), parsing of one tag-pair to `AsnMt940Tag61Parser` + `AsnMt940Tag86Parser`, and counterparty cleanup to `AsnMt940CounterpartyCleaner`. Constructor DI lists all of these explicitly (lines 649–656 of RESEARCH.md).

---

### `*HeaderProfile` Constants Carrier

**Analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` (whole file, 41 lines)

**Class shape** (lines 15–40 — verbatim except for the constants themselves):
```php
namespace Modules\Ingestion\Internal\Adapters\Asn;

final class AsnCsvHeaderProfile
{
    public const FORMAT = 'asn-csv';
    public const DELIMITER = ',';
    public const HAS_HEADER = true;
    public const SOURCE_ENCODING = 'UTF-8';
    public const EXPECTED_COLUMN_COUNT = 20;
    public const HEADER_SIGNATURE = ['Datum', 'Je rekening'];
    public const DATE_FORMAT = 'd-m-Y';
}
```

**For `AsnCamt053HeaderProfile`** — CONSTANTS expected (per RESEARCH.md §"File-Format Signatures" lines 370–410):
- `FORMAT = 'asn-camt053'`
- `XML_NAMESPACES` (list of supported sub-version URIs)
- `FILE_EXTENSIONS` (`['xml']`)

**For `AsnMt940HeaderProfile`** — CONSTANTS expected:
- `FORMAT = 'asn-mt940'`
- `FILE_EXTENSIONS` (`['sta', 'mt940', '940', 'txt']`)
- `HEADER_SIGNATURE_REGEX` (`/^:20:/` or `/^\{1:F01/`)

The docblock pattern (lines 7–14) — describe the file you reflect, name the audit Markdown next to the fixture — applies to both new profiles.

---

### `HeaderSniffer` Extension

**Analog (the same file being modified):** `Modules/Ingestion/Public/Services/HeaderSniffer.php`

**Dispatcher pattern** (lines 51–58 — add two arms):
```php
return match ($declaredFormat) {
    AsnCsvHeaderProfile::FORMAT => $this->sniffAsnCsv($localPath, $head),
    // ★ NEW ↓
    AsnCamt053HeaderProfile::FORMAT => $this->sniffAsnCamt053($localPath, $head),
    AsnMt940HeaderProfile::FORMAT => $this->sniffAsnMt940($localPath, $head),
    default => throw new SniffMismatchException(sprintf(
        'Unsupported sniff target: %s',
        $declaredFormat,
    )),
};
```

**Per-format private method template** (lines 60–102, `sniffAsnCsv` — copy this structure for both new methods):
```php
private function sniffAsnCsv(string $path, string $head): SniffResult
{
    // (1) Extension check
    if (preg_match('/\.csv$/i', $path) !== 1) {
        throw new SniffMismatchException(
            "That file doesn't look like a CSV. Drop in the ASN CSV export ..."
        );
    }

    // (2) Content signature check on $head (first 8 KB, BOM-stripped)
    // ...

    return new SniffResult(
        format: AsnCsvHeaderProfile::FORMAT,
        delimiter: $delim,
        hasHeader: AsnCsvHeaderProfile::HAS_HEADER,
        encoding: AsnCsvHeaderProfile::SOURCE_ENCODING,
        columnCount: count($columns),
    );
}
```

**Error message style** — user-facing strings (lines 64–66, 78–82, 88–92): drop-in-the-file-from-the-bank tone; mention the portal name when relevant.

**For CAMT** — RESEARCH.md §"CAMT.053 signature" (lines 374–410) shows: `.xml` extension, then `simplexml_load_string()` (or equivalent), then namespace match against `AsnCamt053HeaderProfile::XML_NAMESPACES`.

**For MT940** — RESEARCH.md §"MT940 signature" (lines 411–468) shows: extension whitelist, then `preg_match('/^:20:/', $firstLine)` or `preg_match('/^\{1:F01/', $head)`.

---

### `SourceAdapterRegistry` Wiring

**Analog (file being modified):** `Modules/Ingestion/Providers/IngestionServiceProvider.php`

**Registry binding closure** (lines 29–34 — extend the map):
```php
$this->app->singleton(
    SourceAdapterRegistry::class,
    static fn (Container $app): SourceAdapterRegistry => new SourceAdapterRegistry([
        'asn-csv' => $app->make(AsnCsvAdapter::class),
        // ★ NEW ↓
        'asn-camt053' => $app->make(AsnCamt053Adapter::class),
        'asn-mt940' => $app->make(AsnMt940Adapter::class),
    ]),
);
```

**Important:** `SourceAdapterRegistry` itself (`Modules/Ingestion/Public/Services/SourceAdapterRegistry.php`, lines 19–42) is read-only — adding a new format is a single line in the provider closure, never a change to the registry class.

---

### `FingerprintComposer` v3 Bump

**Analog (file being modified):** `Modules/Ledger/Public/Services/FingerprintComposer.php`

**Doc-comment versioning narrative** (lines 28–42) — extend the existing block with a v3 paragraph; the constant has already advertised version-bump behaviour:
```php
public const NORMALIZATION_VERSION = 2;   // ★ bump to 3
```

**Tuple composition** (lines 44–57 — replace body):

Current v2 body:
```php
$tuple = implode('|', [
    (string) ($tx->userId ?? 0),
    (string) $tx->accountId,
    $tx->postedAt->toDateString(),
    (string) $tx->amountMinor,
    $tx->currency,
    $tx->counterpartyNormalized,
    $tx->sourceRef ?? '',
]);

return hash('sha256', $tuple);
```

v3 target body (per CONTEXT.md D-21/D-22 + RESEARCH.md lines 1469–1491):
```php
$tuple = implode('|', [
    (string) ($tx->userId ?? 0),
    (string) $tx->accountId,
    $tx->postedAt->toDateString(),
    $tx->bookedAt->toDateTimeString(),  // ★ NEW in v3
    (string) $tx->amountMinor,
    $tx->currency,
    $tx->counterpartyNormalized,
    // source_ref REMOVED in v3
]);

return hash('sha256', $tuple);
```

**`normalize()` body** (lines 59–67) — **unchanged** (D-22 keeps the counterparty normaliser intact).

**`version()` accessor** (lines 69–72) — unchanged.

---

### `FingerprintStage::classify()` Replacement

**Analog (file being modified):** `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php`

**Current shape** (lines 27–44):
```php
final class FingerprintStage
{
    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly DatabaseManager $db,
    ) {}

    public function isExistingFingerprint(CanonicalTransaction $tx, User $user): bool
    {
        $fingerprint = $this->fingerprints->compose($tx);

        return $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->exists();
    }
}
```

**Target shape** (RESEARCH.md §"FingerprintStage proposed shape" lines 738–788 — verbatim port). Key invariants from the existing v1 method that must survive:
- **`->where('user_id', $user->id)` scoping** (the doc-block at lines 12–26 explains why — never lean on BelongsToUser's global scope in CLI/queue contexts)
- **`DatabaseManager` injection** (line 31 — not facades, not Eloquent Builder)
- **Composer + DB are the only dependencies** — no other collaborators

Notably, RESEARCH.md (line 845) directs: keep `isExistingFingerprint` for one-version transition, mark deprecated, remove in Phase 3.

---

### `FingerprintDisposition` Discriminated DTO

**Analog:** `Modules/Ingestion/Public/Dto/AccountResolution.php` + `KnownAccount.php` + `UnknownAccount.php` (3 files together — they're the codebase's single example of a discriminated-union DTO).

**Abstract base** (`AccountResolution.php` lines 19–30 — verbatim shape):
```php
namespace Modules\Ingestion\Public\Dto;

use Spatie\LaravelData\Data;

abstract class AccountResolution extends Data
{
    public static function known(int $accountId): KnownAccount
    {
        return new KnownAccount($accountId);
    }

    public static function unknown(string $iban): UnknownAccount
    {
        return new UnknownAccount($iban);
    }
}
```

**Final variants** (`KnownAccount.php` lines 12–15, `UnknownAccount.php` lines 12–15 — verbatim shape):
```php
final class KnownAccount extends AccountResolution
{
    public function __construct(public readonly int $accountId) {}
}
```

**For `FingerprintDisposition`** — applying the pattern: abstract `FingerprintDisposition extends Data` with named-constructors `newRow()`, `duplicate()`, `enriched(int $existingId, ?string $fromSourceRef, string $toSourceRef)`. Each returns a final variant class (`NewRowDisposition`, `DuplicateDisposition`, `EnrichedDisposition`). PER CONTEXT.md the file is "likely under `Modules/Import/Public/Dto/`" — verified Import already houses `PreviewRowDto`, `UnknownIban`, `ImportPreviewResult`, `ImportConfirmResult`. **Place there.**

Variant location options: either one file per variant (matches KnownAccount/UnknownAccount precedent — recommended) or a single discriminated file. The Phase 1 precedent splits them.

---

### `PendingEnrichment` DTO

**Analog:** `Modules/Import/Public/Dto/UnknownIban.php` (entire file, 21 lines)

**Shape** (lines 14–20 — exact copy):
```php
namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

final class UnknownIban extends Data
{
    public function __construct(
        public readonly string $iban,
        public readonly ?string $seenCounterpartyName,
    ) {}
}
```

**For `PendingEnrichment`** — same `final class … extends Data` + readonly-promoted constructor + module-local `Public/Dto/` namespace. Fields per RESEARCH.md §"ImportPipeline::preview() changes" lines 816–822:
```php
final class PendingEnrichment extends Data
{
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly string $newSourceRef,
        public readonly int $importRunId,
        public readonly string $sourceFormat,
    ) {}
}
```

---

### `AppliesEnrichments` Contract

**Analog:** `Modules/Import/Public/Contracts/ConfirmsImports.php` (small contract interface) — paired with its implementing action `Modules/Import/Public/Actions/ConfirmImport.php`.

**Contract style** (mirror: `final class ConfirmImport implements ConfirmsImports`, single `__invoke` method). The new contract similarly defines one method:
```php
namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\PendingEnrichment;

interface AppliesEnrichments
{
    /**
     * @param list<PendingEnrichment> $enrichments
     * @return int Number of rows actually enriched (race-condition skips excluded)
     */
    public function __invoke(array $enrichments, User $user): int;
}
```

---

### `ApplyEnrichments` Action

**Analog:** `Modules/Import/Public/Actions/ConfirmImport.php`

**Action template** (lines 27–48 — class header + DI + `__invoke` pattern):
```php
final class ConfirmImport implements ConfirmsImports
{
    public function __construct(
        private readonly RecordsTransactions $recorder,
        private readonly PreviewCache $cache,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $importRunId, User $user): ImportConfirmResult
    {
        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();
        // ...
    }
}
```

**DB transaction usage pattern** — RESEARCH.md §"enriched_from append on UPDATE" (lines 1493–1531) supplies the exact transaction-wrapped `lockForUpdate` + defence-in-depth `$tx->source_ref === $newSourceRef` short-circuit body. Pattern of injecting `DatabaseManager` rather than facade (see FingerprintStage line 31, ConfirmImport.php's container-resolved `Clock` line 33).

---

### `StatementSummary` Eloquent Model

**Analog:** `Modules/Ledger/Models/ImportRun.php` (entire file, 58 lines)

**Class shape** (verbatim — lines 28–57):
```php
namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property int $id
 * @property int|null $user_id
 * ...
 */
final class ImportRun extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'source_format',
        // ...
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'inserted_count' => 'integer',
            // ...
        ];
    }
}
```

**Key conventions to copy:**
- `final class` (line 28)
- `use BelongsToUser` (line 30) — applies the user scope concern from Core
- `@property int|null $user_id` in the docblock (line 19)
- `protected $fillable = [...]` as a list (lines 33–44)
- `casts()` METHOD (not property) returning `array<string, string>` (lines 47–56)
- `'immutable_datetime'` / `'immutable_date'` for date columns
- Integer casts for unsigned-int columns

**Note:** Per CONTEXT.md the new file lands at `Modules/Ledger/Public/Models/StatementSummary.php` — **but every existing Ledger model lives at `Modules/Ledger/Models/`** (Account, Category, Currency, ImportRun, Transaction). This is a discovery item for the planner: the existing convention is `Modules/Ledger/Models/` (no `Public/` segment). Either the new file follows the established `Modules/Ledger/Models/` location, or the planner explicitly justifies moving toward `Public/Models/`. Recommendation: match the existing convention.

---

### Migration: Add Column

**Analog:** `Modules/Ledger/Database/Migrations/2026_05_12_010004_create_import_runs_table.php` (entire file, 35 lines — closest match for column-add pattern; the codebase has no pure ALTER migration yet)

**File header** (lines 1–9 — verbatim):
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
```

**`up()` body** (lines 13–28 — closest pattern; for ALTER use `Schema::table` instead of `Schema::create`):
```php
public function up(): void
{
    Schema::create('import_runs', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        // ...
    });
}
```

**For the `enriched_from` column add migration** — per RESEARCH.md §"enriched_from column on transactions" (lines 964–1005):
```php
Schema::table('transactions', function (Blueprint $table): void {
    $table->json('enriched_from')->nullable()->after('source_ref');
});
```

**`down()` body** (line 31 — pattern is `Schema::dropIfExists` for create, `Schema::table(... drop)` for column-remove). For the new column-add migration:
```php
public function down(): void
{
    Schema::table('transactions', function (Blueprint $table): void {
        $table->dropColumn('enriched_from');
    });
}
```

**Note on facades:** Migrations are the **one place in the codebase that uses `Illuminate\Support\Facades\Schema`** — `tests/Contracts/BoundaryArchTest.php` line 28-30 disallows `Illuminate\\Support\\Facades` *inside `Modules`* but migrations sit at the same path (`Modules/Ledger/Database/Migrations/`) and have been authorised in Phase 1 (every existing migration uses `Schema::`). This is the canonical exception — apply it identically.

---

### Migration: Create Table

**Analog:** `Modules/Ledger/Database/Migrations/2026_05_12_010002_create_accounts_table.php` (entire file, 37 lines — closest match for a small create-table with FK + UNIQUE)

**Full template** (verbatim — adapt column list per RESEARCH.md §"statement_summaries table" lines 1007–1035):
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            // ...
            $table->timestamps();

            $table->index(['user_id', 'kind']);
            $table->unique(['user_id', 'iban']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
```

**Key conventions to copy:**
- `static function (Blueprint $table): void` — closures are `static` and typed
- `$table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete()` — every domain table replicates this FK (multi-user readiness per CONTEXT.md / CLAUDE.md)
- `$table->timestamps()` — present on every domain table
- UNIQUE / INDEX last (after `timestamps()`)
- `down()` does `Schema::dropIfExists` only — no manual cleanup

---

### Artisan Console Command (Fingerprint Re-derive)

**Analog:** `Modules/Core/Internal/Console/InstallCommand.php` (208 lines — the ONLY artisan command in the codebase)

**Class header + signature + DI** (lines 24–70):
```php
namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
// ...

final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'diederik:install
        {--email= : Email for the single-user account}
        {--password= : Password for the single-user account}
        {--period-start-day=1 : Period start day (1-28, 1 = calendar month, 25 = salary cycle)}';

    /** @var string */
    protected $description = 'Idempotent first-run setup: ...';

    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
        private readonly DatabaseManager $db,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // ...
        return self::SUCCESS;  // or self::FAILURE
    }
}
```

**Key conventions to copy:**
- `final class … extends Command` (line 24)
- `/** @var string */` annotation on both `$signature` and `$description` (lines 26, 32 — required for Larastan level 10)
- Constructor DI of `DatabaseManager` (line 67) + any other collaborator; **call `parent::__construct();`** (line 69)
- `handle(): int` returns `self::SUCCESS` / `self::FAILURE`
- Use `$this->info(...)` / `$this->error(...)` for output (lines 81, 110, 138)
- File location: `Modules/<Module>/Internal/Console/` — InstallCommand precedent is Internal namespace

**For `RederiveFingerprintsCommand`** — RESEARCH.md §"Collision pre-check" (lines 866–940) supplies the full body. Constructor DI must include `FingerprintComposer` and `DatabaseManager`. Per CONTEXT.md note: "could also live under `Modules/Ledger/Console/Commands/` — pattern-mapper picks the right one". **Recommendation: `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php`** because (1) the only existing artisan command (`InstallCommand`) lives at `<Module>/Internal/Console/`, (2) Ledger owns the table being mutated, and (3) the command is an implementation detail of the v3 migration — not a public API.

**Service-provider registration** — `Modules/Core/Providers/CoreServiceProvider.php` registers `InstallCommand` (verify). Apply the same registration pattern in `LedgerServiceProvider`.

---

### Console Command Test

**Analog:** `Modules/Core/tests/Feature/InstallCommandTest.php` (the only command test in the repo)

**Test idioms** (lines 10–46 — copy verbatim):
```php
it('creates User id=1 on a fresh install', function (): void {
    Event::fake([UserInstalled::class]);

    $this->artisan('diederik:install', [
        '--email' => 'wessel@example.com',
        '--password' => 'opensesame',
    ])->assertSuccessful();

    $user = User::find(1);
    expect($user)->not->toBeNull();
});
```

**Key patterns:**
- `$this->artisan('command:name', ['--flag' => 'value'])` (line 13)
- `->assertSuccessful()` / `->assertFailed()` (line 17, 60)
- `->expectsOutputToContain('substring')` (line 59) — useful for the "ABORTED — N collisions detected" assertion

---

### Adapter Unit Test (Snapshot + Structural)

**Analog:** `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php` (245 lines)

**Pest `beforeEach()` setup** (lines 16–31):
```php
beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        /** @var array<int, string> */
        public array $askedFor = [];

        public function resolve(string $iban): AccountResolution
        {
            $this->askedFor[] = $iban;
            return AccountResolution::unknown($iban);
        }
    };

    $this->adapter = $this->app->make(AsnCsvAdapter::class);
});
```

**Snapshot pattern** (lines 211–232 — apply verbatim, swapping fixture path + DTO projection):
```php
it('matches the snapshot of the parsed fixture (drift detector)', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    $serialized = array_map(static fn (SourceTransactionDto $d): array => [
        'postedAt' => $d->postedAt->toDateString(),
        'valueDate' => $d->valueDate->toDateString(),
        'ownIban' => $d->ownIban,
        'counterpartyIban' => $d->counterpartyIban,
        'counterpartyName' => $d->counterpartyName,
        'currency' => $d->currency,
        'amountMinor' => $d->amountMinor,
        'sourceRef' => $d->sourceRef,
        'description' => $d->description,
        'sourceRowIndex' => $d->sourceRowIndex,
    ], $dtos);

    expect($serialized)->toMatchSnapshot();
});
```

**Registry test** (lines 234–244):
```php
it('registers under the asn-csv key in the SourceAdapterRegistry', function (): void {
    /** @var SourceAdapterRegistry $registry */
    $registry = $this->app->make(SourceAdapterRegistry::class);

    $adapter = $registry->for('asn-csv');
    expect($adapter)->toBeInstanceOf(AsnCsvAdapter::class);
});
```

**Sniff-rejection test** (lines 181–195):
```php
it('rejects a file that fails the header sniffer before reading any data row', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'asn-wrong-').'.csv';
    file_put_contents($tmp, "a,b,c\n1,2,3\n");

    try {
        expect(function () use ($tmp): void {
            iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);
        })->toThrow(SniffMismatchException::class);
    } finally {
        @unlink($tmp);
    }
});
```

**Snapshot file location:** `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest/it_matches_the_snapshot_of_the_parsed_fixture__drift_detector_.snap`. The snapshot file IS committed (verified — see `tests/.pest/snapshots/` exists). Pest 4's native `expect()->toMatchSnapshot()` writes to `tests/.pest/snapshots/...`, NOT the Spatie default `tests/__snapshots__/`. Phase 1 SUMMARY (01-04-SUMMARY lines 226–233) confirms this.

---

### Fingerprint Composer Test

**Analog:** `Modules/Ledger/tests/Unit/FingerprintComposerTest.php` (entire file, 74 lines)

**Test idioms to mirror in `FingerprintComposerV3Test`:**

Stable-hash test (lines 8–18):
```php
it('produces a stable 64-hex SHA-256 for identical inputs', function (): void {
    $composer = new FingerprintComposer;
    $tx = $this->canonical();
    expect($composer->compose($tx))->toMatch('/^[0-9a-f]{64}$/');
});
```

Tuple-element sensitivity dataset (lines 20–35) — **MUST be updated for v3**: drop the `'sourceRef'` row, add `'bookedAt' => ['bookedAt', CarbonImmutable::parse('2026-06-03 14:00:00')]`.

Version assertion (lines 46–48) — change to:
```php
it('exposes NORMALIZATION_VERSION as 3', function (): void {
    expect((new FingerprintComposer)->version())->toBe(3);
});
```

**Helper:** `$this->canonical(...)` is provided by `Modules/Ledger/tests/TestCase.php` or `Modules/Ledger/tests/Pest.php` — verify and extend if a new factory shape is needed.

---

### End-to-End Import Feature Test

**Analog:** `Modules/Import/tests/Feature/AsnCsvImportTest.php` — the load-bearing end-to-end test pattern (see `Modules/Import/tests/Feature/PreviewWizardTest.php` for the Livewire-specific surface). Read both for the full template.

**Idempotency Contract Test extension** — `tests/Contracts/IdempotencyContractTest.php` (50 lines) is **already a Pest dataset** designed for new adapters to append rows. Lines 7–16:
```php
dataset('idempotent_adapters', [
    'asn-csv' => [
        'adapterFormat' => 'asn-csv',
        'fixture' => __DIR__.'/../fixtures/asn-sample-1.csv',
        'overlapBase' => __DIR__.'/../fixtures/asn-month-a.csv',
        'overlapNext' => __DIR__.'/../fixtures/asn-month-a-and-b.csv',
    ],
    // Future adapters (CAMT.053, MT940, ICS, PayPal, …) append rows here.
]);
```

**Phase 2 additions:** append `'asn-camt053' => [...]` and `'asn-mt940' => [...]` rows pointing to the new fixtures. The test body (lines 18–49) is format-agnostic and re-runs automatically. CONTEXT.md flags 4 dataset rows (2 each for same-file + overlap-period) — verify against this existing test structure.

---

### `UploadWizard` Livewire Component

**Analog (file being modified):** `Modules/Import/Internal/Http/Livewire/UploadWizard.php` (104 lines)

**Validator** (lines 38–44 — both `mimes` and `in` need extending):
```php
public function rules(): array
{
    return [
        'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        'sourceFormat' => ['required', 'in:asn-csv'],
    ];
}
```

Per RESEARCH.md lines 1058–1064 the v2 rules become:
```php
'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xml,sta,mt940,940'],
'sourceFormat' => ['required', 'in:asn-csv,asn-camt053,asn-mt940'],
```

**Messages** (lines 49–55) — extend `file.mimes` copy per RESEARCH.md line 1072.

**Method-level DI** (lines 57–83) — keep. The Phase 1 decision (locked in 01-05-SUMMARY decisions block) is that Livewire components inject collaborators per-method, never via property/constructor.

**Filename sanitisation** (lines 96–102) — note this still hardcodes `.csv` (`return ... .'.csv';`). Phase 2 must extend: when `sourceFormat === 'asn-camt053'` use `.xml`, when `asn-mt940` use `.sta` (or preserve the original extension). This is a planner discovery item — the current method silently turns every upload into a `.csv` filename suffix.

---

### `PreviewRowDto` Extension

**Analog (file being modified):** `Modules/Import/Public/Dto/PreviewRowDto.php` (28 lines)

**Current shape** (lines 14–28):
```php
final class PreviewRowDto extends Data
{
    public function __construct(
        public readonly int $rowIndex,
        /** 'new' | 'duplicate' | 'error' */
        public readonly string $status,
        public readonly ?int $accountId,
        public readonly ?string $bookedAt,
        public readonly ?string $counterpartyName,
        public readonly ?string $categoryName,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $error,
    ) {}
}
```

**Target shape** (per RESEARCH.md lines 1080–1096 — add new optional `$diff` field, update enum docstring):
```php
public function __construct(
    public readonly int $rowIndex,
    /** 'new' | 'duplicate' | 'error' | 'enriched' */                      // ★ EXTENDED
    public readonly string $status,
    // ... existing fields unchanged ...
    public readonly ?string $error,
    /** @var array<string, array{from: ?string, to: string}>|null  */
    public readonly ?array $diff = null,                                   // ★ NEW
) {}
```

**Important:** the file is verified at this path (CONTEXT.md note: "name verified against Phase 1 shipped file"). Confirmed present.

---

### `ImportConfirmResult` Extension

**Analog (file being modified):** `Modules/Import/Public/Dto/ImportConfirmResult.php` (23 lines)

**Add field** to lines 16–21 — append:
```php
public readonly int $enriched,
```

Field comes between `$duplicates` and `$errors` per RESEARCH.md §"Results summary" line 1124.

---

### Blade View: Preview Wizard (Enriched State)

**Analog (file being modified):** `Modules/Import/Resources/views/livewire/preview-wizard.blade.php`

**Status badge switch** (lines 75–82 — extend with new arm BETWEEN `duplicate` and `else` for the ERROR fallback):
```blade
@if ($row->status === 'new')
    <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20" title="Will be added to your ledger.">New</span>
@elseif ($row->status === 'duplicate')
    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20" title="Already imported — will be skipped.">Duplicate</span>
@else
    <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-600/20" title="{{ $row->error }}">Error</span>
@endif
```

**Target** (per RESEARCH.md lines 1100–1113):
```blade
@elseif ($row->status === 'enriched')
    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20"
          title="Existing row will be updated with a stronger source reference.">Enriched</span>
    @if ($row->diff && isset($row->diff['source_ref']))
        <div class="mt-1 text-xs text-slate-500 font-mono">
            source_ref:
            <span class="text-slate-400">{{ $row->diff['source_ref']['from'] ?? '∅' }}</span>
            →
            <span class="text-sky-700">{{ $row->diff['source_ref']['to'] }}</span>
        </div>
    @endif
```

**Palette continuity** — `bg-sky-50` / `text-sky-700` / `ring-sky-600/20` matches the established `emerald` (NEW) / `amber` (DUPLICATE) / `rose` (ERROR) family. RESEARCH.md notes (line 1115) the calm-aesthetic compatibility.

---

### Blade View: Upload Wizard (Dropdown Options)

**Analog (file being modified):** `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` lines 10–22

**Current dropdown** (lines 17–18):
```blade
<select id="sourceFormat" name="sourceFormat" wire:model="sourceFormat" class="...">
    <option value="asn-csv">ASN CSV</option>
</select>
```

**Target** — add two `<option>` rows:
```blade
    <option value="asn-csv">ASN CSV</option>
    <option value="asn-camt053">ASN CAMT.053 (XML)</option>
    <option value="asn-mt940">ASN MT940</option>
```

The `accept=".csv"` attribute on the file input (line 31) must also widen to `accept=".csv,.xml,.sta,.mt940,.940,.txt"`.

---

## Shared Patterns

### Constructor DI (no facades, no helpers)

**Source:** Every existing service / action / adapter in the codebase. Reference: `Modules/Import/Public/Actions/RunImport.php` lines 46–52, `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` lines 29–32, `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` lines 37–40.

**Apply to:** Every new file in this phase except migrations + Blade views + tests.

**Pattern:**
```php
public function __construct(
    private readonly Dependency $name,
    // ...
) {}
```

**Never:** `auth()`, `Auth::user()`, `app()`, `config()`, `now()`, `cache()`, `event()`. CLAUDE.md "feedback_laravel_di_only" is enforced by `tests/Contracts/BoundaryArchTest.php` lines 28–30 (`Illuminate\Support\Facades` banned in `Modules`) and a Larastan strict rule.

**Exception zones (verified):** Migrations may use `Illuminate\Support\Facades\Schema` (Phase 1 precedent — every existing migration does this). Blade views may use the `auth()` helper inside `@auth` directives. No new exceptions in Phase 2.

---

### Spatie\LaravelData\Data for all DTOs

**Source:** Every existing DTO. Reference: `Modules/Import/Public/Dto/PreviewRowDto.php` line 14, `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` line 21.

**Apply to:** `FingerprintDisposition` (+ variants), `PendingEnrichment`.

**Pattern:**
```php
use Spatie\LaravelData\Data;

final class FooDto extends Data
{
    public function __construct(
        public readonly Type $field,
    ) {}
}
```

**Discriminated unions** use `abstract class Foo extends Data` with `final class FooVariant extends Foo` (see AccountResolution + KnownAccount/UnknownAccount).

---

### Final classes + Strict Types

**Source:** Every PHP file in `Modules/` begins with `<?php\n\ndeclare(strict_types=1);`. Every domain class is `final`. Reference: `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` lines 1–3, 35.

**Apply to:** Every new PHP file in this phase.

---

### `final` Modifier + `readonly` Promotion

**Source:** Every Phase 1 DTO and service. Reference: `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` lines 21–39 (readonly-promoted constructor on a final class).

**Apply to:** All new DTOs and Models. Eloquent models use `final class` too (see Account, ImportRun, Transaction).

---

### User Scoping (multi-user readiness — FND-03)

**Source:** Every domain table has `user_id` from migration day one. Reference: `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` line 16, every model uses `use BelongsToUser;` trait.

**Apply to:** `statement_summaries` table migration + `StatementSummary` model.

**Pattern (migration):**
```php
$table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
```
*Note: `->nullable()` only here because Core may seed system-level rows; domain tables in Phase 1 follow this exact form.*

**Pattern (model):**
```php
use Modules\Core\Public\Concerns\BelongsToUser;

final class StatementSummary extends Model
{
    use BelongsToUser;
    // ...
}
```

**Enforced by:** `tests/Contracts/UserIdColumnArchTest.php` (verified present at `tests/Contracts/`).

---

### Integer-Only Money (FND-04 / Pitfall 1)

**Source:** `Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php` lines 28–44 (integer-only regex + arithmetic). CLAUDE.md flags this as a gate.

**Apply to:**
- MT940 adapter — reuse `AsnAmountParser` (per RESEARCH.md line 723: "MT940 reuses it (after `,` → `.`)")
- CAMT adapter — bypass `AsnAmountParser` because `moneyphp/money` already returns minor as string; coerce via `(int) $money->getAmount()` (RESEARCH.md lines 558, 723)
- Both MT940 and CAMT migrations have ZERO float arithmetic in any backfill code.

**Enforced by:** `tests/Contracts/NoFloatMoneyArchTest.php`.

---

### Snapshot Test Location

**Source:** Phase 1 snapshot at `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCsvAdapterTest/it_matches_the_snapshot_of_the_parsed_fixture__drift_detector_.snap`. Pest 4 native, NOT `tests/__snapshots__/`. Confirmed by directory listing.

**Apply to:** Both new adapter tests will land snapshots at:
- `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest/...`
- `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnMt940AdapterTest/...`

**Snapshot is committed** (Phase 1 SUMMARY confirms; .gitignore does not list `.pest/`).

---

### Fixture Location + Audit Markdown

**Source:** `tests/fixtures/asn-sample-1.csv` + `tests/fixtures/asn-sample-1.md` (audit doc covering anonymisation protocol + the empirical structural notes).

**Apply to:** Every new fixture set in Phase 2:
- `tests/fixtures/asn-camt053-sample-1.xml` + `tests/fixtures/asn-camt053-sample-1.md`
- `tests/fixtures/asn-mt940-sample-1.sta` + `tests/fixtures/asn-mt940-sample-1.md`
- Cross-format pair under `tests/fixtures/asn-cross-format/february.csv` + `february.camt053.xml`

**Audit markdown protocol:** mirror `tests/fixtures/asn-sample-1.md` — anonymise counterparty names (public-domain merchant names), anonymise counterparty IBANs (synthetic-but-checksum-valid), pin own IBAN to a single fixed test value. Statement balances + dates + amounts stay real.

---

### Module Boundary Discipline (D-02 / D-03)

**Source:** `tests/Contracts/BoundaryArchTest.php` lines 16–22 enforces `Modules\<Module>\Internal` is only imported within the same module.

**Apply to:**
- New adapters MUST live under `Modules/Ingestion/Internal/Adapters/Asn/`
- MT940 helpers (Lexer, Tag61Parser, Tag86Parser, CounterpartyCleaner) MUST also live under `Internal/Adapters/Asn/` (the adapter is the public surface)
- `FingerprintDisposition` MUST live under `Modules/Import/Public/Dto/` because both `ImportPipeline` (Internal) AND `FingerprintStage` (Internal) consume it, AND tests in `Modules/Import/tests/Unit/` need to construct it
- `RederiveFingerprintsCommand` MUST live under `Modules/Ledger/Internal/Console/` (matches `Modules/Core/Internal/Console/InstallCommand.php` precedent)
- `AppliesEnrichments` contract + `ApplyEnrichments` action MUST live under `Modules/Import/Public/`
- `StatementSummary` model + `StatementSummaryWriter` service MUST live under `Modules/Ledger/Public/` (cross-module: the Import module's `ConfirmImport` action writes through the service when CAMT/MT940 imports land)

---

### Tests TestCase + Helper Setup

**Source:** `Modules/Ingestion/tests/TestCase.php`, `Modules/Import/tests/TestCase.php`, `Modules/Ledger/tests/TestCase.php` (per-module). Pest config at `tests/Pest.php`.

**Apply to:** Phase 2 tests use the existing per-module TestCase. The `$this->canonical(...)` helper in `Modules/Ledger/tests/Pest.php` may need a v3-compatible variant — discovery item for the planner.

---

### Larastan Level 10 Strict Compliance

**Source:** Phase 1 SUMMARY `01-04-SUMMARY.md` lines 280–333 documents 7 auto-fixed PHPStan issues — common patterns to anticipate:
- `Carbon::createFromFormat()` returns `?static`, not `false` — use `if (! $parsed instanceof CarbonImmutable)`
- `Reader::createFromPath()` deprecated → use `Reader::from()`
- `strtok()` returns `string|false` — never empty-string
- Eloquent Builder `count() / orderByDesc() / whereIn() / limit()` are flagged by `staticMethod.dynamicCall` — use raw `DatabaseManager` query builder for aggregating selects (verified in `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` per 01-06-SUMMARY decisions)
- All `protected $signature` / `protected $description` on Commands need `/** @var string */`
- All cast methods need `@return array<string, string>` annotation

**Apply:** Anticipate all of these in Phase 2 plans — the codebase is already 100% PHPStan-clean at level 10 strict per Phase 1.

---

## No Analog Found

Files for which the codebase has no close match. The planner should lean on RESEARCH.md's code examples instead.

| File | Role | Reason | Mitigation |
|------|------|--------|------------|
| `Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Lexer.php` | streaming tokenizer over `fopen()` | Phase 1's adapters delegate file reading to `league/csv` and `genkgo/camt` will handle CAMT. MT940 has no library wrapper — this is the first hand-rolled `fopen()`-based Generator in the codebase. | RESEARCH.md §"MT940 line-by-line streaming with tag-pair flushing" lines 1419–1467 provides the full skeleton. Use `final class` + Generator method, mirror `AsnCsvAdapter`'s lazy semantics. |
| `Modules/Ledger/Database/Migrations/2026_05_13_XXXXXX_rederive_fingerprints_v3.php` | data-backfill migration that invokes an artisan command | Every Phase 1 migration is structural (create-table). No precedent for `Schema::table` + invoke-artisan-from-`up()`. | RESEARCH.md §"Migration ordering" lines 946–960 describes the approach: migration `up()` resolves `Illuminate\Contracts\Console\Kernel` from the container and calls `->call('diederik:rederive-fingerprints', ['--confirm' => true])`. `down()` is intentionally a no-op (regression is destructive). |
| `Modules/Ledger/Public/Services/StatementSummaryWriter.php` | write-once service per import_run | Closest pattern is the chunk inside `ConfirmImport::__invoke` that writes the `import_runs` row; no extracted "writer" service yet. | Mirror `EloquentAccountResolver` shape (`Modules/Import/Public/Services/EloquentAccountResolver.php`) — single class, single method, takes `User` + the data, returns nothing or an integer count. |
| `Modules/Ingestion/Internal/Adapters/Asn/Camt/SepaFragmentSerialiser.php` | helper that builds `rawPayload['sepa']` sub-structure | Existing `rawPayload` is `array<int,string>` (CSV columns). The CAMT structure (RESEARCH.md lines 601–632) introduces the first nested `rawPayload['sepa']` keyed sub-array. | Match `AsnCsvAdapter::joinDescription()` private-helper pattern (lines 168–187) but place in a separate class if the helper exceeds ~30 lines; otherwise inline as a private method on `AsnCamt053Adapter`. |
| `Modules/Import/Public/Dto/PendingEnrichment.php` location vs. CONTEXT.md alternative `Modules/Ledger/Public/Dto/` | DTO consumed by both Import and Ledger | CONTEXT.md says "likely under `Modules/Ledger/Public/Dto/` or `Modules/Import/Public/Dto/`". The DTO is produced inside `ImportPipeline` (Import) and consumed by `ApplyEnrichments` (Import). It's an Import-internal contract; Ledger doesn't need it. | Place under `Modules/Import/Public/Dto/` to match `UnknownIban` / `PreviewRowDto` precedent. |

---

## Critical Discovery Items for the Planner

1. **`StatementSummary` model location** — CONTEXT.md says `Modules/Ledger/Public/Models/StatementSummary.php` but every existing Ledger model is at `Modules/Ledger/Models/` (no `Public/` segment). **Recommendation: match the existing convention** (`Modules/Ledger/Models/StatementSummary.php`). If the planner wants to introduce `Modules/Ledger/Public/Models/`, the move should be a documented stylistic shift, not silent.

2. **`UploadWizard::sanitiseFilename` hardcodes `.csv` suffix** — lines 96–102 turn every upload into `<safe>.csv`. Phase 2 MUST extend this to preserve the format-appropriate extension (xml / sta / mt940). Discovered via reading the actual file; not flagged in RESEARCH.md.

3. **`tests/Contracts/IdempotencyContractTest.php` already a Pest dataset** — designed for new adapters to append rows (line 14 comment: "Future adapters … append rows here"). Plan should plan to extend the existing file, not write a new test class. CONTEXT.md hints at "4 new dataset rows" but the existing scenarios are 2 (same-file + overlap-period); Phase 2 adds the same 2 scenarios × 2 formats = 4 dataset rows in total or, equivalently, 2 new format keys.

4. **Phase 1 `FingerprintStage::isExistingFingerprint` uses `->exists()` — not `->first() !== null`** — the 01-05-SUMMARY decisions (line 90) mentioned `phpstan-strict-rules` flagging `->exists()` and `->count()` as `staticMethod.dynamicCall`, but the actual committed file (`Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` line 42) DOES use `->exists()`. The decisions block was about an alternative; the shipped code uses `->exists()` on `DatabaseManager::table()` which IS allowed. **Apply the same idiom in the new `classify()` method.**

5. **`composer.json` package version pin** — current versions are PHP `^8.5`, Laravel `^13.0`, league/csv `^9.28`, spatie/laravel-data `^4.0`. Phase 2 adds `"genkgo/camt": "^2.10"` to the `require` block (the file is in the project root). Verify the vendor directory does not already contain `genkgo/camt` — `ls vendor/genkgo` returned "No such file or directory" (confirmed unfetched). `composer install` must run after the manifest change.

6. **Migration naming** — Phase 1 migrations use the `2026_05_12_NNNNNN` date prefix. Phase 2 migrations should use `2026_05_13_NNNNNN` (today, per env metadata) and respect ordering: per RESEARCH.md "Option A — re-derive first, then add column":
   - `2026_05_13_010001_rederive_fingerprints_to_v3.php` (data-backfill)
   - `2026_05_13_010002_add_enriched_from_to_transactions.php` (column add)
   - `2026_05_13_010003_add_enriched_count_to_import_runs.php` (column add)
   - `2026_05_13_010004_create_statement_summaries_table.php` (create)

7. **No `Internal/Console` directory under Ledger yet** — `ls Modules/Ledger/Internal/` shows only `Casts` and `Http`. Creating `Internal/Console/` is a new convention but matches Core's existing `Modules/Core/Internal/Console/`. No boundary test will be violated.

---

## Metadata

**Analog search scope:** `Modules/Ingestion/`, `Modules/Import/`, `Modules/Ledger/`, `Modules/Core/`, `Modules/Categorization/`, `tests/Contracts/`, `tests/fixtures/`.

**Files scanned (read for excerpts):**
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` (189 lines)
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` (41 lines)
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php` (57 lines)
- `Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php` (46 lines)
- `Modules/Ingestion/Public/Services/HeaderSniffer.php` (103 lines)
- `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` (42 lines)
- `Modules/Ingestion/Public/Contracts/SourceAdapter.php` (32 lines)
- `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` (41 lines)
- `Modules/Ingestion/Public/Dto/AccountResolution.php` (30 lines)
- `Modules/Ingestion/Public/Dto/KnownAccount.php` (16 lines)
- `Modules/Ingestion/Public/Dto/UnknownAccount.php` (16 lines)
- `Modules/Ingestion/Public/Dto/SniffResult.php` (24 lines)
- `Modules/Ingestion/Public/Exceptions/SniffMismatchException.php` (22 lines)
- `Modules/Ingestion/Public/Exceptions/UnsupportedFormatException.php` (14 lines)
- `Modules/Ingestion/Providers/IngestionServiceProvider.php` (45 lines)
- `Modules/Import/Internal/Pipeline/ImportPipeline.php` (142 lines)
- `Modules/Import/Internal/Pipeline/PreviewCache.php` (107 lines)
- `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` (44 lines)
- `Modules/Import/Internal/Http/Livewire/UploadWizard.php` (103 lines)
- `Modules/Import/Internal/Http/Livewire/PreviewWizard.php` (124 lines)
- `Modules/Import/Public/Dto/PreviewRowDto.php` (28 lines)
- `Modules/Import/Public/Dto/UnknownIban.php` (20 lines)
- `Modules/Import/Public/Dto/ImportConfirmResult.php` (23 lines)
- `Modules/Import/Public/Dto/ImportPreviewResult.php` (26 lines)
- `Modules/Import/Public/Actions/RunImport.php` (158 lines)
- `Modules/Import/Public/Actions/ConfirmImport.php` (106 lines)
- `Modules/Import/Public/Services/EloquentAccountResolver.php` (36 lines)
- `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` (47 lines)
- `Modules/Import/Resources/views/livewire/preview-wizard.blade.php` (108 lines)
- `Modules/Ledger/Public/Services/FingerprintComposer.php` (91 lines)
- `Modules/Ledger/Public/Dto/CanonicalTransaction.php` (81 lines)
- `Modules/Ledger/Models/Transaction.php` (114 lines)
- `Modules/Ledger/Models/ImportRun.php` (57 lines)
- `Modules/Ledger/Models/Account.php` (46 lines)
- `Modules/Ledger/Database/Migrations/2026_05_12_010002_create_accounts_table.php` (37 lines)
- `Modules/Ledger/Database/Migrations/2026_05_12_010004_create_import_runs_table.php` (35 lines)
- `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` (105 lines)
- `Modules/Core/Internal/Console/InstallCommand.php` (208 lines)
- `Modules/Core/tests/Feature/InstallCommandTest.php` (80 lines, partial)
- `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php` (245 lines)
- `Modules/Ingestion/tests/Feature/HeaderSnifferTest.php` (63 lines)
- `Modules/Ledger/tests/Unit/FingerprintComposerTest.php` (74 lines)
- `tests/Contracts/BoundaryArchTest.php` (30 lines)
- `tests/Contracts/IdempotencyContractTest.php` (50 lines)
- `composer.json` (74 lines)
- Phase 1 summaries: `01-SUMMARY.md`, `01-04-SUMMARY.md`, `01-05-SUMMARY.md`, `01-06-SUMMARY.md`

**Pattern extraction date:** 2026-05-13

## PATTERN MAPPING COMPLETE
