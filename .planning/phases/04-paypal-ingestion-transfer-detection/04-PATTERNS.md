# Phase 4: PayPal Ingestion + Transfer Detection - Pattern Map

**Mapped:** 2026-05-15
**Files analyzed:** 24 new + 11 modified
**Analogs found:** 33 / 35 (2 files have no direct analog — patterns derived from RESEARCH.md `Code Examples`)

This map binds every new / modified file in Phase 4 to a concrete existing analog in the codebase. Each "Pattern Assignments" entry gives the analog file path, the lines to read, the import + skeleton lines to copy, and the deltas to apply. The planner consumes this in `<read_first>` and `<action>` blocks.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php` | adapter (CSV streaming) | transform / lazy-generator | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` (Generator+league/csv) + `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php` (stateful `statementMetadata()`) | exact (composite of both) |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php` | format profile / detector | config + dispatch | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` | role + data-flow match |
| `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php` | enum / lookup table | transform | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php` (static-const map shape) | role match (no exact analog — domain-specific) |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvColumnMap.php` | column index map | transform | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php` | exact |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalAmountParser.php` | parser / utility | transform | `Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php` | exact (locale differs) |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php` | parser / utility | transform | `Modules/Ingestion/Internal/Adapters/Ics/IcsDateParser.php` | exact (locale differs) |
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php` | rollup walker | transform (buffered) | **No exact analog** — single-purpose walker; closest shape is `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter::iterateTransactionBlocks()` (block-folding logic). Algorithm comes from RESEARCH.md §"Pattern 1" + PITFALLS Pitfall 3. | partial (algorithm-only) |
| `Modules/Ingestion/Public/Exceptions/UnsupportedPaypalCsvLanguageException.php` | typed exception | request-response | `Modules/Ingestion/Public/Exceptions/SniffMismatchException.php` | exact |
| `Modules/Ingestion/Public/Exceptions/UnknownPaypalEventTypeException.php` | typed exception | request-response | `Modules/Ingestion/Public/Exceptions/SniffMismatchException.php` | exact |
| `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` | test fixture | static data | `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt` (committed redacted Wave-0 fixture) | exact pattern |
| `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md` | fixture-record doc | doc | `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` | exact |
| `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-tiny.csv` | minimal-coverage fixture | static data | `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` + `ics-sample-tiny.md` | exact |
| `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php` | unit test | test | `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php` | exact |
| `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalTransactionRollupTest.php` | unit test (Pest dataset) | test | `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php` (Pest dataset shape) | role match (Pest dataset) |
| `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalAmountParserTest.php` | unit test | test | `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php` | exact |
| `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalDateParserTest.php` | unit test | test | `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsDateParserTest.php` | exact |
| `Modules/Import/Public/Events/TransactionImported.php` | domain event | event-driven | `Modules/Categorization/Public/Events/TransactionCategorized.php` (closest existing per-row event) + `Modules/Core/Public/Events/UserInstalled.php` (event-class shape) | exact |
| `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php` | pipeline stage | transform | `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` | exact |
| `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php` | schema migration (forward-only) | schema | `Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php` (column-add) + `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` (most-recent column-add) | exact |
| `Modules/Transfers/` (module directory) | bounded module | n/a | `Modules/Ingestion/` (minimal-shape module) + `Modules/Categorization/` (module with one listener) | role match (composite) |
| `Modules/Transfers/composer.json` | module manifest | config | `Modules/Ingestion/composer.json` | exact |
| `Modules/Transfers/Providers/TransfersServiceProvider.php` | service provider | config | `Modules/Categorization/Providers/CategorizationServiceProvider.php` (event listener registration) + `Modules/Ingestion/Providers/IngestionServiceProvider.php` (minimal-binding shape) | exact (composite) |
| `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` | event listener | event-driven | `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` (listener class shape) — query body comes from RESEARCH.md §"Code Examples" Example 2 + `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` (query pattern) | role match (composite) |
| `Modules/Transfers/tests/Pest.php` | test bootstrap | test | `Modules/Categorization/tests/Pest.php` | exact |
| `Modules/Transfers/tests/TestCase.php` | test base | test | `Modules/Categorization/tests/TestCase.php` | exact |
| `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php` | feature test | test | `Modules/Categorization/tests/Feature/TriagePageTest.php` (per-user fixture setup) | exact |
| `scripts/anonymize_paypal_csv.php` | anonymisation script | transform | `scripts/anonymize_ics_text.php` | exact |
| `local/paypal/.gitkeep` | gitignored dir | n/a | existing `local/ics/` precedent (mentioned in Phase 3 CONTEXT) | exact |
| `Modules/Import/Public/Actions/ConfirmImport.php` (MODIFIED) | action / orchestrator | event-driven | self — extend the outer transaction loop to fire `TransactionImported` per inserted row | self |
| `Modules/Ledger/Public/Actions/RecordTransactions.php` (MODIFIED) | action / persistence | event-driven | self — alternative fire-site (RESEARCH recommends here; planner picks) | self |
| `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` (MODIFIED) | pipeline stage | transform | self — compose `ClassifyTransactionType` step into pipeline OR keep separate stage | self |
| `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` (NO CODE CHANGE) | registry | config | self — registry receives the new entry from `IngestionServiceProvider` map | self |
| `Modules/Ingestion/Providers/IngestionServiceProvider.php` (MODIFIED) | provider / wiring | config | self — add `'paypal-csv' => $app->make(PaypalCsvAdapter::class)` | self |
| `Modules/Ingestion/Public/Services/HeaderSniffer.php` (MODIFIED) | service / validation | request-response | self — extend `match` switch with new `sniffPaypalCsv()` arm | self |
| `Modules/Import/Public/Services/SourceRefRanker.php` (MODIFIED) | service / ranking | transform | self — add `'paypal-csv' => 1` match arm | self |
| `Modules/Import/Internal/Http/Livewire/UploadWizard.php` (MODIFIED) | Livewire component | request-response | self — add `'paypal'` issuer option + `'paypal-csv'` leaf + updates to `rules()` / `availableFormats()` / `sanitiseFilename()` | self |
| `Modules/Import/Internal/Http/Livewire/PreviewWizard.php` (MODIFIED) | Livewire component | request-response | self — generalise `needsIcsAccountName()` to `needsAccountNameForKind()` + add `savePaypalAccountName()` mirror of `saveIcsAccountName()` | self |
| `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` (MODIFIED) | Livewire component | request-response | self — add `reclassify()` action + dropdown; mutate `type` and pair_transaction_id atomically | self |
| `Modules/Ledger/Models/Transaction.php` (MODIFIED) | Eloquent model | data | self — add `pair_transaction_id` to `$fillable` + cast (integer) + `pair()` BelongsTo relation | self |
| `tests/Contracts/IdempotencyContractTest.php` (MODIFIED) | contract test | test | self — add `'paypal-csv'` dataset entry pointing at fixtures/paypal/paypal-sample-1.csv | self |
| `bootstrap/providers.php` (MODIFIED) | bootstrap config | config | self — add `TransfersServiceProvider::class` line | self |
| `.planning/REQUIREMENTS.md` (MODIFIED) | doc | doc | self — move ING-09 to "Deferred / future-revisit" with business-account trigger | self |
| `.planning/ROADMAP.md` (MODIFIED) | doc | doc | self — adjust Phase 4 SC #2 wording | self |
| `.gitignore` (MODIFIED — verify only) | config | config | self — confirm `/local/` already excludes `local/paypal/`; no edit needed if so | self |

