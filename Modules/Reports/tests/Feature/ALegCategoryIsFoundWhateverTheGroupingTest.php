<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;

// A category filter was tested against transactions.category_id alone on three
// of the four dimensions, so one €100.00 split — €24.50 Groceries, €75.50
// Household — read €24.50 grouped by Category and €0.00 grouped by Account,
// with no banner saying anything had been left out. Categorise the parent as
// well and the same three answered €100.00. The transaction contributes the
// leg's amount whichever chip the reader taps.

/**
 * @return list<string>
 */
function lcgDimensions(): array
{
    return ['category', 'counterparty', 'account', 'time_bucket'];
}

function lcgUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'lcg-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function lcgCategory(string $name): Category
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
function lcgSplitExpense(User $user, int $totalMinor, array $legs, ?int $parentCategoryId = null): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'lcg-'.$user->id],
        ['name' => 'lcg account', 'kind' => 'bank', 'iban' => 'NL00LCG'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/lcg-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'lcg-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'lcg-merchant-'.$suffix,
        'display_name' => 'LCG Merchant',
        'merchant_name' => 'LCG MERCHANT',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'counterparty_id' => $counterpartyId,
        'category_id' => $parentCategoryId,
        'type' => 'expense',
        'posted_at' => '2026-08-05',
        'booked_at' => '2026-08-05 10:00:00',
        'value_date' => '2026-08-05',
        'amount_minor' => $totalMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $totalMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'LCG Merchant',
        'counterparty_normalized' => 'lcg-merchant',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'lcg-tx-'.$suffix),
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
function lcgDefinition(string $dimension, array $categories = []): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: $dimension,
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
        categories: $categories,
    );
}

it('answers with the leg the filter matched, whichever dimension is grouped by', function (string $dimension): void {
    $user = lcgUser();
    $groceries = lcgCategory('Groceries');
    $household = lcgCategory('Household');
    lcgSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    $result = app(ReportAggregator::class)->run($user, lcgDefinition($dimension, [(int) $groceries->id]));

    expect($result->totalMinor)->toBe(2_450)
        // Nothing was left out, so nothing is disclosed: a zero with no banner
        // is the shape this defect wore on three of the four chips.
        ->and($result->excludedCurrencies)->toBe([]);
})->with(lcgDimensions());

// The parent-categorised variant fails the other way: the filter matches the
// parent, and the whole €100.00 is counted for a €24.50 leg.
it('counts the leg and not the parent when the parent carries the filtered category too', function (string $dimension): void {
    $user = lcgUser();
    $groceries = lcgCategory('Groceries');
    $household = lcgCategory('Household');
    lcgSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550], (int) $groceries->id);

    expect(app(ReportAggregator::class)->run($user, lcgDefinition($dimension, [(int) $groceries->id]))->totalMinor)
        ->toBe(2_450);
})->with(lcgDimensions());

it('leaves an unfiltered report reading the whole transaction', function (string $dimension): void {
    $user = lcgUser();
    $groceries = lcgCategory('Groceries');
    $household = lcgCategory('Household');
    lcgSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    expect(app(ReportAggregator::class)->run($user, lcgDefinition($dimension))->totalMinor)->toBe(10_000);
})->with(lcgDimensions());

// A split whose legs do not sum to it is not an attribution at all, so the
// parent's own category is what a filter selects on — the same fall-back
// CategorySpendQuery's first pass makes.
it('falls back to the parent when the legs do not sum to it', function (string $dimension): void {
    $user = lcgUser();
    $groceries = lcgCategory('Groceries');
    $household = lcgCategory('Household');
    lcgSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -1_000], (int) $groceries->id);

    expect(app(ReportAggregator::class)->run($user, lcgDefinition($dimension, [(int) $groceries->id]))->totalMinor)
        ->toBe(10_000);
})->with(lcgDimensions());

it('reports nothing for a category neither the parent nor a leg carries', function (string $dimension): void {
    $user = lcgUser();
    $groceries = lcgCategory('Groceries');
    $household = lcgCategory('Household');
    $travel = lcgCategory('Travel');
    lcgSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    $result = app(ReportAggregator::class)->run($user, lcgDefinition($dimension, [(int) $travel->id]));

    expect($result->totalMinor)->toBe(0);
})->with(lcgDimensions());

it('agrees with the category dimension on every other dimension, filtered', function (): void {
    $user = lcgUser();
    $groceries = lcgCategory('Groceries');
    $household = lcgCategory('Household');
    lcgSplitExpense($user, -10_000, [(int) $groceries->id => -2_450, (int) $household->id => -7_550]);

    $aggregator = app(ReportAggregator::class);
    $totals = array_map(
        static fn (string $dimension): int => $aggregator->run($user, lcgDefinition($dimension, [(int) $groceries->id]))->totalMinor,
        lcgDimensions(),
    );

    expect(array_unique($totals))->toHaveCount(1);
});
