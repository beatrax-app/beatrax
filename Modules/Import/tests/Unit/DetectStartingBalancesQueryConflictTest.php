<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Import\Public\Services\DetectStartingBalancesQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;

uses(RefreshDatabase::class);

/*
 * Unit coverage for DetectStartingBalancesQuery — the aggregator
 * that walks the tagged per-source detectors and applies the
 * per-account conflict-resolution rules. Resolves the singleton
 * from the container so the test exercises the real production
 * detector list bound under `starting-balance.detector`.
 */

it('returns one candidate per account when only one detector fires for that account', function (): void {
    $user = User::query()->create([
        'username' => 'agg-single',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Single-source account',
        'slug' => 'agg-single',
        'kind' => 'bank',
        'iban' => 'NL96ASNB0000000501',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/single.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $run->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 33333,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-01-01 00:00:00'),
    ]);

    /** @var DetectStartingBalancesQuery $query */
    $query = $this->app->make(DetectStartingBalancesQuery::class);

    $candidates = $query->collect([$run->id], $user);

    expect($candidates)->toHaveCount(1);
    expect($candidates[0])->toBeInstanceOf(StartingBalanceCandidate::class);
    expect($candidates[0]->accountId)->toBe($account->id);
    expect($candidates[0]->openingBalanceMinor)->toBe(33333);
    expect($candidates[0]->sourceFormat)->toBe('camt053');
})->group('phase-16.1.1');

it('prefers earliest opening_balance_date when two detectors fire for the same account', function (): void {
    $user = User::query()->create([
        'username' => 'agg-earliest',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Earliest-wins account',
        'slug' => 'agg-earliest',
        'kind' => 'bank',
        'iban' => 'NL15ASNB0000000601',
        'default_currency' => 'EUR',
    ]);

    // CAMT.053 has the EARLIER date (2026-01-01) so it wins on rule 1.
    $camtRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/camt.xml',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    $mt940Run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'mt940',
        'raw_file_path' => '/tmp/mt940.sta',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $camtRun->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 11111,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-01-01 00:00:00'),
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $mt940Run->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 22222,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
    ]);

    /** @var DetectStartingBalancesQuery $query */
    $query = $this->app->make(DetectStartingBalancesQuery::class);

    $candidates = $query->collect([$camtRun->id, $mt940Run->id], $user);

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]->openingBalanceMinor)->toBe(11111);
    expect($candidates[0]->openingBalanceDate)->toBe('2026-01-01');
    expect($candidates[0]->sourceFormat)->toBe('camt053');
})->group('phase-16.1.1');

it('breaks ties on the same date in favour of CAMT.053 over MT940', function (): void {
    $user = User::query()->create([
        'username' => 'agg-camt-wins',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'CAMT-preferred account',
        'slug' => 'agg-camt-wins',
        'kind' => 'bank',
        'iban' => 'NL31ASNB0000000701',
        'default_currency' => 'EUR',
    ]);

    $camtRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/camt-tie.xml',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    $mt940Run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'mt940',
        'raw_file_path' => '/tmp/mt940-tie.sta',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    // Identical opening_balance_date — different values.
    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $camtRun->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 55555,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-01-01 00:00:00'),
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $mt940Run->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 66666,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-01-01 00:00:00'),
    ]);

    /** @var DetectStartingBalancesQuery $query */
    $query = $this->app->make(DetectStartingBalancesQuery::class);

    $candidates = $query->collect([$camtRun->id, $mt940Run->id], $user);

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]->openingBalanceMinor)->toBe(55555);
    expect($candidates[0]->sourceFormat)->toBe('camt053');
})->group('phase-16.1.1');

it('surfaces both candidates when two CAMT.053 imports disagree on the same date with different values', function (): void {
    $user = User::query()->create([
        'username' => 'agg-both',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Conflict account',
        'slug' => 'agg-both',
        'kind' => 'bank',
        'iban' => 'NL47ASNB0000000801',
        'default_currency' => 'EUR',
    ]);

    $camtA = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/camt-a.xml',
        'sha256' => str_repeat('f', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    $camtB = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/camt-b.xml',
        'sha256' => str_repeat('0', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    // Same date, same source format, different values — surface BOTH.
    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $camtA->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 70000,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-01-01 00:00:00'),
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $camtB->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 80000,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-01-01 00:00:00'),
    ]);

    /** @var DetectStartingBalancesQuery $query */
    $query = $this->app->make(DetectStartingBalancesQuery::class);

    $candidates = $query->collect([$camtA->id, $camtB->id], $user);

    expect($candidates)->toHaveCount(2);

    $minors = array_map(static fn (StartingBalanceCandidate $c) => $c->openingBalanceMinor, $candidates);
    sort($minors);
    expect($minors)->toBe([70000, 80000]);

    foreach ($candidates as $candidate) {
        expect($candidate->accountId)->toBe($account->id);
        expect($candidate->sourceFormat)->toBe('camt053');
        expect($candidate->openingBalanceDate)->toBe('2026-01-01');
    }
})->group('phase-16.1.1');
