<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

uses(RefreshDatabase::class);

/*
 * Feature coverage for the per-account confidence legend sidebar on
 * /forecast.
 *
 * The bucket thresholds are locked:
 *   - band_width / |point| <= 10%  → high (emerald)
 *   - 10% < ratio          <= 25%  → medium (slate)
 *   - 25% < ratio                  → low (amber)
 *
 * Series with var=5% → band=10% → "high"; var=10% → band=20% → "medium";
 * var=15% → band=30% → "low"; var=45% → band=90% → "low".
 */

function clUser(): User
{
    return User::query()->create([
        'username' => 'cl-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function clAccount(DatabaseManager $db, int $userId, string $name = 'CL ASN'): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'cl-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'CL'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-05-01',
    ]);
}

function clImportRun(int $userId): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cl-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function clSeries(int $userId, int $accountId, string $name, int $variancePercent, int $amountMinor): RecurringSeries
{
    $series = RecurringSeries::query()->create([
        'user_id' => $userId,
        'cadence' => 'monthly',
        'direction' => $amountMinor < 0 ? 'expense' : 'income',
        'detected_name' => $name,
        'state' => 'approved',
        'variance_tolerance_percent' => $variancePercent,
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => 'EUR',
        'cluster_key' => 'cl-cluster-'.bin2hex(random_bytes(4)),
        'next_expected_at' => '2026-05-15',
    ]);

    $run = clImportRun($userId);
    $txn = Transaction::query()->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => $amountMinor < 0 ? 'expense' : 'income',
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 09:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $name,
        'counterparty_normalized' => strtolower($name),
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('cl-'.$series->id, 64, 'x', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
    RecurringSeriesOccurrence::query()->create([
        'user_id' => $userId,
        'recurring_series_id' => $series->id,
        'transaction_id' => $txn->id,
        'observed_at' => '2026-04-15',
        'observed_amount_minor' => $amountMinor,
        'observed_currency' => 'EUR',
    ]);

    return $series;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = clUser();
});

it('renders the high-confidence emerald chip for a var=5% series (band=10%)', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    clSeries($this->user->id, $accountId, 'Netflix', variancePercent: 5, amountMinor: -1199);

    // Seed a complete forecast_runs row so ForecastQuery::forUser does not return the computing sentinel.
    $payload = json_encode(['as_of' => '2026-05-01', 'horizon_days' => 30, 'accounts' => [
        (string) $accountId => [
            'account_id' => $accountId,
            'account_name' => 'CL ASN',
            'default_currency' => 'EUR',
            'today_balance_minor' => 100000,
            'anchor_source' => 'user_input_opening_balance',
            'points' => [['date' => '2026-05-01', 'low_minor' => 100000, 'point_minor' => 100000, 'high_minor' => 100000, 'currency' => 'EUR']],
        ],
    ]]);
    $this->db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => $payload,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $response = $this->actingAs($this->user)->get('/forecast?account='.$accountId);
    $content = (string) $response->getContent();

    expect($content)->toContain('data-testid="confidence-legend"');
    expect($content)->toContain('bg-emerald-50 text-emerald-700');
    expect($content)->toContain('high');
});

it('renders the medium-confidence slate chip for a var=10% series (band=20%)', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    clSeries($this->user->id, $accountId, 'Spotify', variancePercent: 10, amountMinor: -1099);

    $payload = json_encode(['as_of' => '2026-05-01', 'horizon_days' => 30, 'accounts' => [
        (string) $accountId => [
            'account_id' => $accountId,
            'account_name' => 'CL ASN',
            'default_currency' => 'EUR',
            'today_balance_minor' => 100000,
            'anchor_source' => 'user_input_opening_balance',
            'points' => [['date' => '2026-05-01', 'low_minor' => 100000, 'point_minor' => 100000, 'high_minor' => 100000, 'currency' => 'EUR']],
        ],
    ]]);
    $this->db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => $payload,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $response = $this->actingAs($this->user)->get('/forecast?account='.$accountId);
    $content = (string) $response->getContent();

    expect($content)->toContain('bg-slate-100 text-slate-700');
    expect($content)->toContain('medium');
});

it('renders the low-confidence amber chip for a var=45% series (band=90%)', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    clSeries($this->user->id, $accountId, 'Electricity', variancePercent: 45, amountMinor: -14000);

    $payload = json_encode(['as_of' => '2026-05-01', 'horizon_days' => 30, 'accounts' => [
        (string) $accountId => [
            'account_id' => $accountId,
            'account_name' => 'CL ASN',
            'default_currency' => 'EUR',
            'today_balance_minor' => 100000,
            'anchor_source' => 'user_input_opening_balance',
            'points' => [['date' => '2026-05-01', 'low_minor' => 100000, 'point_minor' => 100000, 'high_minor' => 100000, 'currency' => 'EUR']],
        ],
    ]]);
    $this->db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => $payload,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $response = $this->actingAs($this->user)->get('/forecast?account='.$accountId);
    $content = (string) $response->getContent();

    expect($content)->toContain('bg-amber-50 text-amber-700');
    expect($content)->toContain('low');
});

it('hides the confidence legend on the All accounts tab', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    clSeries($this->user->id, $accountId, 'Netflix', variancePercent: 5, amountMinor: -1199);

    $response = $this->actingAs($this->user)->get('/forecast');
    $content = (string) $response->getContent();

    // No confidence legend on the aggregate tab; the aggregate chart marker is present.
    expect($content)->not->toContain('data-testid="confidence-legend"');
    expect($content)->toContain('data-testid="all-accounts-aggregate-chart"');
});

it('renders the empty-state body when no series contribute to the account forecast', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    // No series — confidence list is empty.

    $payload = json_encode(['as_of' => '2026-05-01', 'horizon_days' => 30, 'accounts' => [
        (string) $accountId => [
            'account_id' => $accountId,
            'account_name' => 'CL ASN',
            'default_currency' => 'EUR',
            'today_balance_minor' => 100000,
            'anchor_source' => 'user_input_opening_balance',
            'points' => [['date' => '2026-05-01', 'low_minor' => 100000, 'point_minor' => 100000, 'high_minor' => 100000, 'currency' => 'EUR']],
        ],
    ]]);
    $this->db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => $payload,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $response = $this->actingAs($this->user)->get('/forecast?account='.$accountId);
    $content = (string) $response->getContent();

    expect($content)->toContain("No series contribute to this account's forecast yet.");
});
