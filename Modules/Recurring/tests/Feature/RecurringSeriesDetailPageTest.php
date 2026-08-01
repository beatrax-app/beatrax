<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Http\Livewire\RecurringSeriesDetailPage;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

/*
 * /recurring/series/{id} drill-in tests — Livewire SFC, ApexCharts
 * dataset injection, occurrences table, cross-user 404, and the
 * "view all points" toggle.
 */

function rsdUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rsdAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'rsd '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00RSDP'.str_pad(substr($slug, 0, 8), 10, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function rsdImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rsd.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function rsdSeries(User $user, string $detectedName, array $overrides = []): RecurringSeries
{
    return RecurringSeries::query()->create(array_merge([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $detectedName,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::'.$detectedName.'::eur::monthly',
        'next_expected_at' => '2026-06-17',
        'next_expected_confidence_low' => false,
    ], $overrides));
}

/**
 * Seeds N monthly transactions for $user/$account/$run and a parent
 * RecurringSeries. Returns the series + the list of inserted
 * transaction ids in chronological order.
 *
 * @return array{series: RecurringSeries, transactionIds: list<int>}
 */
function rsdSeedSeriesWithOccurrences(
    DatabaseManager $db,
    User $user,
    Account $account,
    ImportRun $run,
    int $occurrenceCount,
    string $detectedName = 'spotify',
    string $originalCurrency = 'EUR',
    int $originalAmountMinor = -999,
    int $settledAmountMinor = -999,
    string $settledCurrency = 'EUR',
    ?string $fxRate = null,
): array {
    $series = rsdSeries($user, $detectedName, [
        'latest_amount_minor' => $originalAmountMinor,
        'latest_currency' => $originalCurrency,
        'monthly_equivalent_minor' => $settledAmountMinor,
        'cluster_key' => 'expense::'.$detectedName.'::'.strtolower($originalCurrency).'::monthly',
    ]);

    $connection = $db->connection();
    $transactionIds = [];
    for ($i = 0; $i < $occurrenceCount; $i++) {
        $year = 2025 + intdiv(0 + $i, 12);
        $month = ($i % 12) + 1;
        $date = sprintf('%04d-%02d-15', $year, $month);
        $txId = $connection->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'posted_at' => $date,
            'booked_at' => $date.' 12:00:00',
            'value_date' => $date,
            'amount_minor' => $originalAmountMinor,
            'currency' => $originalCurrency,
            'settled_amount_minor' => $settledAmountMinor,
            'settled_currency' => $settledCurrency,
            'fx_rate_used' => $fxRate,
            'counterparty_name' => $detectedName,
            'counterparty_normalized' => $detectedName,
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $i + 1,
            'fingerprint' => str_pad('rsd-'.$detectedName.'-'.$i, 64, 'z', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
        $transactionIds[] = (int) $txId;

        RecurringSeriesOccurrence::query()->create([
            'user_id' => $user->id,
            'recurring_series_id' => $series->id,
            'transaction_id' => $txId,
            'observed_at' => $date,
            'observed_amount_minor' => $originalAmountMinor,
            'observed_currency' => $originalCurrency,
        ]);
    }

    return ['series' => $series, 'transactionIds' => $transactionIds];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = rsdUser('rsd');
    $this->account = rsdAccount($this->user, 'rsd-acc');
    $this->run = rsdImportRun($this->user, str_repeat('a', 64));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the drill-in page for an authenticated owner', function (): void {
    $seeded = rsdSeedSeriesWithOccurrences($this->db, $this->user, $this->account, $this->run, 12);

    $response = $this->actingAs($this->user)
        ->get(route('recurring.series.show', ['seriesId' => $seeded['series']->id]));

    $response->assertOk();
    $content = $response->getContent() ?: '';
    expect($content)->toContain('spotify');
    expect($content)->toContain('series-chart-'.$seeded['series']->id);
})->group('renders-for-owner');

it('returns 404 when the series belongs to a different user (cross-user-404)', function (): void {
    $other = rsdUser('rsd-other');
    $otherAccount = rsdAccount($other, 'rsd-other-acc');
    $otherRun = rsdImportRun($other, str_repeat('b', 64));
    $seeded = rsdSeedSeriesWithOccurrences($this->db, $other, $otherAccount, $otherRun, 3);

    $response = $this->actingAs($this->user)
        ->get(route('recurring.series.show', ['seriesId' => $seeded['series']->id]));

    $response->assertNotFound();
})->group('cross-user-404');

