<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Ledger\Models\Category;
use Modules\Migration\Internal\Actions\CheckForUpdates;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\DiscardMigrationRun;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-second-import-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

function assignedMinor(string $categoryName, string $periodStart): ?int
{
    $category = Category::query()
        ->where('user_id', test()->user->id)
        ->where('name', $categoryName)
        ->firstOrFail();

    $value = test()->db->connection()->table('envelope_assignments')
        ->where('user_id', test()->user->id)
        ->where('category_id', $category->id)
        ->where('period_start', $periodStart)
        ->value('assigned_minor');

    return $value === null ? null : (int) $value;
}

function importYnab4(string $fixture): int
{
    $run = app(StartMigrationRun::class)->__invoke(
        test()->user,
        MigrationSourceProduct::Ynab4->value,
        MigrationFixturePaths::ynab4Dir($fixture),
        'Beatrax Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, test()->user);

    return $run->id;
}

function importedTransactionId(int $runId, string $postedDate, int $amountMinor): int
{
    // The register carries no per-row id, so the row is named by the day it
    // fell on and what it cost — the two columns the reader would use.
    /** @var object $staged */
    $staged = test()->db->connection()->table('migration_staging_transactions')
        ->where('user_id', test()->user->id)
        ->where('migration_run_id', $runId)
        ->whereNull('parent_source_external_id')
        ->whereDate('posted_at', $postedDate)
        ->where('amount_minor', $amountMinor)
        ->firstOrFail(['source_external_id']);

    return (int) test()->db->connection()->table('migration_source_map')
        ->where('user_id', test()->user->id)
        ->where('source_entity_type', MigrationEntityType::Transaction->value)
        ->where('source_external_id', (string) $staged->source_external_id)
        ->value('beatrax_id');
}

it('a kept-local budget survives the NEXT import of the very same file, because the baseline records what the file said', function (): void {
    $firstRun = importYnab4('v1');

    $jan = CarbonImmutable::parse('2026-01-01');
    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    // v2 moves Groceries 200,00 -> 250,00, colliding with the local 300,00.
    $secondRun = app(CheckForUpdates::class)->__invoke($firstRun, $this->user, MigrationSourceProduct::Ynab4->value, MigrationFixturePaths::ynab4Dir('v2'));
    app(ConfirmMigration::class)->__invoke($secondRun->id, $this->user);

    expect(assignedMinor('Groceries', '2026-01-01'))->toBe(30000);

    // The baseline is the SOURCE's 250,00: recording the kept-local 300,00
    // there made the same file read as a change on the next run.
    $mapId = (int) $this->db->connection()->table('migration_source_map')
        ->where('user_id', $this->user->id)
        ->where('source_entity_type', MigrationEntityType::BudgetAssignment->value)
        ->where('source_external_id', 'cat:frequent/groceries|2026-01-01')
        ->value('id');
    $baseline = $this->db->connection()->table('migration_import_baseline')
        ->where('migration_source_map_id', $mapId)
        ->where('field_name', 'budgeted_minor')
        ->value('baseline_value');
    expect($baseline)->toBe('25000');

    // Re-importing the unchanged v2 must not "apply" the value keep-local rejected.
    $thirdRun = app(CheckForUpdates::class)->__invoke($secondRun->id, $this->user, MigrationSourceProduct::Ynab4->value, MigrationFixturePaths::ynab4Dir('v2'));
    app(ConfirmMigration::class)->__invoke($thirdRun->id, $this->user);

    expect(assignedMinor('Groceries', '2026-01-01'))->toBe(30000);
});

it('a budget cell the second export leaves blank keeps the reader\'s own figure; one that says zero clears it', function (): void {
    $firstRun = importYnab4('sparse/v1');

    expect(assignedMinor('Groceries', '2026-02-01'))->toBe(10000)
        ->and(assignedMinor('Household', '2026-02-01'))->toBe(10000);

    // sparse/v2 says 0,00 for Groceries and says nothing at all for Household.
    $secondRun = app(CheckForUpdates::class)->__invoke($firstRun, $this->user, MigrationSourceProduct::Ynab4->value, MigrationFixturePaths::ynab4Dir('sparse/v2'));
    app(ConfirmMigration::class)->__invoke($secondRun->id, $this->user);

    expect(assignedMinor('Groceries', '2026-02-01'))->toBeNull()
        ->and(assignedMinor('Household', '2026-02-01'))->toBe(10000);
});

it('a budget the reader typed for a month the export only ever zeroed is not deleted by re-importing that export', function (): void {
    $firstRun = importYnab4('edge');

    $feb = CarbonImmutable::parse('2026-02-01');
    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $household = Category::query()->where('user_id', $this->user->id)->where('name', 'Household')->firstOrFail();
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $feb, 30000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $household->id, $feb, 25000);

    $secondRun = app(CheckForUpdates::class)->__invoke($firstRun, $this->user, MigrationSourceProduct::Ynab4->value, MigrationFixturePaths::ynab4Dir('edge'));
    app(ConfirmMigration::class)->__invoke($secondRun->id, $this->user);

    expect(assignedMinor('Groceries', '2026-02-01'))->toBe(30000)
        ->and(assignedMinor('Household', '2026-02-01'))->toBe(25000);
});

