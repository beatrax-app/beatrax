<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Enums\ScenarioTemplate;
use Modules\Forecasting\Public\Actions\CreateScenarioFromTemplate;
use Modules\Forecasting\Public\Http\Livewire\ModelWhatIfDropdown;

uses(RefreshDatabase::class);

// The launchpad is keyed on the mutation kind and the series it targets, and
// the scenario's name — "Change :name amount" — carries no figure. A second
// price typed against the same series was therefore taken for a repeat click:
// the reader was redirected to a curve modelling the FIRST figure, silently.

function asptUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function asptSeries(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1199,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1199,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-05-25',
        'cluster_key' => 'aspt-cluster-'.$userId,
        'cluster_counterparty_key' => 'Spotify',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

/**
 * @return array{scenarios: int, mutations: int, newAmountMinor: int}
 */
function asptWhatIfState(DatabaseManager $db, int $userId): array
{
    $scenarios = $db->connection()->table('forecast_scenarios')->where('user_id', $userId)->count();
    $mutations = $db->connection()->table('forecast_scenario_mutations')
        ->where('user_id', $userId)
        ->where('kind', 'change_series_amount')
        ->get();

    $payload = json_decode((string) ($mutations->first()->payload ?? '{}'), true);

    return [
        'scenarios' => $scenarios,
        'mutations' => $mutations->count(),
        'newAmountMinor' => is_array($payload) && is_int($payload['newAmountMinor'] ?? null) ? $payload['newAmountMinor'] : 0,
    ];
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = asptUser('reprice-reader');
});

it('models the price the reader typed second, not the one they typed first', function (): void {
    $seriesId = asptSeries($this->db, $this->user->id);

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);

    $first = ($action)(ScenarioTemplate::ChangeAmount, $seriesId, $this->user, 1399);
    $second = ($action)(ScenarioTemplate::ChangeAmount, $seriesId, $this->user, 1999);

    expect($second)->toBe($first);

    $state = asptWhatIfState($this->db, $this->user->id);
    expect($state['scenarios'])->toBe(1)
        ->and($state['mutations'])->toBe(1)
        ->and($state['newAmountMinor'])->toBe(1999);
});

it('carries the second price through the dropdown the reader actually uses', function (): void {
    $this->actingAs($this->user);
    $seriesId = asptSeries($this->db, $this->user->id);

    Livewire::test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->call('openAmountForm')
        ->set('newAmountInput', '13,99')
        ->call('saveAmountChange')
        ->assertRedirectContains('/forecast?scenarioId=');

    Livewire::test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->call('openAmountForm')
        ->set('newAmountInput', '19,99')
        ->call('saveAmountChange')
        ->assertRedirectContains('/forecast?scenarioId=');

    $state = asptWhatIfState($this->db, $this->user->id);
    expect($state['scenarios'])->toBe(1)
        ->and($state['mutations'])->toBe(1)
        ->and($state['newAmountMinor'])->toBe(1999);
});

// A cancellation names no figure, so the second click on it is the repeat
// click the lookup was built for and must still write nothing.
it('leaves a cancellation what-if untouched on the second click', function (): void {
    $seriesId = asptSeries($this->db, $this->user->id);

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);

    $first = ($action)(ScenarioTemplate::Cancel, $seriesId, $this->user);
    $before = $this->db->connection()->table('forecast_scenario_mutations')
        ->where('user_id', $this->user->id)
        ->first(['id', 'updated_at']);

    $second = ($action)(ScenarioTemplate::Cancel, $seriesId, $this->user);
    $after = $this->db->connection()->table('forecast_scenario_mutations')
        ->where('user_id', $this->user->id)
        ->first(['id', 'updated_at']);

    expect($second)->toBe($first)
        ->and($this->db->connection()->table('forecast_scenario_mutations')->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($after?->updated_at)->toBe($before?->updated_at);
});
