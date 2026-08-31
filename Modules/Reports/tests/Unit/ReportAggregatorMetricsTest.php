<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;

uses(RefreshDatabase::class);

// Two dimensions disagreeing on the same (metric, period, currency) total would
// mean two different "spend" definitions exist; the canonical one is
// ThisPeriodAtAGlanceQuery's.

function ramtUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'ramt-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function ramtAccount(User $user, string $name = 'ASN'): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00RAMT'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function ramtCategory(string $name = 'Groceries'): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function ramtImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ramt-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'ramt-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ramtTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    // amount_minor tracks settled_amount_minor so two rows differing only in
    // settled amount cannot collide on the composite fingerprint UNIQUE.
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => ramtImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'RAMT Vendor',
        'counterparty_normalized' => 'ramt-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'ramt-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

function ramtDefinition(string $metric, string $dimension, ?string $amountMin = null, ?string $amountMax = null): ReportDefinition
{
    return new ReportDefinition(
        metric: $metric,
        dimension: $dimension,
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-03-01',
        customTo: '2026-03-31',
        amountMin: $amountMin,
        amountMax: $amountMax,
    );
}

function ramtSplit(DatabaseManager $db, User $user, int $transactionId, Category $category, int $minor): void
{
    $db->connection()->table('transaction_splits')->insert([
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'category_id' => $category->id,
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('sums spend/income/net using the canonical type-based definition, excluding transfers', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = ramtUser();
    $account = ramtAccount($user);
    $groceries = ramtCategory();

    ramtTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -12_000, 'category_id' => $groceries->id]);
    ramtTransaction($db, $user, $account, ['type' => 'income', 'settled_amount_minor' => 50_000]);
    // Internal move between own accounts — must contribute 0.
    ramtTransaction($db, $user, $account, ['type' => 'transfer_out', 'settled_amount_minor' => -20_000]);

    $spend = app(ReportAggregator::class)->run($user, ramtDefinition('spend', 'category'));
    expect($spend->totalMinor)->toBe(12_000);
    expect($spend->currency)->toBe('EUR');

    $income = app(ReportAggregator::class)->run($user, ramtDefinition('income', 'category'));
    expect($income->totalMinor)->toBe(50_000);

    $net = app(ReportAggregator::class)->run($user, ramtDefinition('net', 'category'));
    expect($net->totalMinor)->toBe(38_000);
});

it('cross_dimension_total_consistency: same spend total across category/counterparty/account/time_bucket for the same metric+period+currency', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = ramtUser();
    $accountA = ramtAccount($user, 'ASN');
    $accountB = ramtAccount($user, 'ICS');
    $groceries = ramtCategory('Groceries');
    $fuel = ramtCategory('Fuel');

    $vendorOne = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id, 'type' => 'merchant', 'slug' => 'ramt-vendor-one-'.bin2hex(random_bytes(3)),
        'display_name' => 'Vendor One', 'merchant_name' => 'VENDOR ONE',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $vendorTwo = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id, 'type' => 'merchant', 'slug' => 'ramt-vendor-two-'.bin2hex(random_bytes(3)),
        'display_name' => 'Vendor Two', 'merchant_name' => 'VENDOR TWO',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    ramtTransaction($db, $user, $accountA, [
        'type' => 'expense', 'settled_amount_minor' => -15_000,
        'category_id' => $groceries->id, 'counterparty_id' => $vendorOne,
        'posted_at' => '2026-03-05', 'booked_at' => '2026-03-05 09:00:00', 'value_date' => '2026-03-05',
    ]);
    ramtTransaction($db, $user, $accountB, [
        'type' => 'expense', 'settled_amount_minor' => -9_000,
        'category_id' => $fuel->id, 'counterparty_id' => $vendorTwo,
        'posted_at' => '2026-03-20', 'booked_at' => '2026-03-20 09:00:00', 'value_date' => '2026-03-20',
    ]);

    $aggregator = app(ReportAggregator::class);
    $byCategory = $aggregator->run($user, ramtDefinition('spend', 'category'));
    $byCounterparty = $aggregator->run($user, ramtDefinition('spend', 'counterparty'));
    $byAccount = $aggregator->run($user, ramtDefinition('spend', 'account'));
    $byTimeBucket = $aggregator->run($user, ramtDefinition('spend', 'time_bucket'));

    expect($byCategory->totalMinor)->toBe(24_000);
    expect($byCounterparty->totalMinor)->toBe($byCategory->totalMinor);
    expect($byAccount->totalMinor)->toBe($byCategory->totalMinor);
    expect($byTimeBucket->totalMinor)->toBe($byCategory->totalMinor);
});

// The same invariant through the whole aggregator. Every case above ran the
// DEFAULT empty filter set, so the amount bound -- written against the leg by
// the split-aware category pass and against the transaction by the other three
// dimensions -- never met a split at all.
it('cross_dimension_total_consistency: holds through the aggregator when an amount filter is on', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = ramtUser();
    $account = ramtAccount($user);
    $electronics = ramtCategory('Electronics');
    $accessories = ramtCategory('Accessories');

    $splitParent = ramtTransaction($db, $user, $account, [
        'type' => 'expense', 'settled_amount_minor' => -12_450, 'category_id' => null,
        'posted_at' => '2026-03-11', 'booked_at' => '2026-03-11 09:00:00', 'value_date' => '2026-03-11',
    ]);
    ramtSplit($db, $user, $splitParent, $electronics, -10_000);
    ramtSplit($db, $user, $splitParent, $accessories, -2_450);

    ramtTransaction($db, $user, $account, [
        'type' => 'expense', 'settled_amount_minor' => -6_000, 'category_id' => $electronics->id,
        'posted_at' => '2026-03-14', 'booked_at' => '2026-03-14 09:00:00', 'value_date' => '2026-03-14',
    ]);

    $aggregator = app(ReportAggregator::class);

    foreach ([['50.00', null], [null, '50.00']] as [$min, $max]) {
        $byCategory = $aggregator->run($user, ramtDefinition('spend', 'category', $min, $max));

        foreach (['counterparty', 'account', 'time_bucket'] as $dimension) {
            $other = $aggregator->run($user, ramtDefinition('spend', $dimension, $min, $max));

            expect($other->totalMinor)->toBe(
                $byCategory->totalMinor,
                "category vs {$dimension} diverged for amount_min={$min} amount_max={$max}",
            );
        }
    }
});
