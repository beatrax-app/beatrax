<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Models\DriftAlert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-05-19 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('produces zero drift alerts for a stable USD series despite hypothetical EUR shadow drift', function (): void {
    $user = devfxUser('fx-invariant');

    $seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'us-streaming',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1199,
        'latest_currency' => 'USD',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'us-streaming|monthly|USD',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    // Identical in USD, the currency the cluster keys on. Any EUR movement is
    // FX noise the evaluator must not see.
    devfxOccurrence($this->db, $user->id, $seriesId, '2026-04-08', -1199, 'USD');
    devfxOccurrence($this->db, $user->id, $seriesId, '2026-05-08', -1199, 'USD');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    $alertCount = DriftAlert::query()
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->count();
    expect($alertCount)->toBe(0);
});

it('compares original currency only — a real USD drift fires while settled EUR stays unchanged', function (): void {
    $user = devfxUser('fx-real-usd-drift');

    $seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'us-streaming-drift',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1499,
        'latest_currency' => 'USD',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'us-streaming-drift|monthly|USD',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    devfxOccurrence($this->db, $user->id, $seriesId, '2026-04-08', -1199, 'USD');
    devfxOccurrence($this->db, $user->id, $seriesId, '2026-05-08', -1499, 'USD');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    /** @var DriftAlert $row */
    $row = DriftAlert::query()
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->firstOrFail();
    expect($row->currency)->toBe('USD');
    expect($row->delta_minor)->toBe(-300);
    expect($row->annualized_impact_minor)->toBe(-3600);
});

function devfxUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function devfxOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt, int $amountMinor, string $currency): int
{
    static $txCounter = 0;
    $txCounter++;
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN fx',
        'slug' => 'devfx-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00DEVFX'.str_pad((string) $txCounter, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/devfx-'.$txCounter.'.csv',
        'sha256' => str_pad('devfx'.$txCounter, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad('devfx'.$txCounter, 64, 'c', STR_PAD_LEFT),
        'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00',
        'value_date' => $observedAt,
        // Arbitrary: the evaluator reads observed_currency on the occurrence
        // row, never these settled-EUR shadow columns.
        'amount_minor' => -1100,
        'currency' => 'EUR',
        'settled_amount_minor' => -1100,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'us-fixture',
        'counterparty_name' => 'US FIXTURE',
        'normalization_version' => 1,
        'description' => 'drift eval fx fixture',
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
