<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Enums\SeriesConfidence;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

uses(RefreshDatabase::class);

// The chip buckets band_width / |point|: up to 10% high, up to 25% medium, wider
// than that low. A series' band is twice its variance tolerance, which is what
// makes var=5% high, var=10% medium and var=45% low below.

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

function clSeedRun(DatabaseManager $db, int $userId, int $accountId): void
{
    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => json_encode(['as_of' => '2026-05-01', 'horizon_days' => 30, 'accounts' => [
            (string) $accountId => [
                'account_id' => $accountId,
                'account_name' => 'CL ASN',
                'default_currency' => 'EUR',
                'today_balance_minor' => 100000,
                'anchor_source' => 'user_input_opening_balance',
                'points' => [['date' => '2026-05-01', 'low_minor' => 100000, 'point_minor' => 100000, 'high_minor' => 100000, 'currency' => 'EUR']],
            ],
        ]]),
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
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

    // Without a complete run, ForecastQuery::forUser returns the computing
    // sentinel and no legend renders at all.
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
    expect($content)->toContain('>'.Lang::get(SeriesConfidence::High->labelKey()).'<');
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
    expect($content)->toContain('>'.Lang::get(SeriesConfidence::Medium->labelKey()).'<');
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
    expect($content)->toContain('>'.Lang::get(SeriesConfidence::Low->labelKey()).'<');
});

it('hides the confidence legend on the All accounts tab', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    clSeries($this->user->id, $accountId, 'Netflix', variancePercent: 5, amountMinor: -1199);

    $response = $this->actingAs($this->user)->get('/forecast');
    $content = (string) $response->getContent();

    expect($content)->not->toContain('data-testid="confidence-legend"');
    expect($content)->toContain('data-testid="all-accounts-aggregate-chart"');
});

it('renders the empty-state body when no series contribute to the account forecast', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    // Deliberately no clSeries() call here.
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

// The chip printed the enum's backing value, so a Dutch reader got an English
// "low" inside otherwise fully Dutch copy.
it('names the confidence bucket in the reader\'s own language', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    clSeries($this->user->id, $accountId, 'Electricity', variancePercent: 45, amountMinor: -14000);
    clSeedRun($this->db, $this->user->id, $accountId);

    $this->user->locale = 'nl';
    $this->user->save();

    $content = (string) $this->actingAs($this->user)->get('/forecast?account='.$accountId)->getContent();

    expect($content)->toContain('>Laag<')
        ->and($content)->not->toContain('>'.SeriesConfidence::Low->value.'<');
});

// The legend line is suffixed "/mo", and it printed the latest CHARGE beside
// it: a EUR120.00-a-year series read "EUR120,00/mnd" on a page that also told
// the reader their yearly bills.
it('states the monthly equivalent, not the yearly charge, on the per-month line', function (): void {
    $accountId = clAccount($this->db, $this->user->id);
    $series = clSeries($this->user->id, $accountId, 'Domain renewal', variancePercent: 5, amountMinor: -12000);
    $series->cadence = 'yearly';
    $series->monthly_equivalent_minor = -1000;
    $series->save();
    clSeedRun($this->db, $this->user->id, $accountId);

    $content = (string) $this->actingAs($this->user)->get('/forecast?account='.$accountId)->getContent();

    $suffix = Lang::get('forecasting::forecast.per_month_suffix');

    expect($content)->toContain(Money::ofMinor(1_000, 'EUR')->format().$suffix)
        ->and($content)->not->toContain(Money::ofMinor(12_000, 'EUR')->format().$suffix);
});
