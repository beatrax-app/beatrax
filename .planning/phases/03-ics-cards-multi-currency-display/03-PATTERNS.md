# Phase 3: ICS Cards + Multi-Currency Display - Pattern Map

**Mapped:** 2026-05-13
**Files analyzed:** 17 new / 5 modified
**Analogs found:** 22 / 22

Every file in Phase 3 has a strong existing analog in the codebase. Phase 1 and Phase 2 shipped the full Ingestion + Import + Ledger + Core scaffolding, so this phase is mostly "copy a Phase 1/2 file, swap ASN→ICS or extend by one branch". Concrete code excerpts below cite line numbers in the analog files.

## File Classification

### New Files

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvAdapter.php` | adapter (source-parser) | streaming / file-IO | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` | exact |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvHeaderProfile.php` | config (format constants) | none | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` | exact |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvColumnMap.php` | config (column-index constants) | none | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php` | exact |
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` | component (Livewire SFC) | request-response (form) | `Modules/Core/Internal/Http/Livewire/Dashboard.php` (method-DI shape) + `Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php` (live wire:model + save flow) | role-match |
| `Modules/Core/Resources/views/livewire/settings-page.blade.php` | view (Blade) | render | `Modules/Core/Resources/views/livewire/dashboard.blade.php` (calm-aesthetic shell) + `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` (form fields) | role-match |
| `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` | migration (column add) | DDL | `Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php` | exact |
| `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv` | fixture (raw anonymised CSV) | data | `tests/fixtures/asn-sample-1.csv` | exact |
| `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` | fixture-record doc | data | `tests/fixtures/asn-sample-1.md` | exact |
| `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php` | test (unit + snapshot) | test | `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php` | exact |
| `Modules/Import/tests/Feature/IcsCsvImportTest.php` | test (feature) | test | `Modules/Import/tests/Feature/AsnCsvImportTest.php` | exact |
| `Modules/Core/tests/Feature/SettingsPageTest.php` | test (feature) | test | `Modules/Categorization/tests/Feature/TriagePageTest.php` (Livewire feature shape) + `Modules/Core/tests/Feature/InstallCommandTest.php` (User+period_start_day round-trip) | role-match |
| `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php` | test (feature) | test | `Modules/Ledger/tests/Feature/TransactionListTest.php` (assumed; same module pattern) + `Modules/Categorization/tests/Feature/TriagePageTest.php` | role-match |
| `Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php` | test (feature) | test | `Modules/Ledger/tests/Unit/ThisPeriodAtAGlanceQueryTest.php` (existing query test) | role-match |
| `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php` | test (feature) | test | `Modules/Categorization/tests/Feature/TriagePageTest.php` (Livewire blade-render assertion shape) | partial — detail page is itself new in Phase 3+ |
| `Modules/Ledger/Public/Dto/PerCurrencyTile.php` | DTO (Public) | typed-data | `Modules/Ledger/Public/Dto/TransactionRowDto.php` + `Modules/Ledger/Public/Dto/DashboardSummary.php` | role-match |

### Modified Files

| Modified File | Role | Data Flow | Pattern Source | Change Type |
|---------------|------|-----------|----------------|-------------|
| `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` | DTO (Public) | typed-data | itself (D-42 extension) | add 3 nullable fields |
| `Modules/Ingestion/Public/Services/HeaderSniffer.php` | service (Public) | request-response | itself (`sniffAsnCsv`) | add `sniffIcsCsv` branch in `match` |
| `Modules/Ingestion/Providers/IngestionServiceProvider.php` | service-provider | DI wiring | itself | add `'ics-csv' => IcsCsvAdapter::class` to map |
| `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` | service (pipeline-stage) | transform | itself (lines 56-78) | replace lines 65-67 with D-42/D-39 branch |
| `Modules/Import/Internal/Http/Livewire/UploadWizard.php` | component (Livewire) | request-response | itself + Categorization picker shape | refactor flat dropdown into two-step picker; add `'ics-csv'` to `SUPPORTED_FORMATS` |
| `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` | view (Blade) | render | itself + Flux primitives | reshape dropdown into issuer→format picker |
| `Modules/Core/Models/User.php` | model (Eloquent) | persistence | itself | add `default_currency_view` to `$fillable` + `casts()` + PHPDoc `@property` |
| `Modules/Core/Database/Migrations/2026_05_12_000001_create_users_table.php` | migration | DDL | itself | NOT modified — new "add column" migration sits beside it (Phase 3 column add is a forward migration, not an edit of the create) |
| `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` | service (Public query) | CRUD-read | itself (`for()` method, lines 51-111) | add sibling `forByCurrency()` method with GROUP BY |
| `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php` | component (Livewire) | request-response | itself + `InlineCategoryPicker` (live wire:model) | add `#[Url(as: 'currency')]` property + `mount()` user-pref fallback |
| `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` | view (Blade) | render | itself + Flux radio.group | add segmented control + dual-line stack |
| `Modules/Core/Internal/Http/Livewire/Dashboard.php` | component (Livewire) | request-response | itself | inject CurrentUser → read `default_currency_view` → branch tile rendering |
| `Modules/Core/Resources/views/livewire/dashboard.blade.php` | view (Blade) | render | itself | conditional GROUP-BY-currency tile-row rendering |
| `tests/Contracts/IdempotencyContractTest.php` | test (contract) | test | itself (dataset) | add `'ics-csv'` row to dataset |

## Pattern Assignments

### `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvAdapter.php` (adapter, streaming)

**Analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` (200 lines, full file in context)

**Imports + class skeleton** (analog lines 1-46):
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Throwable;

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

    public function statementMetadata(): ?StatementSummaryData
    {
        return null;
    }
```

