<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\SubscriptionDriftWatchPage;
use Modules\DriftAlerts\Public\Services\SubscriptionDriftWatchQuery;

function driftWatchSeries(DatabaseManager $db, int $userId, string $direction, string $name): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => $direction,
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1799,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1799,
        'variance_tolerance_percent' => 25,
        'cluster_key' => $name.'|monthly|EUR|'.bin2hex(random_bytes(3)),
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function driftWatchOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt, int $amountMinor): void
{
    static $i = 0;
    $i++;
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'dw-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00DRWT'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/dw-'.$i.'.csv',
        'sha256' => str_pad('dw'.$i, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-05-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => str_pad('dw'.$i, 64, 'c', STR_PAD_LEFT), 'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00', 'value_date' => $observedAt,
        'amount_minor' => $amountMinor, 'currency' => 'EUR', 'settled_amount_minor' => $amountMinor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'dw fixture', 'counterparty_name' => 'DW FIXTURE', 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => $i,
        'fingerprint_version' => 3, 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
        'observed_at' => $observedAt, 'observed_amount_minor' => $amountMinor, 'observed_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('computes the absolute price drift per subscription', function (): void {
    $id = driftWatchSeries($this->db, $this->user->id, 'expense', 'Spotify');
    driftWatchOccurrence($this->db, $this->user->id, $id, '2026-03-01', -1499);
    driftWatchOccurrence($this->db, $this->user->id, $id, '2026-04-01', -1499);
    driftWatchOccurrence($this->db, $this->user->id, $id, '2026-05-01', -1799);

    $rows = app(SubscriptionDriftWatchQuery::class)->forUser($this->user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->name)->toBe('Spotify');
    expect($rows[0]->baselineMinor)->toBe(1499);   // |−14.99|
    expect($rows[0]->latestMinor)->toBe(1799);      // |−17.99|
    expect($rows[0]->deltaMinor)->toBe(300);        // crept up €3.00
    expect(round($rows[0]->deltaPercent, 1))->toBe(20.0);
    expect($rows[0]->direction())->toBe('up');
});

it('excludes single-observation series, income series, and ranks biggest creep first', function (): void {
    $big = driftWatchSeries($this->db, $this->user->id, 'expense', 'BigCreep');
    driftWatchOccurrence($this->db, $this->user->id, $big, '2026-03-01', -1000);
    driftWatchOccurrence($this->db, $this->user->id, $big, '2026-05-01', -1500); // +500

    $small = driftWatchSeries($this->db, $this->user->id, 'expense', 'SmallCreep');
    driftWatchOccurrence($this->db, $this->user->id, $small, '2026-03-01', -1000);
    driftWatchOccurrence($this->db, $this->user->id, $small, '2026-05-01', -1100); // +100

    $single = driftWatchSeries($this->db, $this->user->id, 'expense', 'OnlyOnce');
    driftWatchOccurrence($this->db, $this->user->id, $single, '2026-03-01', -900); // one point

    $income = driftWatchSeries($this->db, $this->user->id, 'income', 'Salary');
    driftWatchOccurrence($this->db, $this->user->id, $income, '2026-03-01', 250000);
    driftWatchOccurrence($this->db, $this->user->id, $income, '2026-05-01', 260000);

    $rows = app(SubscriptionDriftWatchQuery::class)->forUser($this->user);

    expect(array_map(static fn ($r) => $r->name, $rows))->toBe(['BigCreep', 'SmallCreep']);
});

it('renders the drift watch page', function (): void {
    $id = driftWatchSeries($this->db, $this->user->id, 'expense', 'Netflix');
    driftWatchOccurrence($this->db, $this->user->id, $id, '2026-03-01', -1199);
    driftWatchOccurrence($this->db, $this->user->id, $id, '2026-05-01', -1399);

    Livewire::test(SubscriptionDriftWatchPage::class)
        ->assertOk()
        ->assertSee('Subscription drift')
        ->assertSee('Netflix');
});

it('uses the true first charge as the baseline even past the chart point cap', function (): void {
    $id = driftWatchSeries($this->db, $this->user->id, 'expense', 'LongLived');
    // First charge €5.00, then 25 more at €15.00 — 26 points, past the 24-point
    // series-chart cap. The baseline must still be the true first €5.00.
    driftWatchOccurrence($this->db, $this->user->id, $id, '2024-01-01', -500);
    for ($m = 1; $m <= 25; $m++) {
        driftWatchOccurrence(
            $this->db,
            $this->user->id,
            $id,
            CarbonImmutable::parse('2024-01-01')->addMonths($m)->toDateString(),
            -1500,
        );
    }

    $rows = app(SubscriptionDriftWatchQuery::class)->forUser($this->user);

    expect($rows[0]->baselineMinor)->toBe(500);
    expect($rows[0]->latestMinor)->toBe(1500);
    expect($rows[0]->deltaMinor)->toBe(1000);
});
