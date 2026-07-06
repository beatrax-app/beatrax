<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Actions\StartMigrationRun;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

/*
 * Task 2's own acceptance criteria describe testable behavior with no
 * pre-existing RED stub targeting StartMigrationRun directly — MigrationConfirmTest
 * exercises it only indirectly, chained with ConfirmMigration (Plan 06, not
 * yet built), so it cannot pass yet. Mirrors the Rule 3 precedent Plans 03/04
 * established for dedicated test-coverage additions.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'start-migration-run-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

it('StartMigrationRun: stages the ynab4 v1 fixture and returns a parsed run — Req 1/11', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    expect($run)->toBeInstanceOf(MigrationRun::class);
    expect($run->status)->toBe('parsed');
    expect($run->source_product)->toBe('ynab4');
    expect($run->user_id)->toBe($this->user->id);

    // 3 real categories (Groceries, Household, Salary) + 2 category-group
    // parents (Frequent, Income) materialized as real parent categories
    // (WR-03).
    expect($this->db->connection()->table('migration_staging_categories')->where('migration_run_id', $run->id)->count())->toBe(5);
    expect($this->db->connection()->table('migration_staging_transactions')->where('migration_run_id', $run->id)->whereNull('parent_source_external_id')->count())->toBe(6);

    // Zero domain writes — StartMigrationRun only ever populates staging.
    expect(Category::query()->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('StartMigrationRun: an unknown declared format is rejected with a typed exception', function (): void {
    expect(fn () => app(StartMigrationRun::class)->__invoke(
        $this->user,
        'quicken-2003',
        MigrationFixturePaths::ynab4Dir('v1'),
        'whatever.zip',
    ))->toThrow(InvalidArgumentException::class);

    expect(MigrationRun::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('StartMigrationRun: a corrupt fixture is rejected leaving zero staging AND zero migration_runs rows — Req 1 reject-not-partial', function (): void {
    $extracted = MigrationFixturePaths::extractZip(MigrationFixturePaths::corruptZip());

    expect(fn () => app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        $extracted,
        'not-a-real-export.zip',
    ))->toThrow(UnrecognizedMigrationFileException::class);

    expect(MigrationRun::query()->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('migration_staging_categories')->count())->toBe(0);
    expect($this->db->connection()->table('migration_staging_transactions')->count())->toBe(0);
});

it('StartMigrationRun: a re-run against the same fixture creates a SECOND independent run, each parsed cleanly — every MigrationRun lookup stays scoped to user_id', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    $secondRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    expect($secondRun->id)->not->toBe($firstRun->id);
    expect(MigrationRun::query()->where('user_id', $this->user->id)->where('id', $firstRun->id)->exists())->toBeTrue();
    expect(MigrationRun::query()->where('user_id', $this->user->id)->where('id', $secondRun->id)->exists())->toBeTrue();
});
