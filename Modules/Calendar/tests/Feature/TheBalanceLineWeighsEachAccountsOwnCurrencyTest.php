<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\Internal\Services\DailyBalanceAggregator;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

// AccountStartingBalanceQuery::bucketedByDefaultCurrency groups the baselines
// of several accounts by the currency each is denominated in, which the
// /settings picker can now make differ. The bucket is the whole guard: a
// consumer that re-added the groups would put dollar cents on the euro line.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'blwc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function blwcAccount(DatabaseManager $db, int $userId, string $currency, int $baselineMinor): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'blwc '.$currency,
        'slug' => 'blwc-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00BLWC'.strtoupper($hex),
        'default_currency' => $currency,
        'opening_balance_minor' => $baselineMinor,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

it('prices a dollar account baseline before it joins the euro balance line', function (): void {
    $euro = blwcAccount($this->db, $this->user->id, Currency::Eur->value, 100_000);
    $dollar = blwcAccount($this->db, $this->user->id, Currency::Usd->value, 100_000);

    $result = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$euro, $dollar],
        $this->user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    // The bundled snapshot prices USD1,000.00 at EUR880.36, so a past day the
    // overlay owns reads EUR1,880.36 — never EUR2,000.00.
    expect($result['map']['2026-06-05']->minor)->toBe(188_036);
});

it('leaves a single-currency line at its face value', function (): void {
    $one = blwcAccount($this->db, $this->user->id, Currency::Eur->value, 100_000);
    $two = blwcAccount($this->db, $this->user->id, Currency::Eur->value, 100_000);

    $result = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$one, $two],
        $this->user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($result['map']['2026-06-05']->minor)->toBe(200_000);
});

// A currency the bundled snapshot does not carry: the rate table cannot reach
// it, and its minor units are not euro cents.
it('leaves a currency it has no rate for off the line rather than counting it at par', function (): void {
    $euro = blwcAccount($this->db, $this->user->id, Currency::Eur->value, 100_000);
    $unpriced = blwcAccount($this->db, $this->user->id, 'AED', 100_000);

    $result = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$euro, $unpriced],
        $this->user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    // The figure alone cannot tell "left off the line" from "counted at par":
    // both are one integer. The day's own codes are the channel that can, and
    // they are what the page folds up, so this cannot drift from what renders.
    expect($result['map']['2026-06-05']->minor)->toBe(100_000)
        ->and($result['map']['2026-06-05']->unconvertedCurrencies)->toBe(['AED']);
});

it('names nothing when every currency on the line could be priced', function (): void {
    $euro = blwcAccount($this->db, $this->user->id, Currency::Eur->value, 100_000);
    $dollar = blwcAccount($this->db, $this->user->id, Currency::Usd->value, 100_000);

    $result = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$euro, $dollar],
        $this->user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($result['map']['2026-06-05']->unconvertedCurrencies)->toBe([]);
});

// convertToBase() reads the whole exchange_rates table on every call, and the
// map spans a 365-day forecast horizon plus the month's past days.
it('prices each currency once for the whole month, not once per day', function (): void {
    $euro = blwcAccount($this->db, $this->user->id, Currency::Eur->value, 100_000);
    $dollar = blwcAccount($this->db, $this->user->id, Currency::Usd->value, 100_000);

    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'exchange_rates')) {
            $reads++;
        }
    });

    app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$euro, $dollar],
        $this->user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($reads)->toBeLessThanOrEqual(2);
});
