<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Migration\Internal\Parsers\ActualParser;
use Modules\Migration\Internal\Parsers\Ynab4Parser;
use Modules\Migration\Internal\Pipeline\StagingWriter;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'staging-writer-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);

    // StagingWriter never creates the run row every staging FK points at.
    $this->run = MigrationRun::create([
        'user_id' => $this->user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'Beatrax Test Budget.zip',
    ]);
});

function stageYnab4V1(User $user, int $runId): void
{
    $batch = app(Ynab4Parser::class)->parse(MigrationFixturePaths::ynab4Dir('v1'), $user, $runId);
    app(StagingWriter::class)->write($batch, $runId, $user);
}

it('StagingWriter: lands the parsed batch into all six staging tables scoped by run+user', function (): void {
    stageYnab4V1($this->user, $this->run->id);

    $scoped = fn (string $table) => $this->db->connection()->table($table)
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $this->run->id);

    // 3 real categories (Groceries, Household, Salary) plus the 2 group parents
    // (Frequent, Income), which are materialized as real parent categories.
    expect($scoped('migration_staging_categories')->count())->toBe(5);

    // 2 accounts (Checking, Savings).
    expect($scoped('migration_staging_accounts')->count())->toBe(2);

    // 3 payees (Albert Heijn, Employer, Supermarket) — transfer payees excluded upstream by the parser.
    expect($scoped('migration_staging_payees')->count())->toBe(3);

    // 2 months x 2 categories = 4 budget assignment rows.
    expect($scoped('migration_staging_budget_assignments')->count())->toBe(4);

    // 6 top-level rows + 2 split legs = 8; only the 6 have a NULL
    // parent_source_external_id, since the legs carry the parent's id.
    expect($scoped('migration_staging_transactions')->count())->toBe(8);
    expect($scoped('migration_staging_transactions')->whereNull('parent_source_external_id')->count())->toBe(6);
    expect($scoped('migration_staging_transactions')->whereNotNull('parent_source_external_id')->count())->toBe(2);

    // No unmapped items for this clean YNAB4 fixture.
    expect($scoped('migration_staging_unmapped_items')->count())->toBe(0);
});

it('StagingWriter: split parent + 2 legs sum to the parent amount, category lives only on the legs', function (): void {
    stageYnab4V1($this->user, $this->run->id);

    $parent = $this->db->connection()->table('migration_staging_transactions')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $this->run->id)
        ->where('is_split_parent', true)
        ->first();

    expect($parent)->not->toBeNull();
    expect($parent->category_source_external_id)->toBeNull();
    expect((int) $parent->amount_minor)->toBe(-3000);

    $legs = $this->db->connection()->table('migration_staging_transactions')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $this->run->id)
        ->where('parent_source_external_id', $parent->source_external_id)
        ->get();

    expect($legs)->toHaveCount(2);
    expect((int) $legs->sum('amount_minor'))->toBe(-3000);
    foreach ($legs as $leg) {
        expect($leg->category_source_external_id)->not->toBeNull();
        expect((bool) $leg->is_split_parent)->toBeFalse();
    }
});

it('StagingWriter: transfer pair carries mutual transfer_counterpart_source_external_id references', function (): void {
    stageYnab4V1($this->user, $this->run->id);

    $legs = $this->db->connection()->table('migration_staging_transactions')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $this->run->id)
        ->whereNotNull('transfer_counterpart_source_external_id')
        ->get();

    expect($legs)->toHaveCount(2);
    $bySourceId = $legs->keyBy('source_external_id');
    foreach ($legs as $leg) {
        expect($bySourceId->has($leg->transfer_counterpart_source_external_id))->toBeTrue();
    }
});

it('StagingWriter: writes ONLY staging tables — zero domain-table writes', function (): void {
    stageYnab4V1($this->user, $this->run->id);

    expect(Category::query()->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('accounts')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('counterparties')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('StagingWriter: lands the ONE flat goal_def as a migration_staging_goals row', function (): void {
    $zipPath = sys_get_temp_dir().'/staging-writer-actual-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath);
    $extracted = MigrationFixturePaths::extractZip($zipPath);

    $actualRun = MigrationRun::create([
        'user_id' => $this->user->id,
        'source_product' => 'actual',
        'status' => 'parsed',
        'original_filename' => 'actual-export.zip',
    ]);

    $batch = app(ActualParser::class)->parse($extracted, $this->user, $actualRun->id);
    app(StagingWriter::class)->write($batch, $actualRun->id, $this->user);

    $goals = $this->db->connection()->table('migration_staging_goals')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $actualRun->id)
        ->get();

    // Only "Groceries" carries a flat goal_def; "Emergency Fund"'s template one
    // becomes an unmapped item, never a row here.
    expect($goals)->toHaveCount(1);
    expect($goals->first()->name)->toBe('Groceries');
    expect((int) $goals->first()->target_minor)->toBe(20000);
    expect($goals->first()->target_date)->toBeNull();

    @unlink($zipPath);
});

it('StagingWriter: never materializes the transaction generator into a PHP array', function (): void {
    $source = file_get_contents(__DIR__.'/../../../Internal/Pipeline/StagingWriter.php');

    expect($source)->not->toBeFalse();
    expect(substr_count((string) $source, 'iterator_to_array'))->toBe(0);
});
