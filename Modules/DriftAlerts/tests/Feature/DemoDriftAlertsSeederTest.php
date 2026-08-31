<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Database\Seeders\Demo\DemoDriftAlertsSeeder;
use Modules\DriftAlerts\Models\DriftAlert;

uses(RefreshDatabase::class);

// A demo dataset that contradicts itself teaches the reader the app is broken.
// The shipped one did: /drift and /drift/watch were EUR 4.00 apart about
// Spotify, every alert stamped `global, 10` at a user whose global threshold
// is 5, one alert sat on a rejected series naming a EUR 1.50 payment fee, and
// the prior price every alert named was one no charge in the ledger carried.
function ddsUser(int $thresholdPercent = 5): User
{
    return User::query()->create([
        'username' => 'demo-drift-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'drift_alert_threshold_percent' => $thresholdPercent,
    ]);
}

function ddsSeries(DatabaseManager $db, User $user, string $clusterKey, string $state, int $latestMinor): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $clusterKey,
        'state' => $state,
        'cadence' => 'monthly',
        'latest_amount_minor' => $latestMinor,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $latestMinor,
        'variance_tolerance_percent' => 25,
        'cluster_key' => $clusterKey,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function ddsOccurrence(DatabaseManager $db, User $user, int $seriesId, string $observedAt, int $amountMinor): int
{
    static $i = 0;
    $i++;
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'dds-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00DDSX'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'demo', 'raw_file_path' => '/tmp/dds-'.$i.'.csv',
        'sha256' => hash('sha256', 'dds-'.$i.bin2hex(random_bytes(4))), 'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed', 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'dds-tx-'.$i.bin2hex(random_bytes(4))),
        'posted_at' => $observedAt, 'booked_at' => $observedAt.' 00:00:00', 'value_date' => $observedAt,
        'amount_minor' => $amountMinor, 'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'dds', 'counterparty_name' => 'DDS', 'normalization_version' => 1,
        'description' => 'dds fixture', 'type' => 'expense', 'source_format' => 'demo',
        'source_row_index' => $i, 'fingerprint_version' => 3,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    return (int) $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
        'observed_at' => $observedAt, 'observed_amount_minor' => $amountMinor, 'observed_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
}

// The unrelated charge the shipped seeder reached for by description.
function ddsFeeTransaction(DatabaseManager $db, User $user): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'PayPal', 'slug' => 'dds-pp-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00DDSFEE'.strtoupper(bin2hex(random_bytes(3))), 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'demo', 'raw_file_path' => '/tmp/dds-fee.csv',
        'sha256' => hash('sha256', 'dds-fee-'.bin2hex(random_bytes(4))), 'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed', 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'dds-fee-tx-'.bin2hex(random_bytes(4))),
        'posted_at' => '2026-06-13', 'booked_at' => '2026-06-13 00:00:00', 'value_date' => '2026-06-13',
        'amount_minor' => -150, 'currency' => 'EUR',
        'settled_amount_minor' => -150, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'paypal', 'counterparty_name' => 'PAYPAL', 'normalization_version' => 1,
        'description' => 'PayPal conversion fee', 'type' => 'fee', 'source_format' => 'demo',
        'source_row_index' => 99, 'fingerprint_version' => 3,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function ddsRun(User $user): void
{
    app(DemoDriftAlertsSeeder::class)->run(['demo-1' => $user]);
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
});

it('names the two occurrences the price stepped between, so both drift surfaces agree', function (): void {
    $user = ddsUser();
    $seriesId = ddsSeries($this->db, $user, 'demo:spotify:monthly:1099', 'approved', -1099);
    ddsOccurrence($this->db, $user, $seriesId, '2026-06-11', -999);
    ddsOccurrence($this->db, $user, $seriesId, '2026-07-11', -999);
    $steppedId = ddsOccurrence($this->db, $user, $seriesId, '2026-08-11', -1099);

    ddsRun($user);

    /** @var DriftAlert $alert */
    $alert = DriftAlert::query()->where('recurring_series_id', $seriesId)->firstOrFail();

    expect($alert->latest_occurrence_id)->toBe($steppedId)
        ->and($alert->baseline_amount_minor)->toBe(-999)
        ->and($alert->latest_amount_minor)->toBe(-1099)
        ->and($alert->currency)->toBe('EUR')
        ->and($alert->detected_at?->toDateString())->toBe('2026-08-11');
});

