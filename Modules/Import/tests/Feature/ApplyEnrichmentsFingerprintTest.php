<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\TransactionStatusWriter;

uses(RefreshDatabase::class);

function aefRun(User $user, string $sourceFormat): ImportRun
{
    /** @var ImportRun $run */
    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => $sourceFormat,
        'raw_file_path' => '/tmp/aef-'.bin2hex(random_bytes(4)).'.dat',
        'sha256' => hash('sha256', 'aef-'.uniqid('', true)),
        'uploaded_at' => CarbonImmutable::parse('2026-03-10 09:00:00'),
        'status' => 'confirmed',
    ]);

    return $run;
}

function aefSourceRow(?string $counterpartyName, ?string $sourceRef): SourceTransactionDto
{
    return new SourceTransactionDto(
        bookedAt: CarbonImmutable::parse('2026-03-10 00:00:00'),
        postedAt: CarbonImmutable::parse('2026-03-10'),
        valueDate: CarbonImmutable::parse('2026-03-10'),
        ownIban: 'NL57ASNB0123456789',
        counterpartyIban: null,
        counterpartyName: $counterpartyName,
        currency: 'EUR',
        amountMinor: -2500,
        sourceRef: $sourceRef,
        description: 'Boodschappen',
        rawPayload: [],
        sourceRowIndex: 0,
    );
}

function aefCanonical(SourceTransactionDto $source, int $accountId, User $user, int $importRunId, string $sourceFormat): CanonicalTransaction
{
    /** @var NormalizeStage $normalize */
    $normalize = app(NormalizeStage::class);

    return $normalize->run($source, $accountId, $user, $importRunId, $sourceFormat);
}

function aefPreferReceipt(User $user): void
{
    app(DatabaseManager::class)->connection()
        ->table('users')
        ->where('id', $user->id)
        ->update(['receipt_conflict_resolution' => 'prefer_receipt']);
}

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->account = $seeded['account'];
    $this->composer = $this->app->make(FingerprintComposer::class);
    $this->record = $this->app->make(RecordsTransactions::class);
});

it('re-imports the receipt row as a duplicate after prefer_receipt rewrote the counterparty name', function (): void {
    $statementRun = aefRun($this->fixtureUser, 'asn-csv');
    $stored = aefCanonical(
        aefSourceRow('NLPAYPAL ALBERT HEIJN', 'CSV-REF'),
        $this->account->id,
        $this->fixtureUser,
        $statementRun->id,
        'asn-csv',
    );
    ($this->record)([$stored], $this->fixtureUser);

    /** @var Transaction $tx */
    $tx = Transaction::query()->firstOrFail();
    aefPreferReceipt($this->fixtureUser);

    ($this->app->make(AppliesEnrichments::class))([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'PAYID-CANONICAL',
            importRunId: $statementRun->id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'Albert Heijn'],
            ],
        ),
    ], $this->fixtureUser);

    $receiptRun = aefRun($this->fixtureUser, SourceFormat::Eml->value);
    $reimported = aefCanonical(
        aefSourceRow('Albert Heijn', 'PAYID-CANONICAL'),
        $this->account->id,
        $this->fixtureUser,
        $receiptRun->id,
        SourceFormat::Eml->value,
    );

    $result = ($this->record)([$reimported], $this->fixtureUser);

    expect($result->inserted)->toBe(0)
        ->and($result->duplicates)->toBe(1)
        ->and(Transaction::query()->count())->toBe(1);

    /** @var Transaction $fresh */
    $fresh = Transaction::query()->findOrFail($tx->id);
    expect($fresh->fingerprint)->toBe($this->composer->compose($reimported))
        ->and($fresh->counterparty_normalized)->toBe($reimported->counterpartyNormalized);
});

it('recomposes the fingerprint when prefer_receipt rewrites the amount the tuple hashes', function (): void {
    $run = aefRun($this->fixtureUser, 'asn-csv');
    $stored = aefCanonical(
        aefSourceRow('Albert Heijn', 'CSV-REF'),
        $this->account->id,
        $this->fixtureUser,
        $run->id,
        'asn-csv',
    );
    ($this->record)([$stored], $this->fixtureUser);

    /** @var Transaction $tx */
    $tx = Transaction::query()->firstOrFail();
    aefPreferReceipt($this->fixtureUser);

    ($this->app->make(AppliesEnrichments::class))([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'PAYID-AMOUNT',
            importRunId: $run->id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'amount_minor' => ['stored' => -2500, 'incoming' => -2750],
            ],
        ),
    ], $this->fixtureUser);

    /** @var Transaction $fresh */
    $fresh = Transaction::query()->findOrFail($tx->id);

    expect($fresh->amount_minor)->toBe(-2750)
        ->and($fresh->fingerprint)->toBe($this->composer->composeTuple(
            $this->fixtureUser->id,
            $this->account->id,
            '2026-03-10',
            '2026-03-10 00:00:00',
            -2750,
            'EUR',
            $stored->counterpartyNormalized,
        ));
});

// The receipt sibling of this write already refuses a reconciled row and this
// one adopted the figure instead: a later file carrying a stronger reference
// walked the amount the reader had matched against a bank statement, and the
// run reported it as enriched.
it('leaves a reconciled row\'s amount alone when a later file carries a stronger reference', function (): void {
    $run = aefRun($this->fixtureUser, 'asn-csv');
    $stored = aefCanonical(
        aefSourceRow('Albert Heijn', 'CSV-REF'),
        $this->account->id,
        $this->fixtureUser,
        $run->id,
        'asn-csv',
    );
    ($this->record)([$stored], $this->fixtureUser);

    /** @var Transaction $tx */
    $tx = Transaction::query()->firstOrFail();
    aefPreferReceipt($this->fixtureUser);

    app(DatabaseManager::class)->connection()
        ->table('transactions')
        ->where('id', $tx->id)
        ->update(['status' => ClearedStatus::Cleared->value]);

    // Locked through the flow the reader would use, not by writing the column:
    // a fixture that stamps the value by hand proves the guard reads a string,
    // not that the reconcile path produces one this import then walks back.
    expect(app(TransactionStatusWriter::class)->reconcileClearedUpTo(
        $this->fixtureUser,
        $this->account->id,
        CarbonImmutable::parse('2026-03-10'),
    ))->toBe(1);

    $enrichment = new PendingEnrichment(
        existingTransactionId: $tx->id,
        newSourceRef: 'PAYID-AMOUNT',
        importRunId: $run->id,
        sourceFormat: SourceFormat::Eml->value,
        conflictingFields: [
            'amount_minor' => ['stored' => -2500, 'incoming' => -2750],
        ],
    );

    expect(($this->app->make(AppliesEnrichments::class))([$enrichment], $this->fixtureUser))->toBe(0)
        ->and(Transaction::query()->findOrFail($tx->id)->amount_minor)->toBe(-2500)
        ->and(Transaction::query()->findOrFail($tx->id)->source_ref)->toBe($tx->source_ref);

    // The counter-case: a refusal that also refuses an unlocked row says
    // nothing about the lock.
    app(TransactionStatusWriter::class)->unreconcile($this->fixtureUser, $tx->id);

    expect(($this->app->make(AppliesEnrichments::class))([$enrichment], $this->fixtureUser))->toBe(1)
        ->and(Transaction::query()->findOrFail($tx->id)->amount_minor)->toBe(-2750);
});
