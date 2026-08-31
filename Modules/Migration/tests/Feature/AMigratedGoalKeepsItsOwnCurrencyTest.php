<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Currency;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// The Actual fixture budgets in USD. Every figure it carries was promoted with
// its number kept and its currency dropped, so a $10,000 goal became a €10,000
// one and a $500 budget month read as €500 — both silently, for a reader whose
// base currency is EUR.

beforeEach(function (): void {
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);
    Currency::query()->updateOrInsert(['code' => 'USD'], ['name' => 'US dollar', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'migrated-currency-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);
    $this->db = app(DatabaseManager::class);

    $zipPath = sys_get_temp_dir().'/migrated-currency-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath);
    $extracted = MigrationFixturePaths::extractZip($zipPath);

    $run = app(StartMigrationRun::class)->__invoke($this->user, 'actual', $extracted, 'actual-export.zip');

    // Actual's flat goal_def carries an optional target date and this fixture's
    // omits it, which promote() correctly refuses; the currency question only
    // arises for a goal that does promote.
    $this->db->connection()->table('migration_staging_goals')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $run->id)
        ->update(['target_date' => CarbonImmutable::today()->addYearNoOverflow()->toDateString()]);

    app(ConfirmMigration::class)->__invoke($run->id, $this->user);
    $this->runId = $run->id;
});

it('stores a migrated goal in the currency the source file budgets in', function (): void {
    $staged = $this->db->connection()->table('migration_staging_goals')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $this->runId)
        ->first();

    expect($staged)->not->toBeNull()
        ->and($staged->target_currency)->toBe(ActualFixtureBuilder::BUDGET_FILE_CURRENCY);

    /** @var Goal $goal */
    $goal = Goal::query()->where('user_id', $this->user->id)->firstOrFail();

    expect($goal->target_currency)->toBe(ActualFixtureBuilder::BUDGET_FILE_CURRENCY)
        ->and($goal->target_minor)->toBe((int) $staged->target_minor);
});

it('does not relabel a foreign-currency budget month as the reader\'s own', function (): void {
    $stagedCurrencies = $this->db->connection()->table('migration_staging_budget_assignments')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $this->runId)
        ->distinct()
        ->pluck('currency')
        ->all();

    expect($stagedCurrencies)->toBe([ActualFixtureBuilder::BUDGET_FILE_CURRENCY]);

    $written = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->count();

    expect($written)->toBe(0);
});

it('says which budget months it left out and why', function (): void {
    $item = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $this->runId)
        ->where('item_type', UnmappedItemType::Extra->value)
        ->where('source_external_id', 'budget_currency|'.ActualFixtureBuilder::BUDGET_FILE_CURRENCY)
        ->first();

    expect($item)->not->toBeNull()
        ->and((string) $item->reason)->toContain('EUR')
        ->and((string) $item->reason)->toContain(ActualFixtureBuilder::BUDGET_FILE_CURRENCY);
});

it('imports the same file whole for a reader who budgets in its currency', function (): void {
    $usdReader = User::create([
        'username' => 'migrated-currency-usd-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'USD',
    ]);
    $this->actingAs($usdReader);

    $zipPath = sys_get_temp_dir().'/migrated-currency-usd-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath);
    $extracted = MigrationFixturePaths::extractZip($zipPath);

    $run = app(StartMigrationRun::class)->__invoke($usdReader, 'actual', $extracted, 'actual-export.zip');
    app(ConfirmMigration::class)->__invoke($run->id, $usdReader);

    $written = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $usdReader->id)
        ->get();

    expect($written)->not->toBeEmpty();
    foreach ($written as $row) {
        expect($row->currency)->toBe(ActualFixtureBuilder::BUDGET_FILE_CURRENCY);
    }
});