**Lazy-generator parse with sniffer + league/csv + CharsetConverter** (analog lines 58-118):
```php
public function parse(string $localPath, AccountResolver $accounts): Generator
{
    $this->sniffer->sniff($localPath, AsnCsvHeaderProfile::FORMAT);

    $reader = Reader::from($localPath, 'r');
    $reader->setDelimiter(AsnCsvHeaderProfile::DELIMITER);
    $reader->setEscape('');
    $reader->setHeaderOffset(0);
    CharsetConverter::addTo($reader, AsnCsvHeaderProfile::SOURCE_ENCODING, 'UTF-8');

    $index = 0;
    foreach ($reader->getRecords() as $record) {
        $row = $this->normaliseRow($record);

        try {
            $postedAt = $this->parseDate($row[AsnCsvColumnMap::POSTED_DATE]);
            // ... amount parsing ...
        } catch (Throwable $e) {
            throw new InvalidAmountException(
                sprintf('Row %d: %s', $index, $e->getMessage()),
                0,
                $e,
            );
        }

        // ... AccountResolver call ...
        $accounts->resolve($ownIban);

        yield new SourceTransactionDto( /* ... */ );
        $index++;
    }
}
```

**Row-normaliser + nullIfEmpty helpers** (analog lines 130-198):
```php
private function normaliseRow(mixed $record): array
{
    if (! is_array($record)) {
        throw new InvalidAmountException('Unexpected non-array record from CSV reader.');
    }
    $row = [];
    foreach (array_values($record) as $cell) {
        if ($cell === null) { $row[] = ''; }
        elseif (is_string($cell)) { $row[] = $cell; }
        else {
            throw new InvalidAmountException(sprintf(
                'Unexpected non-string cell in CSV row (got %s).',
                get_debug_type($cell),
            ));
        }
    }
    return $row;
}

private function parseDate(string $cell): CarbonImmutable
{
    $parsed = CarbonImmutable::createFromFormat('!'.AsnCsvHeaderProfile::DATE_FORMAT, $cell);
    if (! $parsed instanceof CarbonImmutable) {
        throw new InvalidAmountException(sprintf(
            "Cannot parse date '%s' (expected format %s)",
            $cell,
            AsnCsvHeaderProfile::DATE_FORMAT,
        ));
    }
    return $parsed;
}

private function nullIfEmpty(string $value): ?string
{
    return $value === '' ? null : $value;
}
```

**D-42 additions** (NOT in analog; from RESEARCH.md Example 1):
The ICS adapter yields `SourceTransactionDto` with the new D-42 fields populated when the source row carries an FX pair:
```php
yield new SourceTransactionDto(
    bookedAt: $bookedAt, postedAt: $postedAt, valueDate: $valueDate,
    ownIban: $icsAccountIban, counterpartyIban: null,
    counterpartyName: $merchantName,
    currency: $hasFxRow ? $originalCurrency : 'EUR',
    amountMinor: $hasFxRow ? $originalMinor : $settledEurMinor,
    sourceRef: $authCode,                // ?string per D-34 Wave 0
    description: $merchantNarrative,
    rawPayload: $row,
    sourceRowIndex: $index,
    settledAmountMinor: $hasFxRow ? $settledEurMinor : null,   // NEW D-42
    settledCurrency: $hasFxRow ? 'EUR' : null,                  // NEW D-42
    fxRateUsed: null,                                           // NEW D-42 (always null at adapter boundary; NormalizeStage derives)
);
```

**Constructor variant (no amount parser):** `AsnCsvAdapter` accepts `AsnAmountParser` because ASN amounts use period-decimal with optional sign. ICS amount format is Wave-0-pending; the constructor signature is therefore either `(HeaderSniffer $sniffer)` (if ICS amounts parse trivially via existing utilities) or `(HeaderSniffer $sniffer, IcsAmountParser $amounts)` (if ICS needs its own parser — a likely outcome given CSV often emits comma-decimal). Defer this to the Wave 0 fixture analysis. The Phase 2 MT940 adapter (`Modules/Ingestion/Internal/Adapters/Asn/AsnMt940Adapter.php` lines 65-72) reuses `AsnAmountParser` after normalising comma-decimal to period-decimal — same pattern is acceptable for ICS.

**Pattern 2 (multi-row look-ahead, only if Wave 0 reports D-35 shape (b)):** see RESEARCH.md §"Pattern 2"; no codebase analog exists yet (Phase 4 PayPal rollup will be the first true peer).

---

### `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvHeaderProfile.php` (config)

**Analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` (full file, 40 lines).

**Full shape to mirror** (analog lines 1-40):
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

final class IcsCsvHeaderProfile
{
    public const FORMAT = 'ics-csv';

    public const DELIMITER = ','; // Wave 0 confirms — ICS CSV in NL may be ';'

    public const HAS_HEADER = true;

    public const SOURCE_ENCODING = 'UTF-8'; // Wave 0 confirms — could be windows-1252

    public const EXPECTED_COLUMN_COUNT = /* Wave 0 fills */ ;

    /**
     * The first two header cells. Locked to the empirical ICS header text.
     */
    public const HEADER_SIGNATURE = [/* Wave 0 fills */];

    public const DATE_FORMAT = 'd-m-Y'; // Wave 0 confirms
}
```

Every constant name + visibility matches the ASN profile exactly so HeaderSniffer can switch on it via the same `match ($declaredFormat)` arm pattern.

---

### `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvColumnMap.php` (config)

**Analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php` (full file, 57 lines).

**Pattern (analog lines 1-56):**
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

final class IcsCsvColumnMap
{
    public const POSTED_DATE = /* Wave 0 fills */;
    public const ORIGINAL_AMOUNT = /* Wave 0 fills */;     // D-35 — native foreign amount
    public const ORIGINAL_CURRENCY = /* Wave 0 fills */;   // D-35 — native foreign currency
    public const SETTLED_AMOUNT = /* Wave 0 fills */;      // D-35 — EUR-settled amount
    public const MERCHANT = /* Wave 0 fills */;
    public const AUTH_CODE = /* Wave 0 fills */;           // D-34 — source_ref candidate
    public const CARD_NUMBER = /* Wave 0 fills */;         // D-37 — read then discard
    // ... etc.
}
```

Same comment-on-the-right-of-constant style as `AsnCsvColumnMap`.

---

### `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` (DTO, MODIFIED — D-42)

**Analog:** itself (42 lines, full file in context).

