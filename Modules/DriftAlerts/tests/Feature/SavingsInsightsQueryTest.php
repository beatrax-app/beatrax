<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\SavingsInsightsCard;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;

/**
 * Coverage for the "You could save here" insight generation: an approved
 * subscription whose resolved counterparty has a support-resource entry yields
 * a suggestion (cheaper plan preferred), dismissible by stable key.
 */
function siChain(DatabaseManager $db, int $userId, string $merchant, int $monthlyMinor): int
{
    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => strtolower($merchant).'-si',
        'display_name' => $merchant, 'merchant_name' => $merchant,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => $merchant,
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -$monthlyMinor, 'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -$monthlyMinor, 'variance_tolerance_percent' => 25,
        'cluster_key' => $merchant.'|monthly|EUR|'.bin2hex(random_bytes(3)),
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'si-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00SAVI'.str_pad((string) $cpId, 8, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/si.csv',
        'sha256' => str_pad('si'.$cpId, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-05-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'counterparty_id' => $cpId,
        'fingerprint' => str_pad('si'.$cpId, 64, 'c', STR_PAD_LEFT), 'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 00:00:00', 'value_date' => '2026-05-01',
        'amount_minor' => -$monthlyMinor, 'currency' => 'EUR', 'settled_amount_minor' => -$monthlyMinor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => strtolower($merchant), 'counterparty_name' => strtoupper($merchant), 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => $cpId,
        'fingerprint_version' => 3, 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
        'observed_at' => '2026-05-01', 'observed_amount_minor' => -$monthlyMinor, 'observed_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    return $seriesId;
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::create(['username' => 'savings-fixture', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    $this->actingAs($this->user);
});

it('suggests the cheaper plan for a subscription whose corpus entry has one', function (): void {
    // Spotify's bundled support entry carries a cheaper_url (student plan).
    $seriesId = siChain($this->db, $this->user->id, 'Spotify', 999);

    $insights = app(SavingsInsightsQuery::class)->forUser($this->user);

    expect($insights)->toHaveCount(1);
    expect($insights[0]->type)->toBe('cheaper');
    expect($insights[0]->key)->toBe('cheaper:'.$seriesId);
    expect($insights[0]->actionUrl)->toContain('spotify.com');
});

it('does not re-surface a dismissed insight', function (): void {
    $seriesId = siChain($this->db, $this->user->id, 'Spotify', 999);
    $query = app(SavingsInsightsQuery::class);

    $query->dismiss($this->user, 'cheaper:'.$seriesId);

    expect($query->forUser($this->user))->toBe([]);
});

it('produces no insight for a counterparty absent from the support corpus', function (): void {
    siChain($this->db, $this->user->id, 'Nameless Shop', 999);

    expect(app(SavingsInsightsQuery::class)->forUser($this->user))->toBe([]);
});

it('renders the dashboard card and dismisses a suggestion', function (): void {
    $seriesId = siChain($this->db, $this->user->id, 'Spotify', 999);

    Livewire\Livewire::test(SavingsInsightsCard::class)
        ->assertSee('Ways to save')
        ->assertSee('cheaper plan')
        ->call('dismiss', 'cheaper:'.$seriesId)
        ->assertDontSee('Ways to save');
});
