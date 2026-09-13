<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ScenarioEditorSidebar;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Services\ScenarioQuery;

uses(RefreshDatabase::class);

function editBoxUser(string $username = 'editbox'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function editBoxSeries(DatabaseManager $db, int $userId, string $currency = 'EUR', int $amountMinor = -1199): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Series '.$currency,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => $currency,
        'monthly_equivalent_minor' => $amountMinor,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-06-25',
        'cluster_key' => 'editbox-'.$currency.'-'.$userId,
        'cluster_counterparty_key' => 'Series '.$currency,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

/**
 * @return array{0: ForecastScenario, 1: AddScenarioMutation}
 */
function editBoxScenario(User $user, string $name = 'Edit box'): array
{
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create(['user_id' => $user->id, 'name' => $name]);
    /** @var AddScenarioMutation $add */
    $add = app(AddScenarioMutation::class);

    return [$scenario, $add];
}

/**
 * @return array<string, mixed>
 */
function editBoxFirstMutation(Testable $component): array
{
    $mutations = $component->get('mutations');
    expect($mutations)->toHaveCount(1);

    return $mutations[0];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-19 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = editBoxUser();
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

// The three amount-bearing kinds. Before this was fixed the stored payload
// reached $form under amountMinor / newAmountMinor while the boxes are bound to
// amount / newAmount, so the box rendered empty over a populated payload and
// every save answered "Amount is required."
it('opens a change-amount what-if with the stored figure in the box it is read back from', function (): void {
    $seriesId = editBoxSeries($this->db, $this->user->id);
    [$scenario, $add] = editBoxScenario($this->user);
    ($add)($scenario->id, $this->user, 'change_series_amount', new ChangeSeriesAmountPayload(seriesId: $seriesId, newAmountMinor: 50000));

    $component = Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id]);
    $component->call('editMutation', editBoxFirstMutation($component)['id']);

    expect($component->get('form'))->toEqual(['seriesId' => $seriesId, 'newAmount' => '500.00']);
});

it('opens a one-off what-if with the stored figure in the box it is read back from', function (): void {
    [$scenario, $add] = editBoxScenario($this->user);
    ($add)($scenario->id, $this->user, 'add_one_off', new AddOneOffPayload(
        date: '2026-06-10', amountMinor: 2500, currency: 'EUR', direction: 'expense', note: 'lunch',
    ));

    $component = Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id]);
    $component->call('editMutation', editBoxFirstMutation($component)['id']);

    expect($component->get('form'))->toEqual([
        'currency' => 'EUR', 'date' => '2026-06-10', 'direction' => 'expense', 'note' => 'lunch', 'amount' => '25.00',
    ]);
});

it('opens a recurring what-if with the stored figure in the box it is read back from', function (): void {
    [$scenario, $add] = editBoxScenario($this->user);
    ($add)($scenario->id, $this->user, 'add_recurring', new AddRecurringPayload(
        startDate: '2026-06-10', amountMinor: 1500, currency: 'EUR', direction: 'expense', cadence: 'monthly', note: null,
    ));

    $component = Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id]);
    $component->call('editMutation', editBoxFirstMutation($component)['id']);

    expect($component->get('form')['amount'])->toBe('15.00');
});

// Saving an untouched edit form has to leave the stored figure alone. It is the
// same box either way: whatever is rendered into it is what AmountStringParser
// reads back, so a rendering at the wrong scale is a silent repricing.
it('saves an untouched edit form back at the figure it was opened with', function (): void {
    $seriesId = editBoxSeries($this->db, $this->user->id);
    [$scenario, $add] = editBoxScenario($this->user);
    ($add)($scenario->id, $this->user, 'change_series_amount', new ChangeSeriesAmountPayload(seriesId: $seriesId, newAmountMinor: 50000));

    $component = Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id]);
    $component->call('editMutation', editBoxFirstMutation($component)['id']);
    $component->call('saveEditMutation');

    expect($component->get('formError'))->toBeNull()
        ->and($component->get('editingMutationId'))->toBeNull();

    /** @var ScenarioQuery $query */
    $query = $this->app->make(ScenarioQuery::class);
    $stored = iterator_to_array($query->mutationsFor($scenario->id, $this->user));
    expect($stored)->toHaveCount(1)
        ->and($stored[0]->payload->toArray()['newAmountMinor'])->toBe(50000);
});

// A yen has no minor unit. Rendered on the repo-wide two decimals a 135,000 yen
// new amount reads as "1.350,00", and saving that box back stores a hundredth
// of the figure the reader never changed.
it('opens a yen series at the yen scale and saves it back unchanged', function (): void {
    $seriesId = editBoxSeries($this->db, $this->user->id, 'JPY', -135000);
    [$scenario, $add] = editBoxScenario($this->user);
    ($add)($scenario->id, $this->user, 'change_series_amount', new ChangeSeriesAmountPayload(seriesId: $seriesId, newAmountMinor: 135000));

    $component = Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id]);
    $component->call('editMutation', editBoxFirstMutation($component)['id']);
    expect($component->get('form')['newAmount'])->toBe('135,000');

    $component->call('saveEditMutation');
    expect($component->get('formError'))->toBeNull();

    /** @var ScenarioQuery $query */
    $query = $this->app->make(ScenarioQuery::class);
    $stored = iterator_to_array($query->mutationsFor($scenario->id, $this->user));
    expect($stored[0]->payload->toArray()['newAmountMinor'])->toBe(135000);
});

// The minor figure is not a form key, so it must not ride along in a public
// Livewire property under a name the form never posts back.
it('leaves no minor-unit key in the form the browser is handed', function (): void {
    [$scenario, $add] = editBoxScenario($this->user);
    ($add)($scenario->id, $this->user, 'add_one_off', new AddOneOffPayload(
        date: '2026-06-10', amountMinor: 2500, currency: 'EUR', direction: 'expense',
    ));

    $component = Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id]);
    $component->call('editMutation', editBoxFirstMutation($component)['id']);

    expect(array_keys($component->get('form')))
        ->not->toContain('amountMinor')
        ->not->toContain('newAmountMinor');
});

// The two kinds that carry no amount were already editable. They are here so
// the translation above cannot start inventing a box for them.
it('leaves the two amountless kinds exactly as they were stored', function (): void {
    $seriesId = editBoxSeries($this->db, $this->user->id);
    [$scenario, $add] = editBoxScenario($this->user);
    ($add)($scenario->id, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    $component = Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id]);
    $component->call('editMutation', editBoxFirstMutation($component)['id']);
    expect($component->get('form'))->toEqual(['seriesId' => $seriesId]);

    [$second, $addSecond] = editBoxScenario($this->user, 'Edit box, shifted');
    ($addSecond)($second->id, $this->user, 'shift_series_date', new ShiftSeriesDatePayload(
        seriesId: $seriesId, newNextDate: '2026-07-02', scope: 'next',
    ));

    $other = Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $second->id]);
    $other->call('editMutation', editBoxFirstMutation($other)['id']);
    expect($other->get('form'))->toEqual(['seriesId' => $seriesId, 'newNextDate' => '2026-07-02', 'scope' => 'next']);
});