**Current constructor (lines 27-41)** — extend by adding three trailing nullable fields:
```php
public function __construct(
    public readonly CarbonImmutable $bookedAt,
    public readonly CarbonImmutable $postedAt,
    public readonly CarbonImmutable $valueDate,
    public readonly string $ownIban,
    public readonly ?string $counterpartyIban,
    public readonly ?string $counterpartyName,
    public readonly string $currency,
    public readonly int $amountMinor,
    public readonly ?string $sourceRef,
    public readonly ?string $description,
    public readonly array $rawPayload,
    public readonly int $sourceRowIndex,
    // NEW D-42 nullable settled pair + derived rate:
    public readonly ?int $settledAmountMinor = null,
    public readonly ?string $settledCurrency = null,
    public readonly ?string $fxRateUsed = null,
) {}
```

The three new fields are appended with `= null` defaults so Phase 1/2 adapters keep compiling without any change (the ASN adapter call sites stay identical; new optional fields are simply omitted).

---

### `Modules/Ingestion/Public/Services/HeaderSniffer.php` (service, MODIFIED)

**Analog:** itself (185 lines, full file in context).

**Match arm extension** (analog lines 54-62):
```php
return match ($declaredFormat) {
    AsnCsvHeaderProfile::FORMAT => $this->sniffAsnCsv($localPath, $head),
    AsnCamt053HeaderProfile::FORMAT => $this->sniffAsnCamt053($localPath, $head),
    AsnMt940HeaderProfile::FORMAT => $this->sniffAsnMt940($localPath, $head),
    IcsCsvHeaderProfile::FORMAT => $this->sniffIcsCsv($localPath, $head),  // NEW
    default => throw new SniffMismatchException(sprintf(
        'Unsupported sniff target: %s',
        $declaredFormat,
    )),
};
```

**New `sniffIcsCsv` method** mirroring `sniffAsnCsv` (analog lines 142-184):
```php
private function sniffIcsCsv(string $path, string $head): SniffResult
{
    if (preg_match('/\.csv$/i', $path) !== 1) {
        throw new SniffMismatchException(
            "That file doesn't look like a CSV. Drop in the ICS Cards CSV export."
        );
    }

    $firstLine = strtok($head, "\r\n");
    if ($firstLine === false) {
        throw new SniffMismatchException('The file is empty.');
    }

    $delim = IcsCsvHeaderProfile::DELIMITER;
    $columns = str_getcsv($firstLine, $delim, '"', '');

    if (count($columns) !== IcsCsvHeaderProfile::EXPECTED_COLUMN_COUNT) {
        throw new SniffMismatchException(sprintf(
            'Expected %d columns, got %d. This file does not match the ICS CSV layout.',
            IcsCsvHeaderProfile::EXPECTED_COLUMN_COUNT,
            count($columns),
        ));
    }

    $expected = IcsCsvHeaderProfile::HEADER_SIGNATURE;
    if ($columns[0] !== $expected[0] || $columns[1] !== $expected[1]) {
        throw new SniffMismatchException(sprintf(
            "This CSV doesn't match the expected ICS column layout (header starts with '%s,%s', got '%s,%s'). If ICS changed their export format, file an issue.",
            $expected[0], $expected[1], $columns[0], $columns[1],
        ));
    }

    return new SniffResult(
        format: IcsCsvHeaderProfile::FORMAT,
        delimiter: $delim,
        hasHeader: IcsCsvHeaderProfile::HAS_HEADER,
        encoding: IcsCsvHeaderProfile::SOURCE_ENCODING,
        columnCount: count($columns),
    );
}
```

---

### `Modules/Ingestion/Providers/IngestionServiceProvider.php` (service-provider, MODIFIED)

**Analog:** itself (48 lines, full file in context).

**Registry extension** (analog lines 31-38):
```php
$this->app->singleton(
    SourceAdapterRegistry::class,
    static fn (Container $app): SourceAdapterRegistry => new SourceAdapterRegistry([
        'asn-csv' => $app->make(AsnCsvAdapter::class),
        'asn-camt053' => $app->make(AsnCamt053Adapter::class),
        'asn-mt940' => $app->make(AsnMt940Adapter::class),
        'ics-csv' => $app->make(IcsCsvAdapter::class),       // NEW
    ]),
);
```

Single-line append. No other changes.

---

### `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` (service, MODIFIED — D-42 + D-39)

**Analog:** itself (80 lines, full file in context).

**Current settled-mirror (lines 56-67)** — replace the three lines that hard-mirror native→settled:
```php
return new CanonicalTransaction(
    // ... existing ...
    settledAmountMinor: $source->amountMinor,    // CURRENT — mirrors native
    settledCurrency: $source->currency,          // CURRENT — mirrors native
    fxRateUsed: null,                            // CURRENT — always null
    // ...
);
```

**New D-42 + D-39 substitution branch** (insert before the `return new CanonicalTransaction(...)`):
```php
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

// D-42: substitute settled = native when the source omitted them
// (Phase 1/2 ASN adapters; EUR-native rows in any adapter).
$settledMinor = $source->settledAmountMinor ?? $source->amountMinor;
$settledCurrency = $source->settledCurrency ?? $source->currency;

// D-39: derive fx_rate_used when both legs are present AND differ.
// Use BigDecimal to preserve decimal(18,8) precision — float is forbidden
// by NoFloatMoneyArchTest.
$fxRateUsed = null;
if ($source->settledAmountMinor !== null
    && $source->settledCurrency !== null
    && $source->amountMinor !== 0
    && $source->settledCurrency !== $source->currency
) {
    $fxRateUsed = (string) BigDecimal::of((string) $source->settledAmountMinor)
        ->dividedBy(
            BigDecimal::of((string) $source->amountMinor),
            8,
            RoundingMode::HALF_UP,
        );
}

return new CanonicalTransaction(
    // ... existing ...
    settledAmountMinor: $settledMinor,
    settledCurrency: $settledCurrency,
    fxRateUsed: $fxRateUsed,
    // ...
);
```

**Existing test** `NormalizeStageTest::'mirrors native amount/currency to settled amount/currency by default'` (test lines 139-148) **stays green** because ASN adapters keep yielding `null` for the new fields — the `??` substitution preserves the existing behaviour. The Phase 3 plan adds new assertions for the D-39 derivation branch using a synthetic non-EUR `SourceTransactionDto`.

