<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Public\Actions\ApplyEnrichments;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

uses(RefreshDatabase::class);

function seedExistingTransaction(
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

    $tuple = implode('|', [
        (string) $user->id,
        (string) $accountId,
        '2026-02-15',
        '2026-02-15 00:00:00',
        '-1234',
        'EUR',
        'albert heijn',
    ]);
    $fingerprint = hash('sha256', $tuple);

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-02-15',
        'booked_at' => '2026-02-15 00:00:00',
        'value_date' => '2026-02-15',
        'amount_minor' => -1234,
        'currency' => 'EUR',
        'settled_amount_minor' => -1234,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => 'Albert Heijn',
        'counterparty_iban' => null,
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 3,
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
    $this->composer = $this->app->make(FingerprintComposer::class);
    $this->applier = $this->app->make(AppliesEnrichments::class);
});

it('returns 0 on an empty enrichments list', function (): void {
    expect(($this->applier)([], $this->fixtureUser))->toBe(0);
})->group('phase-2');

it('updates source_ref and appends a provenance entry to enriched_from', function (): void {
    $tx = seedExistingTransaction($this->fixtureUser, $this->account->id, 'asn-csv', null, $this->composer);

    $count = ($this->applier)([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'EREF-XYZ',
            importRunId: 99,
            sourceFormat: 'camt053',
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(1);

    /** @var Transaction $fresh */
    $fresh = Transaction::query()->findOrFail($tx->id);
    expect($fresh->source_ref)->toBe('EREF-XYZ');

    /** @var ArrayObject<int, array<string, mixed>> $prov */
    $prov = $fresh->enriched_from;
    expect($prov)->not->toBeNull();
    $entries = $prov->getArrayCopy();
    expect($entries)->toHaveCount(1);
    expect($entries[0]['format'])->toBe('camt053');
    expect($entries[0]['import_run_id'])->toBe(99);
    expect($entries[0]['added'])->toBe(['source_ref']);
    expect($entries[0]['ran_at'])->toBeString();
})->group('phase-2');

it('appends to an existing enriched_from array on a subsequent enrichment', function (): void {
    $tx = seedExistingTransaction($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001', $this->composer);
    $tx->enriched_from = [[
        'format' => 'mt940',
        'ran_at' => '2026-02-10T08:00:00+00:00',
        'import_run_id' => 1,
        'added' => ['source_ref'],
    ]];
    $tx->save();

    $count = ($this->applier)([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'EREF-STRONG',
            importRunId: 7,
            sourceFormat: 'camt053',
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(1);

    /** @var Transaction $fresh */
    $fresh = Transaction::query()->findOrFail($tx->id);
    expect($fresh->source_ref)->toBe('EREF-STRONG');

    /** @var ArrayObject<int, array<string, mixed>> $prov */
    $prov = $fresh->enriched_from;
    $entries = $prov->getArrayCopy();
    expect($entries)->toHaveCount(2);
    expect($entries[0]['format'])->toBe('mt940');
    expect($entries[1]['format'])->toBe('camt053');
    expect($entries[1]['import_run_id'])->toBe(7);
})->group('phase-2');

it('race-condition no-op: if existing source_ref already equals incoming, no UPDATE and no count', function (): void {
    $tx = seedExistingTransaction($this->fixtureUser, $this->account->id, 'camt053', 'EREF-X', $this->composer);
    $originalUpdatedAt = $tx->updated_at?->toIso8601String();

    $count = ($this->applier)([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'EREF-X',
            importRunId: 99,
            sourceFormat: 'camt053',
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(0);

    /** @var Transaction $fresh */
    $fresh = Transaction::query()->findOrFail($tx->id);
    expect($fresh->source_ref)->toBe('EREF-X');
    expect($fresh->enriched_from)->toBeNull();
    expect($fresh->updated_at?->toIso8601String())->toBe($originalUpdatedAt);
})->group('phase-2');

it('cross-user safety: a PendingEnrichment for another user\'s row returns 0', function (): void {
    /** @var User $other */
    $other = User::create([
        'username' => 'attacker-enrich',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    /** @var Account $otherAccount */
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Other ASN',
        'slug' => 'other-asn-applier',
        'kind' => 'bank',
        'iban' => 'NL78ABNA0000000999',
        'default_currency' => 'EUR',
    ]);
    $otherTx = seedExistingTransaction($other, $otherAccount->id, 'asn-csv', null, $this->composer);

    $count = ($this->applier)([
        new PendingEnrichment(
            existingTransactionId: $otherTx->id,
            newSourceRef: 'EREF-Y',
            importRunId: 99,
            sourceFormat: 'camt053',
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(0);

    /** @var Transaction $fresh */
    $fresh = Transaction::query()->findOrFail($otherTx->id);
    expect($fresh->source_ref)->toBeNull();
    expect($fresh->enriched_from)->toBeNull();
})->group('phase-2');

it('rank no-op: a cached weaker PendingEnrichment never overwrites a freshly-stored stronger source_ref', function (): void {
    // Preview classified an MT940 promotion (rank 2); the row is then bumped to
    // a CAMT ref (rank 4), standing in for a parallel import landing between
    // preview and confirm. Re-ranking at write time is what has to notice.
    $tx = seedExistingTransaction($this->fixtureUser, $this->account->id, 'asn-csv', 'CSV-001', $this->composer);
    $tx->source_format = 'camt053';
    $tx->source_ref = 'EREF-PARALLEL';
    $tx->save();

    $count = ($this->applier)([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'MT940-WEAK',
            importRunId: 99,
            sourceFormat: 'mt940',
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(0);

    /** @var Transaction $fresh */
    $fresh = Transaction::query()->findOrFail($tx->id);
    expect($fresh->source_ref)->toBe('EREF-PARALLEL');
    expect($fresh->source_format)->toBe('camt053');
    expect($fresh->enriched_from)->toBeNull();
})->group('phase-2');

it('rank no-op: an equal-rank cached enrichment does not overwrite an equally-strong stored ref', function (): void {
    // Both sides are CAMT-rank (4) with different values, so the ref===ref
    // equality short-circuit is not what is being exercised here.
    $tx = seedExistingTransaction($this->fixtureUser, $this->account->id, 'camt053', 'EREF-OLD', $this->composer);

    $count = ($this->applier)([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'EREF-NEW',
            importRunId: 99,
            sourceFormat: 'camt053',
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(0);

    /** @var Transaction $fresh */
    $fresh = Transaction::query()->findOrFail($tx->id);
    expect($fresh->source_ref)->toBe('EREF-OLD');
})->group('phase-2');

it('binds the AppliesEnrichments contract to ApplyEnrichments', function (): void {
    $resolved = $this->app->make(AppliesEnrichments::class);

    expect($resolved)->toBeInstanceOf(ApplyEnrichments::class);
})->group('phase-2');
