<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * Coverage for the recurring ↔ counterparty link queries that power the
 * bidirectional navigation (a counterparty profile's Recurring card and the
 * series detail page's "View counterparty" link). Both resolve through
 * occurrences → transactions.counterparty_id.
 */
function linkChain(DatabaseManager $db, int $userId, int $counterpartyId, string $state = 'approved'): int
{
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => 'Netflix',
        'state' => $state, 'cadence' => 'monthly', 'latest_amount_minor' => -1499, 'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1499, 'variance_tolerance_percent' => 25,
        'cluster_key' => 'netflix|monthly|EUR|'.bin2hex(random_bytes(3)),
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'lc-'.bin2hex(random_bytes(4)),
        'kind' => 'asn', 'iban' => 'NL00LINK'.str_pad((string) $counterpartyId, 8, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/lc.csv',
        'sha256' => str_pad('lc'.$counterpartyId, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-05-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'counterparty_id' => $counterpartyId,
        'fingerprint' => str_pad('lc'.$counterpartyId, 64, 'c', STR_PAD_LEFT), 'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 00:00:00', 'value_date' => '2026-05-01',
        'amount_minor' => -1499, 'currency' => 'EUR', 'settled_amount_minor' => -1499, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'netflix', 'counterparty_name' => 'NETFLIX', 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => $counterpartyId,
        'fingerprint_version' => 3, 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
        'observed_at' => '2026-05-01', 'observed_amount_minor' => -1499, 'observed_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    return $seriesId;
}

function linkCounterparty(DatabaseManager $db, int $userId, string $slug): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => $slug, 'display_name' => 'Netflix',
        'merchant_name' => 'Netflix', 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::create(['username' => 'link-fixture', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    $this->actingAs($this->user);
});

it('resolves the counterparty for a series via its occurrences', function (): void {
    $cpId = linkCounterparty($this->db, $this->user->id, 'netflix-link');
    $seriesId = linkChain($this->db, $this->user->id, $cpId);

    expect(app(RecurringSeriesQuery::class)->counterpartyIdForSeries($seriesId, $this->user))->toBe($cpId);
});

it('lists the approved series for a counterparty', function (): void {
    $cpId = linkCounterparty($this->db, $this->user->id, 'netflix-link');
    $seriesId = linkChain($this->db, $this->user->id, $cpId);

    $series = app(RecurringSeriesQuery::class)->approvedSeriesForCounterparty($cpId, $this->user);

    expect($series)->toHaveKey($seriesId);
    expect($series[$seriesId]->displayName())->toBe('Netflix');
});

it('does not link a pending (unapproved) series to the counterparty card', function (): void {
    $cpId = linkCounterparty($this->db, $this->user->id, 'netflix-link');
    linkChain($this->db, $this->user->id, $cpId, 'pending');

    expect(app(RecurringSeriesQuery::class)->approvedSeriesForCounterparty($cpId, $this->user))->toBe([]);
});

it('returns no counterparty for a series with no resolved occurrences', function (): void {
    $seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id, 'direction' => 'expense', 'detected_name' => 'Orphan',
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -100, 'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -100, 'variance_tolerance_percent' => 25,
        'cluster_key' => 'orphan|monthly|EUR|'.bin2hex(random_bytes(3)),
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    expect(app(RecurringSeriesQuery::class)->counterpartyIdForSeries($seriesId, $this->user))->toBeNull();
});