it('the update preview writes nothing, and discarding it leaves the ledger exactly as it was', function (): void {
    $firstRun = importYnab4('v1');

    $groceryTxId = importedTransactionId($firstRun, '2026-01-15', -4500);

    $before = [
        'household' => assignedMinor('Household', '2026-01-01'),
        'amount' => (int) $this->db->connection()->table('transactions')->where('id', $groceryTxId)->value('amount_minor'),
    ];

    // v2 changes both of those; neither may move before the reader confirms.
    $secondRun = app(CheckForUpdates::class)->__invoke($firstRun, $this->user, MigrationSourceProduct::Ynab4->value, MigrationFixturePaths::ynab4Dir('v2'));

    expect($secondRun->status)->toBe(MigrationRunStatus::NeedsAttention->value)
        ->and(assignedMinor('Household', '2026-01-01'))->toBe($before['household'])
        ->and((int) $this->db->connection()->table('transactions')->where('id', $groceryTxId)->value('amount_minor'))->toBe($before['amount']);

    // The reader has to be able to reach the thing they are deciding about.
    $this->actingAs($this->user)->get("/migrations/{$secondRun->id}/preview")->assertOk();

    app(DiscardMigrationRun::class)->__invoke($secondRun->id, $this->user);

    expect(assignedMinor('Household', '2026-01-01'))->toBe($before['household'])
        ->and((int) $this->db->connection()->table('transactions')->where('id', $groceryTxId)->value('amount_minor'))->toBe($before['amount']);
});

it('confirming the previewed update is what applies it', function (): void {
    $firstRun = importYnab4('v1');

    $secondRun = app(CheckForUpdates::class)->__invoke($firstRun, $this->user, MigrationSourceProduct::Ynab4->value, MigrationFixturePaths::ynab4Dir('v2'));
    app(ConfirmMigration::class)->__invoke($secondRun->id, $this->user);

    $groceryTxId = importedTransactionId($firstRun, '2026-01-15', -4500);

    expect(assignedMinor('Household', '2026-01-01'))->toBe(12000)
        ->and((int) $this->db->connection()->table('transactions')->where('id', $groceryTxId)->value('amount_minor'))->toBe(-5000);
});

it('the summary reports the transfer pairs the import actually formed, not the leftovers a later sweep found', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Budget.zip',
    );
    $result = app(ConfirmMigration::class)->__invoke($run->id, $this->user);

    // v1's register carries exactly one transfer, as two legs.
    $pairedRows = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->whereNotNull('pair_transaction_id')
        ->count();

    expect($pairedRows)->toBe(2)
        ->and($result->transfersPaired)->toBe(1)
        ->and((int) $this->db->connection()->table('migration_runs')->where('id', $run->id)->value('transfers_paired_count'))->toBe(1);
});

it('an Actual export that names no budget mode imports, and says what it assumed', function (): void {
    $zipPath = sys_get_temp_dir().'/actual-no-budget-type-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath, ActualFixtureBuilder::NO_BUDGET_TYPE);
    $extracted = MigrationFixturePaths::extractZip($zipPath);

    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        MigrationSourceProduct::Actual->value,
        $extracted,
        'Beatrax Actual Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, $this->user);

    $budgetMonths = $this->db->connection()->table('migration_staging_budget_assignments')
        ->where('migration_run_id', $run->id)
        ->count();
    expect($budgetMonths)->toBe(4);

    $assumption = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $run->id)
        ->where('item_type', UnmappedItemType::Extra->value)
        ->get()
        ->first(static fn (object $row): bool => StoredCopy::names(
            is_string($row->display_label) ? $row->display_label : null,
            'migration::unmapped.label.budget_file_mode',
        ));

    expect($assumption)->not->toBeNull();
    $reason = StoredCopy::read((string) $assumption->reason);
    expect($reason)->toContain('envelope')
        ->and($reason)->toContain('preferences.budgetType');

    @unlink($zipPath);
});
