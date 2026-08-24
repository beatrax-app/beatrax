<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;

// The review floor is a figure in the reader's reporting currency, and the arm
// that applies it refused every series not already denominated in that
// currency. Pick pounds over a euro ledger and "Ways to save" quietly emptied.


function rfChain(DatabaseManager $db, int $userId, string $merchant, int $monthlyMinor, string $currency): int
{
    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => strtolower($merchant).'-rf',
        'display_name' => $merchant, 'merchant_name' => $merchant,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => $merchant,
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -$monthlyMinor,
        'latest_currency' => $currency, 'monthly_equivalent_minor' => -$monthlyMinor,
        'variance_tolerance_percent' => 25,
        'cluster_key' => $merchant.'|monthly|'.$currency.'|'.bin2hex(random_bytes(3)),
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'rf-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00RFLR'.str_pad((string) $cpId, 8, '0', STR_PAD_LEFT),
        'default_currency' => $currency,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/rf.csv',
        'sha256' => str_pad('rf'.$cpId, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'counterparty_id' => $cpId,
        'fingerprint' => str_pad('rf'.$cpId, 64, 'c', STR_PAD_LEFT), 'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 00:00:00', 'value_date' => '2026-05-01',
        'amount_minor' => -$monthlyMinor, 'currency' => $currency,
        'settled_amount_minor' => -$monthlyMinor, 'settled_currency' => $currency,
        'counterparty_normalized' => strtolower($merchant), 'counterparty_name' => strtoupper($merchant),
        'normalization_version' => 1, 'type' => 'expense', 'source_format' => 'asn-csv',
        'source_row_index' => $cpId, 'fingerprint_version' => 3,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
        'observed_at' => '2026-05-01', 'observed_amount_minor' => -$monthlyMinor,
        'observed_currency' => $currency,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    return $seriesId;
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $this->db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Gbp->value,
        'rate_date' => '2026-05-01',
        'rate' => '0.80',
        'source' => 'ecb',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $this->user = User::create([
        'username' => 'savings-multi-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Gbp->value,
    ]);
    $this->actingAs($this->user);
});

it('offers the review prompt for a euro subscription a pound reader is looking at', function (): void {
    // EUR 45.00 a month is GBP 36.00, comfortably over the review floor.
    $seriesId = rfChain($this->db, $this->user->id, 'KPN', 4500, Currency::Eur->value);

    $insights = app(SavingsInsightsQuery::class)->forUser($this->user);

    expect($insights)->toHaveCount(1)
        ->and($insights[0]->type)->toBe('review')
        ->and($insights[0]->key)->toBe('review:'.$seriesId);
});

it('still withholds it below the floor once converted', function (): void {
    // EUR 6.00 a month is GBP 4.80, under the GBP 5.00 floor.
    rfChain($this->db, $this->user->id, 'KPN', 600, Currency::Eur->value);

    expect(app(SavingsInsightsQuery::class)->forUser($this->user))->toBe([]);
});