## Pattern Assignments

### `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php` (adapter, lazy-generator)

**Primary analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` (lines 1-199 — read whole file)
**Secondary analog:** `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php` (lines 80-160 for the stateful `statementMetadata()` capture)

**Imports pattern** (copy from AsnCsvAdapter lines 1-17):
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

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
```

**Constructor pattern** (composite of Asn lines 38-41 + Ics lines 82-87):
```php
public function __construct(
    private readonly HeaderSniffer $sniffer,
    private readonly PaypalAmountParser $amounts,
    private readonly PaypalDateParser $dates,
    private readonly PaypalCsvLanguageProfile $languageProfile,
    private readonly PaypalCsvEventTypeMap $eventTypes,
    private readonly PaypalTransactionRollup $rollup,
) {}
```

**`format()` pattern** (verbatim from Asn lines 43-46):
```php
public function format(): string
{
    return PaypalCsvLanguageProfile::FORMAT;   // mirrors AsnCsvHeaderProfile::FORMAT
}
```

**Stateful summary pattern** (copy from Ics lines 80, 94-106 — NOT the Asn null-return; PayPal is stateful):
```php
private ?StatementSummaryData $lastStatementMetadata = null;

public function statementMetadata(): ?StatementSummaryData
{
    return $this->lastStatementMetadata;
}
```

**`parse()` skeleton** (compose Asn lines 58-117 + Ics lines 108-160):
```php
public function parse(string $localPath, AccountResolver $accounts): Generator
{
    $this->sniffer->sniff($localPath, PaypalCsvLanguageProfile::FORMAT);
    $this->lastStatementMetadata = null;

    $reader = Reader::from($localPath, 'r');
    $reader->setDelimiter(PaypalCsvLanguageProfile::DELIMITER);
    $reader->setEscape('');
    $reader->setHeaderOffset(0);
    CharsetConverter::addTo($reader, PaypalCsvLanguageProfile::SOURCE_ENCODING, 'UTF-8');

    // Buffer all raw rows for the rollup walker (D-61). PayPal's per-event
    // shape means we can't yield row-by-row from league/csv directly — the
    // walker needs the full set to resolve Reference-Txn-ID parent/child
    // links before emitting canonical DTOs.
    $rawRows = [];
    foreach ($reader->getRecords() as $record) {
        $rawRows[] = $record;
    }

    $accounts->resolve('PAYPAL');   // synthetic IBAN (D-66)

    $rolledUp = $this->rollup->rollup($rawRows);   // returns list<SourceTransactionDto>

    $bookedDates = [];
    $count = 0;
    foreach ($rolledUp as $dto) {
        yield $dto;
        $bookedDates[] = $dto->bookedAt;
        $count++;
    }

    // Stateful summary capture — mirrors Ics lines 153-159.
    $this->lastStatementMetadata = $this->buildStatementMetadata(
        ownIban: 'PAYPAL',
        bookedDates: $bookedDates,
        entryCount: $count,
        skippedHoldCount: $this->rollup->skippedHoldCount(),
        orphanChildCount: $this->rollup->orphanChildCount(),
    );
}
```

**Error handling pattern** (copy from Asn lines 81-87 — wrap parser exceptions with row index for user-visible preview-error surface):
```php
try {
    /* parse row */
} catch (Throwable $e) {
    throw new InvalidAmountException(
        sprintf('Row %d: %s', $index, $e->getMessage()),
        0,
        $e,
    );
}
```

---

### `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php` (profile / detector)

**Analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` (lines 1-41 — read whole file)

**Class skeleton** (verbatim shape from Asn):
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

final class PaypalCsvLanguageProfile
{
    public const FORMAT = 'paypal-csv';
    public const DELIMITER = ',';
    public const HAS_HEADER = true;
    public const SOURCE_ENCODING = 'UTF-8';

    // EXPECTED_COLUMN_COUNT not declared at class top — Wave 0 locks the value
    // and a future second-language profile may differ. Profile-instance state
    // carries it instead.

    // Language detection differs from AsnCsvHeaderProfile (which is single-
    // language): instead of one HEADER_SIGNATURE constant, expose a list of
    // registered language profiles keyed by name.
    public static function detect(array $columns): ?self { /* ... */ }
    public static function supported(): array { /* ... */ }
    public function detected(): string { /* 'en' | 'nl' | ... */ }
}
```

**Delta vs AsnCsvHeaderProfile:** AsnCsvHeaderProfile is a static-only constant bag; PaypalCsvLanguageProfile is instance-stateful (carries the detected language). Wave 0 locks the empirical token vocabulary into a private const array.

---

### `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php` (enum / lookup)

**Analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php` (lines 1-56 — read whole file for the static-const-only shape)

**Class skeleton** (analog shape, domain-specific contents):
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Paypal;

/**
 * Maps PayPal Activity Download event-type strings to one of:
 *   - 'skip'         (Hold / Authorization / Reserve / Reversal of General Account Hold — D-62)
 *   - 'parent'       (Express Checkout Payment, Subscription Payment, General Withdrawal, Refund, ...)
 *   - 'child-fee'    (fee row riding under a parent — folds into rawPayload manifest)
 *   - 'child-fx'     (Currency Conversion row — folds into the dual-amount pair per D-63)
 *
 * The map is language-keyed via PaypalCsvLanguageProfile::detected(); the
 * adapter looks up the canonical action for the localised event-type
 * string the CSV row carries.
 *
 * Wave 0 locks the empirical event-type vocabulary into the const map.
 */
final class PaypalCsvEventTypeMap
{
    // Concrete map populated by Wave 0. Shape:
    // private const MAP_EN = [
    //     'Express Checkout Payment' => 'parent',
    //     'Currency Conversion'      => 'child-fx',
    //     'Refund'                   => 'parent',          // type='refund'
    //     'General Withdrawal'       => 'parent',          // type='transfer_out'
    //     'Hold'                     => 'skip',
    //     'Authorization'            => 'skip',
    //     'Reserve'                  => 'skip',
    //     'Reversal of General Account Hold' => 'skip',
    // ];

    public function classify(string $eventType, string $language): string { /* 'skip'|'parent'|'child-fee'|'child-fx' */ }
    public function transactionType(string $eventType, string $language): string { /* returns Transaction::TYPES value */ }
}
```

