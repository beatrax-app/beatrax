<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Detectors\Camt053StartingBalanceDetector;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;

uses(RefreshDatabase::class);

it('returns the supports() flag only for the camt053 source format', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new Camt053StartingBalanceDetector($db);

    expect($detector->supports('camt053'))->toBeTrue();
    expect($detector->supports('mt940'))->toBeFalse();
    expect($detector->supports('ics-pdf'))->toBeFalse();
    expect($detector->supports('paypal-csv'))->toBeFalse();
})->group('phase-16.1.1');

it('returns an empty list when the importRunIds array is empty', function (): void {
    $user = User::query()->create([
        'username' => 'camt-empty',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new Camt053StartingBalanceDetector($db);

    expect($detector->detect([], $user))->toBe([]);
})->group('phase-16.1.1');

it('returns one candidate per account with the earliest opening_balance_date per account winning', function (): void {
    $user = User::query()->create([
        'username' => 'camt-detect',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $accountA = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'CAMT A',
        'slug' => 'camt-a',
        'kind' => 'bank',
        'iban' => 'NL32ASNB0000000101',
        'default_currency' => 'EUR',
    ]);

    $accountB = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'CAMT B',
        'slug' => 'camt-b',
        'kind' => 'bank',
        'iban' => 'NL05ASNB0000000102',
        'default_currency' => 'EUR',
    ]);

    $runA1 = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/camt-a1.xml',
        'sha256' => str_repeat('1', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-03-01 12:00:00'),
        'status' => 'previewed',
    ]);

    $runA2 = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/camt-a2.xml',
        'sha256' => str_repeat('2', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    $runB = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/camt-b.xml',
        'sha256' => str_repeat('3', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    // Account A: two summaries; earlier date (12345) must win.
    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $runA1->id,
        'account_id' => $accountA->id,
        'iban_owner' => $accountA->iban,
        'opening_balance_minor' => 12345,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $runA2->id,
        'account_id' => $accountA->id,
        'iban_owner' => $accountA->iban,
        'opening_balance_minor' => 99999,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-03-15 00:00:00'),
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $runB->id,
        'account_id' => $accountB->id,
        'iban_owner' => $accountB->iban,
        'opening_balance_minor' => -5000,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-03-20 00:00:00'),
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new Camt053StartingBalanceDetector($db);

    $candidates = $detector->detect([$runA1->id, $runA2->id, $runB->id], $user);

    expect($candidates)->toHaveCount(2);

    $byAccount = [];
    foreach ($candidates as $c) {
        expect($c)->toBeInstanceOf(StartingBalanceCandidate::class);
        $byAccount[$c->accountId] = $c;
    }

    expect($byAccount[$accountA->id]->openingBalanceMinor)->toBe(12345);
    expect($byAccount[$accountA->id]->openingBalanceDate)->toBe('2026-02-01');
    expect($byAccount[$accountA->id]->sourceFormat)->toBe('camt053');

    expect($byAccount[$accountB->id]->openingBalanceMinor)->toBe(-5000);
    expect($byAccount[$accountB->id]->openingBalanceDate)->toBe('2026-03-20');
    expect($byAccount[$accountB->id]->sourceFormat)->toBe('camt053');
})->group('phase-16.1.1');

it('ignores import-runs that belong to a different user (user-scoping guard)', function (): void {
    $userA = User::query()->create([
        'username' => 'camt-user-a',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $userB = User::query()->create([
        'username' => 'camt-user-b',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $accountB = Account::query()->create([
        'user_id' => $userB->id,
        'name' => 'User B account',
        'slug' => 'user-b',
        'kind' => 'bank',
        'iban' => 'NL36ASNB0000000999',
        'default_currency' => 'EUR',
    ]);

    $runB = ImportRun::query()->create([
        'user_id' => $userB->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/userb.xml',
        'sha256' => str_repeat('9', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $userB->id,
        'import_run_id' => $runB->id,
        'account_id' => $accountB->id,
        'iban_owner' => $accountB->iban,
        'opening_balance_minor' => 88888,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new Camt053StartingBalanceDetector($db);

    expect($detector->detect([$runB->id], $userA))->toBe([]);
})->group('phase-16.1.1');

it('ignores import-runs of a different source format (mt940 rows are skipped)', function (): void {
    $user = User::query()->create([
        'username' => 'camt-mixed',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Mixed-source account',
        'slug' => 'mixed-source',
        'kind' => 'bank',
        'iban' => 'NL48ASNB0000000201',
        'default_currency' => 'EUR',
    ]);

    $mt940Run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'mt940',
        'raw_file_path' => '/tmp/mt940.sta',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $mt940Run->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 77777,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new Camt053StartingBalanceDetector($db);

    expect($detector->detect([$mt940Run->id], $user))->toBe([]);
})->group('phase-16.1.1');
