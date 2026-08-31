<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ScenarioEditorSidebar;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-19 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = User::query()->create([
        'username' => 'yen-scenario',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);

    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Tokyo',
    ]);
    $this->scenarioId = $scenario->id;

    $this->seriesId = (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Tokyo rent',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => -120_000,
        'latest_currency' => Currency::Jpy->value,
        'monthly_equivalent_minor' => -120_000,
        'variance_tolerance_percent' => 5,
        'cluster_key' => 'yen-scenario-rent',
        'cluster_counterparty_key' => 'tokyo-rent',
        'next_expected_at' => '2026-06-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('prints a one-off yen mutation in whole yen', function (): void {
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    ($add)($this->scenarioId, $this->user, ScenarioMutationKind::AddOneOff->value, new AddOneOffPayload(
        date: '2026-06-10',
        amountMinor: 128_000,
        currency: Currency::Jpy->value,
        direction: Direction::Expense->value,
    ));

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $this->scenarioId])
        ->assertSee('128,000 JPY')
        ->assertDontSee('1,280.00 JPY');
});

it('prints a recurring yen mutation in whole yen', function (): void {
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    ($add)($this->scenarioId, $this->user, ScenarioMutationKind::AddRecurring->value, new AddRecurringPayload(
        startDate: '2026-06-10',
        amountMinor: 128_000,
        currency: Currency::Jpy->value,
        direction: Direction::Expense->value,
        cadence: SeriesCadence::Monthly->value,
    ));

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $this->scenarioId])
        ->assertSee('128,000 JPY')
        ->assertDontSee('1,280.00 JPY');
});

it('prints a new amount for a yen series in whole yen', function (): void {
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    ($add)($this->scenarioId, $this->user, ScenarioMutationKind::ChangeSeriesAmount->value, new ChangeSeriesAmountPayload(
        seriesId: $this->seriesId,
        newAmountMinor: 135_000,
    ));

    Livewire::test(ScenarioEditorSidebar::class, ['scenarioId' => $this->scenarioId])
        ->assertSee('new amount 135,000')
        ->assertDontSee('new amount 1,350.00');
});
