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
 * Series in state='cadence_changed' must still produce drift alerts —
 * a series whose detector cadence flipped is awaiting user re-approval
 * but the underlying amount drift is independently actionable signal.
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

it('fires a drift alert for a series in state cadence_changed with a +10% drift', function (): void {
    $user = devccUser('cadence-changed');

    $seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'cadence-changed-streamer',
        'state' => 'cadence_changed',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cadence-changed-streamer|monthly|EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    devccOccurrence($this->db, $user->id, $seriesId, '2026-04-15', -999, 'EUR');
    devccOccurrence($this->db, $user->id, $seriesId, '2026-05-15', -1099, 'EUR');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    /** @var DriftAlert $row */
    $row = DriftAlert::query()
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->firstOrFail();
    expect($row->state)->toBe('open');
    expect($row->direction)->toBe('expense');
    expect($row->delta_minor)->toBe(-100);
    expect($row->annualized_impact_minor)->toBe(-1200);
});

function devccUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function devccOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt, int $amountMinor, string $currency): int
{
    static $txCounter = 0;
    $txCounter++;
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN cc',
        'slug' => 'devcc-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00DEVCC'.str_pad((string) $txCounter, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/devcc-'.$txCounter.'.csv',
        'sha256' => str_pad('devcc'.$txCounter, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad('devcc'.$txCounter, 64, 'c', STR_PAD_LEFT),
        'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00',
        'value_date' => $observedAt,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_normalized' => 'cadence-changed-streamer',
        'counterparty_name' => 'CADENCE CHANGED STREAMER',
        'normalization_version' => 1,
        'description' => 'drift eval cc fixture',
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
