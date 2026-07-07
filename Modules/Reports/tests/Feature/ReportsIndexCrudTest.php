<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Actions\UpdateReport;
use Modules\Reports\Public\Dto\ReportDefinition;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stub (999.6-03 Task 2, Req 9).
 *
 * Pins Modules\Reports\Internal\Http\Livewire\ReportsIndex (analog:
 * CounterpartyIndex — constructor-free, method-parameter DI) as the
 * `/reports` index surface: list the current user's saved reports, and
 * delete via the two-step inline-confirm pattern RulesPage established
 * (`confirmDelete()`/`cancelDelete()`/`deleteReport()`, renamed from
 * `deleteRule()`). Create/edit go through the write actions directly
 * (SaveReport/UpdateReport) — this stub locks the full
 * create -> list -> edit -> delete round trip across the actions + the
 * index component's read/delete surface.
 *
 * RED as intended: ReportsIndex/SaveReport/UpdateReport/DeleteReport do
 * not exist yet — every test below fails on a missing class (either
 * `Livewire::test(ReportsIndex::class)` or `app(SaveReport::class)`), not
 * on the (already-existing) ReportDefinition DTO or `saved_reports` table
 * (Plan 01) they're built from.
 */

function ricrudUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'ricrud-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function ricrudDefinition(): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: 'monthly',
        currencyMode: 'base',
        viz: 'table',
    );
}

beforeEach(function (): void {
    $this->user = ricrudUser();
    $this->actingAs($this->user);
});

it('lists the current user\'s saved reports', function (): void {
    app(SaveReport::class)->save($this->user, ricrudDefinition(), 'Monthly spend by category');

    Livewire::test(ReportsIndex::class)
        ->assertSee('Monthly spend by category');
});

it('never lists another user\'s saved report (cross-user isolation)', function (): void {
    $other = ricrudUser();
    app(SaveReport::class)->save($other, ricrudDefinition(), 'Someone else\'s report');

    Livewire::test(ReportsIndex::class)
        ->assertDontSee('Someone else\'s report');
});

it('create -> list -> edit -> delete round trip', function (): void {
    // Create.
    $saved = app(SaveReport::class)->save($this->user, ricrudDefinition(), 'Original name');

    // List.
    Livewire::test(ReportsIndex::class)
        ->assertSee('Original name');

    // Edit — Modules\Reports\Public\Actions\UpdateReport::update(User,
    // int $reportId, ReportDefinition, string $name): SavedReport, mirrors
    // SaveReport's signature (pinned here as the update contract).
    app(UpdateReport::class)->update(
        $this->user,
        $saved->id,
        ricrudDefinition(),
        'Renamed report',
    );

    Livewire::test(ReportsIndex::class)
        ->assertSee('Renamed report')
        ->assertDontSee('Original name');

    // Delete — inline two-step confirm, mirrors RulesPage's
    // confirmDelete()/deleteRule() pattern renamed to deleteReport().
    Livewire::test(ReportsIndex::class)
        ->assertSet('confirmingDeleteId', null)
        ->call('confirmDelete', $saved->id)
        ->assertSet('confirmingDeleteId', $saved->id)
        ->call('deleteReport', $saved->id)
        ->assertSet('confirmingDeleteId', null);

    expect(SavedReport::query()->find($saved->id))->toBeNull();
});

it('refuses to delete a foreign user\'s report (not a 500)', function (): void {
    $other = ricrudUser();
    $foreign = app(SaveReport::class)->save($other, ricrudDefinition(), 'Foreign report');

    Livewire::test(ReportsIndex::class)
        ->call('deleteReport', $foreign->id)
        ->assertSet('confirmingDeleteId', null);

    expect(SavedReport::query()->withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});
