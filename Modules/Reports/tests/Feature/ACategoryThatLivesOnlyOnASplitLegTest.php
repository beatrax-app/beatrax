<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\CategorySpendQuery;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Aggregation\SpendQueryFilters;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;

// Splitting a transaction is precisely how part of it is attributed to a
// category. CurrencyModeApplier::discoverCurrencies() looked for the category on
// transactions.category_id only, so a category living only on legs discovered no
// currency at all and the dimension query was never called: the row that the
// dimension query returns on its own became an empty report.

function cslUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'csl-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function cslCategory(string $name): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

/**
 * @param  array<int, int>  $legs  category id => settled minor
 */
function cslSplitExpense(User $user, int $totalMinor, array $legs): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'csl-'.$user->id],
        ['name' => 'csl account', 'kind' => 'bank', 'iban' => 'NL00CSL'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/csl-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'csl-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        // No category on the parent: the legs carry the whole attribution.
        'category_id' => null,
        'type' => 'expense',
        'posted_at' => '2026-08-05',
        'booked_at' => '2026-08-05 10:00:00',
        'value_date' => '2026-08-05',
        'amount_minor' => $totalMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $totalMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'CSL Vendor',
        'counterparty_normalized' => 'csl-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'csl-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sortOrder = 0;
    foreach ($legs as $categoryId => $legMinor) {
        $db->connection()->table('transaction_splits')->insert([
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'category_id' => $categoryId,
            'settled_amount_minor' => $legMinor,
            'settled_currency' => 'EUR',
            'sort_order' => $sortOrder++,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * @param  list<int>  $categories
 */
function cslDefinition(array $categories = []): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
        categories: $categories,
    );
}

it('answers with the leg total when the filtered category exists on no parent at all', function (): void {
    $user = cslUser();
    $groceries = cslCategory('Groceries');
    $household = cslCategory('Household');
    cslSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    $result = app(ReportAggregator::class)->run($user, cslDefinition([(int) $groceries->id]));

    expect($result->rows)->toHaveCount(1)
        ->and($result->totalMinor)->toBe(2_450)
        ->and($result->rows[0]->groupLabel)->toBe('Groceries');
});

// The dimension query always answered; only the currency discovery in front of
// it did not, so a passing dimension query proved nothing on its own.
it('agrees with the dimension query it wraps', function (): void {
    $user = cslUser();
    $groceries = cslCategory('Groceries');
    $household = cslCategory('Household');
    cslSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    $period = app(PeriodPresetResolver::class)->resolve('custom', '2026-08-01', '2026-08-31');
    $direct = app(CategorySpendQuery::class)->forUserAndPeriod(
        $user,
        $period,
        'spend',
        'EUR',
        new SpendQueryFilters(categoryIds: [(int) $groceries->id]),
    );

    $aggregated = app(ReportAggregator::class)->run($user, cslDefinition([(int) $groceries->id]));

    expect($aggregated->rows)->toHaveCount(count($direct))
        ->and($aggregated->totalMinor)->toBe($direct[0]->amountMinor);
});

it('still narrows to a category the parent itself carries', function (): void {
    $user = cslUser();
    $groceries = cslCategory('Groceries');
    $household = cslCategory('Household');
    cslSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    $result = app(ReportAggregator::class)->run($user, cslDefinition([(int) $household->id]));

    expect($result->totalMinor)->toBe(7_550);
});

it('reports nothing for a category no parent and no leg carries', function (): void {
    $user = cslUser();
    $groceries = cslCategory('Groceries');
    $household = cslCategory('Household');
    $travel = cslCategory('Travel');
    cslSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    $result = app(ReportAggregator::class)->run($user, cslDefinition([(int) $travel->id]));

    expect($result->rows)->toBe([])
        ->and($result->totalMinor)->toBe(0);
});

it('does not widen an unfiltered report', function (): void {
    $user = cslUser();
    $groceries = cslCategory('Groceries');
    $household = cslCategory('Household');
    cslSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    expect(app(ReportAggregator::class)->run($user, cslDefinition())->totalMinor)->toBe(10_000);
});
