<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Http\Livewire\DashboardDriftBadge;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

// drift_alerts.annualized_impact_minor is denominated in the alert's own
// currency column, and the dashboard tile added the integers and stamped the
// reader's sign on the sum. A euro alert of 18.00 read as 18.00 pounds.

function dmcTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN test', 'slug' => 'dmc-asn-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-05-19 00:00:00', 'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/dmc-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'dmc-run-'.$suffix), 'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed', 'created_at' => '2026-05-19 00:00:00', 'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'dmc-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-05-15', 'booked_at' => '2026-05-15 00:00:00', 'value_date' => '2026-05-15',
        'amount_minor' => -1149, 'currency' => Currency::Eur->value,
        'settled_amount_minor' => -1149, 'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'spotify', 'counterparty_name' => 'SPOTIFY', 'normalization_version' => 1,
        'description' => 'dmc fixture', 'type' => 'expense', 'source_format' => 'asn-csv',
        'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00', 'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function dmcAlert(User $user, int $annualizedMinor, string $currency): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id, 'direction' => 'expense', 'detected_name' => 'Spotify',
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -1149,
        'latest_currency' => $currency, 'variance_tolerance_percent' => 25,
        'cluster_key' => 'dmc::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00', 'updated_at' => '2026-05-19 00:00:00',
    ]);

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id, 'recurring_series_id' => $seriesId,
        'transaction_id' => dmcTransaction($db, $user->id), 'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149, 'observed_currency' => $currency,
        'created_at' => '2026-05-19 00:00:00', 'updated_at' => '2026-05-19 00:00:00',
    ]);

    DriftAlert::factory()->create([
        'user_id' => $user->id, 'recurring_series_id' => $seriesId, 'state' => 'open',
        'direction' => 'expense', 'baseline_amount_minor' => -999, 'latest_amount_minor' => -1149,
        'currency' => $currency, 'delta_minor' => -150,
        'annualized_impact_minor' => $annualizedMinor,
        'threshold_percent_used' => 5, 'threshold_source' => 'global',
        'latest_occurrence_id' => $occurrenceId,
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    foreach (['GBP' => '0.80', 'USD' => '2.00'] as $quote => $rate) {
        $db->connection()->table('exchange_rates')->insert([
            'base_currency' => Currency::Eur->value, 'quote_currency' => $quote,
            'rate_date' => '2026-05-20', 'rate' => $rate, 'source' => 'ecb',
            'created_at' => '2026-05-20 00:00:00', 'updated_at' => '2026-05-20 00:00:00',
        ]);
    }

    $this->user = User::query()->create([
        'username' => 'ddb-multi-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => Currency::Gbp->value,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('converts a euro alert into the reader currency instead of relabelling it', function (): void {
    dmcAlert($this->user, -1800, Currency::Eur->value);

    Livewire::actingAs($this->user)->test(DashboardDriftBadge::class)
        ->assertSee('14.40')
        ->assertDontSee('18.00');
});

it('converts each currency before adding them rather than after', function (): void {
    dmcAlert($this->user, -1000, Currency::Eur->value);
    dmcAlert($this->user, -1000, Currency::Usd->value);

    // EUR 10.00 is GBP 8.00; USD 10.00 is EUR 5.00 is GBP 4.00.
    Livewire::actingAs($this->user)->test(DashboardDriftBadge::class)
        ->assertSee('12.00')
        ->assertDontSee('20.00');
});

it('leaves out a currency it has no rate for and names it', function (): void {
    dmcAlert($this->user, -1000, Currency::Eur->value);
    dmcAlert($this->user, -9900, 'ZAR');

    Livewire::actingAs($this->user)->test(DashboardDriftBadge::class)
        ->assertSee('8.00')
        ->assertSee('ZAR');
});