---

### `Modules/Import/Internal/Http/Livewire/UploadWizard.php` (component, MODIFIED — D-33)

**Analog:** itself (125 lines, full file in context).

**Current flat SUPPORTED_FORMATS (lines 38-42)** — extend with `'ics-csv'`:
```php
public const SUPPORTED_FORMATS = [
    'asn-csv',
    'asn-camt053',
    'asn-mt940',
    'ics-csv',           // NEW
];
```

**Current `sanitiseFilename` match (lines 117-121)** — extend extension switch:
```php
$extension = match ($this->sourceFormat) {
    'asn-camt053' => '.xml',
    'asn-mt940' => '.sta',
    default => '.csv',      // covers both asn-csv and ics-csv
};
```
(no edit needed — `.csv` default already covers `ics-csv`.)

**New two-step picker properties (D-33)** — add issuer-tier state and recompute available formats:
```php
public string $issuer = 'asn';    // 'asn' | 'ics' | (later) 'paypal' | 'google-play'

public function rules(): array
{
    return [
        'issuer' => ['required', 'in:asn,ics'],
        'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xml,sta,mt940,940'],
        'sourceFormat' => ['required', 'in:'.implode(',', self::SUPPORTED_FORMATS)],
    ];
}

/**
 * @return list<array{value: string, label: string}>
 */
public function availableFormats(): array
{
    return match ($this->issuer) {
        'asn' => [
            ['value' => 'asn-csv',     'label' => 'ASN CSV'],
            ['value' => 'asn-camt053', 'label' => 'ASN CAMT.053 (XML)'],
            ['value' => 'asn-mt940',   'label' => 'ASN MT940'],
        ],
        'ics' => [
            ['value' => 'ics-csv', 'label' => 'ICS CSV'],
        ],
        default => [],
    };
}
```

**Method-DI for submit/redirect (analog lines 70-96)** — leave intact, only the dropdown shape changes; `submit()`'s logic is unchanged.

---

### `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` (view, MODIFIED)

**Analog:** itself (48 lines, full file in context).

**Current flat dropdown (lines 9-24)** — split into two selects:
```blade
<div class="space-y-1">
    <label for="issuer" class="block text-sm text-slate-900">Issuer</label>
    <select id="issuer" name="issuer" wire:model.live="issuer" class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2">
        <option value="asn">ASN Bank</option>
        <option value="ics">ICS Cards</option>
    </select>
</div>

<div class="space-y-1">
    <label for="sourceFormat" class="block text-sm text-slate-900">Format</label>
    <select id="sourceFormat" name="sourceFormat" wire:model="sourceFormat" class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2">
        @foreach ($this->availableFormats() as $fmt)
            <option value="{{ $fmt['value'] }}">{{ $fmt['label'] }}</option>
        @endforeach
    </select>
    @error('sourceFormat')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
</div>
```

`wire:model.live="issuer"` re-renders the format dropdown when the issuer changes (existing pattern from `Modules/Categorization/Resources/views/livewire/inline-category-picker.blade.php` line 3).

**Snapshot/HTML test re-baseline:** `Modules/Import/tests/Feature/UploadWizardTest.php` asserts via Livewire test calls (`->set('sourceFormat', ...)->call('submit')`) rather than HTML snapshot, so most existing tests still pass. The `it('renders the calm upload form on GET /imports/new')` assertion (test lines 16-20) asserts `assertSee('Upload statement', false)` — also passes. The Phase 3 plan adds an `it('lets the user pick ICS issuer and ics-csv format')` Livewire feature test.

---

### `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (component, NEW — D-45)

**Analog:** Two-source blend.

Primary (method-DI shape + render): `Modules/Core/Internal/Http/Livewire/Dashboard.php` (116 lines, full file in context).
Secondary (form save + wire:model live): `Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php` (48 lines, full file in context).

**Imports + class shell** (from Dashboard analog lines 1-44):
```php
<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

final class SettingsPage extends Component
{
    #[Validate('required|in:eur_only,original')]
    public string $defaultCurrencyView = 'eur_only';

    #[Validate('required|integer|min:1|max:28')]
    public int $periodStartDay = 1;
```

**Method-DI mount + save (Dashboard pattern, lines 53-84)**:
```php
public function mount(CurrentUser $currentUser): void
{
    $user = $currentUser->user();
    $this->defaultCurrencyView = $user->default_currency_view ?? 'eur_only';
    $this->periodStartDay = $user->period_start_day;
}

public function save(CurrentUser $currentUser, UrlGenerator $urls): void
{
    $this->validate();

    $user = $currentUser->user();
    $user->default_currency_view = $this->defaultCurrencyView;
    $user->period_start_day = $this->periodStartDay;
    $user->save();

    // Optional: redirect-as-method pattern from Phase 1 — avoids
    // returning RedirectResponse from a Livewire action.
    $this->dispatch('settings-saved');     // or this->redirect($urls->route('dashboard'), navigate: false)
}

public function render(ViewFactory $views): View
{
    return $views->make('core::livewire.settings-page');
}
```

**Critical invariant (Phase 1 + 2):**
- Method-level DI only. `Dashboard` line 70-75 demonstrates: `public function render(CurrentUser $currentUser, PeriodQuery $periods, ThisPeriodAtAGlanceQuery $glance, ViewFactory $views)`.
- NEVER inject `auth()` / `Auth::user()`. Always `CurrentUser` via parameter.

---

### `Modules/Core/Resources/views/livewire/settings-page.blade.php` (view, NEW)

**Analog:** Two-source blend.

Primary (calm-aesthetic shell + header): `Modules/Core/Resources/views/livewire/dashboard.blade.php` (lines 21-46).
Secondary (form fields shape): `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` (full file).

**Header pattern** (dashboard analog lines 21-25):
```blade
<div class="space-y-12">
    <header class="space-y-1">
        <h1 class="text-3xl font-semibold tracking-tight text-slate-900">Settings</h1>
        <p class="text-sm text-slate-500">Currency display + period boundaries.</p>
    </header>
```

