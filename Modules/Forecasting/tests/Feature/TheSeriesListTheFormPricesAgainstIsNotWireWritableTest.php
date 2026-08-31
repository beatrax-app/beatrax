<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ScenarioEditorSidebar;
use Modules\Forecasting\Models\ForecastScenario;

uses(RefreshDatabase::class);

// availableSeries carries the currency each amount is parsed in, and render()
// is its only writer. An action method runs BEFORE render, so an unlocked
// property let the browser name the denomination the server then parsed with.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-19 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = User::query()->create([
        'username' => 'wire-writable-probe',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function tswSeriesId(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Netflix',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1199,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1199,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-05-25',
        'cluster_key' => 'cluster-netflix-'.$userId,
        'cluster_counterparty_key' => 'Netflix',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function tswScenario(User $user): ForecastScenario
{
    return ForecastScenario::query()->create([
        'user_id' => $user->id,
        'name' => 'Locked probe',
        'description' => null,
    ]);
}

it('serves the series list from the server rather than from the request', function (): void {
    $this->actingAs($this->user);
    $seriesId = tswSeriesId($this->db, (int) $this->user->id);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => tswScenario($this->user)->id])
        ->assertSet('availableSeries', [
            ['id' => $seriesId, 'name' => 'Netflix', 'currency' => 'EUR'],
        ]);
});

it('refuses a browser that rewrites the currency a series is priced in', function (): void {
    $this->actingAs($this->user);
    $seriesId = tswSeriesId($this->db, (int) $this->user->id);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => tswScenario($this->user)->id])
        ->set('availableSeries', [
            ['id' => $seriesId, 'name' => 'Netflix', 'currency' => 'JPY'],
        ]);
})->throws(CannotUpdateLockedPropertyException::class);