---

### `Modules/Ingestion/Internal/Adapters/Paypal/PaypalAmountParser.php` (parser)

**Analog:** `Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php` (lines 1-45 — read whole file)

**Imports + class header** (verbatim from Asn lines 1-7):
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

final class PaypalAmountParser
{
    public function parseMinor(string $raw): int { /* ... */ }
}
```

**Core integer-only regex pattern** (model on Asn lines 28-44 — same shape, US-locale regex):
- Asn: `/^([+-]?)(\d+)\.(\d{2})$/` (period-decimal, no thousands separator)
- PayPal US locale: `/^([+-]?)(\d{1,3}(?:,\d{3})*)\.(\d{2})$/` (comma thousands, dot decimal)
- Strip commas from `$m[2]` before `(int)`, then `$sign * ($whole * 100 + $fractional)`
- NEVER `(float)` / `round()` / `intval(float)` — the integer arithmetic invariant (NoFloatMoneyArchTest gate) is non-negotiable

---

### `Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php` (parser)

**Analog:** `Modules/Ingestion/Internal/Adapters/Ics/IcsDateParser.php` (lines 1-98 — read whole file)

**Imports + class header** (verbatim from Ics lines 1-7):
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Carbon\CarbonImmutable;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

final class PaypalDateParser
{
    public function parse(string $raw): CarbonImmutable { /* startOfDay normalised */ }
}
```

**Deltas vs IcsDateParser:**
- IcsDateParser handles Dutch month abbreviations (`jan`, `feb`, `mrt` etc. — `self::NL_MONTHS` const map on lines 36-45) — PayPal US locale uses NUMERIC dates (`m/d/yyyy` or `yyyy-mm-dd`); strip NL_MONTHS, replace with two `CarbonImmutable::createFromFormat('!m/d/Y', …)` arms
- `startOfDay()` normalisation MUST be preserved (Ics line 71, 93) — same FingerprintComposer v3 day-precision invariant applies
- Stateless / no global locale mutation (Ics class header line 31-32) — same posture

---

### `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php` (single-purpose walker)

**Analog:** **No direct codebase analog** — the closest shape is `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter::iterateTransactionBlocks()` (lines 243-274) which folds a following `Wisselkoers` line into the same logical row. Algorithm comes from RESEARCH.md §"Pattern 1" + PITFALLS Pitfall 3.

**Constructor pattern** (Asn-style DI; verify against project DI-only rule):
```php
<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

final class PaypalTransactionRollup
{
    private int $skippedHoldCount = 0;
    private int $orphanChildCount = 0;

    public function __construct(
        private readonly PaypalCsvEventTypeMap $events,
        private readonly PaypalAmountParser $amounts,
        private readonly PaypalDateParser $dates,
        private readonly PaypalCsvLanguageProfile $languageProfile,
    ) {}

    /**
     * @param  iterable<int, array<string, string>>  $rawRows
     * @return list<SourceTransactionDto>
     */
    public function rollup(iterable $rawRows): array { /* three-pass algorithm */ }

    public function skippedHoldCount(): int { return $this->skippedHoldCount; }
    public function orphanChildCount(): int { return $this->orphanChildCount; }
}
```

**Three-pass algorithm** (verbatim from RESEARCH.md §"Pattern 1" lines 336-379):
- **Pass 1:** Build `$byTxnId` index keyed by `Transaction ID`. Skip filtered event types (`classify() === 'skip'`) up front; bump `$skippedHoldCount`.
- **Pass 2:** Partition rows into parent vs child groups via `Reference Txn ID`. Orphan children become standalone parents; bump `$orphanChildCount`.
- **Pass 3:** Fold each parent's children into ONE `SourceTransactionDto`:
  - `amountMinor` / `currency`: from foreign-currency leg of any `child-fx` sibling, otherwise from parent
  - `settledAmountMinor` / `settledCurrency`: from EUR leg of `child-fx` pair (or null when EUR-native)
  - `fxRateUsed`: ALWAYS null (NormalizeStage derives per Phase 3 D-39)
  - `rawPayload`: `{ format: 'paypal-csv', events: [{ type, row }, …] }` (D-65)
  - `sourceRef`: parent's `Transaction ID` (D-64)
  - `sourceRowIndex`: canonical-row scope, monotonically increasing 0..N

**FX-direction safety net** (Pitfall 2): `if ($row['Currency'] !== 'EUR') { /* this is the native leg */ }` — never pick "the second row" arbitrarily.

---

### `Modules/Ingestion/Public/Exceptions/UnsupportedPaypalCsvLanguageException.php` & `UnknownPaypalEventTypeException.php` (typed exceptions)

**Analog:** `Modules/Ingestion/Public/Exceptions/SniffMismatchException.php` (read whole file — ~10 lines)

**Both files copy this shape verbatim:** namespace under `Modules\Ingestion\Public\Exceptions`, extend `RuntimeException` (or whichever base the existing exceptions use), constructor with a string message. The message must be user-facing (rendered verbatim in the wizard).

---

### `Modules/Import/Public/Events/TransactionImported.php` (domain event)

**Analog:** `Modules/Categorization/Public/Events/TransactionCategorized.php` (read whole file — 22 lines)

**Verbatim shape** (per RESEARCH.md §"Pattern 3" lines 438-465 — adjusted to use User+Transaction objects rather than just ids, because the listener needs the full Transaction):
```php
<?php

declare(strict_types=1);

namespace Modules\Import\Public\Events;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

/**
 * Dispatched once per Transaction row INSERTED by the import pipeline (not
 * for duplicates that insertOrIgnore silently dropped, not for enriched
 * rows that ApplyEnrichments updated).
 *
 * The event is synchronous and dispatched INSIDE the outer DB transaction
 * (no ShouldHandleEventsAfterCommit): listeners that need to query for
 * newly-inserted partner rows within the same import batch
 * (PairTransferCandidates) require the in-transaction read visibility.
 */
final readonly class TransactionImported
{
    public function __construct(
        public Transaction $transaction,
        public User $user,
    ) {}
}
```

**Anti-pattern reminder:** DO NOT add `ShouldHandleEventsAfterCommit`. DO NOT make this implement `ShouldQueue`. Listener runs sync in-transaction (Pitfall 1).

---

