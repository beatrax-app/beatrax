# Phase 7: Email Template Matchers + Categorization Learning — Pattern Map

**Mapped:** 2026-05-17
**Files analyzed:** 38 new + 8 modified
**Analogs found:** 46 / 46 (every new file maps to an existing in-repo precedent — the module is "more of the same" structurally)

---

## Wave 0 — Module Skeleton, Fixtures, Arch Tests, Migrations

Wave-0 ships the empty `Modules/Receipts/` shell, the four new tables (or column additions), the `noEmailFetchFromReceipts` arch invariant, the `IdempotencyContractTest` dataset extension, and the synthesised `.eml`/`.mbox` fixtures. Every file is structurally identical to an existing one — no novel shape.

| New file | Role | Data flow | Closest analog | Match |
|----------|------|-----------|----------------|-------|
| `Modules/Receipts/composer.json` | composer | n/a | `Modules/Transfers/composer.json` | exact |
| `Modules/Receipts/Providers/ReceiptsServiceProvider.php` | service-provider | container-tagged registration + event subscription + view/migration/route loading | `Modules/Chains/Providers/ChainsServiceProvider.php` (richer variant) + `Modules/Transfers/Providers/TransfersServiceProvider.php` (minimal variant) | exact role / data flow |
| `Modules/Receipts/tests/Pest.php` | composer (test bootstrap) | n/a | `Modules/Transfers/tests/Pest.php` | exact |
| `Modules/Receipts/tests/TestCase.php` | composer (test bootstrap) | n/a | `Modules/Transfers/tests/TestCase.php` | exact |
| `Modules/Receipts/Public/Contracts/SenderMatcher.php` | DTO/contract | n/a | `Modules/Ingestion/Public/Contracts/SourceAdapter.php` | exact role — interface for an injectable strategy |
| `Modules/Receipts/Public/Dto/MatcherInputDto.php` | DTO | inbox_messages|file_imports → matcher | `Modules/EmailScan/Public/Dto/InboxMessageDto.php` | exact (immutable readonly Data) |
| `Modules/Receipts/Public/Dto/ParsedReceiptDto.php` | DTO | matcher → adapter | `Modules/EmailScan/Public/Dto/InboxMessageDto.php` (readonly Data shape) + `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` (mapping target) | composite — readonly Data + carries fields the SourceTransactionDto bridge later maps |
| `Modules/Receipts/Public/Dto/MatchOutcomeDto.php` | DTO (sum-type wrapper) | matcher → consumer | `Modules/Import/Public/Dto/FingerprintDisposition.php` family (NewRow/Enriched/Duplicate) — see `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` lines 64-83 | role match — sum-type "outcome" wrapper |
| `Modules/Receipts/Public/Dto/ChainHintPayload/FundedByCardPayload.php` | DTO | event payload | `Modules/EmailScan/Public/Dto/InboxMessageDto.php` (Data readonly) | exact |
| `Modules/Receipts/Public/Dto/ChainHintPayload/RefundOfPayload.php` | DTO | event payload | same | exact |
| `Modules/Receipts/Public/Events/ChainHintDetected.php` | event | Receipts → Chains | `Modules/Import/Public/Events/TransactionImported.php` (readonly cross-module event) + `Modules/Categorization/Public/Events/TransactionCategorized.php` (zero-symbol-coupling shape) | exact role |
| `Modules/Receipts/Public/Events/ReceiptConflictDetected.php` | event | Receipts → ReceiptConflictToast SFC | `Modules/Categorization/Public/Events/TransactionCategorized.php` | exact |
| `Modules/Receipts/Database/Migrations/{ts}_create_file_imports_table.php` | migration | schema | `Modules/EmailScan/Database/Migrations/2026_05_16_020003_create_inbox_messages_table.php` | exact — column-for-column mirror, same status enum + same SQLite trigger-based status guard |
| `Modules/Receipts/Database/Migrations/{ts}_add_matcher_key_to_inbox_messages.php` | migration | schema (widen Phase 6 table) | `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` | exact (anonymous-migration `Schema::table()` + DI-only schema builder) |
| `Modules/Categorization/Database/Migrations/{ts}_create_categorization_rules_table.php` | migration | schema | `Modules/Ledger/Database/Migrations/2026_05_12_010007_create_merchant_memories_table.php` | role-match (similar shape: id + user_id + composite-unique + counter column) |
| `Modules/Categorization/Database/Migrations/{ts}_add_receipt_conflict_resolution_to_users.php` | migration | schema (widen users) | `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` | exact |
| `Modules/Categorization/Database/Migrations/{ts}_create_pending_enrichment_conflicts_table.php` | migration | schema (D-715 — recommendation: new table per RESEARCH "Pending-Conflict Storage Decision") | `Modules/EmailScan/Database/Migrations/2026_05_16_020003_create_inbox_messages_table.php` | role-match (small append-only side table; status enum + trigger guard) |
| `Modules/Categorization/Database/Migrations/{ts}_add_auto_category_provenance_to_transactions.php` | migration | schema (nullable JSON column on `transactions`) | `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` (column addition on transactions) | exact role |
| `Modules/Receipts/tests/fixtures/` | fixture | matcher unit test corpora | `Modules/EmailScan/tests/Unit/MimeHeaderParserTest.php` uses `tests/fixtures/eml/{paypal,ics,googleplay}/*.eml` (reuse / mirror) + `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` (paired CSV row for the fingerprint-parity test) | exact |
| `tests/Contracts/BoundaryArchTest.php` (modified — add `noEmailFetchFromReceipts` + `Modules\\Receipts\\Internal` toOnlyBeUsedIn) | arch-test | invariant | `tests/Contracts/BoundaryArchTest.php` existing `noTransactionWritesFromEmailScan` (lines 222-271) | exact mirror — invert sense (forbid GmailApiClient / GraphApiClient / OAuth imports inside `Modules/Receipts/`) |
| `tests/Contracts/IdempotencyContractTest.php` (modified — add `'eml'` + `'mbox'` dataset rows) | contract-test | invariant | `tests/Contracts/IdempotencyContractTest.php` existing dataset (lines 7-44) | exact |
| `Modules/Receipts/Public/Actions/RecordReceipt.php` (Wave 0 stub — full impl in Wave 1) | action | entrypoint | `Modules/Categorization/Public/Actions/AssignCategory.php` | exact (constructor DI + `__invoke` + event dispatch) |

### Code excerpts to copy from

**`composer.json` — copy from `Modules/Transfers/composer.json`:**

```json
{
    "name": "diederik/receipts",
    "description": "Receipts module — per-sender email matchers + .eml/.mbox ingestion + receipt→transaction bridge.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\Receipts\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Receipts\\Tests\\": "tests/"
        }
    }
}
```

**`ReceiptsServiceProvider::register()` — combine the Transfers (minimal) + Chains (rich) shapes; copy these exact phrasings:**

```php
// Source: Modules/Transfers/Providers/TransfersServiceProvider.php lines 30-47 + Modules/Chains/Providers/ChainsServiceProvider.php lines 67-83
final class ReceiptsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Container-tagged matcher collection (D-702 / D-716). Tag the
        // three Wave 1/2 matcher classes with `'receipts.matcher'`; the
        // registry resolves the tagged collection on construct and
        // sorts by priority() descending. canHandle() is the
        // authoritative filter — first matcher in the sorted list
        // whose canHandle() returns true wins.
        $this->app->tag([
            PaypalReceiptMatcher::class,
            IcsReceiptMatcher::class,
            GooglePlayReceiptMatcher::class,
        ], 'receipts.matcher');

        $this->app->singleton(MatcherRegistry::class, static function (Container $app): MatcherRegistry {
            /** @var iterable<SenderMatcher> $tagged */
            $tagged = $app->tagged('receipts.matcher');
            $matchers = iterator_to_array($tagged);
            usort($matchers, static fn (SenderMatcher $a, SenderMatcher $b) => $b->priority() <=> $a->priority());
            return new MatcherRegistry($matchers);
        });

        $this->app->singleton(EmlMimeReader::class);
        $this->app->singleton(MboxIterator::class);
        $this->app->singleton(ReceiptSourceAdapter::class);
        $this->app->singleton(RecordReceipt::class);
        $this->app->singleton(FileImportQuery::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        // Mirror ChainsServiceProvider::boot() — conditional loaders so a
        // half-built module skeleton in early waves does not throw.
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_file(__DIR__.'/../Routes/console.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'receipts');
        }

        $livewire->component('receipts.wizard-email-file-step', WizardEmailFileStep::class);
        $livewire->component('receipts.receipt-conflict-toast', ReceiptConflictToast::class);
    }
}
```

