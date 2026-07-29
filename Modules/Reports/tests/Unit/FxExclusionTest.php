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
 * Covers 999.6-05 Task 2 (Req 5, T-999.6-12): pins the never-1:1 FX
 * contract for NetWorthSeriesQuery's base-mode totals — a DIFFERENT code
 * path than Modules/Reports/tests/Unit/CurrencyModeExclusionTest.php's
 * fx_exclusion_never_1to1_transactions test (that one exercises
 * ReportAggregator/CurrencyModeApplier over transaction-level
 * settled_currency rows; this one exercises NetWorthSeriesQuery's
 * account-level balances). Fixture helpers prefixed fxe_ to avoid
 * cross-file global-function collisions.
 */

function fxeUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'fxe-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function fxeAccount(User $user, string $currency): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $currency.' account',
        'slug' => 'fxe-'.strtolower($currency).'-'.bin2hex(random_bytes(3)),
        'kind' => 'asn',
        'iban' => 'NL00FXE'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
    ]);
}

function fxeImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fxe-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'fxe-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fxeTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $amountMinor = $overrides['amount_minor'] ?? 10_000;
    $currency = $overrides['currency'] ?? $account->default_currency;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => fxeImportRun($db, $user),
        'type' => 'income',
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 10:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => 'FXE Vendor',
        'counterparty_normalized' => 'fxe-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'fxe-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

it('fx_exclusion_never_1to1: an unconvertible account is excluded and counted, never converted at 1:1', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = fxeUser();

    $eurAccount = fxeAccount($user, 'EUR');
    $jpyAccount = fxeAccount($user, 'JPY'); // no exchange_rates row seeded for JPY -> EUR at any date

    fxeTransaction($db, $user, $eurAccount, ['amount_minor' => 20_000]);
    fxeTransaction($db, $user, $jpyAccount, ['amount_minor' => 500_000]);

    // Single-bucket period so exactly one sample point is produced.
    $period = new Period(
        start: CarbonImmutable::parse('2026-04-01'),
        endExclusive: CarbonImmutable::parse('2026-05-01'),
        label: 'Apr 2026',
    );

    $points = app(NetWorthSeriesQuery::class)->forUser($user, $period, ReportGranularity::Monthly);

    expect($points)->toHaveCount(1);
    $point = $points[0];

    // Only the EUR account's contribution is counted.
    expect($point->totalMinor)->toBe(20_000);
    expect($point->excludedCount)->toBe(1);

    // Never a silent 1:1 fallback: a 1:1 leak would have added the raw JPY
    // minor amount straight into the EUR total (20_000 + 500_000).
    $wouldBeOneToOneTotal = 20_000 + 500_000;
    expect($point->totalMinor)->not->toBe($wouldBeOneToOneTotal);
});
