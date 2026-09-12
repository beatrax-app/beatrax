<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\TransactionType;

uses(RefreshDatabase::class);

// The reference is a lookup and never an identity: it finds the candidate, and
// every other term the fingerprint hashes still has to agree. A reference a
// bank fills with a constant would otherwise swallow unrelated rows.
function restatementCandidateRow(
    int $userId,
    int $accountId,
    int $runId,
    int $amountMinor,
    ?string $sourceRef,
    string $currency = 'EUR',
    string $postedAt = '2026-03-04',
    int $ordinal = 0,
): CanonicalTransaction {
    $day = CarbonImmutable::parse($postedAt);

    return new CanonicalTransaction(
        userId: $userId,
        accountId: $accountId,
        type: TransactionType::Expense->value,
        postedAt: $day,
        bookedAt: $day->startOfDay(),
        valueDate: $day,
        amountMinor: $amountMinor,
        currency: $currency,
        settledAmountMinor: $amountMinor,
        settledCurrency: $currency,
        counterpartyName: 'Cafe Rialto',
        counterpartyIban: null,
        counterpartyNormalized: 'cafe rialto',
        normalizationVersion: 4,
        description: 'Cafe Rialto Utrecht',
        categoryId: null,
        sourceFormat: SourceFormat::Camt053->value,
        importRunId: $runId,
        sourceRowIndex: 0,
        sourceRef: $sourceRef,
        occurrenceOrdinal: $ordinal,
    );
}

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->accountId = $seeded['account']->id;
    $this->otherAccountId = $seeded['icsAccount']->id;
    $this->stage = $this->app->make(FingerprintStage::class);

    /** @var ImportRun $run */
    $run = ImportRun::create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => SourceFormat::Camt053->value,
        'raw_file_path' => '/tmp/restatement-guard.xml',
        'sha256' => hash('sha256', 'restatement-guard'),
        'uploaded_at' => '2026-03-04 12:00:00',
        'status' => 'confirmed',
    ]);

    $this->row = fn (
        int $amountMinor,
        ?string $sourceRef,
        string $currency = 'EUR',
        string $postedAt = '2026-03-04',
        int $ordinal = 0,
        ?int $accountId = null,
    ): CanonicalTransaction => restatementCandidateRow(
        (int) $this->fixtureUser->id,
        $accountId ?? $this->accountId,
        (int) $run->id,
        $amountMinor,
        $sourceRef,
        $currency,
        $postedAt,
        $ordinal,
    );

    $this->store = function (CanonicalTransaction $tx): void {
        $this->app->make(RecordsTransactions::class)([$tx], $this->fixtureUser);
    };

    ($this->store)(($this->row)(-1299, 'E2E-COFFEE-4471902'));
    $this->storedId = (int) DB::table('transactions')->where('user_id', $this->fixtureUser->id)->value('id');
});

it('restates the stored row when only the total disagrees', function (): void {
    $disposition = $this->stage->classify(($this->row)(-1499, 'E2E-COFFEE-4471902'), $this->fixtureUser);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    expect($disposition->existingTransactionId)->toBe($this->storedId);
    expect($disposition->conflictingFields[EnrichmentConflictField::AmountMinor->value] ?? null)
        ->toBe(['stored' => -1299, 'incoming' => -1499]);
});

// Without this the fix reads as working while collapsing everything: a row
// agreeing about the total is the plain duplicate it has always been.
it('leaves a row stating the same total as a duplicate', function (): void {
    expect($this->stage->classify(($this->row)(-1299, 'E2E-COFFEE-4471902'), $this->fixtureUser)->status())
        ->toBe(PreviewRowStatus::Duplicate);
});

it('writes a row carrying no reference of its own as new', function (): void {
    expect($this->stage->classify(($this->row)(-1499, null), $this->fixtureUser)->status())
        ->toBe(PreviewRowStatus::NewRow);
});

it('writes a row carrying another reference as new', function (): void {
    expect($this->stage->classify(($this->row)(-1499, 'E2E-COFFEE-4471903'), $this->fixtureUser)->status())
        ->toBe(PreviewRowStatus::NewRow);
});