**`tests/Pest.php` — copy from `Modules/Transfers/tests/Pest.php` verbatim, swap namespace:**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Receipts\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
```

**Migration shape — copy from `Modules/EmailScan/.../create_inbox_messages_table.php` lines 30-97 (anonymous `Migration` + container-resolved schema builder + paired BEFORE INSERT / BEFORE UPDATE trigger for the `status` enum):**

```php
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('file_imports', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('source_kind', 16);                  // 'eml' | 'mbox'
            $table->string('source_filename', 512);
            $table->string('provider_message_id', 128);          // RFC 822 Message-ID; sha256 synthetic per D-705a
            $table->timestamp('internal_date');
            $table->string('sender_email', 320);
            $table->string('sender_name', 320)->nullable();
            $table->string('subject', 998)->nullable();
            $table->string('eml_path', 1024);
            $table->string('status', 16)->default('fetched');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['user_id', 'provider_message_id']);
            $table->index(['user_id', 'status']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStatuses = "'fetched','parsed','skipped','unmatched'";
        $connection->statement(sprintf(
            "CREATE TRIGGER file_imports_status_check_insert BEFORE INSERT ON file_imports FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.status value'); END",
            $allowedStatuses,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER file_imports_status_check_update BEFORE UPDATE OF status ON file_imports FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.status value'); END",
            $allowedStatuses,
        ));
    }
    // ... down() + private schema()/db() helpers identical to the inbox_messages migration
};
```

**Arch test — copy the `noTransactionWritesFromEmailScan` shape from `tests/Contracts/BoundaryArchTest.php` lines 222-271, INVERT the direction (Receipts must not import EmailScan's OAuth/client symbols):**

```php
// Add to tests/Contracts/BoundaryArchTest.php — mirror noTransactionWritesFromEmailScan
arch('Modules\\Receipts\\Internal is only used inside Modules\\Receipts')
    ->expect('Modules\\Receipts\\Internal')
    ->toOnlyBeUsedIn('Modules\\Receipts');

