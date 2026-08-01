<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Models\DriftAlert;

uses(RefreshDatabase::class);

/*
 * Effective threshold lookup for DriftEvaluator: per-series override
 * beats user-global setting beats the hard 5% default. The audit pair
 * (threshold_percent_used + threshold_source) is captured on the
 * resulting drift_alerts row so subsequent edits to either setting
 * never rewrite history.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-05-19 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('the per-series threshold override beats the user global setting', function (): void {
    $user = devetUser('series-override-wins');
    $user->drift_alert_threshold_percent = 10;
    $user->save();

    // recurring_series.drift_threshold_percent = 50 → 25% drift is below threshold → no alert.
    $seriesId = devetSeries($this->db, $user->id, driftThreshold: 50);
    devetOccurrence($this->db, $user->id, $seriesId, '2026-04-15', -12000, 'EUR');
    devetOccurrence($this->db, $user->id, $seriesId, '2026-05-15', -15000, 'EUR');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    expect(DriftAlert::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('falls back to the user-global setting when no per-series override exists', function (): void {
    $user = devetUser('user-global-fallback');
    $user->drift_alert_threshold_percent = 10;
    $user->save();

    // 25% drift is above the user-global 10% → alert fires with
    // threshold_percent_used=10 / threshold_source='global'.
    $seriesId = devetSeries($this->db, $user->id, driftThreshold: null);
    devetOccurrence($this->db, $user->id, $seriesId, '2026-04-15', -12000, 'EUR');
    devetOccurrence($this->db, $user->id, $seriesId, '2026-05-15', -15000, 'EUR');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    /** @var DriftAlert $row */
    $row = DriftAlert::query()->where('user_id', $user->id)->firstOrFail();
    expect($row->threshold_percent_used)->toBe(10);
    expect($row->threshold_source)->toBe('global');
});

it('falls back to the hard 5 default when neither series override nor user global apply', function (): void {
    $user = devetUser('hard-default');
    // User model attribute default is already 5, but we set it to 0 here
    // so the evaluator must specifically rely on the hard floor.
    $user->drift_alert_threshold_percent = 0;
    $user->save();

    // 6% drift > 5% default → alert fires with threshold_percent_used=5
    // and threshold_source='default'. The 'default' label distinguishes
    // the hard floor from a user-set global value so the audit trail
    // and renderer can tell them apart.
    $seriesId = devetSeries($this->db, $user->id, driftThreshold: null);
    devetOccurrence($this->db, $user->id, $seriesId, '2026-04-15', -10000, 'EUR');
    devetOccurrence($this->db, $user->id, $seriesId, '2026-05-15', -10600, 'EUR');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    /** @var DriftAlert $row */
    $row = DriftAlert::query()->where('user_id', $user->id)->firstOrFail();
    expect($row->threshold_percent_used)->toBe(5);
    expect($row->threshold_source)->toBe('default');
});

function devetUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function devetSeries(DatabaseManager $db, int $userId, ?int $driftThreshold): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'fixture-merchant',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1499,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'fixture|monthly|EUR|'.bin2hex(random_bytes(3)),
        'drift_threshold_percent' => $driftThreshold,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function devetOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt, int $amountMinor, string $currency): int
{
    static $txCounter = 0;
    $txCounter++;
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN et',
        'slug' => 'devet-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00DEVET'.str_pad((string) $txCounter, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/devet-'.$txCounter.'.csv',
        'sha256' => str_pad('devet'.$txCounter, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad('devet'.$txCounter, 64, 'c', STR_PAD_LEFT),
        'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00',
        'value_date' => $observedAt,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_normalized' => 'threshold-fixture',
        'counterparty_name' => 'THRESHOLD FIXTURE',
        'normalization_version' => 1,
        'description' => 'drift eval threshold fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $txCounter,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $txId,
        'observed_at' => $observedAt,
        'observed_amount_minor' => $amountMinor,
        'observed_currency' => $currency,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}