**Form-field pattern** (wizard analog lines 9-39):
```blade
<form wire:submit="save" class="space-y-4">
    <div class="space-y-1">
        <label for="defaultCurrencyView" class="block text-sm text-slate-900">Default currency view</label>
        <select id="defaultCurrencyView" name="defaultCurrencyView" wire:model="defaultCurrencyView" class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2">
            <option value="eur_only">EUR only</option>
            <option value="original">Original currency</option>
        </select>
        @error('defaultCurrencyView')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>

    <div class="space-y-1">
        <label for="periodStartDay" class="block text-sm text-slate-900">Period start day (1–28)</label>
        <input type="number" min="1" max="28" id="periodStartDay" name="periodStartDay" wire:model="periodStartDay" class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2" />
        @error('periodStartDay')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
        Save
    </button>
</form>
</div>
```

Calm-aesthetic palette: slate body + emerald primary button (mirrors wizard's emerald-600).

---

### `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` (migration, NEW)

**Analog:** `Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php` (full file, 38 lines).

**Full pattern (analog lines 1-37):**
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
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('default_currency_view', 16)->default('eur_only')->after('period_start_day');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('default_currency_view');
        });
    }

    private function schema(): Builder
    {
        // Anonymous migrations are instantiated by Laravel's migrator with
        // no constructor arguments, so the schema builder is resolved
        // from the container at the migration boundary. This is the
        // standing Laravel-migration exception to the DI-only rule.
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);

        return $db->connection($this->getConnection())->getSchemaBuilder();
    }
};
```

The `$this->schema()` helper + DI-only comment is the standing project pattern. Anonymous migration class is the standing Laravel 11+ pattern.

---

### `Modules/Core/Models/User.php` (model, MODIFIED)

**Analog:** itself (45 lines, full file in context).

**Current fillable + casts (lines 32-44)**:
```php
/** @var list<string> */
protected $fillable = ['email', 'password', 'period_start_day', 'default_currency_view'];  // NEW append

/** @var list<string> */
protected $hidden = ['password', 'remember_token'];

/** @return array<string, string> */
protected function casts(): array
{
    return [
        'password' => 'hashed',
        'period_start_day' => 'integer',
        'default_currency_view' => 'string',   // NEW append
    ];
}
```

**Class-level PHPDoc (lines 12-23)** — add the property:
```php
/**
 * @property int $id
 * @property string $email
 * @property string $password
 * @property int $period_start_day
 * @property string $default_currency_view    // NEW
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
```

The `@property` line lets Larastan resolve `$user->default_currency_view` without a custom return-type plugin.

---

### `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (service, MODIFIED — D-46)

**Analog:** itself (123 lines, full file in context).

**Current EUR-only `for()` (lines 51-111)** — leave intact, add a sibling `forByCurrency()`:
```php
/**
 * Original-currency mode (D-46). Returns one tile-row per distinct
 * `settled_currency` present in the period. EUR-only months collapse to
 * a single tile-row that renders identically to the EUR-only mode output.
 *
 * @return list<PerCurrencyTile>
 */
public function forByCurrency(User $user, Period $period): array
{
    $connection = $this->db->connection();

    $rows = $connection
        ->table('transactions')
        ->where('user_id', $user->id)
        ->where('posted_at', '>=', $period->start->toDateString())
        ->where('posted_at', '<',  $period->endExclusive->toDateString())
        ->groupBy('settled_currency')
        ->selectRaw(
            'settled_currency,
             COALESCE(SUM(CASE WHEN settled_amount_minor > 0 THEN settled_amount_minor ELSE 0 END), 0) AS inflow_minor,
             COALESCE(SUM(CASE WHEN settled_amount_minor < 0 THEN -settled_amount_minor ELSE 0 END), 0) AS outflow_minor,
             COALESCE(SUM(settled_amount_minor), 0) AS net_minor'
        )
        ->orderBy('settled_currency')
        ->get();

    return $rows->map(fn ($r) => new PerCurrencyTile(
        currency: (string) $r->settled_currency,
        inflow: Money::ofMinor(self::toInt($r->inflow_minor), (string) $r->settled_currency),
        outflow: Money::ofMinor(self::toInt($r->outflow_minor), (string) $r->settled_currency),
        net: Money::ofMinor(self::toInt($r->net_minor), (string) $r->settled_currency),
    ))->all();
}
```

Reuses the existing private `toInt()` helper (analog lines 119-122). Same `DatabaseManager::table()` builder pattern (no Eloquent — `phpstan-strict-rules` `staticMethod.dynamicCall`).

---

### `Modules/Ledger/Public/Dto/PerCurrencyTile.php` (DTO, NEW)

**Analog:** `Modules/Ledger/Public/Dto/TransactionRowDto.php` (full file, 30 lines) + `Modules/Ledger/Public/Dto/DashboardSummary.php` (35 lines).

**Pattern (mirrors TransactionRowDto lines 1-29):**
```php
<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * One per-currency totals row for the dashboard's original-currency view.
 * Built by `ThisPeriodAtAGlanceQuery::forByCurrency()` and consumed by the
 * dashboard Blade — D-46 collapses EUR-only months to a single row, so a
 * EUR-only user's view stays visually identical to the EUR-only mode.
 */
final class PerCurrencyTile extends Data
{
    public function __construct(
        public readonly string $currency,
        public readonly Money $inflow,
        public readonly Money $outflow,
        public readonly Money $net,
    ) {}
}
```

`extends Data` (Spatie) — same as every existing Public DTO.

---

### `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php` (component, MODIFIED — D-44, D-47)

**Analog:** itself (69 lines, full file in context) + `Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php` (wire:model.live shape).

**Current properties + render (lines 27-67)** — add `#[Url]` property + `mount`:
```php
use Livewire\Attributes\Url;

final class TransactionsList extends Component
{
    public bool $fullHistory = false;
    public ?int $cursorId = null;
    public ?string $cursorPostedAt = null;

    /**
     * Currency view mode. '' (empty) = use the user's default_currency_view
     * preference. 'eur' = project the settled-EUR pair.
     */
    #[Url(as: 'currency', except: '')]
    public string $currency = '';

    public function mount(CurrentUser $currentUser): void
    {
        // Fall back to the user's saved preference when the URL has no
        // ?currency= override. This is the D-44 round-trip: Settings → DB
        // → query → URL override path.
        if ($this->currency === '') {
            $pref = $currentUser->user()->default_currency_view;
            $this->currency = $pref === 'eur_only' ? 'eur' : '';
        }
    }

    public function render(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        // Map '' (original) → null projection; 'eur' → 'EUR' projection.
        $currency = $this->currency === 'eur' ? 'EUR' : null;

        $page = $this->fullHistory
            ? $listQuery->fullHistory($user, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $currency)
            : $listQuery->recent($user, daysBack: 90, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $currency);

        return $views->make('ledger::livewire.transactions-list', [
            'page' => $page,
            'fullHistory' => $this->fullHistory,
            'currency' => $this->currency,
        ]);
    }
}
```