it('does not allow any file under Modules/Receipts/ to import EmailScan OAuth/client symbols (noEmailFetchFromReceipts)', function (): void {
    $hits = [];
    $receiptsDir = base_path('Modules/Receipts');
    if (! is_dir($receiptsDir)) {
        expect(true)->toBeTrue();
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($receiptsDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) { continue; }
        $path = $file->getPathname();
        if (preg_match('/\.php$/', $path) !== 1) { continue; }
        if (str_contains($path, '/tests/')) { continue; }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match('/GmailApiClient|GraphApiClientContract|GoogleOAuthProvider|MicrosoftOAuthProvider|OAuthStateRepository|OAuthSecretsRepository/', $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Modules/Receipts/ must never import EmailScan OAuth/client symbols. Offenders:\n  ".implode("\n  ", $hits),
    );
});
```

**`SenderMatcher` contract — copy `SourceAdapter` shape from `Modules/Ingestion/Public/Contracts/SourceAdapter.php` lines 26-49:**

```php
namespace Modules\Receipts\Public\Contracts;

use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;

interface SenderMatcher
{
    /** Stable, lowercase-kebab matcher identifier, e.g. 'paypal-receipt'. */
    public function key(): string;

    /** Higher value = earlier in dispatch order. Default sender-specific matchers = 100; future fallbacks = 0. */
    public function priority(): int;

    /** True if this matcher claims responsibility for the given message; false otherwise. canHandle() is the authoritative filter. */
    public function canHandle(InboxMessageDto $msg): bool;

    /** Parse a raw .eml. MAY return MatchOutcomeDto::skipped() when canHandle() said yes but the body isn't a transaction (e.g. PayPal login notification). */
    public function match(string $emlRaw): MatchOutcomeDto;
}
```

**`ParsedReceiptDto` — copy readonly-Data shape from `Modules/EmailScan/Public/Dto/InboxMessageDto.php` lines 24-38:**

```php
namespace Modules\Receipts\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ParsedReceiptDto extends Data
{
    /** @param  array<int|string, mixed>  $rawPayload */
    public function __construct(
        public readonly string $merchantName,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $settledAmountMinor,
        public readonly ?string $settledCurrency,
        public readonly ?string $referenceId,
        public readonly CarbonImmutable $bookedAt,
        public readonly string $ownIban,            // 'PAYPAL' | 'ICS-CARD' | 'GOOGLE-PLAY'
        public readonly ?string $description,
        public readonly array $rawPayload,
    ) {}
}
```

**`ChainHintDetected` event — copy from `Modules/Categorization/Public/Events/TransactionCategorized.php`:**

```php
namespace Modules\Receipts\Public\Events;

final readonly class ChainHintDetected
{
    public function __construct(
        public int $sourceTransactionId,
        public string $hintType,                    // 'funded_by_card' | 'refund_of' | …
        public object $hintPayload,                 // typed sub-DTO under Public/Dto/ChainHintPayload/
        public string $evidence,
        public int $userId,
    ) {}
}
```

**Divergence notes (Wave 0):**

- The `ReceiptsServiceProvider` does NOT register a `JobFailed` listener (Chains + EmailScan both do, but Phase 7's `ProcessFetchedInboxMessagesJob` is in-process synchronous per RESEARCH Pattern 4 + D-719 — no failed-job audit row to flip). If Wave 3 chooses async, copy the listener verbatim from `Modules/EmailScan/Providers/EmailScanServiceProvider.php` lines 175-205.
- The `file_imports` migration's `eml_path` column (1024 chars) is NEW vs `inbox_messages` — Phase 6 stores blobs at `EmlBlobStore::pathFor()` and re-derives the path from inbox_id + provider_message_id; Phase 7's file-drop path needs the column because the synthetic Message-ID for headerless files is `sha256(bytes)` and the disk path is `storage/app/inbox/{user_id}/file-drop/{YYYY}/{MM}/{message_id_hash}.eml`. Reuse the directory-mode (0700) + file-mode (0600) + atomic tmp+rename writer from `Modules/EmailScan/Internal/EmlBlobStore.php` lines 40-142 — see Wave 1 § EmlMimeReader.
- The `noEmailFetchFromReceipts` arch test ignores the `tests/` subtree (matching the existing `noTransactionWritesFromEmailScan` pattern at line 255-257) so test fixtures and FakeInboxMessageQuery stubs can mention OAuth class names without breaking the rule.

---

## Wave 1 — PayPal Vertical Slice + File-Drop Wizard Entrypoint

Wave-1 ships the first matcher end-to-end (PayPal `.eml` → ParsedReceiptDto → SourceTransactionDto → existing pipeline → transaction row), the `.eml`/`.mbox` wizard arm, the `ProcessFetchedInboxMessagesJob` consumer, and the load-bearing `ReceiptCsvFingerprintParityTest`.

| New file | Role | Data flow | Closest analog | Match |
|----------|------|-----------|----------------|-------|
| `Modules/Receipts/Internal/Matchers/PaypalReceiptMatcher.php` | matcher (strategy) | `InboxMessageDto + emlRaw → MatchOutcomeDto(ParsedReceiptDto)` | `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php` (PayPal-specific parser, constructor DI, typed exceptions) + `Modules/EmailScan/Internal/MimeHeaderParser.php` (zbateson body-extraction posture) | exact role, different body shape (HTML vs CSV) |
| `Modules/Receipts/Internal/MatcherRegistry.php` | service (collection orchestrator) | `MatcherInputDto → SenderMatcher` | `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` | exact role / data flow |
| `Modules/Receipts/Internal/Pipeline/EmlMimeReader.php` | service (zbateson wrapper) | `string emlRaw → {textBody, htmlBody, attachments, headers}` | `Modules/EmailScan/Internal/MimeHeaderParser.php` | exact (thin facade over `MailMimeParser`) |
| `Modules/Receipts/Internal/Pipeline/MboxIterator.php` | service (streaming generator) | `string mboxPath → Generator<int, string emlRaw>` | `Modules/EmailScan/Public/Services/InboxMessageQuery.php` (Generator-based read) + `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php` `parse()` (lazy Generator) | partial — same Generator posture, different state machine (RESEARCH Pattern 3 has the algorithm) |
| `Modules/Receipts/Internal/Pipeline/ReceiptSourceAdapter.php` | pipeline-stage (bridge) | `ParsedReceiptDto → SourceTransactionDto` | `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` (Stage shape, BigDecimal FX, final readonly class, constructor DI) | role-match — a one-method mapper between DTO shapes |
| `Modules/Receipts/Internal/Pipeline/FileDropEmlBlobStore.php` (or extend existing) | service | `string emlRaw + user_id → on-disk path under inbox/{user_id}/file-drop/` | `Modules/EmailScan/Internal/EmlBlobStore.php` (atomic tmp+chmod+rename) | exact — copy the entire `put()` + `delete()` + `pathFor()` shape, just change the path template |
| `Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php` | scheduled-job (queue worker) | `inbox_messages.status='fetched' + file_imports.status='fetched' → MatcherRegistry::dispatch → RecordReceipt → status='parsed|skipped|unmatched'` | `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php` (queued job, ShouldBeUniqueUntilProcessing, constructor DI of `int $userId`, redis uniqueVia carve-out) — also `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` lines 57-143 (audit-row lifecycle) | role-match (queued job over a per-user backlog) |
| `Modules/Receipts/Internal/Http/Livewire/WizardEmailFileStep.php` | livewire-sfc | `file upload → HeaderSniffer → preview` | `Modules/Import/Internal/Http/Livewire/UploadWizard.php` | exact (cascading issuer/format dropdowns + WithFileUploads + validate-then-redirect to preview) |
| `Modules/Receipts/Resources/views/livewire/wizard-email-file-step.blade.php` | blade-view | UI | n/a — extend existing `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` (modified file row below) | n/a |
| `Modules/Ingestion/Public/Services/HeaderSniffer.php` (modified — add RFC-822 + mbox arms) | service-extension | sniff `eml` / `mbox` declared format | `Modules/Ingestion/Public/Services/HeaderSniffer.php` `sniffPaypalCsv` + `sniffIcsPdf` (lines 88-155) | exact — copy the arm shape, swap signature (`Return-Path:` / `Received:` header presence; `From ` line marker for mbox) |
| `Modules/Receipts/Internal/Pipeline/EmlHeaderProfile.php` + `MboxHeaderProfile.php` | helper | declared-format constants | `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php` (FORMAT const + signature regex) | exact |
| `Modules/Import/Internal/Pipeline/Stages/ParseStage.php` (modified — accept `eml` + `mbox` formats by routing through ReceiptSourceAdapter) | pipeline-stage extension | wire receipt path into the existing pipeline | `Modules/Import/Internal/Pipeline/ImportPipeline.php` lines 69-153 (the loop that drains the Generator from `ParseStage`) | role-match |
| `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` + `Modules/Import/Public/Services/SourceRefRanker` (modified — add `paypal-receipt` rank > `paypal-csv`) | helper (ranker extension) | dedup precedence | `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` lines 53-84 (consults `SourceRefRanker`) | exact extension point |
| `Modules/Receipts/tests/Unit/Matchers/PaypalReceiptMatcherTest.php` | unit-test | matcher fixture coverage | `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php` (per-fixture assertions + monotonic sourceRowIndex check) + `Modules/EmailScan/tests/Unit/MimeHeaderParserTest.php` (per-`.eml`-fixture assertions) | exact |
| `Modules/Receipts/tests/Unit/EmlMimeReaderTest.php` | unit-test | zbateson wrapper smoke | `Modules/EmailScan/tests/Unit/MimeHeaderParserTest.php` | exact |
| `Modules/Receipts/tests/Unit/MboxIteratorTest.php` | unit-test | hand-rolled iterator | `Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php` (state-machine-style unit test) | role-match |
| `Modules/Receipts/tests/Feature/EmlFileDropTest.php` | feature-test | end-to-end wizard flow | `Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php` (Livewire wizard feature test) + `Modules/Ingestion/tests/Feature/HeaderSnifferTest.php` | role-match |
| `Modules/Receipts/tests/Feature/MboxFileDropTest.php` | feature-test | mbox multi-message split | same | role-match |
| `Modules/Receipts/tests/Feature/ProcessFetchedInboxMessagesJobTest.php` | feature-test | consumer job behaviour | `Modules/EmailScan/tests/Integration/BackfillPerInboxJobTest.php` | role-match |
| `Modules/Receipts/tests/Feature/ReceiptCsvFingerprintParityTest.php` | feature-test (LOAD-BEARING) | invariant: PayPal CSV row's fingerprint == matching .eml's fingerprint | `Modules/Ingestion/tests/Unit/AsnCamt053CrossFormatFingerprintTest.php` | exact role — cross-format fingerprint equivalence |

### Code excerpts to copy from

**`PaypalReceiptMatcher` — copy constructor-DI + typed-exception + `final` posture from `PaypalCsvAdapter` lines 54-69, copy zbateson facade shape from `MimeHeaderParser` lines 42-103:**

```php
// Modules/Receipts/Internal/Matchers/PaypalReceiptMatcher.php
namespace Modules\Receipts\Internal\Matchers;

final class PaypalReceiptMatcher implements SenderMatcher
{
    public function __construct(
        private readonly EmlMimeReader $reader,
        // No DatabaseManager — matchers are pure functions of the .eml bytes.
    ) {}

    public function key(): string { return 'paypal-receipt'; }
    public function priority(): int { return 100; }

    public function canHandle(InboxMessageDto $msg): bool
    {
        // Sender-domain test — first-cheap filter. The matcher claims any
        // message from @paypal.com; the body parser decides skip/unmatched
        // if it turns out to be a login notification rather than a receipt.
        return str_ends_with($msg->senderEmail, '@paypal.com');
    }

    public function match(string $emlRaw): MatchOutcomeDto
    {
        // Mirror MimeHeaderParser::parseHeaders shape (lines 42-103):
        // construct parser, parse, walk multipart prefering text/plain
        // over text/html (RESEARCH Pitfall 2).
        $parsed = $this->reader->read($emlRaw);
        // ... extract merchant + amount via brick/money + reference id ...
        // ... return MatchOutcomeDto::parsed($parsedReceiptDto) ...
    }
}
```

**`ReceiptSourceAdapter` bridge — copy `NormalizeStage` shape from `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` lines 39-115. The bridge is one method; the SAME field mapping rules apply:**

```php
// Modules/Receipts/Internal/Pipeline/ReceiptSourceAdapter.php
final class ReceiptSourceAdapter
{
    public function toSourceDto(ParsedReceiptDto $parsed, int $sourceRowIndex = 0): SourceTransactionDto
    {
        return new SourceTransactionDto(
            bookedAt: $parsed->bookedAt,
            postedAt: $parsed->bookedAt,                 // receipts have no booked-vs-posted lag
            valueDate: $parsed->bookedAt,
            ownIban: $parsed->ownIban,                   // 'PAYPAL' / 'ICS-CARD' / 'GOOGLE-PLAY'
            counterpartyIban: null,                       // merchants don't appear by IBAN
            counterpartyName: $parsed->merchantName,
            currency: $parsed->currency,
            amountMinor: $parsed->amountMinor,
            sourceRef: $parsed->referenceId,             // PayPal Txn ID / Google Play Order ID / ICS ref
            description: $parsed->description,
            rawPayload: $parsed->rawPayload,
            sourceRowIndex: $sourceRowIndex,
            settledAmountMinor: $parsed->settledAmountMinor,
            settledCurrency: $parsed->settledCurrency,
            fxRateUsed: null,                             // NormalizeStage derives this from native/settled
        );
    }
}
```

**`MatcherRegistry` — copy `SourceAdapterRegistry` from `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` lines 19-42, swap arr key from format-string to matcher-key and add priority ordering (already established in ServiceProvider tag binding above):**

```php
final class MatcherRegistry
{
    /** @param list<SenderMatcher> $matchers Sorted by priority() DESC */
    public function __construct(private readonly array $matchers) {}

    public function dispatch(MatcherInputDto $input): MatchOutcomeDto
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->canHandle($input->toInboxMessageDto())) {
                return $matcher->match($input->emlRaw);
            }
        }
        return MatchOutcomeDto::unmatched();
    }

    /** @return list<string> */
    public function supportedKeys(): array
    {
        return array_map(static fn (SenderMatcher $m): string => $m->key(), $this->matchers);
    }
}
```

**`ProcessFetchedInboxMessagesJob` — copy queued-job + DI-via-handle() shape from `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php` lines 95-160; copy audit-row INSERT + UPDATE-on-complete lifecycle from `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` lines 98-142. NOTE: if Wave 3 picks sync (D-719 recommendation), drop the `ShouldQueue` interface and run inline. The DI surface is identical either way:**

```php
final class ProcessFetchedInboxMessagesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string { return (string) $this->userId; }
    public function uniqueFor(): int { return 600; }

    public function uniqueVia(): Repository
    {
        // Cache facade carve-out — copy the rationale comment verbatim
        // from ResolveChainLinksJob lines 89-96.
        return Cache::driver('redis');
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        InboxMessageQuery $inboxes,
        FileImportQuery $files,
        MatcherRegistry $registry,
        EmlBlobStore $blobs,
        RecordReceipt $recordReceipt,
    ): void {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        // ... walk inbox_messages.status='fetched' via Generator ...
        // ... walk file_imports.status='fetched' ...
        // ... dispatch via registry, transition status, dispatch RecordReceipt ...
    }
}
```

The BoundaryArchTest carve-out at `tests/Contracts/BoundaryArchTest.php` lines 50-60 already lists three `EmailScan` queued-job FQNs for the `Cache::driver('redis')` facade exception — add `Modules\\Receipts\\Internal\\Jobs\\ProcessFetchedInboxMessagesJob` to that list when this file lands.

**`WizardEmailFileStep` — copy `UploadWizard` from `Modules/Import/Internal/Http/Livewire/UploadWizard.php` lines 37-194. Specifically:**

- Property + validation rules — lines 49-91 (`SUPPORTED_FORMATS` const, `#[Validate]` on issuer + sourceFormat, `messages()` overrides).
- Cascading dropdown — `availableFormats()` lines 101-117 + `updatedIssuer()` lines 129-135.
- Submit + sanitise — lines 137-193.

