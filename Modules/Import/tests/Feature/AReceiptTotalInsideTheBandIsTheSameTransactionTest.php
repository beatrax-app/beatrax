<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Internal\Services\NearTotalMatch;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Tests\Support\NearTotalFixture;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

function nearTotalWithAmount(CanonicalTransaction $tx, int $amountMinor): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $tx->userId,
        accountId: $tx->accountId,
        type: $tx->type,
        postedAt: $tx->postedAt,
        bookedAt: $tx->bookedAt,
        valueDate: $tx->valueDate,
        amountMinor: $amountMinor,
        currency: $tx->currency,
        settledAmountMinor: $amountMinor,
        settledCurrency: $tx->settledCurrency,
        counterpartyName: $tx->counterpartyName,
        counterpartyIban: $tx->counterpartyIban,
        counterpartyNormalized: $tx->counterpartyNormalized,
        normalizationVersion: $tx->normalizationVersion,
        description: $tx->description,
        categoryId: null,
        sourceFormat: $tx->sourceFormat,
        importRunId: $tx->importRunId,
        sourceRowIndex: $tx->sourceRowIndex,
        sourceRef: $tx->sourceRef,
        occurrenceOrdinal: $tx->occurrenceOrdinal,
    );
}

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->paypalAccountId = $seeded['paypalAccount']->id;
    $this->stage = $this->app->make(FingerprintStage::class);

    $this->statement = NearTotalFixture::statementCanonical($this->fixtureUser, $this->paypalAccountId);
    app(RecordsTransactions::class)([$this->statement], $this->fixtureUser);
    $this->storedId = (int) DB::table('transactions')->where('user_id', $this->fixtureUser->id)->value('id');
});

it('holds the statement row the receipts below are read against', function (): void {
    expect($this->statement->amountMinor)->toBe(-1299);
    expect($this->statement->currency)->toBe('EUR');
    expect($this->statement->sourceRef)->toBe('PAYPALTXN17052026');
    expect(NearTotalMatch::bandMinorFor(-1300))->toBe(13);
});

it('reads a receipt a cent over the statement as the transaction it nearly matches', function (): void {
    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-a-cent-over-receipt.eml');
    expect($receipt->amountMinor)->toBe(-1300);

    $disposition = $this->stage->classify($receipt, $this->fixtureUser);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    expect($disposition->existingTransactionId)->toBe($this->storedId);
    expect($disposition->conflictingFields[EnrichmentConflictField::AmountMinor->value] ?? null)
        ->toBe(['stored' => -1299, 'incoming' => -1300]);
});

// The comparison that records the disagreement stays exact while the one that
// decides the two are the same purchase does not: a cent inside the band is
// still a cent the reader is shown.
it('records the disagreement even though the totals are inside the band', function (): void {
    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-a-cent-over-receipt.eml');

    $disposition = $this->stage->classify($receipt, $this->fixtureUser);

    /** @var EnrichedDisposition $disposition */
    expect(array_keys($disposition->conflictingFields))->toContain(EnrichmentConflictField::AmountMinor->value);
});

it('still matches a receipt sitting exactly on the edge of the band', function (): void {
    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-at-the-band-edge-receipt.eml');
    expect($receipt->amountMinor)->toBe(-1312);
    expect(abs($receipt->amountMinor - $this->statement->amountMinor))->toBe(NearTotalMatch::bandMinorFor($receipt->amountMinor));

    expect($this->stage->classify($receipt, $this->fixtureUser)->status())->toBe(PreviewRowStatus::Enriched);
});

it('reads a receipt one minor unit past the band as a different transaction', function (): void {
    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-past-the-band-receipt.eml');
    expect($receipt->amountMinor)->toBe(-1313);
    expect(abs($receipt->amountMinor - $this->statement->amountMinor))
        ->toBe(NearTotalMatch::bandMinorFor($receipt->amountMinor) + 1);

    expect($this->stage->classify($receipt, $this->fixtureUser)->status())->toBe(PreviewRowStatus::NewRow);
});

// Matching either of two is worse than matching neither: the one not chosen
// keeps sitting in the ledger holding the other's receipt.
it('matches neither transaction when two of them sit inside the band', function (): void {
    $second = nearTotalWithAmount($this->statement, -1305);
    app(RecordsTransactions::class)([$second], $this->fixtureUser);
    expect(DB::table('transactions')->where('user_id', $this->fixtureUser->id)->count())->toBe(2);

    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-a-cent-over-receipt.eml');

    expect($this->stage->classify($receipt, $this->fixtureUser)->status())->toBe(PreviewRowStatus::NewRow);
});

