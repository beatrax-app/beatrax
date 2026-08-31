<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Services\SubscriptionDriftWatchQuery;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;

// recurring_series.latest_currency is rewritten on every refresh, so a series
// whose merchant switched denomination keeps older occurrences in the old code
// while the header has moved on. The trend read each occurrence's own
// observed_currency and then dropped it, stamping every point with the series
// header's, and the watchlist measured JPY1,200 against EUR12.00 as a creep.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'creep-two-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

/**
 * @param  list<array{date: string, minor: int, currency: string}>  $occurrences
 */
function creepSeries(DatabaseManager $db, int $userId, string $merchant, string $headerCurrency, int $headerMinor, array $occurrences): int
{
    $hex = bin2hex(random_bytes(5));

    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => mb_strtolower($merchant).'-'.$hex,
        'display_name' => $merchant, 'merchant_name' => $merchant,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => $merchant,
        'state' => 'approved', 'cadence' => 'monthly',
        'latest_amount_minor' => $headerMinor, 'latest_currency' => $headerCurrency,
        'monthly_equivalent_minor' => $headerMinor, 'variance_tolerance_percent' => 25,
        'cluster_key' => $merchant.'|monthly|'.$headerCurrency.'|'.$hex,
        'cluster_counterparty_key' => mb_strtolower($merchant).'-'.$hex,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    foreach ($occurrences as $i => $occurrence) {
        $rowHex = bin2hex(random_bytes(5));
        $accountId = $db->connection()->table('accounts')->insertGetId([
            'user_id' => $userId, 'name' => 'Revolut '.$rowHex, 'slug' => 'creep-'.$rowHex, 'kind' => 'bank',
            'iban' => 'GB00CRP'.strtoupper(substr($rowHex, 0, 8)), 'default_currency' => $occurrence['currency'],
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $runId = $db->connection()->table('import_runs')->insertGetId([
            'user_id' => $userId, 'source_format' => 'revolut-csv', 'raw_file_path' => '/tmp/creep-'.$rowHex.'.csv',
            'sha256' => hash('sha256', 'creep-'.$rowHex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'imported',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $txId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'counterparty_id' => $cpId,
            'fingerprint' => hash('sha256', 'creep-fp-'.$rowHex), 'fingerprint_version' => 3,
            'posted_at' => $occurrence['date'], 'booked_at' => $occurrence['date'].' 12:00:00', 'value_date' => $occurrence['date'],
            'amount_minor' => $occurrence['minor'], 'currency' => $occurrence['currency'],
            'settled_amount_minor' => $occurrence['minor'], 'settled_currency' => $occurrence['currency'],
            'counterparty_normalized' => 'sub', 'counterparty_name' => 'SUB', 'normalization_version' => 1,
            'description' => 'subscription', 'type' => 'expense',
            'source_format' => 'revolut-csv', 'source_row_index' => $i + 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
            'observed_at' => $occurrence['date'], 'observed_amount_minor' => $occurrence['minor'],
            'observed_currency' => $occurrence['currency'],
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    return $seriesId;
}

it('carries each occurrence’s own currency onto the trend point', function (): void {
    $seriesId = creepSeries($this->db, $this->user->id, 'Alpha', Currency::Eur->value, -1200, [
        ['date' => '2026-06-01', 'minor' => -1200, 'currency' => Currency::Jpy->value],
        ['date' => '2026-07-01', 'minor' => -1200, 'currency' => Currency::Eur->value],
    ]);

    $trend = app(RecurringOccurrenceQuery::class)->amountTrendForSeries($seriesId, $this->user, 24);

    expect($trend->points[0]['currency'])->toBe(Currency::Jpy->value)
        ->and($trend->points[1]['currency'])->toBe(Currency::Eur->value);
});

it('refuses to call a change of denomination a price creep', function (): void {
    creepSeries($this->db, $this->user->id, 'Alpha', Currency::Eur->value, -1200, [
        ['date' => '2026-06-01', 'minor' => -1200, 'currency' => Currency::Jpy->value],
        ['date' => '2026-07-01', 'minor' => -1200, 'currency' => Currency::Eur->value],
    ]);

    expect(app(SubscriptionDriftWatchQuery::class)->forUser($this->user))->toBe([]);
});

it('still measures a creep inside one currency', function (): void {
    creepSeries($this->db, $this->user->id, 'Alpha', Currency::Eur->value, -1400, [
        ['date' => '2026-06-01', 'minor' => -1200, 'currency' => Currency::Eur->value],
        ['date' => '2026-07-01', 'minor' => -1400, 'currency' => Currency::Eur->value],
    ]);

    $rows = app(SubscriptionDriftWatchQuery::class)->forUser($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->baselineMinor)->toBe(1200)
        ->and($rows[0]->latestMinor)->toBe(1400)
        ->and($rows[0]->currency)->toBe(Currency::Eur->value);
});
