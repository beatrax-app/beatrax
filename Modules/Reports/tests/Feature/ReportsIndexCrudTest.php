<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Actions\SaveReport;
use Modules\Reports\Internal\Actions\UpdateReport;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Models\SavedReport;

uses(RefreshDatabase::class);

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
        granularity: ReportGranularity::Monthly,
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
    $saved = app(SaveReport::class)->save($this->user, ricrudDefinition(), 'Original name');

    Livewire::test(ReportsIndex::class)
        ->assertSee('Original name');

    app(UpdateReport::class)->update(
        $this->user,
        $saved->id,
        ricrudDefinition(),
        'Renamed report',
    );

    Livewire::test(ReportsIndex::class)
        ->assertSee('Renamed report')
        ->assertDontSee('Original name');

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