// A reference two stored rows share is a constant the bank fills in, not a name
// for either of them. Matching one of the two is worse than matching neither,
// because the one not chosen keeps the other's restatement. Two purchases at
// one merchant on one day at different prices are both the ordinal's first.
it('restates neither row when two of them carry the reference', function (): void {
    ($this->store)(($this->row)(-1399, 'E2E-COFFEE-4471902'));
    expect(DB::table('transactions')->where('user_id', $this->fixtureUser->id)->count())->toBe(2);

    expect($this->stage->classify(($this->row)(-1499, 'E2E-COFFEE-4471902'), $this->fixtureUser)->status())
        ->toBe(PreviewRowStatus::NewRow);
});

// The reference names a row of ONE account. The same string on another
// account's statement is another bank's counter.
it('writes a row carrying the reference on another account as new', function (): void {
    $incoming = ($this->row)(-1499, 'E2E-COFFEE-4471902', 'EUR', '2026-03-04', 0, $this->otherAccountId);

    expect($this->stage->classify($incoming, $this->fixtureUser)->status())->toBe(PreviewRowStatus::NewRow);
});

// Widening the total and the day at once multiplies what a wrong match reaches,
// so a row the bank filed on another day is a row of its own.
it('writes a row carrying the reference on another day as new', function (): void {
    $incoming = ($this->row)(-1499, 'E2E-COFFEE-4471902', 'EUR', '2026-03-05');

    expect($this->stage->classify($incoming, $this->fixtureUser)->status())->toBe(PreviewRowStatus::NewRow);
});

// Two rows disagreeing about the currency as well as the total are not one row
// at another price, and subtracting the one from the other is arithmetic on
// unlike quantities.
it('writes a row carrying the reference in another currency as new', function (): void {
    $incoming = ($this->row)(-1499, 'E2E-COFFEE-4471902', 'USD');

    expect($this->stage->classify($incoming, $this->fixtureUser)->status())->toBe(PreviewRowStatus::NewRow);
});

// A bank spelling the code in lower case hands the digest a second currency,
// and a restatement is not refused over a spelling.
it('restates a stored row spelling the currency in lower case', function (): void {
    DB::table('transactions')->where('id', $this->storedId)->update(['currency' => 'eur']);

    $disposition = $this->stage->classify(($this->row)(-1499, 'E2E-COFFEE-4471902'), $this->fixtureUser);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    expect(array_keys($disposition->conflictingFields))->not->toContain(EnrichmentConflictField::Currency->value);
});

it('never reaches a reference stored under another user', function (): void {
    $stranger = User::query()->create([
        'username' => 'restatement-stranger',
        'password' => 'restatement-stranger-password',
        'period_start_day' => 1,
    ]);

    DB::table('transactions')->where('id', $this->storedId)->update(['user_id' => $stranger->id]);

    expect($this->stage->classify(($this->row)(-1499, 'E2E-COFFEE-4471902'), $this->fixtureUser)->status())
        ->toBe(PreviewRowStatus::NewRow);
});

// A yen has no minor unit, so these two figures are whole yen and the hundredth
// a euro band would have bought is not a quantity this currency has.
it('records a zero-decimal disagreement in the units the file stated', function (): void {
    DB::table('transactions')->where('user_id', $this->fixtureUser->id)->delete();

    ($this->store)(($this->row)(-1200, 'JPY-COFFEE-771', 'JPY'));
    $storedId = (int) DB::table('transactions')->where('user_id', $this->fixtureUser->id)->value('id');

    $disposition = $this->stage->classify(($this->row)(-1500, 'JPY-COFFEE-771', 'JPY'), $this->fixtureUser);

    expect($disposition->status())->toBe(PreviewRowStatus::Enriched);
    /** @var EnrichedDisposition $disposition */
    expect($disposition->existingTransactionId)->toBe($storedId);
    expect($disposition->conflictingFields[EnrichmentConflictField::AmountMinor->value] ?? null)
        ->toBe(['stored' => -1200, 'incoming' => -1500]);
});
