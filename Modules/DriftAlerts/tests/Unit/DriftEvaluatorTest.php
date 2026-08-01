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
 * Core happy-path coverage for the drift evaluator: every cadence
 * multiplier (monthly, weekly, quarterly, yearly), stable + below-
 * threshold + above-threshold deltas, and the EUR + USD currency
 * personalities. Each row in the Pest dataset seeds two occurrences
 * for one approved recurring_series row and asserts the row count +
 * column shape against the expected outcome.
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

dataset('drift_evaluator_cases', [
    'stable monthly: no alert' => [
        'cadence' => 'monthly',
        'currency' => 'EUR',
        'direction' => 'expense',
        'priorMinor' => -999,
        'latestMinor' => -999,
        'expectedAlertCount' => 0,
        'expectedDeltaMinor' => null,
        'expectedAnnualizedMinor' => null,
    ],
    'small drift below threshold: no alert' => [
        'cadence' => 'monthly',
        'currency' => 'EUR',
        'direction' => 'expense',
        'priorMinor' => -999,
        'latestMinor' => -1020,
        'expectedAlertCount' => 0,
        'expectedDeltaMinor' => null,
        'expectedAnnualizedMinor' => null,
    ],
    'monthly +15% expense: 1 alert at x12' => [
        'cadence' => 'monthly',
        'currency' => 'EUR',
        'direction' => 'expense',
        'priorMinor' => -999,
        'latestMinor' => -1149,
        'expectedAlertCount' => 1,
        'expectedDeltaMinor' => -150,
        'expectedAnnualizedMinor' => -1800,
    ],
    'weekly +10% expense: 1 alert at x52' => [
        'cadence' => 'weekly',
        'currency' => 'EUR',
        'direction' => 'expense',
        'priorMinor' => -1000,
        'latestMinor' => -1100,
        'expectedAlertCount' => 1,
        'expectedDeltaMinor' => -100,
        'expectedAnnualizedMinor' => -5200,
    ],
    'quarterly +12.5% expense: 1 alert at x4' => [
        'cadence' => 'quarterly',
        'currency' => 'EUR',
        'direction' => 'expense',
        'priorMinor' => -24000,
        'latestMinor' => -27000,
        'expectedAlertCount' => 1,
        'expectedDeltaMinor' => -3000,
        'expectedAnnualizedMinor' => -12000,
    ],
    'yearly +50% expense: 1 alert at x1' => [
        'cadence' => 'yearly',
        'currency' => 'EUR',
        'direction' => 'expense',
        'priorMinor' => -1000,
        'latestMinor' => -1500,
        'expectedAlertCount' => 1,
        'expectedDeltaMinor' => -500,
        'expectedAnnualizedMinor' => -500,
    ],
    'monthly +10% income (raise): 1 positive-signed alert' => [
        'cadence' => 'monthly',
        'currency' => 'EUR',
        'direction' => 'income',
        'priorMinor' => 350000,
        'latestMinor' => 385000,
        'expectedAlertCount' => 1,
        'expectedDeltaMinor' => 35000,
        'expectedAnnualizedMinor' => 420000,
    ],
    'monthly -6% income (cut): 1 negative-signed alert' => [
        'cadence' => 'monthly',
        'currency' => 'EUR',
        'direction' => 'income',
        'priorMinor' => 350000,
        'latestMinor' => 329000,
        'expectedAlertCount' => 1,
        'expectedDeltaMinor' => -21000,
        'expectedAnnualizedMinor' => -252000,
    ],
    'monthly +25% expense USD: 1 alert in USD currency' => [
        'cadence' => 'monthly',
        'currency' => 'USD',
        'direction' => 'expense',
        // Round prior+latest pair so the ratio is exactly 25.0% —
        // avoids the per-mille rounding hazard a non-round pair like
        // -1199 → -1499 would introduce when a future contributor
        // copies the case shape with a 25% threshold (which compares
        // strictly-greater-than the ratio).
        'priorMinor' => -1200,
        'latestMinor' => -1500,
        'expectedAlertCount' => 1,
        'expectedDeltaMinor' => -300,
        'expectedAnnualizedMinor' => -3600,
    ],
]);

it('evaluates drift for a single (series, occurrence) pair across cadences and currencies', function (
    string $cadence,
    string $currency,
    string $direction,
    int $priorMinor,
    int $latestMinor,
    int $expectedAlertCount,
    ?int $expectedDeltaMinor,
    ?int $expectedAnnualizedMinor,
): void {
    $user = devalUser('eval-'.bin2hex(random_bytes(4)));
    $txId = devalSeedTransaction($this->db, $user->id);

    $seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => $direction,
        'detected_name' => 'fixture-merchant',
        'state' => 'approved',
        'cadence' => $cadence,
        'latest_amount_minor' => $latestMinor,
        'latest_currency' => $currency,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'fixture|'.$cadence.'|'.$currency,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $priorId = $this->db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $txId,
        'observed_at' => '2026-04-15',
        'observed_amount_minor' => $priorMinor,
        'observed_currency' => $currency,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    // The detector requires a distinct transaction_id per occurrence
    // because (recurring_series_id, transaction_id) is UNIQUE.
    $latestTxId = devalSeedTransaction($this->db, $user->id, $priorId + 1);
    $latestOccurrenceId = $this->db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $latestTxId,
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => $latestMinor,
        'observed_currency' => $currency,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    /** @var DriftEvaluator $evaluator */
    $evaluator = $this->app->make(DriftEvaluator::class);
    $evaluator->evaluateForSeries($seriesId, $user);

    $actualCount = DriftAlert::query()
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->count();
    expect($actualCount)->toBe($expectedAlertCount);

    if ($expectedAlertCount > 0) {
        /** @var DriftAlert $row */
        $row = DriftAlert::query()
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->orderByDesc('id')
            ->firstOrFail();

        expect($row->state)->toBe('open');
        expect($row->direction)->toBe($direction);
        expect($row->currency)->toBe($currency);
        expect($row->baseline_amount_minor)->toBe($priorMinor);
        expect($row->latest_amount_minor)->toBe($latestMinor);
        expect($row->delta_minor)->toBe($expectedDeltaMinor);
        expect($row->annualized_impact_minor)->toBe($expectedAnnualizedMinor);
        expect($row->latest_occurrence_id)->toBe($latestOccurrenceId);
        expect($row->threshold_percent_used)->toBe(5);
        expect($row->threshold_source)->toBe('global');
    }
})->with('drift_evaluator_cases');

/**
 * Seeds the prerequisite accounts/import_runs/transactions FK chain.
 * The seed is per-call so the (account_id, fingerprint) UNIQUE on
 * transactions does not collide across dataset rows.
 */
function devalSeedTransaction(DatabaseManager $db, int $userId, int $salt = 0): int
{
    static $counter = 0;
    $counter++;
    $slug = 'deval-asn-'.bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00DEVAL'.str_pad((string) $counter, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/deval-'.$counter.'.csv',
        'sha256' => str_pad('deval'.$counter, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad('deval'.$counter.'-'.$salt, 64, 'c', STR_PAD_LEFT),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1149,
        'currency' => 'EUR',
        'settled_amount_minor' => -1149,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'fixture',
        'counterparty_name' => 'FIXTURE',
        'normalization_version' => 1,
        'description' => 'drift eval fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $counter,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function devalUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}
