<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Import\Tests\Support\NearTotalFixture;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Receipts\Public\Events\ReceiptConflictDetected;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;
use Modules\Sync\Public\Events\TransactionMutated;

uses(RefreshDatabase::class);

// A5's edge case end to end, over the fixtures FingerprintParityTest pins: one
// PayPal activity export row, and the mail PayPal sent for the same payment
// printing a total a cent away from it. Before the band, the receipt hashed to
// nothing stored and the reader got a second Netflix charge for the same night.
beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->paypalAccountId = $seeded['paypalAccount']->id;

    $statement = NearTotalFixture::statementCanonical($this->fixtureUser, $this->paypalAccountId);
    app(RecordsTransactions::class)([$statement], $this->fixtureUser);
    $this->storedId = (int) DB::table('transactions')->where('user_id', $this->fixtureUser->id)->value('id');

    $this->receipt = NearTotalFixture::receiptCanonical($this->fixtureUser, $this->paypalAccountId, 'total-a-cent-over-receipt.eml');
});

function nearTotalConflictApply(User $user, CanonicalTransaction $receipt): int
{
    $disposition = app(FingerprintStage::class)->classify($receipt, $user);
    expect($disposition->isEnriched())->toBeTrue();

    /** @var AppliesEnrichments $applier */
    $applier = app(AppliesEnrichments::class);

    return $applier([
        new PendingEnrichment(
            existingTransactionId: $disposition->existingTransactionId,
            newSourceRef: $disposition->toSourceRef,
            importRunId: $receipt->importRunId,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: $disposition->conflictingFields,
        ),
    ], $user)->count;
}

it('records the disagreement instead of writing a second transaction', function (): void {
    Event::fake([ReceiptConflictDetected::class]);

    expect(nearTotalConflictApply($this->fixtureUser, $this->receipt))->toBe(1);

    expect(DB::table('transactions')->where('user_id', $this->fixtureUser->id)->count())->toBe(1);

    $conflict = DB::table('pending_enrichment_conflicts')
        ->where('user_id', $this->fixtureUser->id)
        ->where('field_name', EnrichmentConflictField::AmountMinor->value)
        ->first();

    expect($conflict)->not->toBeNull();
    expect($conflict->transaction_id)->toBe($this->storedId);
    expect($conflict->stored_value)->toBe('-1299');
    expect($conflict->incoming_value)->toBe('-1300');
    expect($conflict->resolution)->toBeNull();

    Event::assertDispatched(ReceiptConflictDetected::class);
});

// The default policy leaves the field alone, so the figure the reader has not
// answered about yet is still the one the bank stated.
it('leaves the stored total standing until the reader answers', function (): void {
    nearTotalConflictApply($this->fixtureUser, $this->receipt);

    $row = DB::table('transactions')->where('id', $this->storedId)->first();

    expect($row->amount_minor)->toBe(-1299);
    expect($row->settled_amount_minor)->toBe(-1299);
});

// Receipt and export carry one PayPal Transaction ID, so this enrichment adds
// no reference strength at all — it is admitted for its disagreement alone, and
// the reference it would have written is the one already stored.
it('keeps the stored source_ref when the receipt only disagrees', function (): void {
    nearTotalConflictApply($this->fixtureUser, $this->receipt);

    $row = DB::table('transactions')->where('id', $this->storedId)->first();

    expect($row->source_ref)->toBe('PAYPALTXN17052026');
    expect($row->enriched_from)->toContain(SourceFormat::Eml->value);
});

it('offers the reader both figures at the currency each is denominated in', function (): void {
    nearTotalConflictApply($this->fixtureUser, $this->receipt);

    $latest = app(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser);

    expect($latest)->not->toBeNull();
    expect($latest['field'])->toBe(EnrichmentConflictField::AmountMinor->value);
    expect($latest['storedValue'])->toBe('-1299');
    expect($latest['incomingValue'])->toBe('-1300');
    expect($latest['storedCurrency'])->toBe('EUR');
    expect($latest['incomingCurrency'])->toBe('EUR');
    expect($latest['sourceFormat'])->toBe(SourceFormat::Eml->value);
});

// The four amount columns and the digest composed over them move together, or
// the row stops matching its own re-import and lands twice.
it('moves every amount column and the fingerprint when the reader takes the receipt', function (): void {
    nearTotalConflictApply($this->fixtureUser, $this->receipt);
    $latest = app(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser);

    Event::fake([TransactionMutated::class]);
    app(ApplyReceiptConflictResolution::class)($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $latest['conflictId']);

    $row = DB::table('transactions')->where('id', $this->storedId)->first();
    $composer = app(FingerprintComposer::class);

    expect($row->amount_minor)->toBe(-1300);
    expect($row->settled_amount_minor)->toBe(-1300);
    expect($row->currency)->toBe('EUR');
    expect($row->settled_currency)->toBe('EUR');
    expect($row->fx_rate_used)->toBeNull();
    expect($row->fingerprint)->toBe($composer->composeTuple(new FingerprintTuple(
        $this->fixtureUser->id,
        $this->paypalAccountId,
        '2026-05-17',
        '2026-05-17 00:00:00',
        -1300,
        'EUR',
        'netflix bv',
        0,
    )));

    // The one conflict the reader was shown, and no other: the same receipt
    // also renamed the row, and that is a second question they have not answered.
    expect(DB::table('pending_enrichment_conflicts')
        ->where('user_id', $this->fixtureUser->id)
        ->where('field_name', EnrichmentConflictField::AmountMinor->value)
        ->count())->toBe(0);
    expect(DB::table('pending_enrichment_conflicts')
        ->where('user_id', $this->fixtureUser->id)
        ->where('field_name', EnrichmentConflictField::Description->value)
        ->count())->toBe(1);

    Event::assertDispatched(TransactionMutated::class, function (TransactionMutated $event): bool {
        return $event->transactionId === $this->storedId
            && array_key_exists('amount_minor', $event->dirtyFields)
            && array_key_exists('settled_amount_minor', $event->dirtyFields)
            && array_key_exists('fingerprint', $event->dirtyFields);
    });
});

// A re-import of the same receipt must find the row it just resolved onto, or
// taking the receipt's figure once buys a duplicate on the next scan.
it('recognises the same receipt again once its figure has been taken', function (): void {
    nearTotalConflictApply($this->fixtureUser, $this->receipt);
    $latest = app(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser);
    app(ApplyReceiptConflictResolution::class)($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $latest['conflictId']);

    $disposition = app(FingerprintStage::class)->classify($this->receipt, $this->fixtureUser);

    expect($disposition->isNew())->toBeFalse();
    expect(DB::table('transactions')->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});
