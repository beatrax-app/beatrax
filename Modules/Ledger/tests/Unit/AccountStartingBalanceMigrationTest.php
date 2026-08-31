<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Ledger\Internal\Services\BackfillStartingBalanceFromStatementSummaries;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;
use Modules\Ledger\Public\Enums\ImportRunStatus;

uses(RefreshDatabase::class);

it('adds starting_balance_minor and starting_balance_date nullable columns to accounts', function (): void {
    expect(Schema::hasColumn('accounts', 'starting_balance_minor'))->toBeTrue();
    expect(Schema::hasColumn('accounts', 'starting_balance_date'))->toBeTrue();

    $user = User::query()->create([
        'username' => 'starting-balance-schema',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Schema check account',
        'slug' => 'schema-check',
        'kind' => 'bank',
        'iban' => 'NL16ASNB0000000001',
        'default_currency' => 'EUR',
    ]);

    expect($account->starting_balance_minor)->toBeNull();
    expect($account->starting_balance_date)->toBeNull();
})->group('phase-16.1.1');

it('backfills starting_balance_minor from the earliest statement_summaries.opening_balance per account', function (): void {
    $user = User::query()->create([
        'username' => 'starting-balance-backfill',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $accountA = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Backfill account A',
        'slug' => 'backfill-a',
        'kind' => 'bank',
        'iban' => 'NL64ASNB0000000010',
        'default_currency' => 'EUR',
    ]);

    $accountB = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Backfill account B',
        'slug' => 'backfill-b',
        'kind' => 'bank',
        'iban' => 'NL85ASNB0000000020',
        'default_currency' => 'EUR',
    ]);

    $runA1 = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/a1.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-03-01 12:00:00'),
        'status' => ImportRunStatus::Confirmed->value,
    ]);

    $runA2 = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/a2.xml',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => ImportRunStatus::Confirmed->value,
    ]);

    $runB = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'mt940',
        'raw_file_path' => '/tmp/b.940',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => ImportRunStatus::Confirmed->value,
    ]);

    // Two summaries: the earlier opening_balance_date wins.
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
        'opening_balance_minor' => -50000,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-03-20 00:00:00'),
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $service = new BackfillStartingBalanceFromStatementSummaries($db);
    $service->run();

    $accountA->refresh();
    $accountB->refresh();

    expect($accountA->starting_balance_minor)->toBe(12345);
    expect($accountA->starting_balance_date?->toDateString())->toBe('2026-02-01');

    expect($accountB->starting_balance_minor)->toBe(-50000);
    expect($accountB->starting_balance_date?->toDateString())->toBe('2026-03-20');
})->group('phase-16.1.1');

it('is idempotent — re-running the backfill does not overwrite a non-null starting_balance', function (): void {
    $user = User::query()->create([
        'username' => 'starting-balance-idempotent',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Idempotent account',
        'slug' => 'idempotent',
        'kind' => 'bank',
        'iban' => 'NL09ASNB0000000030',
        'default_currency' => 'EUR',
        'starting_balance_minor' => 77777,
        'starting_balance_date' => CarbonImmutable::parse('2025-12-31 00:00:00'),
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/idem.xml',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => ImportRunStatus::Confirmed->value,
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $run->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'opening_balance_minor' => 11111,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $service = new BackfillStartingBalanceFromStatementSummaries($db);
    $service->run();
    $service->run();

    $account->refresh();

    expect($account->starting_balance_minor)->toBe(77777);
    expect($account->starting_balance_date?->toDateString())->toBe('2025-12-31');
})->group('phase-16.1.1');