`#[Url(as: 'currency', except: '')]` is the Livewire 4 attribute (RESEARCH.md §"Pattern 4"). Method-DI on `render()` mirrors lines 53-58 of the analog.

---

### `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` (view, MODIFIED — D-44, D-47)

**Analog:** itself (70 lines, full file in context).

**Header extension (analog lines 7-22)** — add Flux segmented control next to the existing toggle button:
```blade
<header class="flex items-end justify-between gap-4">
    <div class="space-y-1">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Transactions</h1>
        <p class="text-sm text-slate-500">
            {{ $fullHistory ? 'Full history.' : 'Recent transactions (last 90 days).' }}
        </p>
    </div>
    <div class="flex items-center gap-2">
        <flux:radio.group wire:model.live="currency" variant="segmented">
            <flux:radio value=""    label="Original" />
            <flux:radio value="eur" label="EUR" />
        </flux:radio.group>
        <button type="button" wire:click="toggleFullHistory" class="inline-flex items-center rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2">
            {{ $fullHistory ? 'Show recent only' : 'Show full history' }}
        </button>
    </div>
</header>
```

**Amount cell extension (analog lines 51-53)** — two-line stack when native ≠ settled (only in original-currency view, i.e. `$currency === ''`):
```blade
<td class="px-4 py-2 text-right text-slate-900" style="font-variant-numeric: tabular-nums;">
    {{ $fmt($row->amount) }}
    {{-- D-47: muted second line when the row's native + settled differ.
         In EUR mode the projection already shows the settled pair, so this
         block is skipped. In original mode, TransactionListQuery returns
         the native pair, and we annotate with the settled pair below. --}}
    {{-- NOTE: Phase 3 may need a small DTO extension (TransactionRowDto
         gains optional ?Money $settledAmount) for the secondary line.
         The planner picks whether to extend the DTO or to project the
         second pair through a separate query. --}}
</td>
```

**Existing pattern reuse (analog lines 1-5)** — the `$fmt = static fn (Money $money): string => $money->format('nl_NL')` helper continues to work; the secondary line uses the same closure.

---

### `Modules/Core/Internal/Http/Livewire/Dashboard.php` (component, MODIFIED — D-46)

**Analog:** itself (116 lines, full file in context).

**Inject CurrentUser into render() (analog lines 70-84)** — branch on user preference:
```php
public function render(
    CurrentUser $currentUser,
    PeriodQuery $periods,
    ThisPeriodAtAGlanceQuery $glance,
    ViewFactory $views,
): View {
    $user = $currentUser->user();
    $period = $this->resolvePeriod($periods);

    // D-46: branch on the user's currency view preference.
    if ($user->default_currency_view === 'original') {
        $tiles = $glance->forByCurrency($user, $period);
        $summary = $glance->for($user, $period);   // still need top-cats + recent panel
        return $views->make('core::livewire.dashboard', [
            'summary' => $summary,
            'tiles' => $tiles,                      // NEW — array<PerCurrencyTile>
        ]);
    }

    $summary = $glance->for($user, $period);
    return $views->make('core::livewire.dashboard', [
        'summary' => $summary,
        'tiles' => null,
    ]);
}
```

`CurrentUser` already exists in the project as the DI-injected auth bridge (`Modules/Core/Public/Contracts/CurrentUser.php` / `Modules/Core/Public/Services/CurrentUserService.php`). No constructor change.

---

### `Modules/Core/Resources/views/livewire/dashboard.blade.php` (view, MODIFIED — D-46)

**Analog:** itself (142 lines, full file in context).

**Tile section (analog lines 48-70)** — conditional on `$tiles`:
```blade
<section class="grid grid-cols-1 gap-4 md:grid-cols-3" aria-label="This period totals">
    @if ($tiles === null)
        {{-- EUR-only view: original three tiles --}}
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <p class="text-xs uppercase tracking-wide text-slate-500">In</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                {{ $fmt($summary->inflow) }}
            </p>
        </div>
        {{-- ... existing Out + Net tiles ... --}}
    @else
        {{-- Original-currency view: one row of tiles per currency --}}
        @foreach ($tiles as $tile)
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <p class="text-xs uppercase tracking-wide text-slate-500">In ({{ $tile->currency }})</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                    {{ $fmt($tile->inflow) }}
                </p>
            </div>
            {{-- ... three tiles per currency ... --}}
        @endforeach
    @endif
</section>
```

The existing `$fmt` closure (analog lines 1-12) handles non-EUR formatting automatically via `Money::format('nl_NL')` — `brick/money` switches per-currency based on the Money's currency code; no per-currency switch in the Blade required.

---

### Test Files (all five new tests)

#### `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php` (NEW)

**Analog:** `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php` (245 lines, full file in context).