it('renders a valid ApexCharts JSON config in the data-options attribute (chart-dataset)', function (): void {
    $seeded = rsdSeedSeriesWithOccurrences($this->db, $this->user, $this->account, $this->run, 6);

    $response = $this->actingAs($this->user)
        ->get(route('recurring.series.show', ['seriesId' => $seeded['series']->id]));

    $content = $response->getContent() ?: '';
    expect($content)->toContain('data-options=');

    // Extract data-options="{...}" content (Blade `@json` escapes
    // quotes as `&quot;`).
    $extracted = null;
    if (preg_match('/data-options=("|\')([^"\']*)\1/u', $content, $matches) === 1) {
        $extracted = $matches[2];
    }
    expect($extracted)->not->toBeNull();
    /** @var string $extracted */
    $decoded = html_entity_decode($extracted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parsed = json_decode($decoded, true);
    expect($parsed)->toBeArray();
    /** @var array<string, mixed> $parsed */
    $chartConfig = $parsed['chart'] ?? null;
    expect($chartConfig)->toBeArray();
    /** @var array<string, mixed> $chartConfig */
    expect($chartConfig['type'] ?? null)->toBe('line');

    $seriesArray = $parsed['series'] ?? null;
    expect($seriesArray)->toBeArray();
    /** @var array<int, mixed> $seriesArray */
    // EUR-only series renders exactly one chart series.
    expect(count($seriesArray))->toBe(1);
})->group('chart-dataset');

it('appends an EUR shadow series for a USD-priced recurring series (eur-shadow-renders-for-usd-series)', function (): void {
    $seeded = rsdSeedSeriesWithOccurrences(
        db: $this->db,
        user: $this->user,
        account: $this->account,
        run: $this->run,
        occurrenceCount: 4,
        detectedName: 'netflix',
        originalCurrency: 'USD',
        originalAmountMinor: -1199,
        settledAmountMinor: -1100,
        settledCurrency: 'EUR',
        fxRate: '0.91737000',
    );

    $response = $this->actingAs($this->user)
        ->get(route('recurring.series.show', ['seriesId' => $seeded['series']->id]));

    $content = $response->getContent() ?: '';

    expect(preg_match('/data-options=("|\')([^"\']*)\1/u', $content, $matches))->toBe(1);
    /** @var array<int, string> $matches */
    $decoded = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parsed = json_decode($decoded, true);
    expect($parsed)->toBeArray();
    /** @var array<string, mixed> $parsed */
    $seriesArray = $parsed['series'] ?? null;
    expect($seriesArray)->toBeArray();
    /** @var array<int, mixed> $seriesArray */
    expect(count($seriesArray))->toBe(2);
})->group('eur-shadow-renders-for-usd-series');

it('expands the chart point set when the view-all toggle is flipped', function (): void {
    $seeded = rsdSeedSeriesWithOccurrences($this->db, $this->user, $this->account, $this->run, 30);

    $component = Livewire::actingAs($this->user)
        ->test(RecurringSeriesDetailPage::class, ['seriesId' => $seeded['series']->id]);

    $component->call('toggleAllPoints');
    expect($component->get('showAllPoints'))->toBeTrue();

    $component->call('toggleAllPoints');
    expect($component->get('showAllPoints'))->toBeFalse();
})->group('view-all-toggle-expands-points');

it('links every occurrence row to its underlying transaction (occurrences-table-links-to-transaction)', function (): void {
    $seeded = rsdSeedSeriesWithOccurrences($this->db, $this->user, $this->account, $this->run, 3);

    $response = $this->actingAs($this->user)
        ->get(route('recurring.series.show', ['seriesId' => $seeded['series']->id]));

    $content = $response->getContent() ?: '';
    foreach ($seeded['transactionIds'] as $txId) {
        expect($content)->toContain(route('transactions.show', ['transactionId' => $txId]));
    }
})->group('occurrences-table-links-to-transaction');
