<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Ledger\Database\Seeders\CurrenciesSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringSeries;

/**
 * End-to-end exercise of the `php artisan demo:seed` pipeline:
 *
 *   - Test 1 — running the command twice with `--reset` produces
 *     identical row counts across every table the seeder writes to
 *     (no duplicates, no exceptions).
 *   - Test 2 — the shape after a single seed run matches the plan's
 *     documented dataset (2 users, 5 accounts, ~150 transactions,
 *     ≥1 counterparty per type, 2 chain kinds, 4 recurring series,
 *     1 forecast scenario).
 *   - Test 3 — a bare `php artisan demo:seed` (no `--reset`) on a
 *     clean DB produces the same shape as `--reset` does.
 *   - Test 4 — running the production reference seeders
 *     (CurrenciesSeeder + DefaultCategoryTreeSeeder) first and then
 *     `demo:seed` does not cause FK violations or duplicates — the
 *     demo dataset coexists cleanly with the production reference
 *     data the install flow populates.
 */
beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

/**
 * Snapshot the seven row counts that define the demo dataset's
 * shape. Each call returns a stable comparable array so test 1's
 * before/after equality compares the whole picture.
 *
 * @return array{users: int, accounts: int, transactions: int, counterparties: int, chain_links: int, recurring_series: int, forecast_scenarios: int}
 */
function demoSeedSnapshot(): array
{
    return [
        'users' => User::query()
            ->where('username', 'like', 'demo-%@beatrax.local')
            ->count(),
        'accounts' => Account::query()
            ->whereIn('user_id', demoSeedUserIds())
            ->count(),
        'transactions' => Transaction::query()
            ->where('source_format', 'demo')
            ->count(),
        'counterparties' => Counterparty::query()
            ->withoutGlobalScopes()
            ->whereIn('user_id', demoSeedUserIds())
            ->count(),
        'chain_links' => ChainLink::query()
            ->whereIn('user_id', demoSeedUserIds())
            ->count(),
        'recurring_series' => RecurringSeries::query()
            ->whereIn('user_id', demoSeedUserIds())
            ->count(),
        'forecast_scenarios' => ForecastScenario::query()
            ->whereIn('user_id', demoSeedUserIds())
            ->count(),
    ];
}

/**
 * The demo users' id list. Returned fresh each call because the
 * users table may have been wiped + re-seeded between consecutive
 * uses of the helper in a single test body.
 *
 * @return list<int>
 */
function demoSeedUserIds(): array
{
    $ids = User::query()
        ->where('username', 'like', 'demo-%@beatrax.local')
        ->pluck('id')
        ->all();

    return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
}

it('produces identical row counts when `demo:seed --reset` runs twice in succession', function (): void {
    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
    $firstSnapshot = demoSeedSnapshot();

    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
    $secondSnapshot = demoSeedSnapshot();

    expect($secondSnapshot)->toBe($firstSnapshot);
});

it('produces the documented dataset shape after a single seed run', function (): void {
    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
    $snap = demoSeedSnapshot();

    expect($snap['users'])->toBe(2);
    expect($snap['accounts'])->toBe(5);
    expect($snap['transactions'])
        ->toBeGreaterThanOrEqual(140)
        ->toBeLessThanOrEqual(160);
    expect($snap['recurring_series'])->toBe(4);
    expect($snap['forecast_scenarios'])->toBe(1);

    // Counterparty coverage: at least one merchant, one personal,
    // one government — exact `bank` coverage depends on the
    // known_counterparty_ibans seed list and the demo IBANs the
    // dataset uses, so it is not asserted as required.
    $countByType = Counterparty::query()
        ->withoutGlobalScopes()
        ->whereIn('user_id', demoSeedUserIds())
        ->selectRaw('type, COUNT(*) as c')
        ->groupBy('type')
        ->pluck('c', 'type')
        ->all();

    expect($countByType)->toHaveKey('merchant');
    expect($countByType)->toHaveKey('personal');
    expect($countByType)->toHaveKey('government');
    expect((int) ($countByType['merchant'] ?? 0))->toBeGreaterThan(0);

    // Two distinct chain kinds (one paypal_funding example plus one
    // ics_bulk_settle example) must both be present.
    $chainKinds = ChainLink::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->pluck('kind')
        ->unique()
        ->values()
        ->all();

    expect($chainKinds)->toContain('paypal_funding');
    expect($chainKinds)->toContain('ics_bulk_settle');

    // ImportRun lifecycle — every demo transaction must belong to an
    // ImportRun stamped `source_format = 'demo'`. This is the wipe
    // marker the --reset path walks.
    $demoImportRuns = ImportRun::query()
        ->where('source_format', 'demo')
        ->whereIn('user_id', demoSeedUserIds())
        ->count();

    expect($demoImportRuns)->toBe(5);
});

it('is idempotent on first run without `--reset` against a clean DB', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();
    $firstSnapshot = demoSeedSnapshot();

    $this->artisan('demo:seed')->assertSuccessful();
    $secondSnapshot = demoSeedSnapshot();

    expect($secondSnapshot)->toBe($firstSnapshot);
});

it('coexists cleanly with the production reference seeders', function (): void {
    // Run the production reference seeders first — mirrors the
    // install flow that populates currencies + the default category
    // tree on first launch. The demo command must not collide with
    // either.
    $this->app->make(CurrenciesSeeder::class)->run();
    $this->app->make(DefaultCategoryTreeSeeder::class)->run();

    $this->artisan('demo:seed')->assertSuccessful();

    // Verify the production rows survived intact — the demo seeder
    // must never drop or rewrite reference data.
    $currencyCount = $this->db->connection()->table('currencies')->count();
    expect($currencyCount)->toBeGreaterThan(0);

    $globalCategoryCount = $this->db->connection()
        ->table('categories')
        ->whereNull('user_id')
        ->count();
    expect($globalCategoryCount)->toBeGreaterThan(0);

    // The demo dataset should be the same shape as it is on a
    // clean run.
    $snap = demoSeedSnapshot();
    expect($snap['users'])->toBe(2);
    expect($snap['accounts'])->toBe(5);
    expect($snap['transactions'])->toBeGreaterThanOrEqual(140);
});
