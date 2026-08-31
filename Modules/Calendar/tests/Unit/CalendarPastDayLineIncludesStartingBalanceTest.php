<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\DailyBalanceAggregator;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function cpsbUser(string $suffix): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'cpsb-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cpsbAccount(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId(array_merge([
        'user_id' => $userId,
        'name' => 'CPSB ASN',
        'slug' => 'cpsb-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00CPSB'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ], $overrides));
}

function cpsbTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $amountMinor): void
{
    static $cpsbRow = 0;
    $cpsbRow++;

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cpsb-'.$cpsbRow.'.csv',
        'sha256' => hash('sha256', 'cpsb-run-'.$cpsbRow.'-'.bin2hex(random_bytes(4))),
        'uploaded_at' => $postedAt.' 00:00:00',
        'status' => 'imported',
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => $amountMinor >= 0 ? 'income' : 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'CPSB Merchant '.$cpsbRow,
        'counterparty_normalized' => 'cpsb merchant '.$cpsbRow,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => $cpsbRow,
        'fingerprint' => hash('sha256', 'cpsb-tx-'.$cpsbRow.'-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);
}

/**
 * @param  list<int>  $accountIds
 * @return array<string, array{0: int, 1: bool}>
 */
function cpsbJuneMap(User $user, array $accountIds): array
{
    /** @var DailyBalanceAggregator $aggregator */
    $aggregator = app(DailyBalanceAggregator::class);

    return $aggregator->buildBalanceMap(
        $accountIds,
        $user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    )['map'];
}

it('opens the past-day line on a dateless baseline instead of on zero', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cpsbUser('dateless');
    $accountId = cpsbAccount($db, $user->id, ['starting_balance_minor' => 285_000]);

    cpsbTransaction($db, $user->id, $accountId, '2026-05-20', 50_000);
    cpsbTransaction($db, $user->id, $accountId, '2026-06-10', -1_000);

    $map = cpsbJuneMap($user, [$accountId]);

    expect($map['2026-06-01']->minor)->toBe(335_000);
    expect($map['2026-06-10']->minor)->toBe(334_000);
    expect($map['2026-06-11']->minor)->toBe(334_000);
});

it('holds a dated baseline flat over rows posted before it and steps on the row posted on it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cpsbUser('dated');
    $accountId = cpsbAccount($db, $user->id, [
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-06-05',
    ]);

    cpsbTransaction($db, $user->id, $accountId, '2026-06-01', -5_000);
    cpsbTransaction($db, $user->id, $accountId, '2026-06-05', -1_000);
    cpsbTransaction($db, $user->id, $accountId, '2026-06-10', -2_000);

    $map = cpsbJuneMap($user, [$accountId]);

    expect($map['2026-06-01']->minor)->toBe(100_000);
    expect($map['2026-06-05']->minor)->toBe(99_000);
    expect($map['2026-06-11']->minor)->toBe(97_000);
});

it('leaves an account carrying no baseline on the bare cumulative sum', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cpsbUser('none');
    $accountId = cpsbAccount($db, $user->id);

    cpsbTransaction($db, $user->id, $accountId, '2026-05-20', 50_000);
    cpsbTransaction($db, $user->id, $accountId, '2026-06-10', -1_000);

    $map = cpsbJuneMap($user, [$accountId]);

    expect($map['2026-06-01']->minor)->toBe(50_000);
    expect($map['2026-06-11']->minor)->toBe(49_000);
});
