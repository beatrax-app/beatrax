<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;

// The report asked whether a split's legs summed to its parent by adding their
// minor units whatever currency each leg was in. A EUR 50.00 charge carrying
// one USD leg of 5000 therefore read as balanced: the euro pass dropped the
// parent and the dollar pass counted the leg, so the charge changed both its
// category and the money it was denominated in on the way to the table.

function alienRptUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'alien-rpt-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function alienRptCategory(string $name): Category
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
 * @param  array<int, array{0: int, 1: string}>  $legs  category id => [settled minor, currency]
 */
function alienRptSplitExpense(User $user, int $totalMinor, int $parentCategoryId, array $legs): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'alien-rpt-'.$user->id],
        ['name' => 'alien rpt account', 'kind' => 'bank', 'iban' => 'NL00ARP'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/alien-rpt-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'alien-rpt-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transactionId = (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'category_id' => $parentCategoryId,
        'type' => 'expense',
        'posted_at' => '2026-08-05',
        'booked_at' => '2026-08-05 10:00:00',
        'value_date' => '2026-08-05',
        'amount_minor' => $totalMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $totalMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Alien Rpt Vendor',
        'counterparty_normalized' => 'alien-rpt-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'alien-rpt-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Straight to the table: no local write path produces a leg priced away
    // from its parent, and the one that can is a peer's merge of the column.
    $sortOrder = 0;
    foreach ($legs as $categoryId => [$legMinor, $legCurrency]) {
        $db->connection()->table('transaction_splits')->insert([
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'category_id' => $categoryId,
            'settled_amount_minor' => $legMinor,
            'settled_currency' => $legCurrency,
            'sort_order' => $sortOrder++,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $transactionId;
}

/**
 * @param  list<int>  $categories
 */
function alienRptDefinition(array $categories = []): ReportDefinition
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

it('counts the parent under its own category when the only leg is priced elsewhere', function (): void {
    $user = alienRptUser();
    $rent = alienRptCategory('Rent');
    $travel = alienRptCategory('Travel');
    alienRptSplitExpense($user, -5_000, (int) $rent->id, [(int) $travel->id => [-5_000, 'USD']]);

    $result = app(ReportAggregator::class)->run($user, alienRptDefinition());

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->groupLabel)->toBe('Rent')
        ->and($result->rows[0]->currency)->toBe('EUR')
        ->and($result->totalMinor)->toBe(5_000);
});

it('finds that charge under a filter on the category the parent carries', function (): void {
    $user = alienRptUser();
    $rent = alienRptCategory('Rent');
    $travel = alienRptCategory('Travel');
    alienRptSplitExpense($user, -5_000, (int) $rent->id, [(int) $travel->id => [-5_000, 'USD']]);

    expect(app(ReportAggregator::class)->run($user, alienRptDefinition([(int) $rent->id]))->totalMinor)->toBe(5_000)
        ->and(app(ReportAggregator::class)->run($user, alienRptDefinition([(int) $travel->id]))->rows)->toBe([]);
});

// The control: a split whose legs share the parent's currency still reports
// through its legs, so the currency clause narrowed only what it had to.
it('still reports a same-currency split through its legs', function (): void {
    $user = alienRptUser();
    $rent = alienRptCategory('Rent');
    $travel = alienRptCategory('Travel');
    alienRptSplitExpense($user, -8_000, (int) $rent->id, [
        (int) $travel->id => [-3_000, 'EUR'],
        (int) $rent->id => [-5_000, 'EUR'],
    ]);

    $result = app(ReportAggregator::class)->run($user, alienRptDefinition());

    expect($result->totalMinor)->toBe(8_000)
        ->and(app(ReportAggregator::class)->run($user, alienRptDefinition([(int) $travel->id]))->totalMinor)->toBe(3_000);
});