### `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php` (pipeline stage)

**Analog:** `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` (read whole file — 116 lines)

**Imports pattern** (from NormalizeStage lines 1-12):
```php
<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Modules\Core\Models\User;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
```

**Class shape** (from NormalizeStage lines 39-45 — final class with DI constructor + single `run()` method):
```php
final class ClassifyTransactionType
{
    public function __construct(
        private readonly PaypalCsvEventTypeMap $eventTypes,
    ) {}

    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction { /* ... */ }
}
```

**Algorithm** (verbatim from RESEARCH.md §"Code Examples" Example 3 lines 850-893):
1. Preserve already-classified `refund` / `fee` / `adjustment` rows
2. Cross-account-IBAN check: if `$tx->counterpartyIban` matches one of the user's `Account.iban` (excluding self), flip to `transfer_out` (negative) or `transfer_in` (positive)
3. Source-format event-type map (PayPal only): use rawPayload manifest's first event type → consult `PaypalCsvEventTypeMap::transactionType()`
4. Subtractive income detector (D-77): positive amount AND NOT transfer/refund/fee → `'income'`
5. Default: keep NormalizeStage's amount-sign-derived default

**Cross-user safety:** every Account query MUST filter on `$user->id` (verified pattern from Phase 3-07 cross-user 404 tests + FingerprintStage lines 57-62).

**`CanonicalTransaction::withType()`** — the DTO is already a `spatie/laravel-data` `Data` class; add a `withType(string $type): self` clone-with-override method on the DTO (this is the cleanest path — mirrors how `StatementSummaryData::withImportRunId()` works).

**Decoupling discipline** (Pitfall 3): NEVER re-classify an already-persisted row. NEVER query `transactions` from this stage. NEVER mutate `pair_transaction_id` here.

---

### `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php` (schema migration)

**Analog:** `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` (read whole file — 50 lines; most-recent migration with the canonical shape) + `Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php` (37 lines, nearly identical shape)

**Skeleton** (verbatim shape from `add_raw_payload_to_transactions.php`):
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
            $table->foreignId('pair_transaction_id')
                ->nullable()
                ->after('settled_amount_minor')
                ->constrained('transactions')
                ->nullOnDelete();
        });

        // Partial index for the listener's hot-path lookup (RESEARCH §"Pattern 4")
        $this->db()->connection($this->getConnection())->statement(
            "CREATE INDEX transactions_unpaired_transfer_idx ON transactions(user_id, account_id, booked_at) ".
            "WHERE pair_transaction_id IS NULL AND type IN ('transfer_out', 'transfer_in')"
        );
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropForeign(['pair_transaction_id']);
            $table->dropColumn('pair_transaction_id');
        });
        $this->db()->connection($this->getConnection())->statement(
            'DROP INDEX IF EXISTS transactions_unpaired_transfer_idx'
        );
    }

    private function schema(): Builder
    {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);

        return $db->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);

        return $db;
    }
};
```

**DI-only exception note (from existing migrations lines 28-36):**
> "Anonymous migrations are instantiated by Laravel's migrator with no constructor arguments, so the schema builder is resolved from the container at the migration boundary. This is the standing Laravel-migration exception to the DI-only rule."

Preserve this comment verbatim — it documents why `app(DatabaseManager::class)` is acceptable here despite the DI-only rule.

**No fingerprint version bump** — `pair_transaction_id` is NOT in the v3 fingerprint tuple (verified: see FingerprintStage line 55-61).

---

### `Modules/Transfers/` (new bounded module)

**Analog:** `Modules/Ingestion/` directory layout (minimal-shape module) + `Modules/Categorization/` (module with one listener registration)

**Directory shape to mirror (composer.json + Providers + Internal/Listeners + tests):**
```
Modules/Transfers/
├── composer.json
├── Internal/
│   └── Listeners/
│       └── PairTransferCandidates.php
├── Providers/
│   └── TransfersServiceProvider.php
├── Public/                    # stays empty in Phase 4 (D-80)
└── tests/
    ├── Pest.php
    ├── TestCase.php
    ├── Unit/
    └── Feature/
        └── PairTransferCandidatesTest.php
```

---

### `Modules/Transfers/composer.json`

**Analog:** `Modules/Ingestion/composer.json` (read whole file — 17 lines)

**Verbatim copy with namespace swap:**
```json
{
    "name": "diederik/transfers",
    "description": "Transfers module — cross-account pair detection listener.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\Transfers\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Transfers\\Tests\\": "tests/"
        }
    }
}
```

---

### `Modules/Transfers/Providers/TransfersServiceProvider.php` (service provider)

**Primary analog:** `Modules/Categorization/Providers/CategorizationServiceProvider.php` (read whole file — 53 lines; canonical event-listener registration shape)
**Secondary analog:** `Modules/Ingestion/Providers/IngestionServiceProvider.php` (lines 1-50 — minimal-binding shape, no Livewire components)

**Shape** (from CategorizationServiceProvider lines 1-53 — strip the AssignsCategory binding + Livewire registrations; keep the `boot(Dispatcher $events)` event registration):
```php
<?php

declare(strict_types=1);

namespace Modules\Transfers\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Transfers\Internal\Listeners\PairTransferCandidates;

final class TransfersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings in Phase 4 — listener is constructed via DI when the
        // dispatcher resolves it.
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(TransactionImported::class, PairTransferCandidates::class);
    }
}
```

**Critical:** Phase 4 module DOES NOT call `$this->loadMigrationsFrom(...)` / `$this->loadRoutesFrom(...)` / `$this->loadViewsFrom(...)` — the module has no migrations, no routes, no views.

---

### `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` (event listener)

**Analog (shape):** `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` (read whole file — 25 lines; canonical listener-class shape)
**Analog (query body):** `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` lines 53-83 (DatabaseManager + user-scoped queries) + RESEARCH.md §"Code Examples" Example 2 (full listener body)

**Class shape** (mirror SeedDefaultCategoryTree's exact structure):
```php
<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