Add new arms to `SUPPORTED_FORMATS` (`'eml'`, `'mbox'`), new option in `availableFormats()` for issuer `'email-file'` (UI-SPEC Section 4 locks the labels), and new extensions in `sanitiseFilename()`'s match (`'eml' => '.eml'`, `'mbox' => '.mbox'`). The file-upload validation regex must accept `.eml`, `.mbox`, `.zip`.

**`HeaderSniffer` arms — copy `sniffPaypalCsv` from `Modules/Ingestion/Public/Services/HeaderSniffer.php` lines 88-126:**

```php
// Inside HeaderSniffer::sniff() match block, add two new arms:
'eml' => $this->sniffEml($localPath, $head),
'mbox' => $this->sniffMbox($localPath, $head),

private function sniffEml(string $path, string $head): SniffResult
{
    if (preg_match('/\.eml$/i', $path) !== 1) {
        throw new SniffMismatchException(
            "That file doesn't look like an email message. Drop in a .eml file."
        );
    }
    // RFC 822: any of Return-Path:, Received:, From:, Message-ID: in head.
    if (preg_match('/^(Return-Path|Received|From|Message-ID):/im', $head) !== 1) {
        throw new SniffMismatchException(
            'This file does not look like an RFC 822 message. If you exported a different format by mistake, re-download as .eml.'
        );
    }
    return new SniffResult(format: 'eml', delimiter: '', hasHeader: false, encoding: 'UTF-8', columnCount: 0);
}

private function sniffMbox(string $path, string $head): SniffResult
{
    if (preg_match('/\.mbox$/i', $path) !== 1) {
        throw new SniffMismatchException("That file doesn't look like an mbox archive. Drop in a .mbox file.");
    }
    if (! str_starts_with($head, 'From ')) {                  // mbox separator line marker
        throw new SniffMismatchException('This file does not start with a From ‹separator› line — not an mbox.');
    }
    return new SniffResult(format: 'mbox', delimiter: '', hasHeader: false, encoding: 'UTF-8', columnCount: 0);
}
```

**`EmlMimeReader` — copy zbateson facade pattern from `Modules/EmailScan/Internal/MimeHeaderParser.php` lines 42-103. Phase 7's reader exposes BODY content (Phase 6's parser exposed HEADERS only):**

```php
final class EmlMimeReader
{
    /** Read once, expose plain + html body + decoded headers. Stateless / singleton-safe. */
    public function read(string $rawEml): ParsedMimeMessage
    {
        $parser = new MailMimeParser;
        $message = $parser->parse($rawEml, true);

        // Prefer text/plain over text/html (RESEARCH Pitfall 2: multipart/alternative
        // ordering is not guaranteed).
        $plainPart = $message->getTextPart();   // zbateson auto-chooses first text/plain
        $htmlPart = $message->getHtmlPart();
        // ... extract attachments, hand back composite DTO ...
    }
}
```

**`ReceiptCsvFingerprintParityTest` — copy assertion shape from RESEARCH §"Pattern 2" lines 433-450 (the test was sketched there for this exact phase) and copy fixture-loader pattern from `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php` lines 21-54 (the `beforeEach` + `iterator_to_array($adapter->parse(...))` shape).**

### Divergence notes (Wave 1)

- **`PaypalReceiptMatcher` mirrors `PaypalCsvAdapter` in DTO + immutable shape but replaces the CSV `Reader::createFromPath()` step with `EmlMimeReader::read($emlRaw)`, and emits `MatchOutcomeDto(ParsedReceiptDto)` not `Generator<SourceTransactionDto>`** — the per-receipt single-emit semantics are the only structural shift. The bridge `ReceiptSourceAdapter` does the `ParsedReceiptDto → SourceTransactionDto` conversion downstream so the matcher stays a pure function of the bytes.
- **`MboxIterator` is hand-rolled per RESEARCH Pattern 3** — `armin/mbox-parser` is incompatible with locked `zbateson 4.0.1`. Algorithm + edge cases documented in RESEARCH lines 459-498.
- **`HeaderSniffer` arm extension is the only modification inside `Modules/Ingestion/`** — receipts adapter does NOT register through `SourceAdapterRegistry` (it does not implement `SourceAdapter` — it implements `SenderMatcher`). The `eml`/`mbox` formats route through a NEW `ReceiptSourceAdapter` branch inside `ParseStage` rather than the standard `SourceAdapterRegistry::for($format)->parse()` path. Document this on `ParseStage` when extending.
- **`ProcessFetchedInboxMessagesJob` does NOT mutate the `transactions` table directly** — it dispatches `RecordReceipt` which routes writes through the existing `RecordTransactions` action in Ledger. The `noEmailFetchFromReceipts` arch invariant (D-701) keeps the symmetry honest in the other direction; for transaction-write safety the existing arch invariants on Ledger's sole-mutator status do the work.
- **File-drop blob storage path differs from EmailScan**: Phase 6 uses `app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/{provider_message_id}.eml`; Phase 7 file-drop uses `app/inbox/{user_id}/file-drop/{YYYY}/{MM}/{message_id_hash}.eml` (D-705a — synthetic Message-ID = `sha256(bytes)` when the header is absent). Reuse the atomic-write code from `EmlBlobStore::put()` (lines 88-142) verbatim; only the path template changes.

---

## Wave 2 — ICS + Google Play Matchers

Wave-2 ships the two remaining matchers; everything is a clone of Wave 1's PaypalReceiptMatcher + its test.

| New file | Role | Data flow | Closest analog | Match |
|----------|------|-----------|----------------|-------|
| `Modules/Receipts/Internal/Matchers/IcsReceiptMatcher.php` | matcher | `InboxMessageDto + emlRaw → MatchOutcomeDto` | `Modules/Receipts/Internal/Matchers/PaypalReceiptMatcher.php` (Wave 1) | exact |
| `Modules/Receipts/Internal/Matchers/GooglePlayReceiptMatcher.php` | matcher | same | same | exact |
| `Modules/Receipts/tests/Unit/Matchers/IcsReceiptMatcherTest.php` | unit-test | per-fixture coverage | `Modules/Receipts/tests/Unit/Matchers/PaypalReceiptMatcherTest.php` (Wave 1) | exact |
| `Modules/Receipts/tests/Unit/Matchers/GooglePlayReceiptMatcherTest.php` | unit-test | same | same | exact |
| `Modules/Receipts/tests/fixtures/ics/*.eml` | fixture | matcher input | `Modules/EmailScan/tests/fixtures/eml/ics/sample-statement-notice.eml` (Phase 6 already has a synthesised ICS .eml — reuse + add receipt-shaped variants) | partial — Phase 6 fixture is a statement *notice* (no body amount); Phase 7 needs receipt-shaped fixtures (multiple template generations per RESEARCH Pitfall 1) |
| `Modules/Receipts/tests/fixtures/googleplay/*.eml` | fixture | matcher input | same | partial |
| `Modules/Receipts/tests/Feature/ReceiptCsvFingerprintParityTest.php` (extended) | feature-test | invariant: also covers ICS + Google Play | exists from Wave 1 | exact extension |

### Code excerpts

The two matchers are pure copies of the Wave 1 `PaypalReceiptMatcher` shape — see Wave 1 code excerpt above. The only divergences are:

