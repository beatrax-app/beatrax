<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Enums\ShiftScope;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/forecasting/projection-math.md#cadence-jitter */
const SVN_TODAY = '2026-08-23';

const SVN_OPENING_MINOR = 500_000;

const SVN_BILL_MINOR = -15_500;

const SVN_BILL_DATE = '2026-09-01';

// 23 August + 60 days reaches 22 October, so the walk emits 1 September and
// 1 October — enough for a ShiftScope::Next shift to have a second occurrence
// it must leave alone.
const SVN_HORIZON_DAYS = 60;

// Both bars for the percentile tier, which is the only tier CadenceJitter
// smears and so the only one this defect could reach.
const SVN_HIGH_VARIANCE_PERCENT = 50;

const SVN_OCCURRENCES = 6;

// Seven replicas each rounded to a whole minor unit do not re-sum to the
// original amount; the doc accepts up to a unit per replica.
const SVN_ROUNDING_SLACK_MINOR = 10;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(SVN_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'svn',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
    $this->accountId = svnAccount($this->db, $this->user->id);
    $this->seriesId = svnVariableBill($this->db, $this->user->id, $this->accountId);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function svnAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'SVN Bank',
        'slug' => 'svn-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00SVN'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => SVN_OPENING_MINOR,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function svnTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt): int
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/svn-'.$hex.'.csv',
        'sha256' => hash('sha256', 'svn-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'svn-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => SVN_BILL_MINOR,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => SVN_BILL_MINOR,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'stedin-energie',
        'counterparty_name' => 'Stedin Energie',
        'normalization_version' => 1,
        'description' => 'svn fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function svnVariableBill(DatabaseManager $db, int $userId, int $accountId): int
{
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Stedin Energie',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => SVN_BILL_MINOR,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => SVN_BILL_MINOR,
        'variance_tolerance_percent' => SVN_HIGH_VARIANCE_PERCENT,
        'cluster_key' => 'svn::'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => 'stedin-energie',
        'next_expected_at' => SVN_BILL_DATE,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    for ($month = 1; $month <= SVN_OCCURRENCES; $month++) {
        $observedAt = CarbonImmutable::parse(SVN_BILL_DATE)->subMonthsNoOverflow($month)->toDateString();
        $transactionId = svnTransaction($db, $userId, $accountId, $observedAt);

        $db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $userId,
            'recurring_series_id' => $seriesId,
            'transaction_id' => $transactionId,
            'observed_at' => $observedAt,
            'observed_amount_minor' => SVN_BILL_MINOR,
            'observed_currency' => Currency::Eur->value,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    return $seriesId;
}

/**
 * @param  list<ForecastPointDto>  $points
 */
function svnPointOn(array $points, string $date): int
{
    foreach ($points as $point) {
        if ($point->date === $date) {
            return $point->pointMinor;
        }
    }

    throw new RuntimeException('No forecast point on '.$date);
}

/**
 * @return list<ForecastPointDto>
 */
function svnProject(User $user, int $accountId, ?int $scenarioId): array
{
    app(ProjectionPipeline::class)->project($user, $scenarioId, SVN_HORIZON_DAYS);

    return app(ForecastQuery::class)->forUser($accountId, SVN_HORIZON_DAYS, $scenarioId, $user)->points;
}

// Jitter used to run inside RangeProjector, so by the time a mutation saw the
// series it was seven replicas rather than one occurrence. Rewriting each of
// them to the full new amount charged the bill seven times: modelling a EUR155
// bill at its own current EUR155 wiped EUR930 off the day-30 balance.
it('leaves the projected balance where it was when a variable bill is modelled at the amount it already is', function (): void {
    $baseline = svnProject($this->user, $this->accountId, null);

    $scenarioId = (app(CreateScenario::class))($this->user, 'Same amount');
    (app(AddScenarioMutation::class))(
        $scenarioId,
        $this->user,
        ScenarioMutationKind::ChangeSeriesAmount->value,
        new ChangeSeriesAmountPayload(seriesId: $this->seriesId, newAmountMinor: abs(SVN_BILL_MINOR)),
    );

    $scenario = svnProject($this->user, $this->accountId, $scenarioId);

    $horizonEnd = CarbonImmutable::parse(SVN_TODAY)->addDays(SVN_HORIZON_DAYS)->toDateString();

    expect(abs(svnPointOn($scenario, $horizonEnd) - svnPointOn($baseline, $horizonEnd)))
        ->toBeLessThanOrEqual(SVN_ROUNDING_SLACK_MINOR);
});

// The same defect through the other mutation that selects one occurrence:
// with replicas in the list the "earliest" entry was the first of seven, so
// shifting a EUR155 bill moved EUR22 and left the other six behind.
it('moves the whole of the next occurrence when a bill is shifted, not one seventh of it', function (): void {
    $baseline = svnProject($this->user, $this->accountId, null);

    $scenarioId = (app(CreateScenario::class))($this->user, 'A week later');
    (app(AddScenarioMutation::class))(
        $scenarioId,
        $this->user,
        ScenarioMutationKind::ShiftSeriesDate->value,
        new ShiftSeriesDatePayload(
            seriesId: $this->seriesId,
            newNextDate: '2026-09-08',
            scope: ShiftScope::Next->value,
        ),
    );

    $scenario = svnProject($this->user, $this->accountId, $scenarioId);

    // 4 September is the last day of the un-shifted bill's ±3-day window and
    // the day before the shifted one's first: the whole charge has landed in
    // the baseline and none of it in the scenario.
    $afterOriginalWindow = '2026-09-04';
    $todayMinor = svnPointOn($baseline, SVN_TODAY);

    expect(abs(svnPointOn($baseline, $afterOriginalWindow) - ($todayMinor + SVN_BILL_MINOR)))
        ->toBeLessThanOrEqual(SVN_ROUNDING_SLACK_MINOR)
        ->and(abs(svnPointOn($scenario, $afterOriginalWindow) - $todayMinor))
        ->toBeLessThanOrEqual(SVN_ROUNDING_SLACK_MINOR);

    // October's occurrence is untouched, so both curves end the horizon on the
    // same balance.
    $horizonEnd = CarbonImmutable::parse(SVN_TODAY)->addDays(SVN_HORIZON_DAYS)->toDateString();
    expect(abs(svnPointOn($scenario, $horizonEnd) - svnPointOn($baseline, $horizonEnd)))
        ->toBeLessThanOrEqual(SVN_ROUNDING_SLACK_MINOR);
});
