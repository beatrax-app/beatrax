<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportGranularity;

// 'base' mode converted and rounded every grouped row on its own, so the same
// report totalled 8942.01 by category and 8942.04 by counterparty. The drift is
// half a minor unit per group, so it grows with the number of groups — which is
// exactly what changing the dimension changes.

beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

function bmtUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'bmt-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

// exchange_rates is EUR-based and RateTable crosses through EUR, so the pair is
// written the way a provider publishes it, never as its inverse.
function bmtRate(string $rate): void
{
    app(DatabaseManager::class)->connection()->table('exchange_rates')->updateOrInsert(
        ['base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate_date' => '2026-08-01'],
        ['rate' => $rate, 'source' => 'bmt-fixture', 'created_at' => now(), 'updated_at' => now()],
    );
}

/**
 * @param  list<int>  $amountsMinor  one transaction each, each in its own category and counterparty
 */
function bmtSeedUsd(User $user, array $amountsMinor): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'bmt-'.$user->id],
        ['name' => 'bmt account', 'kind' => 'bank', 'iban' => 'NL00BMT'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'USD'],
    );

    foreach ($amountsMinor as $index => $amountMinor) {
        $suffix = bin2hex(random_bytes(8));

        /** @var Category $category */
        $category = Category::query()->create([
            'user_id' => null,
            'name' => 'BMT '.$index,
            'slug' => 'bmt-'.$index.'-'.$suffix,
            'kind' => 'expense',
            'display_order' => $index,
        ]);

        $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
            'user_id' => $user->id,
            'type' => 'merchant',
            'slug' => 'bmt-merchant-'.$index.'-'.$suffix,
            'display_name' => 'BMT Merchant '.$index,
            'merchant_name' => 'BMT MERCHANT '.$index,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = $db->connection()->table('import_runs')->insertGetId([
            'user_id' => $user->id,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/bmt-'.$suffix.'.csv',
            'sha256' => hash('sha256', 'bmt-'.$suffix),
            'uploaded_at' => now(),
            'status' => 'committed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // One transaction per calendar month, so the time-bucket dimension has as
        // many groups as the other three.
        $postedAt = '2026-0'.($index + 1).'-05';

        $db->connection()->table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_run_id' => $runId,
            'category_id' => $category->id,
            'counterparty_id' => $counterpartyId,
            'type' => 'expense',
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 10:00:00',
            'value_date' => $postedAt,
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'USD',
            'counterparty_name' => 'BMT Merchant '.$index,
            'counterparty_normalized' => 'bmt-merchant-'.$index,
            'normalization_version' => 1,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'fingerprint' => hash('sha256', 'bmt-tx-'.$suffix),
            'fingerprint_version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function bmtDefinition(string $dimension): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: $dimension,
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-01-01',
        customTo: '2026-06-30',
    );
}

it('cross_dimension_total_consistency holds after conversion, not only before it', function (): void {
    $user = bmtUser();
    bmtRate('1.09290000');
    // Converted one by one these land two minor units away from the same six
    // converted as one subtotal, which is the drift the dimension changes.
    bmtSeedUsd($user, [-32_895, -42_046, -5_629, -67_589, -8_686, -57_037]);

    $aggregator = app(ReportAggregator::class);
    $totals = array_map(
        static fn (string $dimension): int => $aggregator->run($user, bmtDefinition($dimension))->totalMinor,
        ['category', 'counterparty', 'account', 'time_bucket'],
    );

    expect(array_unique($totals))->toHaveCount(1);
});

it('totals what the currency subtotal converts to, not what the rounded rows add up to', function (): void {
    $user = bmtUser();
    bmtRate('1.09290000');
    bmtSeedUsd($user, [-32_895, -42_046, -5_629, -67_589, -8_686, -57_037]);

    // The whole USD subtotal converted once, which is what a currency total is.
    $expected = app(CrossCurrencyTotal::class)->of(['USD' => 213_882], 'EUR')->minor;

    expect(app(ReportAggregator::class)->run($user, bmtDefinition('category'))->totalMinor)->toBe($expected);
});

it('leaves the rows adding up to the total the footer prints', function (): void {
    $user = bmtUser();
    bmtRate('1.09290000');
    bmtSeedUsd($user, [-32_895, -42_046, -5_629, -67_589, -8_686, -57_037]);

    $result = app(ReportAggregator::class)->run($user, bmtDefinition('category'));
    $sumOfRows = array_sum(array_map(static fn (ReportResultRow $row): int => $row->amountMinor, $result->rows));

    expect($sumOfRows)->toBe($result->totalMinor);
});

it('spreads the remainder on the largest rows, deterministically', function (): void {
    $user = bmtUser();
    bmtRate('1.09290000');
    bmtSeedUsd($user, [-32_895, -42_046, -5_629, -67_589, -8_686, -57_037]);

    $aggregator = app(ReportAggregator::class);
    $first = $aggregator->run($user, bmtDefinition('category'))->rows;
    $second = $aggregator->run($user, bmtDefinition('category'))->rows;

    expect(array_map(static fn (ReportResultRow $row): int => $row->amountMinor, $first))
        ->toBe(array_map(static fn (ReportResultRow $row): int => $row->amountMinor, $second));
});
