<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;

uses(RefreshDatabase::class);

// The dashboard converted each category's per-currency bucket on its own, so it
// rounded once per category: its rows drifted a cent away from its own "Out"
// tile and from the same category on /reports, which converts each currency's
// subtotal once and hands the remainder back.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-15 09:00:00');
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
    app(DatabaseManager::class)->connection()->table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => 'USD',
        'rate_date' => '2026-08-01',
        'rate' => '1.0851',
        'source' => 'ecb',
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

function tdrUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'tdr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function tdrSpend(User $user, Category $category, int $minor, string $currency): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'tdr-'.strtolower($currency).'-'.$user->id],
        ['name' => $currency.' account', 'kind' => 'bank', 'iban' => 'NL00TDR'.strtoupper(bin2hex(random_bytes(6))), 'default_currency' => $currency],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tdr-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tdr-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-08-10',
        'booked_at' => '2026-08-10 10:00:00',
        'value_date' => '2026-08-10',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'category_id' => $category->id,
        'counterparty_name' => 'TDR Vendor '.$category->name,
        'counterparty_normalized' => 'tdr-vendor-'.strtolower($category->name),
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'tdr-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tdrCategory(string $name): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => 'tdr-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

/**
 * @return array{dashboard: array<string, int>, report: array<string, int>, out: int}
 */
function tdrFigures(User $user): array
{
    $period = app(PeriodQuery::class)->current();

    $dashboard = [];
    foreach (app(TopCategoriesByPeriodQuery::class)->for($user, $period, 'EUR', limit: 20)->rows as $row) {
        /** @var TopCategoryRow $row */
        $dashboard[$row->name] = $row->spend->toMinor();
    }

    $report = [];
    foreach (app(ReportAggregator::class)->run($user, new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
    ))->rows as $row) {
        $report[$row->groupLabel] = $row->amountMinor;
    }

    // Sorted by name: the dashboard orders by spend and the report by the
    // aggregator's own grouping, and the question here is the figures.
    ksort($dashboard);
    ksort($report);

    return [
        'dashboard' => $dashboard,
        'report' => $report,
        'out' => app(ThisPeriodAtAGlanceQuery::class)->for($user, $period, 'EUR')->outflow->toMinor(),
    ];
}

it('gives the same category the same figure on the dashboard and on /reports', function (): void {
    $user = tdrUser();
    test()->actingAs($user);

    foreach (['Groceries', 'Transport', 'Utilities', 'Leisure', 'Health'] as $name) {
        tdrSpend($user, tdrCategory($name), -1_000, 'USD');
    }

    $figures = tdrFigures($user);

    expect($figures['dashboard'])->not->toBe([])
        ->and($figures['dashboard'])->toBe($figures['report']);
});

it('adds its own rows up to its own Out tile', function (): void {
    $user = tdrUser();
    test()->actingAs($user);

    foreach (['Groceries', 'Transport', 'Utilities', 'Leisure', 'Health'] as $name) {
        tdrSpend($user, tdrCategory($name), -1_000, 'USD');
    }

    $figures = tdrFigures($user);

    expect(array_sum($figures['dashboard']))->toBe($figures['out']);
});