**Test scaffolding (analog lines 16-31)** — copy verbatim, swap ASN→ICS:
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

    $this->adapter = $this->app->make(IcsCsvAdapter::class);
});
```

**Mirror these existing tests** (analog test names):
- `it('reports its stable format identifier')` (line 33)
- `it('parses the real fixture into SourceTransactionDtos')` (line 37) — assert `IcsCsvHeaderProfile::FORMAT === 'ics-csv'` + non-empty DTO array
- `it('preserves the full source row in rawPayload for audit')` (line 75)
- `it('emits monotonically increasing sourceRowIndex starting at zero')` (line 91)
- `it('asks the AccountResolver for the own IBAN of every parsed row')` (line 146) — adapt to "asks the AccountResolver once per ICS account context"
- `it('rejects a file that fails the header sniffer before reading any data row')` (line 181)
- `it('matches the snapshot of the parsed fixture (drift detector)')` (line 211) — extend snapshot projection to include `settledAmountMinor` + `settledCurrency`
- `it('registers under the ics-csv key in the SourceAdapterRegistry')` (line 234)

**New Phase 3-specific tests:**
- `it('yields native + settled pair for a foreign-currency row')` — Pitfall 1 guard (RESEARCH.md §"Pitfall 1")
- `it('yields settledAmountMinor=null for an EUR-native row')` — D-42 honesty contract

#### `Modules/Import/tests/Feature/IcsCsvImportTest.php` (NEW)

**Analog:** `Modules/Import/tests/Feature/AsnCsvImportTest.php` (96 lines, full file in context).

**Pattern (analog lines 11-28)** — copy verbatim with file swap:
```php
beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
});

it('imports every parsed row from the gold fixture on the first run', function (): void {
    $result = $this->importer->runAndConfirm(
        __DIR__.'/../../../Ingestion/tests/fixtures/ics/ics-sample-1.csv',
        'ics-csv',
        $this->fixtureUser,
    );

    expect($result)->toBeInstanceOf(ImportConfirmResult::class);
    expect($result->inserted)->toBeGreaterThan(0);
    expect($result->duplicates)->toBe(0);
    expect(Transaction::count())->toBe($result->inserted);
});

it('returns zero new rows when re-importing the same file', function (): void { /* mirror analog lines 30-40 */ });
```

#### `Modules/Core/tests/Feature/SettingsPageTest.php` (NEW)

**Analog:** Two-source blend.

Primary (Livewire page assertion shape): `Modules/Categorization/tests/Feature/TriagePageTest.php` (full file partially read).
Secondary (User update + period_start_day handling): `Modules/Core/tests/Feature/InstallCommandTest.php` (full file partially read — `period_start_day` round-trip on lines 16-25, 41-46).

**Setup (TriagePageTest analog lines 13-19):**
```php
beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'wessel@example.com',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});
```

**Round-trip test (RESEARCH.md §"Pitfall 5" — full 7-step path):**
```php
it('persists default_currency_view + period_start_day and round-trips into TransactionsList', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('defaultCurrencyView', 'original')
        ->set('periodStartDay', 25)
        ->call('save')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->default_currency_view)->toBe('original');
    expect($this->user->period_start_day)->toBe(25);

    // Visit /transactions with NO ?currency= override — preference wins.
    Livewire::test(TransactionsList::class)
        ->assertSet('currency', '');     // '' = original mode

    // Visit /transactions?currency=eur — URL overrides preference.
    Livewire::withQueryParams(['currency' => 'eur'])
        ->test(TransactionsList::class)
        ->assertSet('currency', 'eur');
});
```

#### `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php` (NEW)

**Analog:** `Modules/Categorization/tests/Feature/TriagePageTest.php` (Livewire-feature shape) + `Modules/Import/tests/Feature/UploadWizardTest.php` (Livewire toggle shape, lines 22-42).

**Pattern (UploadWizardTest analog lines 22-31):**
```php
Livewire::test(TransactionsList::class)
    ->set('currency', '')
    ->assertSee(/* native pair amount */);

Livewire::test(TransactionsList::class)
    ->set('currency', 'eur')
    ->assertSee(/* settled-EUR amount */);
```

#### `Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php` (NEW)

**Analog:** `Modules/Ledger/tests/Unit/ThisPeriodAtAGlanceQueryTest.php` (existing unit test — assumed to seed transactions via Eloquent and assert on the DashboardSummary).

**Test the GROUP-BY-currency branch directly:**
```php
it('groups EUR-only month into a single tile-row in original mode', function (): void {
    // Seed: one user + one ICS-card account + 3 EUR-native transactions in this period.
    // Call forByCurrency() → assert 1 PerCurrencyTile with currency='EUR'.
});

it('returns one tile-row per currency in a mixed EUR/USD month', function (): void {
    // Seed: 2 EUR rows + 1 USD row (synthetic, via direct insert with settled_currency='USD').
    // Call forByCurrency() → assert 2 PerCurrencyTiles, deterministically ordered by currency code.
});
```

---

### `tests/Contracts/IdempotencyContractTest.php` (MODIFIED — dataset)

**Analog:** itself (77 lines, full file in context).

**Current dataset (lines 7-32)** — add `'ics-csv'` row mirroring the `asn-camt053`/`asn-mt940` same-file-fallback shape until a real two-month ICS export exists:
```php
'ics-csv' => [
    'adapterFormat' => 'ics-csv',
    'fixture' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv',
    'overlapBase' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv',
    'overlapNext' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv',
],
```

The `same-file fallback` comment (analog lines 14-20) explicitly documents this is acceptable until a real overlap-period pair lands.

---

### `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` (NEW — Wave 0 deliverable)

**Analog:** `tests/fixtures/asn-sample-1.md` (full structure visible — Confirmed format table + Column map table + Anonymisation protocol section).

**Mirror exactly:** the same markdown headings (`# ICS CSV — empirical fixture record`, `## Confirmed format`, `## Column map`, `## Differences from the prior assumed layout`, `## Anonymisation protocol`) and the same column-map table shape (`| Index | Header | Field | Notes |`).

The Wave 0 plan's deliverable is this file populated with the answers to D-34 (source_ref availability), D-35 (FX-row shape), D-40 (markup separability), and the empirical column count + signature.

---

## Shared Patterns

### DI-only (no facades / no helpers / no `auth()`)

**Source:** `Modules/Core/Public/Services/CurrentUserService.php` (full file, 54 lines) + `Modules/Core/Internal/Http/Livewire/Dashboard.php` lines 70-84.

**Apply to:** Every new Livewire component (`SettingsPage`), every modified Livewire component (`TransactionsList`, `Dashboard`, `UploadWizard`), every new service (`IcsCsvAdapter`).

```php
// CurrentUser is the DI bridge — never `Auth::user()` or `auth()`.
public function render(
    CurrentUser $currentUser,
    TransactionListQuery $listQuery,
    ViewFactory $views,
): View {
    $user = $currentUser->user();
    // ...
}
```

