<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Internal\Http\Livewire\RecurringPage;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

// A Revolut import carries a currency per row, so one reader holds a euro
// subscription and a dollar one at once. recurring_series.monthly_equivalent_minor
// is derived from latest_amount_minor, so it is denominated in the series' own
// latest_currency -- and the net-flow strip added the two integers and stamped
// the reader's sign on the sum. Measured on /recurring: a EUR100.00 and a
// USD100.00 subscription beside EUR2,000.00 of salary drew EUR200.00 of
// expenses and EUR1,800.00 net, at a dollar priced 2.0 to the euro.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);

    // The bundled snapshot ships a rate for every major, and one case here
    // turns on a pair having none at all, so this suite builds its own world.
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'rec-multi-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function recSeries(DatabaseManager $db, int $userId, string $direction, int $monthlyMinor, string $currency): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => $direction,
        'detected_name' => 'Series '.$hex,
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => $monthlyMinor,
        'latest_currency' => $currency,
        'monthly_equivalent_minor' => $monthlyMinor,
        'cluster_key' => 'cluster-'.$hex,
        'cluster_counterparty_key' => 'cp-'.$hex,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function recRate(DatabaseManager $db, string $quote, string $rate): void
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

function recEuroAndDollarSubscriptions(DatabaseManager $db, int $userId): void
{
    recSeries($db, $userId, Direction::Expense->value, -10_000, Currency::Eur->value);
    recSeries($db, $userId, Direction::Expense->value, -10_000, Currency::Usd->value);
    recSeries($db, $userId, Direction::Income->value, 200_000, Currency::Eur->value);
}

it('converts the dollar subscription instead of adding its cents to the euro one', function (): void {
    recEuroAndDollarSubscriptions($this->db, $this->user->id);
    recRate($this->db, Currency::Usd->value, '2.0');

    $totals = app(FixedPaymentsViewQuery::class)->monthlyEquivalentTotals($this->user);

    expect($totals->expense->toMinor())->toBe(-15_000)
        ->and($totals->expense->currency())->toBe(Currency::Eur->value)
        ->and($totals->income->toMinor())->toBe(200_000)
        ->and($totals->net->toMinor())->toBe(185_000)
        ->and($totals->isPartial())->toBeFalse();
});

// Never a silent one to one: a series whose pair the rate table cannot reach
// is left out of the figure and named, so the reader can see it is partial.
it('leaves out a series it has no rate for and names its currency', function (): void {
    recEuroAndDollarSubscriptions($this->db, $this->user->id);
    recSeries($this->db, $this->user->id, Direction::Expense->value, -500_000, Currency::Jpy->value);
    recRate($this->db, Currency::Usd->value, '2.0');

    $totals = app(FixedPaymentsViewQuery::class)->monthlyEquivalentTotals($this->user);

    expect($totals->expense->toMinor())->toBe(-15_000)
        ->and($totals->unconverted)->toBe([Currency::Jpy->value])
        ->and($totals->isPartial())->toBeTrue();
});

it('prints the converted net flow on /recurring rather than the added cents', function (): void {
    recEuroAndDollarSubscriptions($this->db, $this->user->id);
    recRate($this->db, Currency::Usd->value, '2.0');

    $html = Livewire::test(RecurringPage::class)->html();

    expect($html)->toContain('€150.00')
        ->and($html)->toContain('€1,850.00')
        ->and($html)->not->toContain('€200.00')
        ->and($html)->not->toContain('€1,800.00');
});

it('says on /recurring which currency the net flow could not reach', function (): void {
    recEuroAndDollarSubscriptions($this->db, $this->user->id);
    recSeries($this->db, $this->user->id, Direction::Expense->value, -500_000, Currency::Jpy->value);
    recRate($this->db, Currency::Usd->value, '2.0');

    $html = Livewire::test(RecurringPage::class)->html();

    expect($html)->toContain(Currency::Jpy->value.' not converted');
});

// The shadow beside a foreign row's own amount is the reader's currency, so
// the figure under it has to have been through a rate: USD100.00 rendered a
// EUR100.00 shadow, which is the raw minor units relabelled.
it('shadows a foreign row with a converted amount, not the same integer relabelled', function (): void {
    recEuroAndDollarSubscriptions($this->db, $this->user->id);
    recRate($this->db, Currency::Usd->value, '2.0');

    $html = Livewire::test(RecurringPage::class)->html();

    $shadows = [];
    preg_match_all('/data-eur-shadow="true">([^<]+)</u', $html, $matches);
    foreach ($matches[1] as $shadow) {
        $shadows[] = trim($shadow);
    }

    expect($shadows)->toBe(['-€50.00']);
});
