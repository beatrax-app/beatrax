<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\DailyFold;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;
use Modules\Forecasting\Internal\Pipeline\ScenarioApplier;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Enums\ShiftScope;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

// A stored shift whose target has since fallen behind today — the write side
// refuses one today, but time moves under a saved what-if — landed the charge
// in a bucket the fold's walk never reads. The EUR25.00 simply left the
// scenario, which then read EUR25.00 better off than the truth.

const ASITP_ASOF = '2026-08-23';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(ASITP_ASOF.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'asitp',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->scenarioId = app(CreateScenario::class)($this->user, 'Pay it early');

    $this->seriesId = (int) $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => Direction::Expense->value,
        'detected_name' => 'Gym',
        'state' => 'approved',
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => -2_500,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => -2_500,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-09-01',
        'cluster_key' => 'asitp-cluster',
        'cluster_counterparty_key' => 'Gym',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

// Written straight to the table: the Action refuses this date now, and the row
// this reproduces is one saved before today caught up with it.
function asitpStoreShift(DatabaseManager $db, int $userId, int $scenarioId, int $seriesId, string $newDate): void
{
    $mutation = new ForecastScenarioMutation;
    $mutation->user_id = $userId;
    $mutation->forecast_scenario_id = $scenarioId;
    $mutation->kind = ScenarioMutationKind::ShiftSeriesDate->value;
    $mutation->target_series_id = $seriesId;
    $mutation->payload = new ShiftSeriesDatePayload(
        seriesId: $seriesId,
        newNextDate: $newDate,
        scope: ShiftScope::Next->value,
    );
    $mutation->created_at = CarbonImmutable::now();
    $mutation->updated_at = CarbonImmutable::now();
    $mutation->save();
}

it('keeps the charge on the curve when the shift target has fallen behind today', function (): void {
    asitpStoreShift($this->db, $this->user->id, $this->scenarioId, $this->seriesId, '2026-08-10');

    $asOf = CarbonImmutable::parse(ASITP_ASOF);
    $baseline = [
        new ForecastContribution(
            date: $asOf->addDays(9),
            pointMinor: -2_500,
            lowMinor: -2_500,
            highMinor: -2_500,
            currency: Currency::Eur->value,
            seriesId: $this->seriesId,
            accountId: 1,
        ),
    ];

    $applied = app(ScenarioApplier::class)->apply($baseline, $this->scenarioId, $this->user, $asOf, 30);

    $curve = app(DailyFold::class)->fold(
        openingBalanceMinor: 100_000,
        contributions: $applied,
        asOf: $asOf,
        horizonDays: 30,
        defaultCurrency: Currency::Eur->value,
        rates: [],
    )->points;

    // The charge is still spent by the end of the horizon; the scenario is not
    // EUR25.00 better off than the truth.
    expect($curve[$asOf->addDays(30)->toDateString()]['point_minor'])->toBe(97_500)
        ->and($applied)->toHaveCount(1)
        ->and($applied[0]->pointMinor)->toBe(-2_500)
        // The contribution carries the day it lands on, rather than one the
        // fold has to rescue it from.
        ->and($applied[0]->date->toDateString())->toBe($asOf->toDateString());
});