- `key()` returns `'ics-receipt'` / `'google-play-receipt'`.
- `canHandle()` matches `@ics.nl` / `@google.com` sender domains.
- `match()` body extracts merchant + amount via the per-sender HTML/text anchors (RESEARCH lines 950-1100 catalogues empirical anchor candidates).
- Google Play's matcher emits `currency: 'USD'` + `settledAmountMinor` + `settledCurrency: 'EUR'` when the underlying card was charged EUR; the ReceiptSourceAdapter bridge already handles that shape (it's the same path PayPal CSV's Cloudflare USD chain uses — see `PaypalCsvAdapterTest::yields the dual-amount pair for the Cloudflare USD chain` lines 65-80).

### Divergence notes (Wave 2)

- **ICS receipts may arrive with a PDF attachment** (D-deferred / Phase 7 v2): the matcher's `match()` should detect a PDF attachment, mark the outcome as `skipped(reason: 'pdf_attachment_v2_only')`, and let the user know via the `Unmatched` status filter on `/inboxes`. Real PDF OCR is explicitly out of scope.
- **Google Play `GPA.X-X-X-X` order ID is a strong `referenceId`** — populates `transaction.reference_id` automatically, triggering Phase 5's `ResolveChainLinksJob` for the future case where a corresponding ICS card-statement row needs linking. No new code in `Modules/Chains/` for this case.

---

## Wave 3 — ApplyAutoCategoryStage + MerchantMemoryWriter + Conflict Resolution

Wave-3 grows `Modules/Categorization/` with the rule evaluator + memory writer + new pipeline stage + the first-conflict toast resolver. This wave introduces ZERO new modules — everything extends existing Categorization or Import surfaces.

| New file | Role | Data flow | Closest analog | Match |
|----------|------|-----------|----------------|-------|
| `Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php` | pipeline-stage | `CanonicalTransaction → AutoCategorizationOutcomeDto(canonical with category_id set, provenance)` | `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` (lines 39-115) + `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` (lines 45-85) | exact — same constructor-DI + run/classify method + final readonly class |
| `Modules/Categorization/Internal/Services/RuleEvaluator.php` | service | `CanonicalTransaction + User → AutoCategorizationOutcomeDto` (D-711 specificity scoring) | `Modules/Categorization/Public/Services/CategoryOptionsQuery.php` (DatabaseManager + raw query builder + stdClass row mapping + toInt/toString helpers) | exact role / data flow |
| `Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php` | listener | `TransactionCategorized → upsert merchant_memories row` | `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` (existing event listener inside Categorization) + `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` (constructor-DI + handle() + raw query builder writes) | exact role |
| `Modules/Categorization/Public/Dto/AutoCategorizationOutcomeDto.php` | DTO | pipeline payload (provenance + ruleId/memoryId) | `Modules/EmailScan/Public/Dto/InboxMessageDto.php` (readonly Data) | exact |
| `Modules/Categorization/Public/Dto/CategorizationRuleDto.php` | DTO | rule query result | `Modules/Categorization/Public/Dto/CategoryOption.php` (readonly Data) | exact |
| `Modules/Categorization/Public/Services/CategorizationRuleQuery.php` | query | `User → list<CategorizationRuleDto>` | `Modules/Categorization/Public/Services/CategoryOptionsQuery.php` (lines 27-65 — DatabaseManager + scoped query) | exact |
| `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` (read-side for RuleEvaluator) | query | `User + merchantId → CategoryOption|null` | `Modules/Categorization/Public/Services/CategoryOptionsQuery.php` | exact |
| `Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php` | action | first-conflict toast → write `users.receipt_conflict_resolution` + resolve held pending | `Modules/Categorization/Public/Actions/AssignCategory.php` (constructor DI + `__invoke` + event dispatch) | exact |
| `Modules/Receipts/Internal/Http/Livewire/ReceiptConflictToast.php` | livewire-sfc | listens for `ReceiptConflictDetected` event → renders toast | `Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php` (lines 43-80 — `#[On(...)]` event listener + service collaborators via action methods) | exact (modal/toast SFC posture) |
| `Modules/Receipts/Resources/views/livewire/receipt-conflict-toast.blade.php` | blade-view | UI chrome | reuse Phase 5 failed-job toast chrome (mentioned in 07-UI-SPEC § Toast chrome, line 104) | exact |
| `Modules/Import/Public/Actions/ApplyEnrichments.php` (modified — detect conflict, hold in pending_enrichment_conflicts, dispatch ReceiptConflictDetected) | action extension | enrich-vs-conflict branching | existing `ApplyEnrichments` at `Modules/Import/Public/Actions/ApplyEnrichments.php` (Phase 2 Wave 3) | exact extension point |
| `Modules/Import/Internal/Pipeline/ImportPipeline.php` (modified — slot ApplyAutoCategoryStage between ClassifyTransactionType and FingerprintStage::classify) | pipeline extension | wire new stage | `Modules/Import/Internal/Pipeline/ImportPipeline.php` lines 100-119 (current normalize → classifier → fingerprint sequence) | exact extension point — see RESEARCH Pattern 4 §"Where exactly" |
| `Modules/Categorization/Providers/CategorizationServiceProvider.php` (modified — register MerchantMemoryWriter listener + RuleEvaluator + ApplyAutoCategoryStage singletons + CategorizationRuleQuery + MerchantMemoryQuery) | service-provider extension | wire new bindings + event subscriptions | existing `CategorizationServiceProvider` lines 32-53 | exact extension |
| `Modules/Categorization/tests/Feature/RuleEvaluatorTest.php` | feature-test | specificity scoring (D-711) | `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php` (lines 36-80 — beforeEach + fixture user + action method scenarios) | exact role |
| `Modules/Categorization/tests/Feature/MerchantMemoryWriterTest.php` | feature-test | listener fires on TransactionCategorized | same | exact |
| `Modules/Categorization/tests/Feature/ApplyAutoCategoryStageTest.php` | feature-test | stage sets category_id before fingerprinting | same | exact |
| `Modules/Receipts/tests/Feature/ReceiptConflictResolutionTest.php` | feature-test | first-conflict toast lifecycle (D-707) | `Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php` (Livewire SFC feature test) | role-match |

### Code excerpts

**`ApplyAutoCategoryStage` — copy stage shape from `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` lines 39-44 + 90-114:**

```php
namespace Modules\Categorization\Internal\Pipeline;

/**
 * Looks up matching CategorizationRule + merchant_memories for the canonical
 * row's user + merchant; if a candidate wins per D-711 specificity scoring,
 * the canonical row's category_id is set BEFORE persistence. The returned
 * AutoCategorizationOutcomeDto carries the provenance + rule_id/memory_id
 * so RecordTransactions can stamp transactions.auto_category_provenance.
 */
final class ApplyAutoCategoryStage
{
    public function __construct(
        private readonly RuleEvaluator $evaluator,
    ) {}

    public function apply(CanonicalTransaction $tx, User $user): AutoCategorizationOutcomeDto
    {
        $outcome = $this->evaluator->evaluate($tx, $user);
        if ($outcome->categoryId === null) {
            return AutoCategorizationOutcomeDto::manual($tx);
        }
        $canonical = $tx->withCategoryId($outcome->categoryId);   // CanonicalTransaction is a Data DTO; add the wither
        return AutoCategorizationOutcomeDto::auto(
            canonical: $canonical,
            provenance: $outcome->source,                          // 'rule' | 'memory'
            ruleId: $outcome->ruleId,
            memoryId: $outcome->memoryId,
        );
    }
}
```

**`RuleEvaluator` — copy query shape from `Modules/Categorization/Public/Services/CategoryOptionsQuery.php` lines 27-65 (constructor DI + DatabaseManager + raw query builder + scoped where + stdClass mapping):**

```php
namespace Modules\Categorization\Internal\Services;

final class RuleEvaluator
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function evaluate(CanonicalTransaction $tx, User $user): RuleEvaluationOutcome
    {
        $userId = $user->id;

        // Pull all candidate rules for this user that match any of the
        // configurable target fields. Indexed on (user_id, active).
        $ruleRows = $this->db->connection()
            ->table('categorization_rules')
            ->where('user_id', $userId)
            ->where('active', true)
            ->get();

        // Pull the merchant_memories row (if any) for the canonical
        // row's merchant_id.
        $memoryRow = $tx->merchantId === null
            ? null
            : $this->db->connection()
                ->table('merchant_memories')
                ->where('user_id', $userId)
                ->where('merchant_id', $tx->merchantId)
                ->orderByDesc('occurrence_count')
                ->first();

        // Specificity scoring per D-711:
        //   equals=100, memory=90, starts_with=50+len(value), contains=10+len(value)
        // Tiebreaker: rule beats memory at equal score.
        // ... compute winner, return RuleEvaluationOutcome ...
    }
}
```