// A statement is the authority on what settled, so a fuzzy total is no ground
// for one to absorb a row the reader would otherwise be shown.
it('refuses the band to an incoming statement row', function (): void {
    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-a-cent-over-receipt.eml');
    $statementShaped = new CanonicalTransaction(
        userId: $receipt->userId,
        accountId: $receipt->accountId,
        type: $receipt->type,
        postedAt: $receipt->postedAt,
        bookedAt: $receipt->bookedAt,
        valueDate: $receipt->valueDate,
        amountMinor: $receipt->amountMinor,
        currency: $receipt->currency,
        settledAmountMinor: $receipt->settledAmountMinor,
        settledCurrency: $receipt->settledCurrency,
        counterpartyName: $receipt->counterpartyName,
        counterpartyIban: $receipt->counterpartyIban,
        counterpartyNormalized: $receipt->counterpartyNormalized,
        normalizationVersion: $receipt->normalizationVersion,
        description: $receipt->description,
        categoryId: null,
        sourceFormat: SourceFormat::Camt053->value,
        importRunId: $receipt->importRunId,
        sourceRowIndex: 0,
        sourceRef: 'EREF-NEAR-TOTAL',
    );

    expect($this->stage->classify($statementShaped, $this->fixtureUser)->status())->toBe(PreviewRowStatus::NewRow);
});

// Without a reference of its own the receipt has nothing to attach and nothing
// the ranker can weigh, so it stays the sole occurrence of itself.
it('refuses the band to a receipt carrying no reference of its own', function (): void {
    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-a-cent-over-receipt.eml');
    $unreferenced = new CanonicalTransaction(
        userId: $receipt->userId,
        accountId: $receipt->accountId,
        type: $receipt->type,
        postedAt: $receipt->postedAt,
        bookedAt: $receipt->bookedAt,
        valueDate: $receipt->valueDate,
        amountMinor: $receipt->amountMinor,
        currency: $receipt->currency,
        settledAmountMinor: $receipt->settledAmountMinor,
        settledCurrency: $receipt->settledCurrency,
        counterpartyName: $receipt->counterpartyName,
        counterpartyIban: $receipt->counterpartyIban,
        counterpartyNormalized: $receipt->counterpartyNormalized,
        normalizationVersion: $receipt->normalizationVersion,
        description: $receipt->description,
        categoryId: null,
        sourceFormat: SourceFormat::Eml->value,
        importRunId: $receipt->importRunId,
        sourceRowIndex: 0,
        sourceRef: null,
    );

    expect($this->stage->classify($unreferenced, $this->fixtureUser)->status())->toBe(PreviewRowStatus::NewRow);
});

// The band is bare minor units, so reaching across currencies would be
// arithmetic on unlike quantities.
it('refuses the band to a receipt naming another currency', function (): void {
    DB::table('transactions')->where('id', $this->storedId)->update(['currency' => 'USD', 'settled_currency' => 'USD']);

    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-a-cent-over-receipt.eml');

    expect($this->stage->classify($receipt, $this->fixtureUser)->status())->toBe(PreviewRowStatus::NewRow);
});

// GenericCsvAdapter returns the currency cell verbatim, so a bank spelling it
// 'eur' hands the fingerprint a second digest for one currency. The match is
// case-normalised, and the disagreement it then compares is not one.
it('reads a statement row spelling the currency in lower case as the same transaction', function (): void {
    DB::table('transactions')->where('id', $this->storedId)->update(['currency' => 'eur']);

    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-a-cent-over-receipt.eml');
    $disposition = $this->stage->classify($receipt, $this->fixtureUser);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    expect(array_keys($disposition->conflictingFields))->not->toContain(EnrichmentConflictField::Currency->value);
});

// The exact arm is untouched: an identical total still reaches the ranking, so
// the near-total arm cannot be what is answering here.
it('leaves a receipt whose total matches exactly on the exact arm', function (): void {
    $receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'current-receipt.eml');
    expect($receipt->amountMinor)->toBe(-1299);

    $disposition = $this->stage->classify($receipt, $this->fixtureUser);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    expect(array_keys($disposition->conflictingFields))->not->toContain(EnrichmentConflictField::AmountMinor->value);
});
