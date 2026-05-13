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
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-camt053', 'EREF-A', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', null);

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns enriched when fingerprint matches and incoming source_ref is non-null vs existing NULL', function (): void {
    $existing = seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-csv', null, $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-camt053', 'EREF-A');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('enriched');
    expect($disposition->isEnriched())->toBeTrue();
    /** @var EnrichedDisposition $disposition */
    expect($disposition->existingTransactionId)->toBe($existing->id);
    expect($disposition->fromSourceRef)->toBeNull();
    expect($disposition->toSourceRef)->toBe('EREF-A');
})->group('phase-2');

it('returns enriched when both source_refs non-null but incoming has higher rank (CAMT > MT940)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-mt940', 'MT940-REF-A', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-camt053', 'EREF-B');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('enriched');
    /** @var EnrichedDisposition $disposition */
    expect($disposition->fromSourceRef)->toBe('MT940-REF-A');
    expect($disposition->toSourceRef)->toBe('EREF-B');
})->group('phase-2');

it('returns enriched when both source_refs non-null but incoming has higher rank (MT940 > CSV)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-mt940', 'MT940-REF-A');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('enriched');
    /** @var EnrichedDisposition $disposition */
    expect($disposition->fromSourceRef)->toBe('CSV-001');
    expect($disposition->toSourceRef)->toBe('MT940-REF-A');
})->group('phase-2');

it('returns duplicate when incoming rank is lower than existing (CSV after CAMT)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-camt053', 'EREF-A', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns duplicate when incoming rank equals existing (same format re-import, same ref)', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-camt053', 'EREF-A', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-camt053', 'EREF-A');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('returns duplicate when incoming rank equals existing AND ref values differ', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-camt053', 'EREF-OLD', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-camt053', 'EREF-NEW');

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('duplicate');
})->group('phase-2');

it('scopes the existing-row lookup by user_id', function (): void {
    /** @var User $otherUser */
    $otherUser = User::create([
        'email' => 'other-fingerprint-test@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    /** @var Account $otherAccount */
    $otherAccount = Account::create([
        'user_id' => $otherUser->id,
        'name' => 'Other ASN',
        'slug' => 'other-asn',
        'kind' => 'asn',
        'iban' => 'NL18ABNA0000000999',
        'default_currency' => 'EUR',
    ]);
    seedTransactionMatchingCanonical($otherUser, $otherAccount->id, 'asn-camt053', 'EREF-OTHER', $this->composer);

    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', null);

    $disposition = $this->stage->classify($tx, $this->fixtureUser);

    expect($disposition->status())->toBe('new');
})->group('phase-2');

it('classify retains backward compat through deprecated isExistingFingerprint', function (): void {
    seedTransactionMatchingCanonical($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001', $this->composer);
    $tx = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001');

    expect($this->stage->isExistingFingerprint($tx, $this->fixtureUser))->toBeTrue();

    $fresh = canonicalForUser($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-002');
    // Different counterparty-normalized would change the fingerprint; we use a
    // different account_id here to confirm "not found" returns false.
    $fresh = new CanonicalTransaction(
        userId: $fresh->userId,
        accountId: 99_999,
        type: $fresh->type,
        postedAt: $fresh->postedAt,
        bookedAt: $fresh->bookedAt,
        valueDate: $fresh->valueDate,
        amountMinor: $fresh->amountMinor,
        currency: $fresh->currency,
        settledAmountMinor: $fresh->settledAmountMinor,
        settledCurrency: $fresh->settledCurrency,
        fxRateUsed: null,
        counterpartyName: $fresh->counterpartyName,
        counterpartyIban: null,
        counterpartyNormalized: $fresh->counterpartyNormalized,
        normalizationVersion: $fresh->normalizationVersion,
        description: null,
        categoryId: null,
        sourceFormat: $fresh->sourceFormat,
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: null,
    );
    expect($this->stage->isExistingFingerprint($fresh, $this->fixtureUser))->toBeFalse();
})->group('phase-2');
