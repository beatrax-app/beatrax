<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;

// The floor was the integer 500, which is EUR 5.00 in a hundred-to-the-major
// currency and JPY 500 — about EUR 3.00 — in one with no subdivision at all.
// A yen reader was therefore offered "review this subscription" on a monthly
// charge a euro reader beside them, on the same real amount, never saw.

function rfzdSeries(DatabaseManager $db, int $userId, string $merchant, int $monthlyMinor, string $currency): int
{
    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => mb_strtolower($merchant).'-rfzd',
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
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'rfzd-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00RFZD'.str_pad((string) $cpId, 8, '0', STR_PAD_LEFT),
        'default_currency' => $currency,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/rfzd.csv',
        'sha256' => str_pad('rfzd'.$cpId, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'counterparty_id' => $cpId,
        'fingerprint' => str_pad('rfzd'.$cpId, 64, 'c', STR_PAD_LEFT), 'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 00:00:00', 'value_date' => '2026-05-01',
        'amount_minor' => -$monthlyMinor, 'currency' => $currency,
        'settled_amount_minor' => -$monthlyMinor, 'settled_currency' => $currency,
        'counterparty_normalized' => mb_strtolower($merchant), 'counterparty_name' => mb_strtoupper($merchant),
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

function rfzdReader(string $username, string $baseCurrency): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => $baseCurrency,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Jpy->value,
        'rate_date' => '2026-05-01',
        'rate' => '160.00',
        'source' => 'ecb',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
});

it('withholds the prompt from a yen reader on a charge worth under the floor', function (): void {
    // JPY 600 a month is about EUR 3.60, and the floor is EUR 5.00 — JPY 800.
    $user = rfzdReader('savings-jpy-under', Currency::Jpy->value);
    $this->actingAs($user);
    rfzdSeries($this->db, $user->id, 'KPN', 600, Currency::Jpy->value);

    expect(app(SavingsInsightsQuery::class)->forUser($user))->toBe([]);
});

it('still offers it to that reader once the charge clears the floor', function (): void {
    // JPY 1,200 a month is about EUR 7.50.
    $user = rfzdReader('savings-jpy-over', Currency::Jpy->value);
    $this->actingAs($user);
    $seriesId = rfzdSeries($this->db, $user->id, 'KPN', 1200, Currency::Jpy->value);

    $insights = app(SavingsInsightsQuery::class)->forUser($user);

    expect($insights)->toHaveCount(1)
        ->and($insights[0]->type)->toBe('review')
        ->and($insights[0]->key)->toBe('review:'.$seriesId);
});

// The other half of the parity: the same real amount, read in euro, has always
// been below the floor. Both readers now answer the same about one figure.
it('withholds it from a euro reader on the same real amount', function (): void {
    $user = rfzdReader('savings-eur-under', Currency::Eur->value);
    $this->actingAs($user);
    rfzdSeries($this->db, $user->id, 'KPN', 360, Currency::Eur->value);

    expect(app(SavingsInsightsQuery::class)->forUser($user))->toBe([]);
});