final class PairTransferCandidates
{
    private const WINDOW_DAYS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function handle(TransactionImported $event): void { /* body from RESEARCH Example 2 */ }
}
```

**Listener body** (RESEARCH.md §"Code Examples" Example 2, lines 750-836 — copy verbatim):
- Bail if `$tx->type` is not in `['transfer_out', 'transfer_in']`
- Bail if `$tx->pair_transaction_id !== null` (already paired)
- Bail if `$tx->counterparty_iban === null`
- `Account::query()->where('user_id', $user->id)->where('iban', $tx->counterparty_iban)->first()` — bail if no own-account match
- `$windowStart = $tx->booked_at->subDays(self::WINDOW_DAYS); $windowEnd = $tx->booked_at->addDays(self::WINDOW_DAYS);`
- Partner query: `Transaction::query()->where('user_id', $user->id)->where('account_id', $partnerAccount->id)->where('amount_minor', -$tx->amount_minor)->where('currency', $tx->currency)->whereBetween('booked_at', [$windowStart, $windowEnd])->whereNull('pair_transaction_id')->whereIn('type', ['transfer_out', 'transfer_in'])->where('id', '!=', $tx->id)->orderBy('booked_at')->first()`
- Symmetric write: BOTH sides get `pair_transaction_id` set in the same `handle()` call — NO new `DB::transaction(…)` wrapper (the listener inherits the outer transaction frame from `RecordTransactions`/`ConfirmImport`)

**Pitfall reminders:**
- DO NOT implement `ShouldHandleEventsAfterCommit` (Pitfall 1).
- DO NOT update `type` on the partner row (Pitfall 3 — the listener LINKS, never re-types).
- DO NOT wrap in `DB::transaction(...)` — adds savepoint overhead with no benefit; inherits the outer transaction frame already.
- Defensive `$event->transaction->user_id === $event->user->id` invariant assertion (T-04-02 mitigation, RESEARCH §"Security Domain").

**Cross-user safety:** every query filters on `$user->id`. The Phase 3-07 cross-user 404 pattern applies.

---

### `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` + `.md` (Wave 0 fixtures)

**Analog:** `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt` + `ics-sample-1.md` (committed redacted fixture + fixture-record doc — both read directly)

**Pattern reminders:**
- Raw CSV stays under `local/paypal/` (gitignored under `/local/` — verify in Wave 0)
- Redacted fixture committed under `Modules/Ingestion/tests/fixtures/paypal/`
- `.md` doc records: language detected, columns observed, event types observed, parent/child chain shapes seen, FX representation, reconciliation result
- Deterministic two-pass anonymisation (Pitfall 5): `$realToSynthetic` map ensures parent-child `Reference Txn ID` links survive

---

### `scripts/anonymize_paypal_csv.php` (anonymisation script)

**Analog:** `scripts/anonymize_ics_text.php` (read whole file for the idempotent regex-pass shape)

**Patterns to mirror:**
- Idempotent — re-runnable on the same input produces identical output
- Two-pass `Transaction ID` deterministic counter map (Pitfall 5)
- Email → `kaarthouder@example.test`
- Name → `KAARTHOUDER`
- IBAN → mod-97-valid `NL00ASNB0000000000` (same anonymisation Phase 2 CAMT fixture uses)
- Preserve dates / amounts / currencies / event-type strings / merchant strings VERBATIM
- Address / ZIP redaction

---

### `Modules/Import/Public/Actions/ConfirmImport.php` (MODIFIED)

**Analog:** self — read lines 1-132. The change adds a `Dispatcher` constructor injection + fires `TransactionImported` for each row inserted by the recorder.

**Two implementation options (planner picks):**

**Option A: Fire from RecordTransactions** (RESEARCH recommendation, lines 471-481). Edit `Modules/Ledger/Public/Actions/RecordTransactions.php` to inject `Dispatcher` and dispatch inside the foreach after `$effected === 1`. ConfirmImport remains unchanged. Cleaner.

**Option B: Fire from ConfirmImport** (alternate fire-site). Loop over the recorder's returned inserted-rows list and dispatch from ConfirmImport. Adds an extra iteration.

**RESEARCH recommends Option A** — verified at RESEARCH.md §"Code Examples" Example 3 + the "Don't Hand-Roll" table line "Per-row event in `RecordTransactions`".

**Dispatcher injection pattern** (verified from CategorizationServiceProvider line 7 + the project's DI-only constraint):
```php
private readonly Dispatcher $events,   // Illuminate\Contracts\Events\Dispatcher
```
NEVER `event(…)` helper. NEVER `Event::dispatch(…)` facade.

**Fire-site (Option A, modifying `RecordTransactions::__invoke()` lines 44-69):**
After `$effected = Transaction::insertOrIgnore($attrs);` and `if ($effected === 1)`:
```php
$inserted++;
// Fetch the just-inserted row via fingerprint (NOT via lastInsertId because
// insertOrIgnore doesn't reliably populate it across drivers).
$persisted = Transaction::query()
    ->where('user_id', $row->userId)
    ->where('fingerprint', $fingerprint)
    ->firstOrFail();
// User lookup: prefer injecting via the action's caller context. The
// existing $row carries userId only; the listener needs a User model.
// Resolve via DI'd User repository or pass user model into __invoke().
$this->events->dispatch(new TransactionImported($persisted, $userModel));
```

**Edge:** the existing `__invoke()` takes `iterable $canonical` and has no `User` parameter. Adjust signature to `__invoke(iterable $canonical, User $user)` and propagate from `ConfirmImport::__invoke()` (which DOES have `User $user` at line 48). This signature change is a small breaking diff inside the module's Public surface — call sites: `ConfirmImport` line 100. Verify no other consumers exist before changing.

---

### `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` (MODIFIED)

**Analog:** self — read lines 1-116. Planner picks between two integration shapes:

**Shape A: Compose ClassifyTransactionType into NormalizeStage** (lighter — just call the classifier at the end of `run()` before returning the `CanonicalTransaction`)
**Shape B: Keep ClassifyTransactionType as a sibling stage** in `Modules/Import/Internal/Pipeline/Stages/` and wire it into `ImportPipeline::preview()` between Normalize and Fingerprint (read `Modules/Import/Internal/Pipeline/ImportPipeline.php` lines 67-110 for the iteration shape)

**RESEARCH discretion (D-76):** planner picks. Shape B is cleaner architecturally; Shape A is fewer file edits. Default to Shape B.

---

### `Modules/Ingestion/Providers/IngestionServiceProvider.php` (MODIFIED)

**Analog:** self — read lines 1-50.

**Single delta** (extend `register()` lines 32-40):
```php
$this->app->singleton(
    SourceAdapterRegistry::class,
    static fn (Container $app): SourceAdapterRegistry => new SourceAdapterRegistry([
        'asn-csv' => $app->make(AsnCsvAdapter::class),
        'asn-camt053' => $app->make(AsnCamt053Adapter::class),
        'asn-mt940' => $app->make(AsnMt940Adapter::class),
        'ics-pdf' => $app->make(IcsPdfAdapter::class),
        'paypal-csv' => $app->make(PaypalCsvAdapter::class),   // <-- NEW
    ]),
);
```

Also add the import: `use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;` (top of file).

---

### `Modules/Ingestion/Public/Services/HeaderSniffer.php` (MODIFIED)

**Analog:** self — read lines 1-216.

**Two deltas:**
1. Extend the `match` switch (lines 55-65) with new `paypal-csv` arm:
   ```php
   PaypalCsvLanguageProfile::FORMAT => $this->sniffPaypalCsv($localPath, $head),
   ```
2. Add the `sniffPaypalCsv()` method — mirror `sniffAsnCsv()` (lines 173-215). Differences:
   - No fixed `EXPECTED_COLUMN_COUNT` check (column count varies by language); instead call `PaypalCsvLanguageProfile::detect($columns)`
   - On detection miss, throw `UnsupportedPaypalCsvLanguageException` (not `SniffMismatchException`)
   - See RESEARCH.md §"Code Examples" Example 4 (lines 904-940) for the complete arm

**Add imports** at the top:
```php
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Public\Exceptions\UnsupportedPaypalCsvLanguageException;
```

---

### `Modules/Import/Public/Services/SourceRefRanker.php` (MODIFIED)

**Analog:** self — read whole file (38 lines).

**Single delta** (extend `match` lines 30-35 with `'paypal-csv' => 1`):
```php
return match ($format) {
    'asn-camt053' => 4,
    'asn-mt940' => 2,
    'asn-csv' => 1,
    'paypal-csv' => 1,   // <-- NEW (same band as asn-csv per D-64)
    default => 0,
};
```

PayPal rows never cross-format-enrich ASN rows (different `account_id` always — verified). The rank line is added for completeness so the contract test sweep includes paypal-csv.

---

### `Modules/Import/Internal/Http/Livewire/UploadWizard.php` (MODIFIED)

**Analog:** self — read lines 1-188.

**Five deltas:**

1. `SUPPORTED_FORMATS` const (lines 48-53) — add `'paypal-csv'`:
   ```php
   public const SUPPORTED_FORMATS = [
       'asn-csv', 'asn-camt053', 'asn-mt940', 'ics-pdf', 'paypal-csv',
   ];
   ```

2. `$issuer` `#[Validate]` (line 63) — extend to `'required|in:asn,ics,paypal'`