**`MerchantMemoryWriter` — copy listener shape from `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` (lines 16-24, event-listener with constructor DI) + raw-query-builder upsert shape from `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` lines 169-185:**

```php
namespace Modules\Categorization\Internal\Listeners;

final class MerchantMemoryWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function handle(TransactionCategorized $event): void
    {
        if ($event->categoryId === null) { return; }

        $connection = $this->db->connection();

        // Read merchant_id off the transaction.
        $txRow = $connection->table('transactions')
            ->where('user_id', $event->userId)
            ->where('id', $event->transactionId)
            ->first(['merchant_id']);
        if ($txRow === null || $txRow->merchant_id === null) { return; }

        // Upsert merchant_memories (user_id, merchant_id, category_id)
        // — the table's UNIQUE constraint makes this idempotent.
        $now = $this->clock->now()->toDateTimeString();
        $connection->table('merchant_memories')->updateOrInsert(
            [
                'user_id' => $event->userId,
                'merchant_id' => $txRow->merchant_id,
                'category_id' => $event->categoryId,
            ],
            [
                'occurrence_count' => $connection->raw('occurrence_count + 1'),
                'last_seen_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
}
```

Then subscribe in `CategorizationServiceProvider::boot()`:

```php
$events->listen(TransactionCategorized::class, MerchantMemoryWriter::class);
```

**Wire `ApplyAutoCategoryStage` into the pipeline — modify `Modules/Import/Internal/Pipeline/ImportPipeline.php` constructor (line 45-52) + the loop body (line 100-119)** — see RESEARCH §"Pattern 4 — Where exactly in ImportPipeline::preview" (lines 510-525) for the exact placement.

### Divergence notes (Wave 3)