The constructor of a Livewire `Component` subclass takes NO collaborators — they all arrive on method parameters. Constructor-DI is reserved for non-Livewire classes (services, actions, DTOs). This rule is enforced by `phpstan-strict-rules` and was an auto-fix item in Phase 1 (see `.planning/phases/01-foundation-asn-csv-vertical-slice/01-05-SUMMARY.md`, referenced in CONTEXT.md but NOT to be cited in production code per the GSD-agnostic rule).

### Idempotent migrations via `$this->schema()` helper

**Source:** `Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php` (full file, 37 lines).

**Apply to:** The new `add_default_currency_view_to_users.php` migration.

```php
private function schema(): Builder
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection($this->getConnection())->getSchemaBuilder();
}
```

The `app()` helper inside a migration is the standing exception to the DI-only rule (anonymous migration classes have no constructor for Laravel's migrator to call). The PHPDoc cast on the `$db` variable is required to keep Larastan level 10 strict happy.

### Integer-cent money + brick/money / brick/math

**Source:**
- `Modules/Ledger/Public/ValueObjects/Money.php` (full file, 75 lines) — the project's only money construction surface (`Money::ofMinor`).
- `Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php` (full file, 46 lines) — string-regex parsing, integer arithmetic, **no `(float)`, no `round()`**.

**Apply to:** `IcsCsvAdapter` (amount parsing), `NormalizeStage` (fx_rate_used derivation), `ThisPeriodAtAGlanceQuery::forByCurrency` (Money construction from SQL SUMs).

```php
// In NormalizeStage — D-39 rate derivation. NEVER `(float) $settled / (float) $native`.
$fxRateUsed = (string) BigDecimal::of((string) $source->settledAmountMinor)
    ->dividedBy(
        BigDecimal::of((string) $source->amountMinor),
        8,
        RoundingMode::HALF_UP,
    );
```

The `NoFloatMoneyArchTest` (`tests/Contracts/NoFloatMoneyArchTest.php`) is a Pest arch test that lints for float casts on money-bearing code paths.

### Module boundaries (`Public/` vs `Internal/`)

**Source:** Every module follows the convention — verified by `tests/Contracts/BoundaryArchTest.php`.

**Apply to:** Phase 3 file placement:
- New DTO `PerCurrencyTile` → `Modules/Ledger/Public/Dto/` (other modules consume it).
- New adapter `IcsCsvAdapter` → `Modules/Ingestion/Internal/Adapters/Ics/` (only the Public registry maps to it).
- New Livewire `SettingsPage` → `Modules/Core/Internal/Http/Livewire/` (route-driven UI, not consumed by other modules).
- New migration → `Modules/Core/Database/Migrations/` (module owns its own schema growth).

### Spatie laravel-data DTOs

**Source:** Every Public DTO — see `Modules/Ledger/Public/Dto/TransactionRowDto.php` lines 1-29 and `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` lines 1-41.

**Apply to:** `PerCurrencyTile` (new) and the `SourceTransactionDto` extension.

```php
final class PerCurrencyTile extends Data
{
    public function __construct(
        public readonly string $currency,
        public readonly Money $inflow,
        // ...
    ) {}
}
```

Spatie Data gives validation + JSON serialisation + ergonomic constructor without boilerplate getters.

### Cross-module access only via Public contracts

**Source:** `Modules/Categorization/Public/Actions/AssignCategory.php` (full file, 42 lines) — Categorization writes to Ledger only through `UpdatesTransactionCategory` (Public contract).

**Apply to:** `SettingsPage` reads/writes the User row directly because `User` is in `Modules/Core/Models/` (the writing component is in `Modules/Core/Internal/`, same module — direct Eloquent access is permitted). `Dashboard` calling `ThisPeriodAtAGlanceQuery::forByCurrency` is a Core→Ledger call, but `ThisPeriodAtAGlanceQuery` lives in `Modules/Ledger/Public/Services/` — same Public-contract pattern as Phase 1/2.

---

## No Analog Found

No file in this phase lacks a close analog. Two have only partial matches:

| File | Role | Data Flow | Notes |
|------|------|-----------|-------|
| `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php` | test (feature) | test | The transaction detail page itself is not yet built in Phase 1/2 (deferred). The Phase 3 plan should clarify whether the detail page lands in Phase 3 alongside this test, or whether D-48 explicitly defers the detail-page UI to a later phase. If the page lands in Phase 3, mirror the `TriagePageTest` shape; if it does not, defer this test alongside the page. |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvAdapter.php` — Pattern 2 (multi-row look-ahead, D-35 shape (b)) | adapter | streaming | No existing adapter in the codebase performs row-buffering. Phase 4 PayPal will be the first true peer. If Wave 0 confirms shape (b), the planner is the first to land this pattern; RESEARCH.md §"Pattern 2" supplies the reference shape. |

---

## Metadata

**Analog search scope:**
- `Modules/Ingestion/Internal/Adapters/Asn/` (full directory — every CSV / CAMT / MT940 adapter file)
- `Modules/Ingestion/Public/` (Dto, Services, Contracts — full directory)
- `Modules/Import/Internal/` (Pipeline, Http/Livewire — full directory)
- `Modules/Import/tests/Feature/`, `Modules/Import/tests/Unit/` (full)
- `Modules/Ledger/Public/Services/`, `Modules/Ledger/Public/Dto/`, `Modules/Ledger/Internal/Http/Livewire/`
- `Modules/Ledger/Database/Migrations/` (full)
- `Modules/Core/Internal/Http/Livewire/`, `Modules/Core/Internal/Console/`, `Modules/Core/Models/`, `Modules/Core/Database/Migrations/`
- `Modules/Categorization/Internal/Http/Livewire/`, `Modules/Categorization/tests/Feature/`
- `tests/Contracts/` (IdempotencyContractTest)
- `tests/fixtures/` (asn-sample-1.md fixture-record template)

**Files scanned:** ~50 PHP files + 5 Blade views + 2 migrations + 4 markdown fixture records.

**Pattern extraction date:** 2026-05-13
