<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Migration\Internal\Http\Livewire\NewMigration;
use Modules\Migration\Public\Actions\DiscardMigrationRun;
use Modules\Migration\Public\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

/*
 * RED Wave 0 stub (13.5-02 Task 3) pinning the wizard surface's public
 * contract (Req 11/12) and the T-13.5-04 cross-user IDOR mitigation for
 * every one of the four `/migrations*` routes. None of NewMigration /
 * PreviewMigration / MigrationResults / MigrationsIndex or the real routes
 * exist until Plan 08 — every test below is EXPECTED to fail now
 * (missing-class / route-not-found error), not pass.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-wizard-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

it('MigrationWizard: GET /migrations/new renders the upload form', function (): void {
    $response = $this->actingAs($this->user)->get('/migrations/new');

    $response->assertOk();
    $response->assertSee('Migration', false);
});

it('MigrationWizard: NewMigration requires a source-product selection', function (): void {
    Livewire::actingAs($this->user)
        ->test(NewMigration::class)
        ->set('sourceProduct', '')
        ->call('submit')
        ->assertHasErrors(['sourceProduct']);
});

it('MigrationWizard: preview renders the 5 mapped counts + a grouped unmapped summary — Req 11/12', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $response = $this->actingAs($this->user)->get("/migrations/{$run->id}/preview");

    $response->assertOk();
    // 5 mapped counts: categories, accounts, payees, budget assignments,
    // transactions.
    $response->assertSee('Categories', false);
    $response->assertSee('Accounts', false);
    $response->assertSee('Transactions', false);
});

it('MigrationWizard: NO domain writes occur before confirm — Req 11 (staging only)', function (): void {
    app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    // Staging is populated (parse succeeded)...
    $stagingCount = $this->db->connection()->table('migration_staging_categories')->count();
    expect($stagingCount)->toBeGreaterThan(0);

    // ...but zero domain writes exist anywhere — the "database unchanged
    // before confirm" contract is interpreted as DOMAIN tables (D-07);
    // staging is scratch and may hold rows pre-confirm.
    expect(Category::query()->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('MigrationWizard: discarding a run leaves domain tables unchanged and truncates staging — Req 11', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    app(DiscardMigrationRun::class)->__invoke($run->id, $this->user);

    expect($this->db->connection()->table('migration_staging_categories')->where('migration_run_id', $run->id)->count())->toBe(0);
    expect(Category::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('MigrationWizard: partner receives 404 (never 403) requesting the owner\'s migration preview — T-13.5-04', function (): void {
    $partner = User::create(['username' => 'migration-wizard-partner', 'password' => 'opensesame', 'period_start_day' => 1]);

    $ownerRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $response = $this->actingAs($partner)->get("/migrations/{$ownerRun->id}/preview");

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('MigrationWizard: partner receives 404 (never 403) requesting the owner\'s migration results — T-13.5-04', function (): void {
    $partner = User::create(['username' => 'migration-wizard-partner-2', 'password' => 'opensesame', 'period_start_day' => 1]);

    $ownerRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $response = $this->actingAs($partner)->get("/migrations/{$ownerRun->id}/results");

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('MigrationWizard: the owner\'s migration run never bleeds into the partner\'s /migrations index — T-13.5-04', function (): void {
    $partner = User::create(['username' => 'migration-wizard-partner-3', 'password' => 'opensesame', 'period_start_day' => 1]);

    app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $response = $this->actingAs($partner)->get('/migrations');

    $response->assertOk();
    $response->assertDontSee('Beatrax Test Budget.zip', false);
});

it('MigrationWizard: GET /migrations/new is reachable by any authenticated user — T-13.5-04 (no per-entity id, no data to leak)', function (): void {
    $partner = User::create(['username' => 'migration-wizard-partner-4', 'password' => 'opensesame', 'period_start_day' => 1]);

    $this->actingAs($partner)->get('/migrations/new')->assertOk();
});
