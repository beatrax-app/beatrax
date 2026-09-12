<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\RecordResult;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

// The bridge counts the ordinal off the ledger and the recorder opens its own
// transaction to write the row, so there is a gap between them. The desktop runs
// one queue worker, but sync:serve is a separate process whose applier inserts
// rows a peer sent — a writer that can take the counted ordinal inside that gap.
beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $seeded = $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->paypalAccountId = $seeded['paypalAccount']->id;

    $this->coffee = static fn (string $reference): ParsedReceiptDto => new ParsedReceiptDto(
        merchantName: 'Koffiehuis',
        amountMinor: -350,
        currency: 'EUR',
        settledAmountMinor: null,
        settledCurrency: null,
        referenceId: $reference,
        bookedAt: CarbonImmutable::parse('2026-05-15 00:00:00'),
        ownIban: 'PAYPAL',
        description: 'coffee',
        rawPayload: [],
    );

    $this->canonicalFor = function (ParsedReceiptDto $receipt, SourceFormat $format): CanonicalTransaction {
        $run = ImportRun::query()->create([
            'user_id' => $this->fixtureUser->id,
            'source_format' => $format->value,
            'raw_file_path' => 'rival-'.$receipt->referenceId.'.'.$format->value,
            'sha256' => hash('sha256', 'rival-'.$receipt->referenceId.'-'.$format->value),
            'uploaded_at' => CarbonImmutable::now()->toDateTimeString(),
            'status' => ImportRunStatus::Confirmed->value,
        ]);

        return app(NormalizeStage::class)->run(
            (new ReceiptSourceAdapter)->toSourceDto($receipt),
            $this->paypalAccountId,
            $this->fixtureUser,
            $run->id,
            $format->value,
        );
    };

    // The interleaving itself: the rival commits on the first call into the
    // recorder, which is after the bridge has counted its ordinal and before
    // the insert carrying it reaches the unique index.
    $this->raceAgainst = function (CanonicalTransaction $rival): void {
        $real = app(RecordsTransactions::class);

        app()->instance(RecordsTransactions::class, new class($real, $rival, $this->fixtureUser) implements RecordsTransactions
        {
            private bool $raced = false;

            public function __construct(
                private readonly RecordsTransactions $real,
                private readonly CanonicalTransaction $rival,
                private readonly User $owner,
            ) {}

            public function __invoke(iterable $canonical, User $user, bool $captureForSync = true): RecordResult
            {
                if (! $this->raced) {
                    $this->raced = true;
                    ($this->real)([$this->rival], $this->owner);
                }

                return ($this->real)($canonical, $user, $captureForSync);
            }
        });
    };

    $this->ledger = fn (): array => DB::table('transactions')
        ->where('user_id', $this->fixtureUser->id)
        ->orderBy('occurrence_ordinal')
        ->get(['source_format', 'source_ref', 'occurrence_ordinal'])
        ->map(static fn (object $row): array => [
            'source_format' => (string) $row->source_format,
            'source_ref' => (string) $row->source_ref,
            'occurrence_ordinal' => (int) $row->occurrence_ordinal,
        ])
        ->all();
});

it('keeps a second purchase whose ordinal another writer took first', function (): void {
    ($this->raceAgainst)(($this->canonicalFor)(($this->coffee)('order-1'), SourceFormat::Eml));

    app(ReceiptLedgerBridge::class)->bridge(($this->coffee)('order-2'), $this->fixtureUser, null, SourceFormat::Eml);

    expect(($this->ledger)())->toBe([
        ['source_format' => 'eml', 'source_ref' => 'order-1', 'occurrence_ordinal' => 0],
        ['source_format' => 'eml', 'source_ref' => 'order-2', 'occurrence_ordinal' => 1],
    ]);
});

// The reason a blind `ordinal + 1` is the wrong answer to a refusal. The receipt
// ordinal counts over receipt formats alone so that a purchase a statement
// already booked stays at 0 and dedupes into that booking; stepping past the
// statement row writes the purchase twice.
it('leaves the purchase on a statement row that landed while the receipt was mid-flight', function (): void {
    ($this->raceAgainst)(($this->canonicalFor)(($this->coffee)('camt-99'), SourceFormat::PaypalCsv));

    app(ReceiptLedgerBridge::class)->bridge(($this->coffee)('order-1'), $this->fixtureUser, null, SourceFormat::Eml);

    expect(($this->ledger)())->toBe([
        ['source_format' => 'paypal-csv', 'source_ref' => 'camt-99', 'occurrence_ordinal' => 0],
    ]);
});

// The recount re-reads the message's own reference, so it answers "already in the
// ledger" rather than a higher number. A retry that only compared ordinals would
// write this message a second time under a second ordinal.
it('writes nothing when a peer copy of the same message lands mid-flight', function (): void {
    ($this->raceAgainst)(($this->canonicalFor)(($this->coffee)('order-1'), SourceFormat::Eml));

    app(ReceiptLedgerBridge::class)->bridge(($this->coffee)('order-1'), $this->fixtureUser, null, SourceFormat::Eml);

    expect(($this->ledger)())->toBe([
        ['source_format' => 'eml', 'source_ref' => 'order-1', 'occurrence_ordinal' => 0],
    ]);
});
