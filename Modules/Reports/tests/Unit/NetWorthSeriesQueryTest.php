<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\NetWorthSeriesQuery;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

/*
 * Covers 999.6-05 Task 1 (Req 2/5/7): NetWorthSeriesQuery samples a
 * base-currency net-worth total once per TimeBucketGenerator bucket — a
 * time series (no group-by dimension) built by repeating NetWorthQuery's
 * exclude+count algorithm at each bucket's sample date via
 * AccountBalanceQuery::clearedBalanceAsOf() (Pattern 3). Fixture helpers
 * prefixed nws_ to avoid cross-file global-function collisions.
 */

function nwsUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'nws-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function nwsAccount(User $user, string $kind = 'asn', string $currency = 'EUR'): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $kind.' account',
        'slug' => 'nws-'.$kind.'-'.bin2hex(random_bytes(3)),
        'kind' => $kind,
        'iban' => 'NL00NWS'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
    ]);
}

function nwsImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nws-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'nws-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function nwsTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $amountMinor = $overrides['amount_minor'] ?? 10_000;
    $currency = $overrides['currency'] ?? $account->default_currency;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => nwsImportRun($db, $user),
        'type' => 'income',
        'posted_at' => '2025-07-10',
        'booked_at' => '2025-07-10 10:00:00',
        'value_date' => '2025-07-10',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => 'NWS Vendor',
        'counterparty_normalized' => 'nws-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'nws-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

it('renders one point per monthly bucket over a 12-month span — a time series, not a group-by', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nwsUser();
    $account = nwsAccount($user);

    // €500.00 posted in the first bucket month; €300.00 more posted in
    // November — the running cleared balance is point-in-time, so early
    // buckets must NOT see the November deposit.
    nwsTransaction($db, $user, $account, ['amount_minor' => 50_000, 'posted_at' => '2025-07-10', 'booked_at' => '2025-07-10 10:00:00', 'value_date' => '2025-07-10']);
    nwsTransaction($db, $user, $account, ['amount_minor' => 30_000, 'posted_at' => '2025-11-10', 'booked_at' => '2025-11-10 10:00:00', 'value_date' => '2025-11-10']);

    $period = new Period(
        start: CarbonImmutable::parse('2025-07-01'),
        endExclusive: CarbonImmutable::parse('2026-07-01'),
        label: 'FY',
    );

    $points = app(NetWorthSeriesQuery::class)->forUser($user, $period, ReportGranularity::Monthly);

    expect($points)->toHaveCount(12);

    // Time-series shape: strictly increasing sample dates, one per bucket —
    // never a group-by key.
    for ($i = 1; $i < count($points); $i++) {
        expect($points[$i]->date->greaterThan($points[$i - 1]->date))->toBeTrue();
    }

    // Jul 2025's sample date (end of month) only sees the July deposit.
    expect($points[0]->totalMinor)->toBe(50_000);
    // Nov 2025 (index 4) sees both deposits.
    expect($points[4]->totalMinor)->toBe(80_000);
    // Jun 2026 (last point) still reflects both deposits.
    expect($points[11]->totalMinor)->toBe(80_000);

    foreach ($points as $point) {
        expect($point->currency)->toBe('EUR');
        expect($point->excludedCount)->toBe(0);
    }
});

it('excludes paypal_funding accounts from every point, matching NetWorthQuery parity', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nwsUser();
    $account = nwsAccount($user, 'asn');
    $funding = nwsAccount($user, 'paypal_funding');

    nwsTransaction($db, $user, $account, ['amount_minor' => 20_000]);
    // Large balance on the excluded-kind account — must never contribute.
    nwsTransaction($db, $user, $funding, ['amount_minor' => 999_000]);

    $period = new Period(
        start: CarbonImmutable::parse('2025-07-01'),
        endExclusive: CarbonImmutable::parse('2025-08-01'),
        label: 'Jul 2025',
    );

    $points = app(NetWorthSeriesQuery::class)->forUser($user, $period, ReportGranularity::Monthly);

    expect($points)->toHaveCount(1);
    expect($points[0]->totalMinor)->toBe(20_000);
});
