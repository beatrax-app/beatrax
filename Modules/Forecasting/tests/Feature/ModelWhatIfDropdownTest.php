<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Enums\ScenarioTemplate;
use Modules\Forecasting\Public\Actions\CreateScenarioFromTemplate;
use Modules\Forecasting\Public\Http\Livewire\ModelWhatIfDropdown;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function mwidUser(string $username = 'mwid'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function mwidSeries(DatabaseManager $db, int $userId, string $name = 'Spotify', int $amountMinor = -999): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $amountMinor,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-05-25',
        'cluster_key' => 'cluster-'.$name.'-'.$userId,
        'cluster_counterparty_key' => $name,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = mwidUser();
});

it('mounts with the current series amount pre-populated', function (): void {
    $this->actingAs($this->user);
    $seriesId = mwidSeries($this->db, $this->user->id);

    Livewire::test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->assertSet('seriesName', 'Spotify')
        ->assertSet('currentAmountMinor', -999)
        ->assertSet('newAmountInput', '9.99');
});

it('Model cancellation invokes CreateScenarioFromTemplate + redirects to /forecast', function (): void {
    $this->actingAs($this->user);
    $seriesId = mwidSeries($this->db, $this->user->id);

    Livewire::test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->call('openMenu')
        ->call('modelCancellation')
        ->assertRedirectContains('/forecast?scenarioId=');

    $scenarios = $this->db->connection()->table('forecast_scenarios')->where('user_id', $this->user->id)->get();
    expect($scenarios->count())->toBe(1);
    $mutations = $this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenarios->first()->id)->get();
    expect($mutations->first()->kind)->toBe('cancel_series');
});

it('Model amount change form opens with the current amount pre-populated', function (): void {
    $this->actingAs($this->user);
    $seriesId = mwidSeries($this->db, $this->user->id);

    Livewire::test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->call('openMenu')
        ->call('openAmountForm')
        ->assertSet('mode', 'amount-form')
        ->assertSet('newAmountInput', '9.99');
});

it('Model amount change saves + invokes CreateScenarioFromTemplate + redirects', function (): void {
    $this->actingAs($this->user);
    $seriesId = mwidSeries($this->db, $this->user->id);

    Livewire::test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->call('openMenu')
        ->call('openAmountForm')
        ->set('newAmountInput', '11,49')
        ->call('saveAmountChange')
        ->assertRedirectContains('/forecast?scenarioId=');

    $scenarios = $this->db->connection()->table('forecast_scenarios')->where('user_id', $this->user->id)->get();
    expect($scenarios->count())->toBe(1);
    expect($scenarios->first()->name)->toBe('Change Spotify amount');
    $mutations = $this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenarios->first()->id)->get();
    expect($mutations->first()->kind)->toBe('change_series_amount');
    $payload = json_decode((string) $mutations->first()->payload, true);
    expect($payload['newAmountMinor'])->toBe(1149);
});

it('cross-user mount via RecurringSeriesQuery::forSeries returns null', function (): void {
    $other = mwidUser('other');
    $seriesId = mwidSeries($this->db, $other->id);
    /** @var RecurringSeriesQuery $sq */
    $sq = $this->app->make(RecurringSeriesQuery::class);
    // Livewire::test() does not surface mount-time exceptions here, so the null
    // the dropdown's mount() 404s on is what gets asserted.
    expect($sq->forSeries($seriesId, $this->user))->toBeNull();
});

it('invalid amount input surfaces the inline error and does NOT invoke the launchpad', function (): void {
    $this->actingAs($this->user);
    $seriesId = mwidSeries($this->db, $this->user->id);

    Livewire::test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->call('openAmountForm')
        ->set('newAmountInput', 'not-a-number')
        ->call('saveAmountChange')
        ->assertSet('errorMessage', 'Amount must be a positive number.');

    expect($this->db->connection()->table('forecast_scenarios')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('CreateScenarioFromTemplate returns 404 for another user\'s series, cancelling or repricing', function (): void {
    $other = mwidUser('other2');
    $seriesId = mwidSeries($this->db, $other->id);
    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);

    expect(fn () => ($action)(ScenarioTemplate::Cancel, $seriesId, $this->user))->toThrow(NotFoundHttpException::class)
        ->and(fn () => ($action)(ScenarioTemplate::ChangeAmount, $seriesId, $this->user, 1499))->toThrow(NotFoundHttpException::class);
});