3. `rules()` (lines 71-78) — extend both `'issuer'` and `'sourceFormat'` validator arrays:
   ```php
   'issuer' => ['required', 'in:asn,ics,paypal'],
   'sourceFormat' => ['required', 'in:asn-csv,asn-camt053,asn-mt940,ics-pdf,paypal-csv'],
   ```

4. `availableFormats()` (lines 99-112) — add `paypal` match arm:
   ```php
   'paypal' => [
       ['value' => 'paypal-csv', 'label' => 'Activity Download (CSV)'],
   ],
   ```

5. `sanitiseFilename()` (lines 179-184) — add extension arm:
   ```php
   $extension = match ($this->sourceFormat) {
       'asn-camt053' => '.xml',
       'asn-mt940' => '.sta',
       'ics-pdf' => '.pdf',
       'paypal-csv' => '.csv',
       default => '.csv',
   };
   ```

`mimes:` validator (line 74) — `csv` is already in the list. No `mimes` change needed.

**Blade view** (`Modules/Import/Resources/views/livewire/upload-wizard.blade.php`) — third issuer radio / select option `PayPal` mirroring the existing `ASN` / `ICS` markup. Planner reads the Blade file during the modify-pass.

---

### `Modules/Import/Internal/Http/Livewire/PreviewWizard.php` (MODIFIED)

**Analog:** self — read lines 1-267 (file is 267 lines).

**Generalisation deltas:**

1. Add synthetic IBAN const for PayPal (after line 54):
   ```php
   private const PAYPAL_OWN_IBAN = 'PAYPAL';
   ```

2. Add `$paypalAccountName = '';` property mirror of `$icsAccountName` (line 60)

3. Add `savePaypalAccountName()` method — copy `saveIcsAccountName()` (lines 134-175) verbatim, swap:
   - `$this->icsAccountName` → `$this->paypalAccountName`
   - `'ics-card'` slug suffix → `'-paypal'`
   - `'ics_card'` kind → `'paypal'`
   - `self::ICS_OWN_IBAN` → `self::PAYPAL_OWN_IBAN`
   - Locked Blade copy: "Name your PayPal account." / "This is the first time you've imported PayPal data..." etc. (RESEARCH §"Pattern 6")

4. Generalise `needsIcsAccountName()` (lines 241-266) — either keep as a sibling `needsPaypalAccountName()` method (cleaner — less code churn), or refactor into `needsAccountNameForKind(): ?string`. Default to the sibling method approach to minimise blast radius:
   ```php
   private function needsPaypalAccountName(CurrentUser $currentUser, DatabaseManager $db): bool
   {
       /* mirror needsIcsAccountName(); source_format check uses 'paypal-csv';
          accounts.kind check uses 'paypal' */
   }
   ```

5. Pass both flags through `render()` (lines 211-220):
   ```php
   return $views->make('import::livewire.preview-wizard', [
       'preview' => $preview,
       'previewExpired' => $this->previewExpired,
       'needsIcsAccountName' => $needsIcsAccountName,
       'needsPaypalAccountName' => $needsPaypalAccountName,
   ]);
   ```

6. Blade partial (`Modules/Import/Resources/views/livewire/preview-wizard.blade.php`) — add the PayPal naming branch alongside the ICS one. Planner reads the partial when modifying.

---

### `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` (MODIFIED — Reclassify action)

**Analog:** self — read lines 1-87 (whole file).

**Two deltas:**

1. Add `reclassify()` Livewire action with `DatabaseManager` parameter (NOT constructor — Livewire components have no constructor; collaborators arrive as method parameters per the class header docblock lines 28-33):

   ```php
   public function reclassify(
       string $newType,
       CurrentUser $currentUser,
       DatabaseManager $db,
   ): void {
       if (! in_array($newType, Transaction::TYPES, true)) {
           throw new \InvalidArgumentException("Invalid transaction type: '{$newType}'");
       }

       $user = $currentUser->user();

       /** @var Transaction $tx */
       $tx = Transaction::query()
           ->where('id', $this->transactionId)
           ->where('user_id', $user->id)
           ->firstOrFail();

       $partnerId = $tx->pair_transaction_id;

       $db->connection()->transaction(static function () use ($tx, $newType, $partnerId, $user): void {
           $tx->type = $newType;
           if (! in_array($newType, ['transfer_out', 'transfer_in'], true)) {
               $tx->pair_transaction_id = null;
           }
           $tx->save();

           if ($partnerId !== null && ! in_array($newType, ['transfer_out', 'transfer_in'], true)) {
               // Break the pair atomically — partner row's pair_transaction_id
               // also clears (D-78 invariant).
               Transaction::query()
                   ->where('user_id', $user->id)
                   ->where('id', $partnerId)
                   ->update(['pair_transaction_id' => null]);
           }
       });
   }
   ```

