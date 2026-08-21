<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\AccountSpendQuery;
use Modules\Reports\Internal\Aggregation\CategorySpendQuery;
use Modules\Reports\Internal\Aggregation\CounterpartySpendQuery;
use Modules\Reports\Internal\Aggregation\TimeBucketSpendQuery;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

function xdcUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'xdc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function xdcAccount(User $user, string $name): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => 'xdc-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00XDC'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function xdcCategory(string $name): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => strtolower($name).'-xdc-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function xdcCounterparty(DatabaseManager $db, User $user, string $name): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'xdc-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'display_name' => $name,
        'merchant_name' => strtoupper($name),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function xdcImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/xdc-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'xdc-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function xdcTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => xdcImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'XDC Vendor',
        'counterparty_normalized' => 'xdc-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'xdc-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

it('produces the identical spend/income/net grand total across category/counterparty/account/time_bucket for the same metric+period+currency', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = xdcUser();
    $accountA = xdcAccount($user, 'ASN');
    $accountB = xdcAccount($user, 'ICS');
    $groceries = xdcCategory('Groceries');
    $fuel = xdcCategory('Fuel');
    $vendorOne = xdcCounterparty($db, $user, 'Vendor One');
    $vendorTwo = xdcCounterparty($db, $user, 'Vendor Two');

    xdcTransaction($db, $user, $accountA, [
        'type' => 'expense', 'settled_amount_minor' => -15_000,
        'category_id' => $groceries->id, 'counterparty_id' => $vendorOne,
        'posted_at' => '2026-03-05', 'booked_at' => '2026-03-05 09:00:00', 'value_date' => '2026-03-05',
    ]);
    xdcTransaction($db, $user, $accountB, [
        'type' => 'expense', 'settled_amount_minor' => -9_000,
        'category_id' => $fuel->id, 'counterparty_id' => $vendorTwo,
        'posted_at' => '2026-03-20', 'booked_at' => '2026-03-20 09:00:00', 'value_date' => '2026-03-20',
    ]);
    xdcTransaction($db, $user, $accountA, [
        'type' => 'income', 'settled_amount_minor' => 80_000,
        'category_id' => null, 'counterparty_id' => null,
        'posted_at' => '2026-03-08', 'booked_at' => '2026-03-08 09:00:00', 'value_date' => '2026-03-08',
    ]);
    // Internal move between own accounts — must contribute 0 everywhere.
    xdcTransaction($db, $user, $accountA, [
        'type' => 'transfer_out', 'settled_amount_minor' => -20_000,
        'category_id' => $groceries->id, 'counterparty_id' => $vendorOne,
        'posted_at' => '2026-03-12', 'booked_at' => '2026-03-12 09:00:00', 'value_date' => '2026-03-12',
    ]);

    $period = new Period(
        start: CarbonImmutable::parse('2026-03-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'March 2026',
    );

    $categoryQuery = app(CategorySpendQuery::class);
    $counterpartyQuery = app(CounterpartySpendQuery::class);
    $accountQuery = app(AccountSpendQuery::class);
    $timeBucketQuery = app(TimeBucketSpendQuery::class);

    foreach (['spend', 'income', 'net'] as $metric) {
        $categoryTotal = array_sum(array_map(fn ($r) => $r->amountMinor, $categoryQuery->forUserAndPeriod($user, $period, $metric, 'EUR')));
        $counterpartyTotal = array_sum(array_map(fn ($r) => $r->amountMinor, $counterpartyQuery->forUserAndPeriod($user, $period, $metric, 'EUR')));
        $accountTotal = array_sum(array_map(fn ($r) => $r->amountMinor, $accountQuery->forUserAndPeriod($user, $period, $metric, 'EUR')));
        $timeBucketTotal = array_sum(array_map(fn ($r) => $r->amountMinor, $timeBucketQuery->forUserAndPeriod($user, $period, $metric, 'EUR', ReportGranularity::Monthly)));

        expect($counterpartyTotal)->toBe($categoryTotal, "counterparty vs category mismatch for metric={$metric}");
        expect($accountTotal)->toBe($categoryTotal, "account vs category mismatch for metric={$metric}");
        expect($timeBucketTotal)->toBe($categoryTotal, "time_bucket vs category mismatch for metric={$metric}");
    }

    $spendTotal = array_sum(array_map(fn ($r) => $r->amountMinor, $categoryQuery->forUserAndPeriod($user, $period, 'spend', 'EUR')));
    $incomeTotal = array_sum(array_map(fn ($r) => $r->amountMinor, $categoryQuery->forUserAndPeriod($user, $period, 'income', 'EUR')));
    $netTotal = array_sum(array_map(fn ($r) => $r->amountMinor, $categoryQuery->forUserAndPeriod($user, $period, 'net', 'EUR')));

    expect($spendTotal)->toBe(24_000);
    expect($incomeTotal)->toBe(80_000);
    expect($netTotal)->toBe(56_000);
});

it('captures a genuinely-unsplit $0.00 transaction in CategorySpendQuery, matching the other three dimensions coverage', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = xdcUser();
    $account = xdcAccount($user, 'ASN');
    $auditCategory = xdcCategory('Audit Adjustment');

    // No split rows and settled_amount_minor = 0: COALESCE(NULL, 0) <> 0 is
    // FALSE, so the old amount-comparison-only WHERE dropped this row from
    // CategorySpendQuery while the other three dimensions still showed it.
    xdcTransaction($db, $user, $account, [
        'type' => 'expense', 'settled_amount_minor' => 0, 'amount_minor' => 0,
        'category_id' => $auditCategory->id,
        'posted_at' => '2026-03-15', 'booked_at' => '2026-03-15 09:00:00', 'value_date' => '2026-03-15',
    ]);

    $period = new Period(
        start: CarbonImmutable::parse('2026-03-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'March 2026',
    );

    $categoryRows = app(CategorySpendQuery::class)->forUserAndPeriod($user, $period, 'spend', 'EUR');
    $accountRows = app(AccountSpendQuery::class)->forUserAndPeriod($user, $period, 'spend', 'EUR');
    $counterpartyRows = app(CounterpartySpendQuery::class)->forUserAndPeriod($user, $period, 'spend', 'EUR');
    $timeBucketRows = app(TimeBucketSpendQuery::class)->forUserAndPeriod($user, $period, 'spend', 'EUR', ReportGranularity::Monthly);

    expect($categoryRows)->toHaveCount(1);
    expect($categoryRows[0]->groupLabel)->toBe('Audit Adjustment');
    expect($categoryRows[0]->amountMinor)->toBe(0);

    // The category dimension is no longer a strict subset of the other three.
    expect($accountRows)->toHaveCount(1);
    expect($counterpartyRows)->toHaveCount(1);
    expect($timeBucketRows)->toHaveCount(1);
});
