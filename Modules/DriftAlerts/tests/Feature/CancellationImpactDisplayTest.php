<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;

uses(RefreshDatabase::class);

function cidUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cidTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'cid-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cid-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'cid-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'cid-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1149,
        'currency' => 'EUR',
        'settled_amount_minor' => -1149,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'description' => 'cid fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

/**
 * @param  array<string, mixed>  $seriesOverrides
 * @param  array<string, mixed>  $alertOverrides
 */
function cidAlert(
    User $user,
    string $detectedName = 'Spotify',
    array $seriesOverrides = [],
    array $alertOverrides = [],
): DriftAlert {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId(array_merge([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $detectedName,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1500,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1500,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cid::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ], $seriesOverrides));

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => cidTransaction($db, $user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1500,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return DriftAlert::factory()->create(array_merge([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'state' => 'open',
        'direction' => 'expense',
        'baseline_amount_minor' => -1000,
        'latest_amount_minor' => -1500,
        'currency' => 'EUR',
        'delta_minor' => -500,
        'annualized_impact_minor' => -6000,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $occurrenceId,
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ], $alertOverrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = cidUser('cid');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders Cancel this → save €X/yr inline on each open alert row', function (): void {
    // monthly_equivalent_minor -1500 × 12 = 18000 cents, rendered nl_NL.
    cidAlert($this->user, 'Spotify');

    $response = $this->actingAs($this->user)->get('/drift');
    $response->assertOk();

    $response->assertSeeText('Cancel this');
    // Split in two: the symbol and the amount are separated by a space whose
    // width and breaking behaviour are the reader's, not this test's.
    $response->assertSee('€');
    $response->assertSee('180.00');
    $response->assertSeeText('/yr');
});

it('renders USD-primary cancellation projection when the series is denominated in USD', function (): void {
    // monthly_equivalent_minor -1199 × 12 = 14388 cents.
    cidAlert($this->user, 'Google Play', seriesOverrides: [
        'latest_amount_minor' => -1199,
        'latest_currency' => 'USD',
        'monthly_equivalent_minor' => -1199,
    ], alertOverrides: [
        'baseline_amount_minor' => -1199,
        'latest_amount_minor' => -1399,
        'currency' => 'USD',
        'delta_minor' => -200,
        'annualized_impact_minor' => -2400,
    ]);

    $response = $this->actingAs($this->user)->get('/drift');
    $response->assertOk();

    // en_US emits "$143.88" with no NBSP, so one assertion suffices here.
    $response->assertSeeText('Cancel this');
    $response->assertSee('$143.88');
});
