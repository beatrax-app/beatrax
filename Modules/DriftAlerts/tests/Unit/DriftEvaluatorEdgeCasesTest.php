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
 * Edge-case coverage for DriftEvaluator: divide-by-zero guards
 * (prior_amount = 0, fewer than two occurrences), cadence='irregular'
 * (multiplier=0 → no alert), and state filters that exclude
 * pending/rejected/snoozed/irregular-state series from evaluation.
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

it('does not insert a drift alert when the prior occurrence amount is zero', function (): void {
    $user = devecUser('prior-zero');
    $seriesId = devecApprovedSeries($this->db, $user->id, 'monthly', 'EUR', 'expense');
    devecOccurrence($this->db, $user->id, $seriesId, '2026-04-15', 0, 'EUR');
    devecOccurrence($this->db, $user->id, $seriesId, '2026-05-15', -1499, 'EUR');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    expect(DriftAlert::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('does not insert a drift alert when the series has fewer than two occurrences', function (): void {
    $user = devecUser('single-occurrence');
    $seriesId = devecApprovedSeries($this->db, $user->id, 'monthly', 'EUR', 'expense');
    devecOccurrence($this->db, $user->id, $seriesId, '2026-05-15', -1499, 'EUR');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    expect(DriftAlert::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('does not insert a drift alert when the series cadence is irregular (multiplier=0)', function (): void {
    $user = devecUser('irregular-cadence');
    $seriesId = devecApprovedSeries($this->db, $user->id, 'irregular', 'EUR', 'expense');
    devecOccurrence($this->db, $user->id, $seriesId, '2026-04-15', -999, 'EUR');
    devecOccurrence($this->db, $user->id, $seriesId, '2026-05-15', -1499, 'EUR');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    expect(DriftAlert::query()->where('user_id', $user->id)->count())->toBe(0);
});

dataset('drift_excluded_states', [
    'pending' => ['pending'],
    'rejected' => ['rejected'],
    'snoozed' => ['snoozed'],
]);

it('does not insert a drift alert for series in excluded states', function (string $excludedState): void {
    $user = devecUser('excluded-'.$excludedState);
    $seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'fixture-merchant',
        'state' => $excludedState,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1499,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'fixture|monthly|EUR|'.$excludedState,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    devecOccurrence($this->db, $user->id, $seriesId, '2026-04-15', -999, 'EUR');
    devecOccurrence($this->db, $user->id, $seriesId, '2026-05-15', -1499, 'EUR');

    $this->app->make(DriftEvaluator::class)->evaluateForSeries($seriesId, $user);

    expect(DriftAlert::query()->where('user_id', $user->id)->count())->toBe(0);
})->with('drift_excluded_states');

it('returns silently when the series id is unknown / cross-user', function (): void {
    $user = devecUser('cross-user');

    // No series with id=999999 in the database.
    $this->app->make(DriftEvaluator::class)->evaluateForSeries(999999, $user);

    expect(DriftAlert::query()->count())->toBe(0);
});

function devecUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function devecApprovedSeries(DatabaseManager $db, int $userId, string $cadence, string $currency, string $direction): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => $direction,
        'detected_name' => 'fixture-merchant',
        'state' => 'approved',
        'cadence' => $cadence,
        'latest_amount_minor' => -999,
        'latest_currency' => $currency,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'fixture|'.$cadence.'|'.$currency.'|'.bin2hex(random_bytes(3)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function devecOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt, int $amountMinor, string $currency): int
{
    static $txCounter = 0;
    $txCounter++;
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN edge',
        'slug' => 'deval-edge-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00DEVALEDGE'.str_pad((string) $txCounter, 4, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/deval-edge-'.$txCounter.'.csv',
        'sha256' => str_pad('devaledge'.$txCounter, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad('devaledge'.$txCounter, 64, 'c', STR_PAD_LEFT),
        'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00',
        'value_date' => $observedAt,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_normalized' => 'fixture',
        'counterparty_name' => 'FIXTURE',
        'normalization_version' => 1,
        'description' => 'drift eval edge fixture',
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
