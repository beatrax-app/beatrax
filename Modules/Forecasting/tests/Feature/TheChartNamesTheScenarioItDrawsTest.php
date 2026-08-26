<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Public\Actions\CreateScenario;

beforeEach(function (): void {
    $this->seeded = $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// Selecting a scenario lit its tab and left the chart card headed
// "All accounts · Baseline" -- the heading was a literal. On a scenario with no
// mutations yet the plotted line is identical to the baseline, so the heading is
// the only thing telling the reader which one they are looking at.
it('names the selected scenario in the chart heading, not the baseline', function (): void {
    $scenarioId = app(CreateScenario::class)($this->fixtureUser, 'Rent rise');

    Livewire::test(ForecastPage::class)
        ->assertSee('Baseline')
        ->call('setScenario', $scenarioId)
        ->assertSee('All accounts · Rent rise', escape: false);
});

// The guard above reads the aggregate view only. Selecting an account swaps in a
// second panel whose heading was the literal word "Scenario", so a reader with
// two scenarios open could not tell from the heading which one the lower chart
// drew. Found on an iPhone at /forecast?account=1&scenarioId=1.
it('names the scenario on the account view too, where a second panel draws it', function (): void {
    $scenarioId = app(CreateScenario::class)($this->fixtureUser, 'Rent rise');
    $account = $this->seeded['account'];

    Livewire::test(ForecastPage::class)
        ->set('account', (string) $account->id)
        ->call('setScenario', $scenarioId)
        ->assertSee($account->name.' · Rent rise', escape: false)
        ->assertDontSee($account->name.' · Scenario', escape: false);
});

it('goes back to naming the baseline when the baseline is selected', function (): void {
    $scenarioId = app(CreateScenario::class)($this->fixtureUser, 'Rent rise');

    Livewire::test(ForecastPage::class)
        ->call('setScenario', $scenarioId)
        ->assertSee('All accounts · Rent rise', escape: false)
        ->call('setScenario', null)
        ->assertSee('All accounts · Baseline', escape: false)
        ->assertDontSee('All accounts · Rent rise', escape: false);
});
