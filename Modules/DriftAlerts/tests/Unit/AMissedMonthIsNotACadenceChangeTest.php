<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Models\DriftAlert;

uses(RefreshDatabase::class);

// The prior amount is annualised at the rate the gap before it says it was
// billed at, and one gap only names a rate if nothing is missing from it. A
// monthly plan that skipped March posts 59 days apart, which fits a quarter
// better than a month, so a EUR 2.00 rise was priced as EUR 104.00 a year.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-05-19 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

dataset('gaps_with_a_period_missing', [
    'a monthly plan that skipped March' => [
        'cadence' => 'monthly',
        'observations' => [
            ['2026-02-15', -1000],
            ['2026-04-15', -1000],
            ['2026-05-15', -1200],
        ],
        'expectedAnnualizedMinor' => -2400,
    ],
    'a weekly plan that skipped two weeks' => [
        'cadence' => 'weekly',
        'observations' => [
            ['2026-04-24', -1000],
            ['2026-05-15', -1000],
            ['2026-05-22', -1100],
        ],
        'expectedAnnualizedMinor' => -5200,
    ],
]);

it('prices the year at the cadence the series still bills at', function (
    string $cadence,
    array $observations,
    int $expectedAnnualizedMinor,
): void {
    $user = amncUser('missed-'.$cadence);
    $seriesId = amncSeries($this->db, $user, $cadence, (int) $observations[2][1]);

    foreach ($observations as $observation) {
        amncOccurrence($this->db, $user->id, $seriesId, (string) $observation[0], (int) $observation[1]);
    }

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    /** @var DriftAlert $row */
    $row = DriftAlert::query()
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->firstOrFail();

    expect($row->annualized_impact_minor)->toBe($expectedAnnualizedMinor);
})->with('gaps_with_a_period_missing');

// The other side of the same read: a gap that genuinely names a different
// cadence still does. EUR 10.00 a month became EUR 100.00 a year, which is a
// EUR 20.00 saving and not a EUR 90.00 rise.
it('still reads a restructured plan at the rate its prior amount was billed at', function (): void {
    $user = amncUser('restructured-plan');
    $seriesId = amncSeries($this->db, $user, 'yearly', -10000);

    foreach ([['2024-10-15', -1000], ['2024-11-15', -1000], ['2025-11-15', -10000]] as $observation) {
        amncOccurrence($this->db, $user->id, $seriesId, $observation[0], $observation[1]);
    }

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    /** @var DriftAlert $row */
    $row = DriftAlert::query()
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->firstOrFail();

    expect($row->annualized_impact_minor)->toBe(2000);
});

function amncUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function amncSeries(DatabaseManager $db, User $user, string $cadence, int $latestMinor): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'missed-period-plan',
        'state' => 'approved',
        'cadence' => $cadence,
        'latest_amount_minor' => $latestMinor,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'missed-period|'.$cadence.'|'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function amncOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt, int $amountMinor): void
{
    static $counter = 0;
    $counter++;

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN missed',
        'slug' => 'amnc-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00AMNC'.str_pad((string) $counter, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/amnc-'.$counter.'.csv',
        'sha256' => str_pad('amnc'.$counter, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad('amnc'.$counter, 64, 'c', STR_PAD_LEFT),
        'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00',
        'value_date' => $observedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'missed-period-plan',
        'counterparty_name' => 'MISSED PERIOD PLAN',
        'normalization_version' => 1,
        'description' => 'missed period fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $counter,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $txId,
        'observed_at' => $observedAt,
        'observed_amount_minor' => $amountMinor,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}
