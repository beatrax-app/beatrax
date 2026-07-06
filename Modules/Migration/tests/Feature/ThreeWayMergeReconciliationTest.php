<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Actions\CheckForUpdates;
use Modules\Migration\Public\Actions\ConfirmMigration;
use Modules\Migration\Public\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

/*
 * RED Wave 0 stub (13.5-02 Task 3) pinning the Req 10 3-way-merge
 * reconciliation scenario, concretized in 13.5-RESEARCH.md's own algorithm
 * walkthrough: import v1, LOCALLY edit one category's budget assignment,
 * re-import v2 which ALSO changed that SAME category (a CONFLICT — the
 * local edit is untouched, the conflict is listed) AND changed a
 * DIFFERENT category (a clean APPLY — untouched locally, source wins).
 *
 * `CheckForUpdates` does not exist until Plan 07 — every test below is
 * EXPECTED to fail now (missing-class error).
 *
 * Scope note: this file targets the budget-assignment field per
 * RESEARCH.md's own concrete walkthrough (the algorithm's only fully
 * worked example). A transaction-field 3-way-merge conflict follows the
 * identical algorithm against a different entity kind and is not
 * separately re-proven here.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-3wm-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

it('ThreeWayMerge: a category changed on BOTH source and local is a conflict (untouched + listed); a category changed only on source applies cleanly — Req 10', function (): void {
    // 1. Import v1.
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $household = Category::query()->where('user_id', $this->user->id)->where('name', 'Household')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');

    // 2. LOCAL edit: the user bumps Groceries' Jan assignment to 300.00
    // (30000 minor) via the real public writer — simulating manual local
    // budgeting activity between the two migration imports. Household is
    // left untouched.
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    // 3. Re-import v2, which changed BOTH Groceries (200.00 -> 250.00,
    // colliding with the local edit above) AND Household (100.00 -> 120.00,
    // untouched locally).
    app(CheckForUpdates::class)->__invoke($firstRun->id, $this->user, 'ynab4', MigrationFixturePaths::ynab4Dir('v2'));

    // Groceries: CONFLICT — the local value (300.00) survives untouched;
    // the source's 250.00 is NOT silently applied.
    $groceriesAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');
    expect((int) $groceriesAssigned)->toBe(30000);

    // Household: clean APPLY — no local edit existed, so the source's
    // 120.00 change is applied automatically.
    $householdAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $household->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');
    expect((int) $householdAssigned)->toBe(12000);
});

it('ThreeWayMerge: the conflict is listed for the user, not silently swallowed — Req 10/12', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    // The reconciliation attempt is surfaced with a distinct lifecycle
    // status when unresolved conflicts exist (D-06/D-07's 'needs_attention'
    // status) rather than silently reporting 'confirmed'.
    expect($reconciliationRun->status)->toBe('needs_attention');

    $unmappedConflicts = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->get();
    expect($unmappedConflicts)->not->toBeEmpty();
});

it('CR-01: confirming a needs_attention reconciliation run does NOT overwrite the kept-local budget-assignment conflict', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');

    // LOCAL edit that will collide with v2's Groceries change (200.00 ->
    // 250.00), producing a conflict CheckForUpdates leaves unresolved.
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );
    expect($reconciliationRun->status)->toBe('needs_attention');

    // The wizard's own docblock (PreviewMigration.php:52-58) documents
    // clicking Confirm on a needs_attention run as expected/safe — this is
    // the exact call CR-01 found silently defeating D-14's keep-local
    // guarantee on its SECOND promote() pass.
    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    $groceriesAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');

    // The local 300.00 value must survive Confirm untouched — NOT silently
    // reset to the source's conflicting 250.00.
    expect((int) $groceriesAssigned)->toBe(30000);

    $confirmedRun = MigrationRun::query()->findOrFail($reconciliationRun->id);
    expect($confirmedRun->status)->toBe('confirmed');
});
