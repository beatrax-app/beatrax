<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

uses(RefreshDatabase::class);

/**
 * Builds a CanonicalTransaction whose v3 fingerprint matches any other
 * row built with `canonicalForUser($user, $accountId)`. Keep the date,
 * amount, counterparty fields stable across calls so the SHA-256 hash
 * is invariant.
 */
function canonicalForUser(User $user, int $accountId, string $sourceFormat, ?string $sourceRef): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $user->id,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-02-15'),
        bookedAt: CarbonImmutable::parse('2026-02-15 00:00:00'),
        valueDate: CarbonImmutable::parse('2026-02-15'),
        amountMinor: -1234,
        currency: 'EUR',
        settledAmountMinor: -1234,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: 'Albert Heijn',
        counterpartyIban: null,
        counterpartyNormalized: 'albert heijn',
        normalizationVersion: 3,
        description: null,
        categoryId: null,
        sourceFormat: $sourceFormat,
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: $sourceRef,
    );
}

/**
 * Persists an existing transactions row whose v3 fingerprint matches the
 * canonical produced by `canonicalForUser`. The DB row carries the
 * (sourceFormat, sourceRef) pair under test; the fingerprint itself is
 * independent of those fields (v3 drops source_ref from the tuple).
 */
function seedTransactionMatchingCanonical(
    User $user,
    int $accountId,
    string $sourceFormat,
    ?string $sourceRef,
    FingerprintComposer $composer,
): Transaction {
    /** @var ImportRun $run */
    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => $sourceFormat,
        'raw_file_path' => '/tmp/seed-'.bin2hex(random_bytes(4)).'.dat',
        'sha256' => hash('sha256', 'seed-'.uniqid('', true)),
        'uploaded_at' => CarbonImmutable::parse('2026-02-15 12:00:00'),
        'status' => 'confirmed',
    ]);

    $canonical = canonicalForUser($user, $accountId, $sourceFormat, $sourceRef);
    $fingerprint = $composer->compose($canonical);

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => $canonical->postedAt->toDateString(),
        'booked_at' => $canonical->bookedAt->toDateTimeString(),
        'value_date' => $canonical->valueDate->toDateString(),
        'amount_minor' => $canonical->amountMinor,
        'currency' => $canonical->currency,
        'settled_amount_minor' => $canonical->settledAmountMinor,
        'settled_currency' => $canonical->settledCurrency,
        'fx_rate_used' => null,
        'counterparty_name' => $canonical->counterpartyName,
        'counterparty_iban' => null,
        'counterparty_normalized' => $canonical->counterpartyNormalized,
        'normalization_version' => $canonical->normalizationVersion,
        'description' => null,
        'category_id' => null,
        'source_format' => $sourceFormat,
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'source_ref' => $sourceRef,
        'fingerprint' => $fingerprint,
        'fingerprint_version' => $composer->version(),
        'status' => 'cleared',
    ]);
}

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->account = $seeded['account'];
    $this->stage = $this->app->make(FingerprintStage::class);
    $this->composer = $this->app->make(FingerprintComposer::class);
});

it('returns newRow when no existing fingerprint matches', function (): void {
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('new');
    expect($disposition->isNew())->toBeTrue();
})->group('phase-2');

it('returns duplicate when fingerprint matches and incoming source_ref is NULL', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'camt053', 'EREF-A', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', null);

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns duplicate when fingerprint matches across statement formats (CSV → CAMT) — receipt-format gate not satisfied', function (): void {
    // Phase 16 UAT batch 7 policy change: statement-vs-statement
    // collisions drop as DUPLICATE regardless of source_ref rank. The
    // prior phase-2 behaviour returned ENRICHED here because the
    // incoming CAMT source_ref outranked the stored CSV NULL ref; the
    // user's UAT report locked the simpler "same transaction → drop"
    // policy because the audit chain shouldn't grow a second
    // source_format on a re-import.
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-csv', null, $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'camt053', 'EREF-A');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns duplicate when fingerprint matches across statement formats (MT940 → CAMT)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'mt940', 'MT940-REF-A', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'camt053', 'EREF-B');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns duplicate when fingerprint matches across statement formats (CSV → MT940)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'mt940', 'MT940-REF-A');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns enriched when fingerprint matches and the incoming side is a receipt format (paypal-receipt > paypal-csv)', function (): void {
    // Receipt-driven enrichment is preserved: a receipt may carry a
    // clean merchant name / line items the bank-statement export
    // lacks, so the rank-based upgrade still fires when at least one
    // side is a receipt format.
    $existing = seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'paypal-csv', 'O-00000000000000001', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'paypal-receipt', 'PAYID-CANONICAL');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('enriched');
    expect($disposition->isEnriched())->toBeTrue();
    /** @var EnrichedDisposition $disposition */
    expect($disposition->existingTransactionId)->toBe($existing->id);
    expect($disposition->toSourceRef)->toBe('PAYID-CANONICAL');
})->group('phase-2');

it('returns enriched when fingerprint matches and the existing side is a receipt format (ics-csv impossible, ics-pdf → ics-receipt)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'ics-pdf', 'PDF-ROW-12', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'ics-receipt', 'RECEIPT-REF');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('enriched');
    /** @var EnrichedDisposition $disposition */
    expect($disposition->fromSourceRef)->toBe('PDF-ROW-12');
    expect($disposition->toSourceRef)->toBe('RECEIPT-REF');
})->group('phase-2');

it('returns duplicate when incoming rank is lower than existing (CSV after CAMT)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'camt053', 'EREF-A', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns duplicate when incoming rank equals existing (same format re-import, same ref)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'camt053', 'EREF-A', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'camt053', 'EREF-A');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns duplicate when incoming rank equals existing AND ref values differ', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'camt053', 'EREF-OLD', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'camt053', 'EREF-NEW');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('scopes the existing-row lookup by user_id', function (): void {
    /** @var User $otherUser */
    $otherUser = User::create([
        'username' => 'other-fingerprint-test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    /** @var Account $otherAccount */
    $otherAccount = Account::create([
        'user_id' => $otherUser->id,
        'name' => 'Other ASN',
        'slug' => 'other-asn',
        'kind' => 'bank',
        'iban' => 'NL78ABNA0000000999',
        'default_currency' => 'EUR',
    ]);
    seedTransactionMatchingCanonical($otherUser, $otherAccount->id, 'camt053', 'EREF-OTHER', $this->composer);

    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', null);

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('new');
})->group('phase-2');
