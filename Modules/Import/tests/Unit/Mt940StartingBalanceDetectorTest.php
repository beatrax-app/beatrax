<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Detectors\Mt940StartingBalanceDetector;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;

uses(RefreshDatabase::class);

it('returns the supports() flag only for the mt940 source format', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new Mt940StartingBalanceDetector($db);

    expect($detector->supports('mt940'))->toBeTrue();
    expect($detector->supports('camt053'))->toBeFalse();
    expect($detector->supports('ics-pdf'))->toBeFalse();
    expect($detector->supports('paypal-csv'))->toBeFalse();
})->group('phase-16.1.1');

it('returns one candidate per account with the earliest opening_balance_date winning', function (): void {
    $user = User::query()->create([
        'username' => 'mt940-detect',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'MT940 account',
        'slug' => 'mt940-account',
        'kind' => 'bank',
        'iban' => 'NL64ASNB0000000301',
        'default_currency' => 'EUR',
    ]);

    $earlierRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'mt940',
        'raw_file_path' => '/tmp/earlier.sta',
        'sha256' => str_repeat('4', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-03-01 12:00:00'),
        'status' => 'previewed',
    ]);

    $laterRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'mt940',
        'raw_file_path' => '/tmp/later.sta',
        'sha256' => str_repeat('5', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $earlierRun->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 4242,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $laterRun->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 50000,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-03-01 00:00:00'),
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new Mt940StartingBalanceDetector($db);

    $candidates = $detector->detect([$earlierRun->id, $laterRun->id], $user);

    expect($candidates)->toHaveCount(1);
    $candidate = $candidates[0];
    expect($candidate)->toBeInstanceOf(StartingBalanceCandidate::class);
    expect($candidate->accountId)->toBe($account->id);
    expect($candidate->openingBalanceMinor)->toBe(4242);
    expect($candidate->openingBalanceDate)->toBe('2026-02-01');
    expect($candidate->sourceFormat)->toBe('mt940');
})->group('phase-16.1.1');

it('skips import-runs of a non-mt940 source format', function (): void {
    $user = User::query()->create([
        'username' => 'mt940-mixed',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Mixed account',
        'slug' => 'mt940-mixed',
        'kind' => 'bank',
        'iban' => 'NL80ASNB0000000401',
        'default_currency' => 'EUR',
    ]);

    $camtRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/camt.xml',
        'sha256' => str_repeat('6', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $camtRun->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 99999,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new Mt940StartingBalanceDetector($db);

    expect($detector->detect([$camtRun->id], $user))->toBe([]);
})->group('phase-16.1.1');