// The shipped seeder read the prior price off a constant, so every alert
// claimed a rise the transaction list flatly denied: all three Spotify charges
// were EUR 10.99.
it('writes no alert for a series charged the same amount every period', function (): void {
    $user = ddsUser();
    $seriesId = ddsSeries($this->db, $user, 'demo:spotify:monthly:1099', 'approved', -1099);
    ddsOccurrence($this->db, $user, $seriesId, '2026-06-11', -1099);
    ddsOccurrence($this->db, $user, $seriesId, '2026-07-11', -1099);
    ddsOccurrence($this->db, $user, $seriesId, '2026-08-11', -1099);

    ddsRun($user);

    expect(DriftAlert::query()->where('recurring_series_id', $seriesId)->count())->toBe(0);
});

it('stamps the threshold the user actually has, with the source that named it', function (): void {
    $user = ddsUser(5);
    $seriesId = ddsSeries($this->db, $user, 'demo:spotify:monthly:1099', 'approved', -1099);
    ddsOccurrence($this->db, $user, $seriesId, '2026-06-11', -999);
    ddsOccurrence($this->db, $user, $seriesId, '2026-07-11', -1099);

    ddsRun($user);

    /** @var DriftAlert $alert */
    $alert = DriftAlert::query()->where('recurring_series_id', $seriesId)->firstOrFail();

    expect($alert->threshold_percent_used)->toBe(5)
        ->and($alert->threshold_source)->toBe('global');
});

// The rejected series had no charge of its own in the demo ledger, so the
// seeder attached the newest row carrying a name it had been handed — a EUR
// 1.50 PayPal fee — and opened an alert claiming EUR 6.99 against it.
it('writes no alert against a series the evaluator refuses by design', function (): void {
    $user = ddsUser();
    $seriesId = ddsSeries($this->db, $user, 'demo:nordvpn:monthly:499', 'rejected', -499);
    ddsFeeTransaction($this->db, $user);

    ddsRun($user);

    expect(DriftAlert::query()->where('recurring_series_id', $seriesId)->count())->toBe(0)
        ->and($this->db->connection()->table('recurring_series_occurrences')
            ->where('recurring_series_id', $seriesId)->count())->toBe(0);
});

it('derives the annualised impact from the delta at the series cadence', function (): void {
    $user = ddsUser();
    $seriesId = ddsSeries($this->db, $user, 'demo:sport-city:monthly:2500', 'approved', -2500);
    ddsOccurrence($this->db, $user, $seriesId, '2026-06-01', -2250);
    ddsOccurrence($this->db, $user, $seriesId, '2026-07-01', -2500);

    ddsRun($user);

    /** @var DriftAlert $alert */
    $alert = DriftAlert::query()->where('recurring_series_id', $seriesId)->firstOrFail();

    expect($alert->baseline_amount_minor)->toBe(-2250)
        ->and($alert->delta_minor)->toBe(-250)
        ->and($alert->annualized_impact_minor)->toBe(-3000);
});

// Each step is its own alert, and the newest one is the row the demo opens:
// an older step already has its own alert from the run that observed it.
it('names the newest step when a series moved more than once', function (): void {
    $user = ddsUser();
    $seriesId = ddsSeries($this->db, $user, 'demo:spotify:monthly:1099', 'approved', -1099);
    ddsOccurrence($this->db, $user, $seriesId, '2026-06-11', -899);
    ddsOccurrence($this->db, $user, $seriesId, '2026-07-11', -999);
    ddsOccurrence($this->db, $user, $seriesId, '2026-08-11', -1099);

    ddsRun($user);

    /** @var DriftAlert $alert */
    $alert = DriftAlert::query()->where('recurring_series_id', $seriesId)->firstOrFail();

    expect($alert->baseline_amount_minor)->toBe(-999)
        ->and($alert->latest_amount_minor)->toBe(-1099);
});

it('re-seeds without minting a second alert for the same occurrence', function (): void {
    $user = ddsUser();
    $seriesId = ddsSeries($this->db, $user, 'demo:spotify:monthly:1099', 'approved', -1099);
    ddsOccurrence($this->db, $user, $seriesId, '2026-06-11', -999);
    ddsOccurrence($this->db, $user, $seriesId, '2026-07-11', -1099);

    ddsRun($user);
    ddsRun($user);

    expect(DriftAlert::query()->where('recurring_series_id', $seriesId)->count())->toBe(1);
});
