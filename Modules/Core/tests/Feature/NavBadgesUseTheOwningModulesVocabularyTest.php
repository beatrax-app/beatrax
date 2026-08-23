<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\NavCountsService;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Public\Enums\RecurringSeriesState;

// The badge predicates name states and directions that other modules own and
// store. Written as bare strings they drift silently: a renamed case leaves the
// query syntactically fine, the count comes back 0, and a badge that has simply
// stopped counting looks identical to one with nothing to count.

beforeEach(function (): void {
    $this->reader = User::query()->create([
        'username' => 'nav-vocabulary-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

function navSeries(int $userId, RecurringSeriesState $state, Direction $direction, string $key): int
{
    return (int) DB::table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => $direction->value,
        'detected_name' => 'Series '.$key,
        'state' => $state->value,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1200,
        'latest_currency' => Currency::Eur->value,
        'variance_tolerance_percent' => 25,
        'next_expected_confidence_low' => 0,
        'cluster_key' => 'nav::'.$key,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

// drift_alerts.latest_occurrence_id is a real foreign key, so an alert needs a
// transaction and an occurrence behind it before its state can be asserted.
function navOccurrence(int $userId, int $seriesId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Nav fixture',
        'slug' => 'nav-'.$suffix,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nav-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'nav-run-'.$suffix),
        'uploaded_at' => '2026-05-01 00:00:00',
        'status' => ImportRunStatus::Previewed->value,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $transactionId = DB::table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'nav-'.$suffix),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1149,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => -1149,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'description' => 'nav fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    return (int) DB::table('recurring_series_occurrences')->insertGetId([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $transactionId,
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => Currency::Eur->value,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function navDriftAlert(int $userId, int $seriesId, DriftAlertState $state, ?string $snoozedUntil = null): void
{
    DB::table('drift_alerts')->insert([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'state' => $state->value,
        'direction' => Direction::Expense->value,
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -1149,
        'currency' => Currency::Eur->value,
        'delta_minor' => -150,
        'annualized_impact_minor' => -1800,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => navOccurrence($userId, $seriesId),
        'snoozed_until' => $snoozedUntil,
        'detected_at' => '2026-05-01 00:00:00',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

it('counts a drift alert stored under the state the owning module names', function (): void {
    $series = navSeries($this->reader->id, RecurringSeriesState::Approved, Direction::Expense, 'drift-open');
    navDriftAlert($this->reader->id, $series, DriftAlertState::Open);

    expect(app(NavCountsService::class)->forUser($this->reader->id)['drift'])->toBe(1);
});

// A snooze whose deadline has passed is back on the page, so the badge counts
// it. The revived branch reads the same vocabulary and would fail the same way.
it('counts a snoozed alert whose deadline has already passed', function (): void {
    $series = navSeries($this->reader->id, RecurringSeriesState::Approved, Direction::Expense, 'drift-revived');
    navDriftAlert($this->reader->id, $series, DriftAlertState::Snoozed, '2020-01-01 00:00:00');

    expect(app(NavCountsService::class)->forUser($this->reader->id)['drift'])->toBe(1);
});

it('leaves a still-sleeping snooze out of the badge', function (): void {
    $series = navSeries($this->reader->id, RecurringSeriesState::Approved, Direction::Expense, 'drift-asleep');
    navDriftAlert($this->reader->id, $series, DriftAlertState::Snoozed, '2099-01-01 00:00:00');

    expect(app(NavCountsService::class)->forUser($this->reader->id)['drift'])->toBe(0);
});

it('counts exactly the two series states the badge calls active', function (): void {
    navSeries($this->reader->id, RecurringSeriesState::Approved, Direction::Expense, 'a');
    navSeries($this->reader->id, RecurringSeriesState::CadenceChanged, Direction::Expense, 'b');
    navSeries($this->reader->id, RecurringSeriesState::Pending, Direction::Expense, 'c');
    navSeries($this->reader->id, RecurringSeriesState::Rejected, Direction::Expense, 'd');

    expect(app(NavCountsService::class)->forUser($this->reader->id)['recurring'])->toBe(2);
});

// Subscriptions are the expense half of the same table, so this pins the
// direction vocabulary as well as the state vocabulary.
it('counts only the expense direction as a subscription', function (): void {
    navSeries($this->reader->id, RecurringSeriesState::Approved, Direction::Expense, 'sub-expense');
    navSeries($this->reader->id, RecurringSeriesState::Approved, Direction::Income, 'sub-income');

    $counts = app(NavCountsService::class)->forUser($this->reader->id);

    expect($counts['subscriptions'])->toBe(1)
        ->and($counts['recurring'])->toBe(2);
});

// The tables carry CHECK triggers naming their own vocabulary. If an enum case
// ever stops matching, the insert aborts here rather than in a badge that has
// quietly gone to zero.
it('stores every case of each enum the badges read', function (): void {
    foreach (RecurringSeriesState::cases() as $index => $state) {
        navSeries($this->reader->id, $state, Direction::Expense, 'vocab-'.$index);
    }

    $series = navSeries($this->reader->id, RecurringSeriesState::Approved, Direction::Income, 'vocab-drift');

    foreach (DriftAlertState::cases() as $state) {
        navDriftAlert($this->reader->id, $series, $state);
    }

    expect(DB::table('recurring_series')->where('user_id', $this->reader->id)->count())
        ->toBe(count(RecurringSeriesState::cases()) + 1)
        ->and(DB::table('drift_alerts')->where('user_id', $this->reader->id)->count())
        ->toBe(count(DriftAlertState::cases()));
});
