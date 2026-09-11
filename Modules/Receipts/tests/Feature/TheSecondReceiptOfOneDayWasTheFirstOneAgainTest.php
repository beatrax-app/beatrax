<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $seeded = $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->paypalAccountId = $seeded['paypalAccount']->id;

    $this->coffee = static fn (?string $reference): ParsedReceiptDto => new ParsedReceiptDto(
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

    $this->rowCount = fn (): int => DB::table('transactions')->where('user_id', $this->fixtureUser->id)->count();
});

it('keeps both of two receipts a reader was sent for one day', function (): void {
    $bridge = app(ReceiptLedgerBridge::class);

    $runId = $bridge->bridge(($this->coffee)('order-1'), $this->fixtureUser, null, SourceFormat::Eml);
    $bridge->bridge(($this->coffee)('order-2'), $this->fixtureUser, $runId, SourceFormat::Eml);

    expect(($this->rowCount)())->toBe(2)
        ->and(DB::table('transactions')->orderBy('occurrence_ordinal')->pluck('occurrence_ordinal')->all())
        ->toBe([0, 1]);
});

it('hashes each row over the ordinal it actually stored', function (): void {
    $bridge = app(ReceiptLedgerBridge::class);

    $runId = $bridge->bridge(($this->coffee)('order-1'), $this->fixtureUser, null, SourceFormat::Eml);
    $bridge->bridge(($this->coffee)('order-2'), $this->fixtureUser, $runId, SourceFormat::Eml);

    $composer = app(FingerprintComposer::class);

    foreach (DB::table('transactions')->orderBy('occurrence_ordinal')->get() as $row) {
        expect($row->fingerprint)->toBe($composer->composeTuple(new FingerprintTuple(
            userId: (int) $row->user_id,
            accountId: (int) $row->account_id,
            postedAtDate: (string) $row->posted_at,
            bookedAtDateTime: (string) $row->booked_at,
            amountMinor: (int) $row->amount_minor,
            currency: (string) $row->currency,
            counterpartyNormalized: (string) $row->counterparty_normalized,
            occurrenceOrdinal: (int) $row->occurrence_ordinal,
        )));
    }
});

it('writes one row when the same message is read a second time', function (): void {
    $bridge = app(ReceiptLedgerBridge::class);

    $runId = $bridge->bridge(($this->coffee)('order-1'), $this->fixtureUser, null, SourceFormat::Eml);
    $bridge->bridge(($this->coffee)('order-1'), $this->fixtureUser, $runId, SourceFormat::Eml);

    expect(($this->rowCount)())->toBe(1);
});

it('writes one row when a message names no reference to be told apart by', function (): void {
    $bridge = app(ReceiptLedgerBridge::class);

    $runId = $bridge->bridge(($this->coffee)(null), $this->fixtureUser, null, SourceFormat::Eml);
    $bridge->bridge(($this->coffee)(null), $this->fixtureUser, $runId, SourceFormat::Eml);

    expect(($this->rowCount)())->toBe(1);
});

it('leaves a receipt on the statement row that already booked the purchase', function (): void {
    $receipt = ($this->coffee)('order-1');
    $run = ImportRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => SourceFormat::PaypalCsv->value,
        'raw_file_path' => 'statement.csv',
        'sha256' => hash('sha256', 'statement.csv'),
        'uploaded_at' => CarbonImmutable::now()->toDateTimeString(),
        'status' => ImportRunStatus::Confirmed->value,
    ]);

    $statementRow = app(NormalizeStage::class)->run(
        (new ReceiptSourceAdapter)->toSourceDto($receipt),
        $this->paypalAccountId,
        $this->fixtureUser,
        $run->id,
        SourceFormat::PaypalCsv->value,
    );
    app(RecordsTransactions::class)([$statementRow], $this->fixtureUser);

    app(ReceiptLedgerBridge::class)->bridge($receipt, $this->fixtureUser, null, SourceFormat::Eml);

    expect(($this->rowCount)())->toBe(1)
        ->and(DB::table('transactions')->value('source_format'))->toBe(SourceFormat::PaypalCsv->value);
});
