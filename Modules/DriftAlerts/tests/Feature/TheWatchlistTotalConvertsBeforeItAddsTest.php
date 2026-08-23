<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\SubscriptionDriftWatchPage;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\DriftAlerts\Public\Services\SubscriptionDriftWatchQuery;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;

// A Revolut import carries a currency per row, so the subscription watchlist
// holds a euro subscription beside a dollar one. recurring_series.monthly_equivalent_minor
// is denominated in the series' own latest_currency, and /drift/watch added the
// integers and stamped the reader's sign on the sum. Measured with a EUR100.00
// and a USD100.00 subscription at a dollar priced 2.0 to the euro: EUR200.00
// per month total, against a true EUR150.00.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);

    // The bundled snapshot ships a rate for every major, and one case here
    // turns on a pair having none at all, so this suite builds its own world.
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'drift-multi-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function dwRate(DatabaseManager $db, string $quote, string $rate): void
{
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => $quote,
        'rate_date' => '2026-08-23',
        'rate' => $rate,
        'source' => 'ecb',
        'created_at' => '2026-08-23 00:00:00',
        'updated_at' => '2026-08-23 00:00:00',
    ]);
}

function dwTransaction(DatabaseManager $db, int $userId, int $cpId, int $minor, string $currency, string $date): int
{
    $hex = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Revolut '.$hex, 'slug' => 'rev-'.$hex, 'kind' => 'bank',
        'iban' => 'GB00REV'.strtoupper($hex), 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'revolut-csv', 'raw_file_path' => '/tmp/rev-'.$hex.'.csv',
        'sha256' => hash('sha256', 'rev-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'counterparty_id' => $cpId,
        'fingerprint' => hash('sha256', 'rev-fp-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => $date, 'booked_at' => $date.' 12:00:00', 'value_date' => $date,
        'amount_minor' => $minor, 'currency' => $currency,
        'settled_amount_minor' => $minor, 'settled_currency' => $currency,
        'counterparty_normalized' => 'sub', 'counterparty_name' => 'SUB', 'normalization_version' => 1,
        'description' => 'subscription', 'type' => 'expense',
        'source_format' => 'revolut-csv', 'source_row_index' => 1,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function dwSubscription(DatabaseManager $db, int $userId, string $merchant, int $monthlyMinor, string $currency): int
{
    $hex = bin2hex(random_bytes(4));

    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => mb_strtolower($merchant).'-'.$hex,
        'display_name' => $merchant, 'merchant_name' => $merchant,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => $merchant,
        'state' => 'approved', 'cadence' => 'monthly',
        'latest_amount_minor' => $monthlyMinor, 'latest_currency' => $currency,
        'monthly_equivalent_minor' => $monthlyMinor, 'variance_tolerance_percent' => 25,
        'cluster_key' => $merchant.'|monthly|'.$currency.'|'.$hex,
        'cluster_counterparty_key' => mb_strtolower($merchant).'-'.$hex,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    foreach (['2026-06-01', '2026-07-01'] as $date) {
        $txId = dwTransaction($db, $userId, $cpId, $monthlyMinor, $currency, $date);
        $db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
            'observed_at' => $date, 'observed_amount_minor' => $monthlyMinor, 'observed_currency' => $currency,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    return $seriesId;
}

it('converts the dollar subscription instead of adding its cents to the euro one', function (): void {
    dwSubscription($this->db, $this->user->id, 'Alpha', -10_000, Currency::Eur->value);
    dwSubscription($this->db, $this->user->id, 'Beta', -10_000, Currency::Usd->value);
    dwRate($this->db, Currency::Usd->value, '2.0');

    $query = app(SubscriptionDriftWatchQuery::class);
    $total = $query->monthlyTotalFor($this->user, $query->forUser($this->user));

    expect($total->minor)->toBe(15_000)
        ->and($total->currency)->toBe(Currency::Eur->value)
        ->and($total->isPartial())->toBeFalse();
});

// Never a silent one to one: a subscription whose pair the rate table cannot
// reach is left out of the figure and named.
it('leaves out a subscription it has no rate for and names its currency', function (): void {
    dwSubscription($this->db, $this->user->id, 'Alpha', -10_000, Currency::Eur->value);
    dwSubscription($this->db, $this->user->id, 'Beta', -10_000, Currency::Usd->value);
    dwSubscription($this->db, $this->user->id, 'Gamma', -500_000, Currency::Jpy->value);
    dwRate($this->db, Currency::Usd->value, '2.0');

    $query = app(SubscriptionDriftWatchQuery::class);
    $total = $query->monthlyTotalFor($this->user, $query->forUser($this->user));

    expect($total->minor)->toBe(15_000)
        ->and($total->unconverted)->toBe([Currency::Jpy->value]);
});

it('prints the converted per-month total on /drift/watch rather than the added cents', function (): void {
    dwSubscription($this->db, $this->user->id, 'Alpha', -10_000, Currency::Eur->value);
    dwSubscription($this->db, $this->user->id, 'Beta', -10_000, Currency::Usd->value);
    dwRate($this->db, Currency::Usd->value, '2.0');

    $html = Livewire::test(SubscriptionDriftWatchPage::class)->html();

    expect($html)->toContain('€150.00')
        ->and($html)->not->toContain('€200.00');
});

it('says on /drift/watch which currency the per-month total could not reach', function (): void {
    dwSubscription($this->db, $this->user->id, 'Alpha', -10_000, Currency::Eur->value);
    dwSubscription($this->db, $this->user->id, 'Gamma', -500_000, Currency::Jpy->value);

    $html = Livewire::test(SubscriptionDriftWatchPage::class)->html();

    expect($html)->toContain(Currency::Jpy->value.' not converted');
});

// "Ways to save", ordered by what each costs the reader: on raw minor units a
// USD150.00 subscription outranked a EUR100.00 one while being the cheaper of
// the two.
it('ranks savings insights by what they cost in the reader’s currency', function (): void {
    dwSubscription($this->db, $this->user->id, 'Spotify', -10_000, Currency::Eur->value);
    dwSubscription($this->db, $this->user->id, 'Adobe', -15_000, Currency::Usd->value);
    dwRate($this->db, Currency::Usd->value, '2.0');

    $insights = app(SavingsInsightsQuery::class)->forUser($this->user);

    expect(array_map(static fn ($insight): string => $insight->name, $insights))
        ->toBe(['Spotify', 'Adobe']);
});
