<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// YNAB and Actual both emit a month START for every budget row, so a reader
// whose own month begins on payday was handed a key no period ever matches:
// the plan was on disk, the grid read zero, and the results screen still
// counted it as imported.

beforeEach(function (): void {
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'migrated-budget-payday-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 15,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, $this->user);
});

it('shows a migrated month on the grid of a reader whose month starts on the 15th', function (): void {
    $period = app(PeriodQuery::class)->containingDate('2026-01-01');
    expect($period)->not->toBeNull()
        ->and($period->start->toDateString())->toBe('2025-12-15');

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $household = Category::query()->where('user_id', $this->user->id)->where('name', 'Household')->firstOrFail();

    $rows = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period)['rows'];

    expect($rows[$groceries->id]->assignedMinor)->toBe(20000)
        ->and($rows[$household->id]->assignedMinor)->toBe(10000);
});

it('writes every migrated assignment onto a key the reader has a period for', function (): void {
    $periods = app(PeriodQuery::class);

    $stored = app(DatabaseManager::class)->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->pluck('period_start')
        ->all();

    expect($stored)->toHaveCount(4);

    foreach ($stored as $key) {
        expect($periods->containingDate((string) $key)?->start->toDateString())->toBe((string) $key);
    }
});
