<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Http\Livewire\ScenarioEditorSidebar;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Services\ScenarioQuery;

uses(RefreshDatabase::class);

function ssbUser(string $username = 'ssb'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ssbSeriesId(DatabaseManager $db, int $userId, string $name = 'Netflix', int $amountMinor = -1199): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => $amountMinor < 0 ? 'expense' : 'income',
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
    // The form fixtures below are dated in June 2026 and the write-side now
    // refuses a date no horizon reaches, so the clock sits where they do.
    CarbonImmutable::setTestNow('2026-05-19 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = ssbUser();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('Public Service rejects cross-user mount via ScenarioQuery::find returning null', function (): void {
    $other = ssbUser('other');
    /** @var ForecastScenario $otherScenario */
    $otherScenario = ForecastScenario::query()->create([
        'user_id' => $other->id,
        'name' => 'Other user',
    ]);
    /** @var ScenarioQuery $sq */
    $sq = $this->app->make(ScenarioQuery::class);
    expect($sq->find($otherScenario->id, $this->user))->toBeNull();
});

it('mounts with empty mutation list and renders the empty-state body', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Empty scenario',
    ]);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->assertSet('scenarioName', 'Empty scenario')
        ->assertSet('mutations', [])
        ->assertSee('No mutations yet');
});

it('lists existing mutations in the sidebar render', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Has mutations',
    ]);
    $seriesId = ssbSeriesId($this->db, $this->user->id);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    ($add)($scenario->id, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    // The series' own name, not the "series #7" fallback: the list the
    // summaries are written from is loaded before them now, so the first paint
    // resolves it too.
    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->assertSee('Cancel Netflix')
        ->assertSee('Mutations (1)');
});

it('Add chooser → cancel_series → invokes the Public Action and refreshes mutations', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Cancel flow',
    ]);
    $seriesId = ssbSeriesId($this->db, $this->user->id);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startAddMutation')
        ->call('selectKind', 'cancel_series')
        ->set('form.seriesId', $seriesId)
        ->call('saveAddMutation')
        ->assertDispatched('toast');

    expect($this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenario->id)->count())->toBe(1);
});

it('Add chooser → add_one_off — typed payload persists with date + amount + direction', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'One-off',
    ]);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startAddMutation')
        ->call('selectKind', 'add_one_off')
        ->set('form.date', '2026-06-15')
        ->set('form.amount', '50,00')
        ->set('form.currency', 'EUR')
        ->set('form.direction', 'expense')
        ->call('saveAddMutation')
        ->assertDispatched('toast');

    $row = $this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenario->id)->first();
    $payload = json_decode((string) $row->payload, true);
    expect($payload['date'])->toBe('2026-06-15');
    expect($payload['amountMinor'])->toBe(5000);
    expect($payload['direction'])->toBe('expense');
});

it('Add chooser → shift_series_date with scope=next persists scope correctly', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Shift next',
    ]);
    $seriesId = ssbSeriesId($this->db, $this->user->id);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startAddMutation')
        ->call('selectKind', 'shift_series_date')
        ->set('form.seriesId', $seriesId)
        ->set('form.newNextDate', '2026-06-02')
        ->set('form.scope', 'next')
        ->call('saveAddMutation')
        ->assertDispatched('toast');

    $row = $this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenario->id)->first();
    $payload = json_decode((string) $row->payload, true);
    expect($payload['scope'])->toBe('next');
});

it('Add chooser → shift_series_date with scope=all_subsequent persists scope correctly', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Shift all',
    ]);
    $seriesId = ssbSeriesId($this->db, $this->user->id);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startAddMutation')
        ->call('selectKind', 'shift_series_date')
        ->set('form.seriesId', $seriesId)
        ->set('form.newNextDate', '2026-06-02')
        ->set('form.scope', 'all_subsequent')
        ->call('saveAddMutation');

    $row = $this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenario->id)->first();
    $payload = json_decode((string) $row->payload, true);
    expect($payload['scope'])->toBe('all_subsequent');
});

it('Remove mutation flow deletes the row and dispatches toast', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'To remove',
    ]);
    $seriesId = ssbSeriesId($this->db, $this->user->id);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    $mutationId = ($add)($scenario->id, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('removeMutation', $mutationId)
        ->assertDispatched('toast');

    expect($this->db->connection()->table('forecast_scenario_mutations')->where('id', $mutationId)->count())->toBe(0);
});

it('Rename flow updates scenarioName and dispatches toast', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Old name',
    ]);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startRename')
        ->set('renameInput', 'New name')
        ->call('saveRename')
        ->assertSet('scenarioName', 'New name')
        ->assertDispatched('toast');

    expect($this->db->connection()->table('forecast_scenarios')->where('id', $scenario->id)->value('name'))->toBe('New name');
});

it('Delete scenario flow dispatches scenario-deleted and removes the row', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'To delete',
    ]);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('confirmDeleteScenario')
        ->call('deleteScenario')
        ->assertDispatched('scenario-deleted')
        ->assertDispatched('toast');

    expect($this->db->connection()->table('forecast_scenarios')->where('id', $scenario->id)->count())->toBe(0);
});

it('ScenarioEditorSidebar cross-user contract is locked at the ScenarioQuery::find layer', function (): void {
    // Livewire::test() does not propagate mount()'s NotFoundHttpException under
    // Livewire 4 here, so the lock sits on ScenarioQuery::find instead — the
    // null it returns is the exact condition mount() raises on.
    $other = ssbUser('m-other');
    /** @var ForecastScenario $otherScenario */
    $otherScenario = ForecastScenario::query()->create([
        'user_id' => $other->id,
        'name' => 'Cross-user scenario',
    ]);
    /** @var ScenarioQuery $sq */
    $sq = $this->app->make(ScenarioQuery::class);
    expect($sq->find($otherScenario->id, $this->user))->toBeNull();
});

it('kind/payload mismatch surfaces a form error inline (defensive contract)', function (): void {
    $this->actingAs($this->user);
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Mismatch sidebar',
    ]);

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startAddMutation')
        ->call('selectKind', 'cancel_series')
        // form.seriesId is deliberately left unset.
        ->call('saveAddMutation')
        // The label above the box, in the reader's language — never the array
        // key the form posts under, in English.
        ->assertSet('formError', Lang::get('forecasting::forecast.errors.field_required', [
            'field' => Lang::get('forecasting::scenario.form.series'),
        ]));
});