- **`ApplyAutoCategoryStage` lives in `Modules/Categorization/Internal/`, not `Modules/Import/Internal/`** — the stage is a Categorization concern that plugs into Import's pipeline. The pipeline constructor binds it via the contract (introduce `AppliesAutoCategory` Public interface in Categorization, register the binding in `CategorizationServiceProvider::register()`, inject the contract in `ImportPipeline`). Mirror the `RecordsStatementSummary` / `AppliesEnrichments` cross-module contract pattern already in `ImportPipeline` (lines 45-52).
- **`MerchantMemoryWriter` is `final` and synchronous** — listener fires inside the outer DB transaction frame of `RecordTransactions` (same posture as `PairTransferCandidates`, see lines 38-44 of that file's docblock). The `updateOrInsert` is atomic and idempotent on re-fire.
- **`receipt_conflict_resolution` enum column** uses the same `default('unset')` + `after('...')` shape as `default_currency_view` in `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php`. No SQLite trigger needed (set is small; PHP-side validation in `ApplyReceiptConflictResolution` is sufficient since the column is private to a single writer).
- **`AutoCategorizationOutcomeDto`** carries provenance JSON that `RecordTransactions` stamps onto the NEW `transactions.auto_category_provenance` column added in Wave 0. The column is a nullable JSON column on the existing transactions table — mirror `Modules/Ledger/Database/Migrations/2026_05_15_010001_add_raw_payload_to_transactions.php` for the schema-add shape.

---

## Wave 4 — `/rules` Page + Correction-Divergence UX

Wave-4 ships the rule CRUD page + correction-divergence toast/panel + top-nav extension + watched-folder toggle. Every UI surface is locked by the 07-UI-SPEC contract (the executor reproduces copy verbatim).

| New file | Role | Data flow | Closest analog | Match |
|----------|------|-----------|----------------|-------|
| `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` | action | `(field, match, value, categoryId) → categorization_rules row` | `Modules/Categorization/Public/Actions/AssignCategory.php` (constructor DI + `__invoke` + event dispatch) + `Modules/Chains/Public/Actions/ConfirmChainLink.php` (cross-user 404 + DB transaction) | exact |
| `Modules/Categorization/Public/Actions/UpdateCategorizationRule.php` | action | `(ruleId, updates) → row updated` | `Modules/Chains/Public/Actions/ConfirmChainLink.php` lines 43-105 (`firstOrFail` + `NotFoundHttpException` cross-user pattern; UPDATE inside `DB::transaction` closure) | exact |
| `Modules/Categorization/Public/Actions/DeleteCategorizationRule.php` | action | `(ruleId) → row deleted` | same | exact |
| `Modules/Categorization/Models/CategorizationRule.php` | model | Eloquent | `Modules/Chains/Models/ChainLink.php` (BelongsToUser trait + final + casts) | exact |
| `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` | livewire-sfc | `/rules` page | `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` (lines 42-95 — render via Public query, action methods take service params, view extends layout) | exact |
| `Modules/Categorization/Internal/Http/Livewire/RuleFormModal.php` | livewire-sfc | create/edit modal | `Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php` (lines 43-80 — `#[On(...)]` open event, properties, submit, dismiss) | exact |
| `Modules/Categorization/Internal/Http/Livewire/CategorizationProvenancePanel.php` | livewire-sfc | inline drawer panel on `/transactions/{id}` | `Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php` (lines 24-48 — mounts on transactionId, action methods take services as params, renders Blade variant) | exact (the existing component already drops into the transaction-detail drawer) |
| `Modules/Categorization/Internal/Http/Livewire/CorrectionDivergenceToast.php` | livewire-sfc | listens for `CategorizationDiverged` event → renders toast | `Modules/Receipts/Internal/Http/Livewire/ReceiptConflictToast.php` (Wave 3 analog) + `Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php` (`#[On]` shape) | exact |
| `Modules/Categorization/Public/Events/CategorizationDiverged.php` | event | `AssignCategory` fires when prior provenance was 'rule' AND new category_id differs | `Modules/Categorization/Public/Events/TransactionCategorized.php` (lines 14-21, three-field readonly event) | exact |
| `Modules/Categorization/Public/Actions/AssignCategory.php` (modified — detect divergence + dispatch event) | action extension | wire new event | existing `AssignCategory` (lines 27-40) | exact extension |
| `Modules/Categorization/Resources/views/rules.blade.php` | blade-view | page wrapper | `Modules/Categorization/Resources/views/triage.blade.php` (3-line wrapper that extends `layouts.app` + `@livewire(...)`) | exact |
| `Modules/Categorization/Resources/views/livewire/rules-page.blade.php` | blade-view | rules table + empty state | `Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php` (lines 39-80 — header + empty state + `<ul>` of rows with inline actions) | exact |
| `Modules/Categorization/Resources/views/livewire/rule-form-modal.blade.php` | blade-view | Flux modal form | `Modules/Core/Resources/views/livewire/settings-page.blade.php` (lines 7-59 — form-row layout, `@error` rendering, emerald submit button) — wrapped in `<flux:modal>` per UI-SPEC | partial |
| `Modules/Categorization/Resources/views/livewire/correction-divergence-toast.blade.php` | blade-view | toast UI | Phase 5 failed-job toast (referenced by 07-UI-SPEC § Toast chrome) | exact |
| `Modules/Categorization/Resources/views/livewire/categorization-provenance-panel.blade.php` | blade-view | drawer panel UI | reuse chain-drawer "no chain found" panel chrome (referenced 07-UI-SPEC § Spacing) | partial |
| `Modules/Categorization/Routes/web.php` (modified — add `Route::view('/rules', ...)`) | route-registration | new top-nav route | existing `Modules/Categorization/Routes/web.php` lines 7-9 (single Route::view('/uncategorized')) + `Modules/EmailScan/Routes/web.php` (Livewire SFC routing) | exact |
| `Modules/Core/Resources/views/livewire/top-nav.blade.php` (modified — add Rules entry between Uncategorized and Review chains) | blade-view extension | nav | existing top-nav.blade.php lines 52-63 (Uncategorized anchor shape) | exact |
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (modified — add `bool $autoImportFromDropFolder` property + save extension) | livewire-sfc extension | watched-folder toggle | existing SettingsPage lines 33-66 (validate-then-save pattern) | exact |
| `Modules/Core/Resources/views/livewire/settings-page.blade.php` (modified — append new `<section>`) | blade-view extension | settings section | existing lines 28-46 (currency-display section template) | exact |
| `Modules/Core/Database/Migrations/{ts}_add_auto_import_drop_folder_to_users.php` | migration | schema (widen users) | `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` | exact |
| `Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php` | scheduled-job | watched-folder scan (D-704 secondary) | `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php` (queued, unique, redis carve-out) — see Wave 1 `ProcessFetchedInboxMessagesJob` for the full template | exact |
| `routes/console.php` (modified — register `ScanInboxDropFolderJob` schedule per D-718) | scheduled-job registration | every 5 minutes per RESEARCH Pattern 7 lines 640-648 | existing `routes/console.php` lines 31-53 (Schedule::call with closure DI + `withoutOverlapping`) | exact |
| `app/Providers/AppServiceProvider.php` (or layout) — mount the three global Livewire SFCs (`RuleFormModal`, `CorrectionDivergenceToast`, `ReceiptConflictToast`) | layout extension | per UI-SPEC § Mounting Strategy lines 484-495 | existing `resources/views/layouts/app.blade.php` lines 12-14 (`@auth → @livewire('core.top-nav')`) | exact |
| `Modules/Categorization/tests/Feature/RulesPageTest.php` | feature-test | page mount + CRUD | `Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php` (Livewire wizard feature test) | exact |
| `Modules/Categorization/tests/Feature/RuleFormModalTest.php` | feature-test | modal lifecycle | same | exact |
| `Modules/Categorization/tests/Feature/CorrectionDivergenceToastTest.php` | feature-test | toast event flow | same | exact |

### Code excerpts

**`CreateCategorizationRule` — copy from `Modules/Categorization/Public/Actions/AssignCategory.php` (lines 20-41) for the action shape; copy cross-user 404 from `Modules/Chains/Public/Actions/ConfirmChainLink.php` (lines 43-68) for the update/delete actions:**

```php
namespace Modules\Categorization\Public\Actions;

final class CreateCategorizationRule
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(
        User $user,
        string $field,           // 'merchant' | 'description' | 'counterparty'
        string $match,           // 'contains' | 'equals' | 'starts_with'
        string $value,
        int $categoryId,
    ): int {
        // Defensive: assert field + match against enum allow-lists.
        // The DB UNIQUE (user_id, field, match, value) catches dupes
        // — translate the QueryException to a typed ValidationException
        // for the UI layer per UI-SPEC § Copywriting Contract.

        $now = $this->clock->now()->toDateTimeString();
        return (int) $this->db->connection()->table('categorization_rules')->insertGetId([
            'user_id' => $user->id,
            'field' => $field,
            'match' => $match,
            'value' => $value,
            'category_id' => $categoryId,
            'hits_count' => 0,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
```

**`RulesPage` Livewire SFC — copy from `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` lines 42-95:**

```php
namespace Modules\Categorization\Internal\Http\Livewire;

final class RulesPage extends Component
{
    public function deleteRule(int $ruleId, CurrentUser $currentUser, DeleteCategorizationRule $delete): void
    {
        ($delete)($currentUser->user(), $ruleId);
    }

    public function openCreateModal(): void
    {
        $this->dispatch('rule-form:open');
    }

    public function openEditModal(int $ruleId): void
    {
        $this->dispatch('rule-form:open', ruleId: $ruleId);
    }

    public function render(
        CurrentUser $currentUser,
        CategorizationRuleQuery $query,
        ViewFactory $views,
    ): View {
        $rules = $query->forUser($currentUser->user());

        $view = $views->make('categorization::livewire.rules-page', ['rules' => $rules]);
        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Rules · diederik']);
        return $view;
    }
}
```

**`RuleFormModal` — copy from `Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php` lines 43-80** (the `#[On('rule-form:open')]` event listener + properties + submit shape — same posture as the backfill modal):

```php
final class RuleFormModal extends Component
{
    public ?int $editingRuleId = null;
    public string $field = '';
    public string $match = '';
    public string $value = '';
    public ?int $categoryId = null;
    public string $errorMessage = '';

    #[On('rule-form:open')]
    public function open(?int $ruleId = null): void
    {
        $this->editingRuleId = $ruleId;
        // ... hydrate from CategorizationRule::findOrFail($ruleId, $userId) if edit mode ...
    }

    public function save(
        CurrentUser $currentUser,
        CreateCategorizationRule $create,
        UpdateCategorizationRule $update,
    ): void {
        // ... validate, dispatch to the correct action, fire 'rule-form:saved' ...
    }

    public function render(
        CurrentUser $currentUser,
        CategoryOptionsQuery $options,
        ViewFactory $views,
    ): View {
        return $views->make('categorization::livewire.rule-form-modal', [
            'categories' => $options->for($currentUser->user()),
        ]);
    }
}
```

**`AssignCategory` extension — modify `Modules/Categorization/Public/Actions/AssignCategory.php` lines 27-40 to detect divergence + dispatch `CategorizationDiverged`** (the existing structure already dispatches `TransactionCategorized` after the update, mirror that shape):

```php
public function __invoke(int $transactionId, ?int $categoryId, User $user): int
{
    // Read prior provenance BEFORE the update so we know if this was a rule-driven suggestion.
    $priorProvenance = $this->db->connection()
        ->table('transactions')
        ->where('id', $transactionId)
        ->where('user_id', $user->id)
        ->value('auto_category_provenance');

    $affected = ($this->updater)($transactionId, $categoryId, $user);

    if ($affected > 0) {
        $this->events->dispatch(new TransactionCategorized($transactionId, $categoryId, $user->id));

        // Divergence detection — fires CategorizationDiverged when the
        // user reclassifies a row whose initial suggestion came from a rule.
        if ($priorProvenance !== null) {
            $prior = json_decode($priorProvenance, true);
            if (is_array($prior) && ($prior['source'] ?? null) === 'rule' && $prior['rule_id'] !== null && $categoryId !== $prior['category_id']) {
                $this->events->dispatch(new CategorizationDiverged(
                    transactionId: $transactionId,
                    ruleId: (int) $prior['rule_id'],
                    oldCategoryId: (int) $prior['category_id'],
                    newCategoryId: $categoryId,
                    userId: $user->id,
                ));
            }
        }
    }

    return $affected;
}
```

**Top-nav modification — copy the Uncategorized anchor (top-nav.blade.php lines 52-63) and insert a Rules entry between it and Review chains, no badge:**

```blade
{{-- Inserted between Uncategorized and Review chains per 07-UI-SPEC § Navigation Decision --}}
<a
    href="{{ route('rules') }}"
    class="inline-flex items-center rounded-md px-3 py-1.5 text-sm {{ $isActive('/rules') }} focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
>Rules</a>
```

**SettingsPage extension — copy the currency-display section from `Modules/Core/Resources/views/livewire/settings-page.blade.php` lines 28-46 (the period-start-day section is the closer analog: single property + label + input + `@error` + help text):**

```blade
<section class="space-y-2">
    <h2 class="text-xs uppercase tracking-wide text-slate-500">Auto-import</h2>
    <div class="space-y-1">
        <label for="autoImportFromDropFolder" class="flex items-center gap-3 text-sm text-slate-900">
            <input
                type="checkbox"
                id="autoImportFromDropFolder"
                name="autoImportFromDropFolder"
                wire:model.live="autoImportFromDropFolder"
                class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600"
            />
            Auto-import from drop folder
        </label>
        <p id="auto-import-help" class="text-xs text-slate-500">
            When on, diederik scans <code>storage/app/inbox-drop/</code> every 5 minutes for .eml and .mbox files and imports them through the same matcher pipeline as the wizard. Processed files move to <code>/processed/{YYYY-MM}/</code> so they're never imported twice.
        </p>
    </div>
</section>
```

**`ScanInboxDropFolderJob` registration — copy schedule shape from `routes/console.php` lines 31-36** (already documents the closure-DI + facade-rule-doesn't-apply rationale):

```php
// routes/console.php — append after the existing email-scan entries
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $userIds = $db->connection()->table('users')->where('auto_import_drop_folder', true)->pluck('id');
    foreach ($userIds as $id) {
        $bus->dispatch(new ScanInboxDropFolderJob((int) $id));
    }
})->name('receipts.scan-drop-folder')->everyFiveMinutes()->withoutOverlapping(10);
```

**Layout extension — append three global SFCs after `@livewire('core.top-nav')` in `resources/views/layouts/app.blade.php` lines 12-14 per 07-UI-SPEC lines 484-495:**

```blade
@auth
    @livewire('core.top-nav')
    @livewire('categorization.rule-form-modal')
    @livewire('categorization.correction-divergence-toast')
    @livewire('receipts.receipt-conflict-toast')
@endauth
```

### Divergence notes (Wave 4)

- **`RuleFormModal` is mounted globally** (not on `/rules` only) — so the `Update rule` action from the inline drawer panel on `/transactions/{id}` can dispatch `rule-form:open` and open the modal without `/rules` being mounted. UI-SPEC § Mounting Strategy is authoritative.
- **`CorrectionDivergenceToast` uses Livewire's session-flash → Alpine bridge** rather than an Echo broadcast (single-user, single-machine app — no broadcaster). The `AssignCategory` action dispatches the event; the SFC reads it via Livewire's `dispatch('rule-divergence-toast', payload: [...])` Browser event and the Alpine `x-on:rule-divergence-toast.window` listener (RESEARCH Pattern 5, lines 539-573).
- **`CategorizationRule` model uses BelongsToUser trait** (per FND-03 + existing project pattern on every domain table) — mirror `Modules/Chains/Models/ChainLink.php` shape; the `withoutGlobalScopes()` escape hatch in `DefaultCategoryTreeSeeder` (lines 72, 84) is only for the global default tree, NOT for user-owned rules.
- **Validation error for duplicate-rule** translates the `QueryException` (`UNIQUE (user_id, field, match, value)` constraint) to a `ValidationException` with the locked copy "A rule with this field, match, and value already exists. Edit the existing rule instead." per 07-UI-SPEC § Copywriting Contract. The existing pattern at `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php` shows how to translate DB errors to user-facing inline messages.
- **`ScanInboxDropFolderJob` is per-user-keyed** (`public readonly int $userId` constructor + `uniqueId()`), even though the closure iterates all opted-in users — this preserves the FND-03 isolation invariant and matches `IncrementalScanJob`'s per-inbox keying.

---

## Shared Cross-Cutting Patterns

These apply to multiple Phase 7 files. Apply consistently across all waves.

### Shared Pattern 1: Constructor DI only (CLAUDE.md `feedback_laravel_di_only.md`)

**Source:** Every existing module's service class, especially `Modules/Transfers/Public/Services/PairLookup.php` lines 28-32.

**Apply to:** Every NON-Livewire-component class in Phase 7 (matchers, services, actions, listeners, pipeline stages, jobs).

```php
final class SomeService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        // ... never facades, never helper functions like auth() / request() / config() / view() ...
    ) {}
}
```

### Shared Pattern 2: Livewire components — services on action methods, NOT constructor

**Source:** `Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php` lines 24-48 (note the docblock at lines 22-23: "Constructor-free Livewire component; services arrive as parameters on the relevant action / render methods.").

**Apply to:** Every Livewire SFC introduced or modified in Phase 7 (`RulesPage`, `RuleFormModal`, `CategorizationProvenancePanel`, `WizardEmailFileStep`, `ReceiptConflictToast`, `CorrectionDivergenceToast`, `SettingsPage` extension).

```php
final class SomeLivewireComponent extends Component
{
    public int $someProperty = 0;
    public ?int $optionalProperty = null;

    public function mount(SomeService $service): void { /* ... */ }

    public function someAction(CurrentUser $currentUser, SomeAction $action): void
    {
        ($action)($this->someProperty, $currentUser->user());
    }

    public function render(CurrentUser $user, SomeQuery $query, ViewFactory $views): View
    {
        return $views->make('module::livewire.some-component', [...]);
    }
}
```

### Shared Pattern 3: Cross-user isolation — `firstOrFail` or `where('user_id', $user->id)`

**Source:** `Modules/Chains/Public/Actions/ConfirmChainLink.php` lines 52-68 (firstOrFail-or-throw-NotFoundHttpException pattern); `Modules/Transfers/Public/Services/PairLookup.php` lines 36-50 (raw query builder + `where('user_id', $user->id)` scoping).

**Apply to:** EVERY Phase 7 action + service + query that reads/writes a per-user table — `categorization_rules`, `merchant_memories`, `file_imports`, `pending_enrichment_conflicts`, `transactions`.

```php
$row = $connection->table('categorization_rules')
    ->where('id', $ruleId)
    ->where('user_id', $user->id)
    ->first();
if ($row === null) { throw new NotFoundHttpException('Rule not found.'); }
```

### Shared Pattern 4: Raw `DatabaseManager` for `whereBetween` / `whereIn` / `orderBy` (PHPStan strict-rules `staticMethod.dynamicCall`)

**Source:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` lines 45-51 (the docblock spells it out) + lines 133-147 (raw query builder usage).

**Apply to:** `RuleEvaluator`, `MerchantMemoryWriter`, `ProcessFetchedInboxMessagesJob`, every action with composite WHERE clauses. Eloquent direct lookup by single PK + Eloquent `where()` chains are allowed; `whereBetween` / `whereIn` / `orderBy` are not (PHPStan blocks them).

### Shared Pattern 5: GSD-agnostic code comments (CLAUDE.md `feedback_codebase_gsd_agnostic.md`)

**Source:** Every existing file in the codebase. PHPDocs use plain technical language; no D-numbers, no plan references, no `.planning/...` paths, no PHASE-7 markers.

**Apply to:** Every PHPDoc, inline comment, and Blade comment in Phase 7. The rationale is captured in this PATTERNS.md and in CONTEXT.md; the code carries the *what*, not the *why-from-the-plan*.

### Shared Pattern 6: `view()` global helper is FORBIDDEN — use injected `ViewFactory`

**Source:** `Modules/Chains/Providers/ChainsServiceProvider.php` lines 122-137 + the docblock at lines 105-121 documenting the issue #12 fix.

**Apply to:** `ReceiptsServiceProvider`, any new View Factory composer (e.g., if a `/rules` top-nav badge ships later), every Livewire SFC's `render()` (already done by injecting `ViewFactory $views`).

### Shared Pattern 7: Migrations use container-resolved schema builder (NOT `Schema::create()` facade)

**Source:** `Modules/EmailScan/Database/Migrations/2026_05_16_020003_create_inbox_messages_table.php` lines 30-97 (the `private function schema()` + `private function db()` helpers) + `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` lines 26-37.

**Apply to:** Every new Phase 7 migration. Anonymous-migration classes get a `private ?DatabaseManager $resolvedDb = null` + `private function schema(): Builder` pair so the strict-DI invariant is visible even at the migration boundary. (Some existing migrations use `Schema::create()` directly — Phase 7 follows the newer DI-respecting pattern.)

### Shared Pattern 8: Pest test file shape

**Source:** `Modules/Transfers/tests/Pest.php` + `Modules/Transfers/tests/TestCase.php` + `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php` lines 36-80.

**Apply to:** Every new Phase 7 test file. Per the Phase 4 D-80b 3-step pattern: composer.json `autoload-dev` PSR-4 + phpunit.xml `<testsuite>` + `Modules/Receipts/tests/Pest.php` registration.

```
1. composer.json:    "Modules\\Receipts\\Tests\\": "tests/"
2. phpunit.xml:      <testsuite name="receipts"><directory>Modules/Receipts/tests</directory></testsuite>
3. Pest.php:         pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature'); pest()->extend(TestCase::class)->in('Unit');
```

---

## No Analog Found

Every Phase 7 file maps to an existing in-repo analog. Two files require RESEARCH.md as the secondary source of code shape (the in-repo precedent is only structurally similar, not data-flow similar):

| File | Role | Why partial | Use this from RESEARCH.md |
|------|------|-------------|----------------------------|
| `Modules/Receipts/Internal/Pipeline/MboxIterator.php` | streaming generator | No mbox iterator exists in the repo — the closest analog (`MailMimeParser`) is wrapped at the .eml level, not the .mbox level. The hand-rolled state machine is unique to Phase 7. | RESEARCH §"Pattern 3: `.mbox` streaming iterator (hand-rolled)" lines 453-498 has the full algorithm + edge cases. |
| `Modules/Receipts/Internal/Matchers/{Paypal,Ics,GooglePlay}ReceiptMatcher.php` per-sender body anchors | matcher body | The `canHandle()` + `match()` shape is exactly `PaypalCsvAdapter`'s; the empirical HTML/text anchors per sender are not in any existing file (they live in the .eml fixtures Wave 0 produces). | RESEARCH §"Per-Sender Matcher Patterns" (search the document for sender-specific anchor catalogues) + Wave 0 fixture corpus. |

---

## Metadata

**Analog search scope:** `Modules/Transfers/`, `Modules/Chains/`, `Modules/EmailScan/`, `Modules/Categorization/`, `Modules/Ingestion/`, `Modules/Import/`, `Modules/Ledger/`, `Modules/Core/`, `routes/`, `tests/Contracts/`, `resources/views/layouts/`.
**Files scanned:** 47 in-repo PHP/Blade/JSON files read end-to-end; 8 listings cross-referenced.
**Pattern extraction date:** 2026-05-17.

---

*Phase: 7 — Email Template Matchers + Categorization Learning*
*Patterns mapped: 2026-05-17*
