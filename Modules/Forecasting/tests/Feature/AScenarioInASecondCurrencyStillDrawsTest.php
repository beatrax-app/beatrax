<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/forecasting/scenario-isolation.md */
const SSC_TODAY = '2026-08-23';

const SSC_HORIZON_DAYS = 30;

const SSC_OPENING_MINOR = 500_000;

const SSC_ONE_OFF_MINOR = -90_000;

const SSC_ONE_OFF_DATE = '2026-09-05';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(SSC_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'ssc',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
    $this->accountId = sscAccount($this->db, $this->user->id);
    sscSeries($this->db, $this->user->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function sscAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'SSC Bank',
        'slug' => 'ssc-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00SSC'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => SSC_OPENING_MINOR,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function sscSeries(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'SSC Rent',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => -100_000,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => -100_000,
        'variance_tolerance_percent' => 5,
        'cluster_key' => 'ssc::'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => 'ssc-rent',
        'next_expected_at' => '2026-09-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function sscScenarioWithOneOff(User $user, string $currency): int
{
    $scenarioId = (app(CreateScenario::class))($user, 'Bike in '.$currency);
    (app(AddScenarioMutation::class))(
        $scenarioId,
        $user,
        ScenarioMutationKind::AddOneOff->value,
        new AddOneOffPayload(
            date: SSC_ONE_OFF_DATE,
            amountMinor: SSC_ONE_OFF_MINOR,
            currency: $currency,
            direction: Direction::Expense->value,
        ),
    );

    return $scenarioId;
}

/**
 * @param  list<ForecastPointDto>  $points
 */
function sscPointOn(array $points, string $date): int
{
    foreach ($points as $point) {
        if ($point->date === $date) {
            return $point->pointMinor;
        }
    }

    throw new RuntimeException('No forecast point on '.$date);
}

// The mutation persisted, so every retry of the queued job re-crashed on it;
// the reader was never told, because a non-complete run falls through to the
// flat-line fallback and the scenario chart drew a straight line forever.
it('completes a projection whose scenario spends in a currency the account is not denominated in', function (): void {
    $scenarioId = sscScenarioWithOneOff($this->user, Currency::Usd->value);

    app(ProjectionPipeline::class)->project($this->user, $scenarioId, SSC_HORIZON_DAYS);

    $run = $this->db->connection()->table('forecast_runs')
        ->where('user_id', $this->user->id)
        ->where('scenario_id', $scenarioId)
        ->orderByDesc('id')
        ->first();

    expect($run?->status)->toBe(JobRunStatus::Complete->value);

    $points = app(ForecastQuery::class)->forUser($this->accountId, SSC_HORIZON_DAYS, $scenarioId, $this->user)->points;
    $dayBefore = CarbonImmutable::parse(SSC_ONE_OFF_DATE)->subDay()->toDateString();

    // Converted, not folded at face value: 900 US dollars is not 900 euros,
    // and it is not nothing either.
    $step = sscPointOn($points, SSC_ONE_OFF_DATE) - sscPointOn($points, $dayBefore);
    expect($step)->toBeLessThan(0)
        ->and($step)->not->toBe(SSC_ONE_OFF_MINOR);
});

it('spends the stated amount when the scenario currency is the account\'s own', function (): void {
    $scenarioId = sscScenarioWithOneOff($this->user, Currency::Eur->value);

    app(ProjectionPipeline::class)->project($this->user, $scenarioId, SSC_HORIZON_DAYS);

    $points = app(ForecastQuery::class)->forUser($this->accountId, SSC_HORIZON_DAYS, $scenarioId, $this->user)->points;
    $dayBefore = CarbonImmutable::parse(SSC_ONE_OFF_DATE)->subDay()->toDateString();

    expect(sscPointOn($points, SSC_ONE_OFF_DATE) - sscPointOn($points, $dayBefore))->toBe(SSC_ONE_OFF_MINOR);
});

it('says a run failed rather than passing its fallback line off as a projection', function (): void {
    $scenarioId = (app(CreateScenario::class))($this->user, 'Broken');

    $this->db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => $scenarioId,
        'horizon_days' => SSC_HORIZON_DAYS,
        'status' => JobRunStatus::Failed->value,
        'created_at' => SSC_TODAY.' 09:00:00',
        'updated_at' => SSC_TODAY.' 09:00:00',
    ]);

    $forecast = app(ForecastQuery::class)->forUser($this->accountId, SSC_HORIZON_DAYS, $scenarioId, $this->user);

    expect($forecast->runFailed)->toBeTrue()
        ->and($forecast->isComputing)->toBeFalse();
});

it('does not call a projection that never ran a failed one', function (): void {
    $forecast = app(ForecastQuery::class)->forUser($this->accountId, SSC_HORIZON_DAYS, null, $this->user);

    expect($forecast->runFailed)->toBeFalse();
});
