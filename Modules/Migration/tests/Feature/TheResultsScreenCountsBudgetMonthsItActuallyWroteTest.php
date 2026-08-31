<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// Every other figure on the results screen comes from what the promote step
// did. Budget months alone was counted from staging — the months the FILE
// held — so importing the same export twice reported "Imported 0 categories,
// 2 budget months, and 0 transactions" and offered a "View budgets" link for
// budgets it had not touched. The second run writes nothing: the cells are
// already at the baseline the first import recorded.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'budget-month-count-user',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

function runBudgetImport(): MigrationRun
{
    $run = app(StartMigrationRun::class)->__invoke(
        test()->user,
        MigrationSourceProduct::Ynab4->value,
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, test()->user);

    return MigrationRun::query()->findOrFail($run->id);
}

function stagedBudgetMonths(int $runId): int
{
    return test()->db->connection()->table('migration_staging_budget_assignments')
        ->where('user_id', test()->user->id)
        ->where('migration_run_id', $runId)
        ->distinct()
        ->count('period_start');
}

it('reports the budget months a first import wrote', function (): void {
    $run = runBudgetImport();

    expect($run->budget_months_count)->toBeGreaterThan(0)
        ->and($run->budget_months_count)->toBe(stagedBudgetMonths($run->id));
});

it('reports nothing for a second import of the same export', function (): void {
    runBudgetImport();
    $second = runBudgetImport();

    // Staging still holds every month the file carries, which is exactly the
    // number the screen used to print as imported.
    expect(stagedBudgetMonths($second->id))->toBeGreaterThan(0)
        ->and($second->budget_months_count)->toBe(0);
});
