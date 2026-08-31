<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\EditScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Enums\ShiftScope;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

// A mutation dated where no horizon reaches was saved, listed as active beside
// the ones that work, and changed nothing at 30, 60, 90, 180 or 365 days.
// Silently inert is the one outcome that leaves the reader nothing to read.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'whnh',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->scenarioId = app(CreateScenario::class)($this->user, 'Out of reach');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function whnhOutOfRangeMessage(): string
{
    $lastDay = ForecastHorizon::longestDays();

    return Lang::choice('forecasting::scenario.errors.date_out_of_range', $lastDay, ['days' => $lastDay]);
}

function whnhSeriesId(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => Direction::Expense->value,
        'detected_name' => 'Netflix',
        'state' => 'approved',
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => -1199,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => -1199,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-09-01',
        'cluster_key' => 'whnh-cluster',
        'cluster_counterparty_key' => 'Netflix',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function whnhOneOff(string $date): AddOneOffPayload
{
    return new AddOneOffPayload(
        date: $date,
        amountMinor: 2_500,
        currency: Currency::Eur->value,
        direction: Direction::Expense->value,
    );
}

it('refuses a one-off dated past the longest horizon, with the reason', function (): void {
    $add = fn (): int => app(AddScenarioMutation::class)(
        $this->scenarioId,
        $this->user,
        ScenarioMutationKind::AddOneOff->value,
        whnhOneOff(CarbonImmutable::now()->addDays(ForecastHorizon::longestDays() + 1)->toDateString()),
    );

    expect($add)->toThrow(InvalidArgumentException::class, whnhOutOfRangeMessage());
    expect($this->db->connection()->table('forecast_scenario_mutations')->count())->toBe(0);
});

it('refuses a one-off dated before today, which no horizon reaches either', function (): void {
    $add = fn (): int => app(AddScenarioMutation::class)(
        $this->scenarioId,
        $this->user,
        ScenarioMutationKind::AddOneOff->value,
        whnhOneOff(CarbonImmutable::now()->subDay()->toDateString()),
    );

    expect($add)->toThrow(InvalidArgumentException::class, whnhOutOfRangeMessage());
    expect($this->db->connection()->table('forecast_scenario_mutations')->count())->toBe(0);
});

// The bound is the LONGEST horizon, not the one the page opens on: a one-off
// 200 days out is inert at 30 and real at 365.
it('accepts a one-off past the default horizon but inside the longest one', function (): void {
    $id = app(AddScenarioMutation::class)(
        $this->scenarioId,
        $this->user,
        ScenarioMutationKind::AddOneOff->value,
        whnhOneOff(CarbonImmutable::now()->addDays(200)->toDateString()),
    );

    expect($id)->toBeGreaterThan(0);
});

it('refuses a shift whose new date no horizon reaches', function (): void {
    $seriesId = whnhSeriesId($this->db, $this->user->id);

    $add = fn (): int => app(AddScenarioMutation::class)(
        $this->scenarioId,
        $this->user,
        ScenarioMutationKind::ShiftSeriesDate->value,
        new ShiftSeriesDatePayload(
            seriesId: $seriesId,
            newNextDate: CarbonImmutable::now()->addDays(ForecastHorizon::longestDays() + 30)->toDateString(),
            scope: ShiftScope::Next->value,
        ),
    );

    expect($add)->toThrow(InvalidArgumentException::class, whnhOutOfRangeMessage());
});

// A recurring START may sit behind today; the occurrence walk steps over the
// past ones and the later ones still land on the curve.
it('keeps a recurring mutation whose start date precedes today', function (): void {
    $id = app(AddScenarioMutation::class)(
        $this->scenarioId,
        $this->user,
        ScenarioMutationKind::AddRecurring->value,
        new AddRecurringPayload(
            startDate: CarbonImmutable::now()->subMonthsNoOverflow(3)->toDateString(),
            amountMinor: 1_000,
            currency: Currency::Eur->value,
            direction: Direction::Expense->value,
            cadence: SeriesCadence::Monthly->value,
        ),
    );

    expect($id)->toBeGreaterThan(0);
});

it('refuses the same date on an edit, not only on the first save', function (): void {
    $id = app(AddScenarioMutation::class)(
        $this->scenarioId,
        $this->user,
        ScenarioMutationKind::AddOneOff->value,
        whnhOneOff(CarbonImmutable::now()->addDays(10)->toDateString()),
    );

    $edit = fn (): mixed => app(EditScenarioMutation::class)(
        $id,
        $this->user,
        whnhOneOff(CarbonImmutable::now()->addYearsNoOverflow(3)->toDateString()),
    );

    expect($edit)->toThrow(InvalidArgumentException::class, whnhOutOfRangeMessage());
});
