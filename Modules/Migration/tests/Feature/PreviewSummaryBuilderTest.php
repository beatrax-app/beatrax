<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Dto\PreviewSummary;
use Modules\Migration\Internal\Exceptions\MigrationRunNotParsedException;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'preview-summary-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
});

it('PreviewSummaryBuilder: returns the 5 mapped counts for a staged ynab4 v1 run', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $summary = app(PreviewSummaryBuilder::class)->forRun($run->id, $this->user);

    expect($summary)->toBeInstanceOf(PreviewSummary::class);
    // 3 real categories (Groceries, Household, Salary) plus the 2 group parents
    // (Frequent, Income), which are materialized as real parent categories.
    expect($summary->categoriesCount)->toBe(5);
    expect($summary->accountsCount)->toBe(2);
    expect($summary->counterpartiesCount)->toBe(3);
    // 6 logical transactions — a 2-leg split counts once, not 3 times.
    expect($summary->transactionsCount)->toBe(6);
    // 2 distinct budget months (2026-01, 2026-02), not the 4 raw assignment rows.
    expect($summary->budgetMonthsCount)->toBe(2);

    expect($summary->unmapped)->toHaveKeys(['extra', 'conflict']);
    expect($summary->unmapped['conflict']['count'])->toBe(0);
});

it('PreviewSummaryBuilder: the unmapped summary lists >=1 row Actual carries that Beatrax has no home for, grouped under extra with a count', function (): void {
    $zipPath = sys_get_temp_dir().'/preview-summary-actual-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath);
    $extracted = MigrationFixturePaths::extractZip($zipPath);

    $run = app(StartMigrationRun::class)->__invoke($this->user, 'actual', $extracted, 'actual-export.zip');

    $summary = app(PreviewSummaryBuilder::class)->forRun($run->id, $this->user);

    // ActualFixtureBuilder's golden fixture carries a non-flat goal_def, a
    // saved-report row, and a schedules row — all surfaced as 'extra'.
    expect($summary->unmapped['extra']['count'])->toBeGreaterThanOrEqual(3);
    $labels = array_column($summary->unmapped['extra']['items'], 'label');
    expect($labels)->not->toBeEmpty();

    @unlink($zipPath);
});

it('PreviewSummaryBuilder: throws MigrationRunNotParsedException for a discarded run (staging deliberately truncated)', function (): void {
    $run = MigrationRun::create([
        'user_id' => $this->user->id,
        'source_product' => 'ynab4',
        'status' => 'discarded',
        'original_filename' => 'discarded.zip',
    ]);

    expect(fn () => app(PreviewSummaryBuilder::class)->forRun($run->id, $this->user))
        ->toThrow(MigrationRunNotParsedException::class);
});

it('PreviewSummaryBuilder: a genuinely-empty PARSED run (zero staged rows) does NOT throw — it is a legitimate empty preview', function (): void {
    // 'parsed' is only ever set after staging succeeded, so zero staged rows
    // here means an empty source file, not a truncated one.
    $run = MigrationRun::create([
        'user_id' => $this->user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'empty.zip',
    ]);

    $summary = app(PreviewSummaryBuilder::class)->forRun($run->id, $this->user);

    expect($summary)->toBeInstanceOf(PreviewSummary::class);
    expect($summary->categoriesCount)->toBe(0);
    expect($summary->accountsCount)->toBe(0);
    expect($summary->counterpartiesCount)->toBe(0);
    expect($summary->transactionsCount)->toBe(0);
    expect($summary->budgetMonthsCount)->toBe(0);
});

it('PreviewSummaryBuilder: a run belonging to another user resolves to a not-found exception — IDOR', function (): void {
    $owner = User::create(['username' => 'preview-summary-owner', 'password' => 'opensesame', 'period_start_day' => 1]);
    $run = app(StartMigrationRun::class)->__invoke(
        $owner,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $partner = User::create(['username' => 'preview-summary-partner', 'password' => 'opensesame', 'period_start_day' => 1]);

    expect(fn () => app(PreviewSummaryBuilder::class)->forRun($run->id, $partner))
        ->toThrow(ModelNotFoundException::class);
});

it('PreviewSummaryBuilder: uses raw query-builder reads only — no chained dynamic Eloquent orderBy', function (): void {
    $source = file_get_contents(__DIR__.'/../../Internal/Pipeline/PreviewSummaryBuilder.php');

    expect($source)->not->toBeFalse();
    expect(substr_count((string) $source, '->orderBy('))->toBe(0);
});