2. Add the reclassify dropdown to the Blade view (`Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php`). Planner reads + edits during implementation.

**Cross-user safety pattern** — verified from this same file's `mount()` lines 45-67 (the `->where('user_id', $currentUser->user()->id)` guard).

**Single-click + toast UX** (D-78 default) — Filament-notifications-style toast on completion ("Pair removed" / "Reclassified to <type>").

---

### `Modules/Ledger/Models/Transaction.php` (MODIFIED)

**Analog:** self — read whole file (123 lines).

**Three deltas:**

1. PHPDoc property annotation (around line 40):
   ```php
    * @property int|null $pair_transaction_id
   ```

2. `$fillable` (lines 63-76) — add `'pair_transaction_id'`:
   ```php
   protected $fillable = [
       /* ...existing fields... */
       'pair_transaction_id',
   ];
   ```

3. `casts()` (lines 78-97) — no cast needed (FK column, plain integer). Optionally add `'pair_transaction_id' => 'integer'` for symmetry with other FK columns (verify pattern against existing casts — `category_id` has no explicit cast either, so adding pair_transaction_id is optional).

4. Add `pair()` BelongsTo relation:
   ```php
   /**
    * @return BelongsTo<Transaction, $this>
    */
   public function pair(): BelongsTo
   {
       return $this->belongsTo(Transaction::class, 'pair_transaction_id');
   }
   ```

---

### `bootstrap/providers.php` (MODIFIED)

**Analog:** self — read whole file (18 lines).

**Single line addition** + import line:
```php
<?php

declare(strict_types=1);

use Modules\Categorization\Providers\CategorizationServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Import\Providers\ImportServiceProvider;
use Modules\Ingestion\Providers\IngestionServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;
use Modules\Transfers\Providers\TransfersServiceProvider;   // <-- NEW

return [
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
    IngestionServiceProvider::class,
    ImportServiceProvider::class,
    CategorizationServiceProvider::class,
    TransfersServiceProvider::class,                         // <-- NEW
];
```

**No `composer.json` `extra.laravel.providers` change** — the project uses manual `bootstrap/providers.php` registration (RESEARCH §"Pattern 5" — verified against existing 5 providers all manually listed).

**Composer autoloader regeneration:** Phase 4 ALSO needs `composer dump-autoload` after adding the new module's psr-4 entry. The root `composer.json` likely has a `Modules/` autoload entry (verify during plan-phase); if it uses path-globbing autoload via the `merge-plugin` mechanism, the new module's `composer.json` is auto-discovered. If it uses explicit per-module psr-4 entries, the planner adds `"Modules\\Transfers\\": "Modules/Transfers/"` (or whatever shape the existing entries take). Planner reads root `composer.json` to lock the exact diff.

---

### `tests/Contracts/IdempotencyContractTest.php` (MODIFIED)

**Analog:** self — read whole file (83 lines).

**Single delta:** add the `'paypal-csv'` dataset entry to the `dataset('idempotent_adapters', [...])` block (lines 7-38), pointing at the Wave 0 fixture:
```php
'paypal-csv' => [
    'adapterFormat' => 'paypal-csv',
    'fixture' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv',
    'overlapBase' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv',
    'overlapNext' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv',
],
```

Same-file fallback for overlap (consistent with the CAMT / MT940 fallback at lines 20-31). Both tests already accept the same-file fallback at lines 70-74.

---

### `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php` (NEW unit test)

**Analog:** `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php` (existing file — read for structure: beforeEach binding pattern, fixture-path constant, generator-walking assertion)

**Pest dataset for Pitfall 2 (FX rollup):** snapshot test asserting that a Currency-Conversion pair from the fixture produces a single DTO with both legs populated. `spatie/pest-plugin-snapshots` is the library (verified installed).

---

### `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php` (NEW feature test)

**Analog:** `Modules/Categorization/tests/Feature/TriagePageTest.php` (lines 1-60 — per-user fixture setup pattern with `beforeEach` creating User + Account + ImportRun + Categories)

**Test cases to cover** (from RESEARCH §"Phase Requirements → Test Map"):
- `pairsAsnIcsSettlement` — both legs land, both pair_transaction_ids set, both rows query each other
- `halfPair` — only one leg lands, type stays `transfer_out`, `pair_transaction_id IS NULL`
- `partnerLandsLater` — half-pair becomes full-pair when partner arrives via second import
- `doesNotRetype` — listener never changes `type`
- `cannotSelfPair` — `where('id', '!=', $tx->id)` covers this
- `cannotCrossUserPair` — User-A's transfer + User-B's mirror IBAN never pair

**`Modules/Transfers/tests/Pest.php`** — copy `Modules/Categorization/tests/Pest.php` verbatim (test-bootstrap shape).

**`Modules/Transfers/tests/TestCase.php`** — copy `Modules/Categorization/tests/TestCase.php` (RefreshDatabase trait, app boot).

---

## Shared Patterns

### Authentication / Cross-User Safety

**Source:** Pattern used in every Livewire component + every cross-module query — verified against `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` lines 45-67, `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` lines 53-62, `Modules/Categorization/Public/Actions/AssignCategory.php` patterns.
**Apply to:** Every new query in `PairTransferCandidates`, `ClassifyTransactionType`, `TransactionDetail::reclassify`, `PreviewWizard::savePaypalAccountName`, every test setup.

```php
// CurrentUser is the canonical DI source — never Auth::user(), never auth().
public function someMethod(CurrentUser $currentUser, DatabaseManager $db): void
{
    $user = $currentUser->user();

    // Every query SCOPED to user_id explicitly.
    SomeModel::query()
        ->where('user_id', $user->id)
        ->where('id', $someId)
        ->firstOrFail();   // raises NotFoundHttpException → 404
}
```

### Dispatcher Injection (NO facades)

**Source:** `Modules/Categorization/Providers/CategorizationServiceProvider.php` line 7 (`Illuminate\Contracts\Events\Dispatcher`). Project memory rule: DI-only, no facade calls.
**Apply to:** `RecordTransactions` (or `ConfirmImport`) — dispatcher injection for `TransactionImported` event.

```php
use Illuminate\Contracts\Events\Dispatcher;

public function __construct(
    /* ... existing ... */
    private readonly Dispatcher $events,
) {}

// ...

$this->events->dispatch(new TransactionImported($persisted, $user));
```

NEVER `event(new TransactionImported(...))`. NEVER `Event::dispatch(...)`.

### DatabaseManager Injection (NO DB facade)

