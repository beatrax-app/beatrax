<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Models\ForecastScenario;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// deleteScenario() takes no id parameter: it acts on $scenarioId and never
// compares it to $confirmingDeleteScenario. Unlocked, a replayed snapshot
// naming a second scenario deleted that one instead — cascading its mutations
// and shipping a delete tombstone to every paired device — while the toast
// still named the scenario the reader had open. ForecastPage::deleteScenario()
// is the same verb written the other way, taking the id as a parameter.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'scenario-delete-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->onScreen = ForecastScenario::factory()->create(['user_id' => $this->user->id, 'name' => 'On screen']);
    $this->neighbour = ForecastScenario::factory()->create(['user_id' => $this->user->id, 'name' => 'Neighbour']);
});

function scenarioSidebarSnapshot(int $scenarioId): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) Livewire::mount('forecasting.scenario-editor-sidebar', ['scenarioId' => $scenarioId]),
        'forecasting.scenario-editor-sidebar',
    );
}

it('refuses a payload that moves the delete onto a second scenario', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        scenarioSidebarSnapshot($this->onScreen->id),
        ['scenarioId' => $this->neighbour->id],
        [['path' => '', 'method' => 'deleteScenario', 'params' => []]],
    )->assertForbidden();

    $this->assertDatabaseHas('forecast_scenarios', ['id' => $this->neighbour->id]);
    $this->assertDatabaseHas('forecast_scenarios', ['id' => $this->onScreen->id]);
});

it('still deletes the scenario the sidebar was mounted for', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        scenarioSidebarSnapshot($this->onScreen->id),
        [],
        [['path' => '', 'method' => 'deleteScenario', 'params' => []]],
    )->assertOk();

    $this->assertDatabaseMissing('forecast_scenarios', ['id' => $this->onScreen->id]);
    $this->assertDatabaseHas('forecast_scenarios', ['id' => $this->neighbour->id]);
});
