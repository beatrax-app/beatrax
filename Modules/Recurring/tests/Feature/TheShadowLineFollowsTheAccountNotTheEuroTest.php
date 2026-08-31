<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;

uses(RefreshDatabase::class);

// The amount-trend chart draws a second line for what the account was really
// debited when the charge was quoted in something else. It was pinned to the
// euro on both sides, so an account denominated in pounds — settling a dollar
// subscription — got no second line at all.

function shadowSeries(DatabaseManager $db, int $userId, string $observedCurrency, string $settledCurrency): int
{
    $hex = bin2hex(random_bytes(4));

    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => 'Adobe',
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -1999,
        'latest_currency' => $observedCurrency, 'monthly_equivalent_minor' => -1999,
        'variance_tolerance_percent' => 25, 'cluster_key' => 'shadow::'.$hex,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Account '.$hex, 'slug' => 'shadow-'.$hex, 'kind' => 'bank',
        'iban' => 'GB00SHDW'.strtoupper($hex), 'default_currency' => $settledCurrency,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/shadow-'.$hex.'.csv',
        'sha256' => hash('sha256', 'shadow-'.$hex), 'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'shadow-tx-'.$hex),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 12:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -1999, 'currency' => $observedCurrency,
        'settled_amount_minor' => -1750, 'settled_currency' => $settledCurrency,
        'counterparty_name' => 'ADOBE', 'counterparty_normalized' => 'adobe', 'normalization_version' => 3,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-06-15 00:00:00', 'updated_at' => '2026-06-15 00:00:00',
    ]);

    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
        'observed_at' => '2026-06-15', 'observed_amount_minor' => -1999,
        'observed_currency' => $observedCurrency,
        'created_at' => '2026-06-15 00:00:00', 'updated_at' => '2026-06-15 00:00:00',
    ]);

    return $seriesId;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-24 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::create([
        'username' => 'shadow-line',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('carries the settled amount for an account denominated in pounds', function (): void {
    $seriesId = shadowSeries($this->db, $this->user->id, Currency::Usd->value, Currency::Gbp->value);

    $trend = app(RecurringOccurrenceQuery::class)->amountTrendForSeries($seriesId, $this->user);

    expect($trend->points)->toHaveCount(1)
        ->and($trend->points[0]['settled_amount_minor'])->toBe(-1750)
        ->and($trend->points[0]['settled_currency'])->toBe(Currency::Gbp->value);
});

it('draws no second line when the account was debited in the currency quoted', function (): void {
    $seriesId = shadowSeries($this->db, $this->user->id, Currency::Gbp->value, Currency::Gbp->value);

    $trend = app(RecurringOccurrenceQuery::class)->amountTrendForSeries($seriesId, $this->user);

    expect($trend->points[0]['settled_amount_minor'])->toBeNull()
        ->and($trend->points[0]['settled_currency'])->toBeNull();
});