**Source:** `Modules/Ledger/Public/Actions/RecordTransactions.php` line 34 + `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` line 49 + `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` line 56.
**Apply to:** `PairTransferCandidates`, `TransactionDetail::reclassify`, every new query site.

```php
use Illuminate\Database\DatabaseManager;

// Either constructor-inject (for services / actions / listeners):
public function __construct(private readonly DatabaseManager $db) {}

// Or method-inject (for Livewire components — they have no constructor):
public function someAction(DatabaseManager $db): void { /* ... */ }
```

### Error Handling: typed exceptions surface to user via wizard

**Source:** `Modules/Ingestion/Public/Exceptions/SniffMismatchException.php` (canonical example) + `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` lines 81-87.
**Apply to:** `PaypalAmountParser`, `PaypalDateParser`, `PaypalCsvLanguageProfile::detect()`, `PaypalCsvAdapter::parse()`.

Pattern: typed exception messages are USER-FACING (rendered verbatim in wizard error rows / sniff mismatch panels). Compose them as full sentences ending with concrete remediation guidance ("Drop in the ASN CSV..." / "If ASN changed their export format, file an issue.").

### Migration shape (anonymous-class + DI-only exception)

**Source:** `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` lines 1-49.
**Apply to:** `2026_05_15_010002_add_pair_transaction_id_to_transactions.php`.

```php
private function schema(): Builder
{
    // Anonymous migrations are instantiated by Laravel's migrator with no
    // constructor arguments, so the schema builder is resolved from the
    // container at the migration boundary. This is the standing Laravel-
    // migration exception to the DI-only rule.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection($this->getConnection())->getSchemaBuilder();
}
```

The `app(...)` call is acceptable HERE and HERE ONLY (migrations). Every other site uses constructor DI.

### Test fixture pattern (Wave 0 redacted-fixture-first)

**Source:** Phase 2 + Phase 3 precedent + `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` (fixture-record doc shape) + `scripts/anonymize_ics_text.php` (anonymisation script).
**Apply to:** Wave 0 deliverables (anonymisation script + `paypal-sample-1.csv` + `paypal-sample-1.md` + `paypal-sample-tiny.csv`).

Layout:
- Raw user export → `local/paypal/` (gitignored)
- Run anonymiser → committed fixture in `Modules/Ingestion/tests/fixtures/paypal/`
- Fixture-record `.md` documents: language, columns, event types, FX representation, reconciliation result, redaction targets applied

### Larastan level 10 strict gates (project-wide)

**Source:** `phpstan.neon` (level 10 strict ruleset) + project memory rule "Larastan level 10 strict".
**Apply to:** Every new file in Phase 4.

- `staticMethod.dynamicCall` rule: prefer raw query-builder `$db->connection()->table(…)->exists()` over Eloquent `Model::query()->exists()` for boolean-only checks (verified at `TransactionDetail.php` lines 50-60 and `PreviewWizard.php` lines 259-264).
- `dynamic-class-name`: every class-level static call uses a static-known class.
- All array types declared in PHPDoc (`array<int, string>`, `list<SourceTransactionDto>`, etc.) — see SourceTransactionDto line 32.

### Pest test bootstrap

**Source:** `Modules/Categorization/tests/Pest.php` + `Modules/Categorization/tests/TestCase.php`.
**Apply to:** `Modules/Transfers/tests/Pest.php` + `Modules/Transfers/tests/TestCase.php`.

Verbatim copy; only namespace differs (`Modules\Categorization\Tests` → `Modules\Transfers\Tests`).

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php` | rollup walker | transform (buffered) | No multi-row group-fold walker exists in the codebase. The closest shape (Ics `iterateTransactionBlocks` folds one optional next line) doesn't cover the N-row parent-child group case. Algorithm comes from RESEARCH.md §"Pattern 1" + PITFALLS Pitfall 3, NOT from a codebase analog. |
| `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php` | event-type → action map | transform | No domain-specific event-type-classifier exists. The static-const shape mirrors `AsnCsvColumnMap` (column index map) but the semantic concern (event-type vocabulary) is entirely PayPal-specific. Wave 0 locks the empirical map. |

For both: the planner writes the file from RESEARCH.md guidance + Wave 0 empirical findings; there is no codebase pattern to copy verbatim.

## Operational Workaround Notes (Phase 4 carries forward)

**`import_runs.sha256` UNIQUE blocks re-upload after walker bug-fix** (Pitfall 4):
- For now: a manual `DELETE FROM import_runs WHERE ...` step is the operational workaround.
- DO NOT make the SHA-256 UNIQUE conditionally bypassable in Phase 4 — that's a foundational change for Phase 11.
- Document the workaround in PLAN.md so the team knows it exists.

**Cross-currency PayPal sweeps (USD→EUR) miss Layer-1 deterministic pairing** (RESEARCH §"Example 2 caveat"):
- `where('currency', $tx->currency)` filter assumes same-currency pairs.
- Wave 0 verifies whether PayPal "Transfer to bank" rows surface in EUR or USD.
- If USD: those sweeps stay unpaired in Phase 4 (Layer-2 ships in Phase 5).
- Document in PLAN.md as an accepted limit; do NOT add an FX-aware Layer-1 in Phase 4.

## Metadata

**Analog search scope:**
- `Modules/Ingestion/Internal/Adapters/Asn/*` (full)
- `Modules/Ingestion/Internal/Adapters/Ics/*` (full)
- `Modules/Ingestion/Public/{Contracts,Dto,Exceptions,Services}/*`
- `Modules/Ingestion/Providers/IngestionServiceProvider.php`
- `Modules/Import/Internal/Pipeline/{ImportPipeline,Stages/*}.php`
- `Modules/Import/Internal/Http/Livewire/{UploadWizard,PreviewWizard}.php`
- `Modules/Import/Public/{Actions,Events,Services}/*`
- `Modules/Ledger/Database/Migrations/2026_05_13_*.php` + `2026_05_15_010001_*`
- `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php`
- `Modules/Ledger/Models/{Transaction,Account}.php`
- `Modules/Ledger/Public/Actions/RecordTransactions.php`
- `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php`
- `Modules/Categorization/Public/Events/TransactionCategorized.php`
- `Modules/Categorization/Providers/CategorizationServiceProvider.php`
- `Modules/Categorization/tests/{Pest,TestCase,Feature/TriagePageTest}.php`
- `Modules/Core/Public/Events/UserInstalled.php`
- `bootstrap/providers.php`
- `tests/Contracts/IdempotencyContractTest.php`
- `tests/Contracts/BoundaryArchTest.php`
- `scripts/anonymize_ics_text.php`

**Files scanned:** 31 source files + 4 test / doc files = 35 files
**Pattern extraction date:** 2026-05-15
