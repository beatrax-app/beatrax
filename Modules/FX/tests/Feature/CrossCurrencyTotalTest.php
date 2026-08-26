<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

function crossRate(DatabaseManager $db, string $quote, string $rate): void
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

it('converts each bucket at its own rate before it adds anything', function (): void {
    crossRate($this->db, Currency::Usd->value, '2.0');

    $total = app(CrossCurrencyTotal::class)->of(
        [Currency::Eur->value => 10_000, Currency::Usd->value => 10_000],
        Currency::Eur->value,
    );

    expect($total->minor)->toBe(15_000)
        ->and($total->currency)->toBe(Currency::Eur->value)
        ->and($total->isPartial())->toBeFalse();
});

// Never a silent one to one: a bucket whose pair the table cannot reach is
// left out of the figure and named, which is what lets the reader see that
// the figure is partial.
it('leaves out a currency it has no rate for and names it', function (): void {
    crossRate($this->db, Currency::Usd->value, '2.0');

    $total = app(CrossCurrencyTotal::class)->of(
        [Currency::Eur->value => 10_000, Currency::Usd->value => 10_000, Currency::Jpy->value => 500_000],
        Currency::Eur->value,
    );

    expect($total->minor)->toBe(15_000)
        ->and($total->unconverted)->toBe([Currency::Jpy->value])
        ->and($total->isPartial())->toBeTrue()
        ->and($total->unconvertedList())->toBe(Currency::Jpy->value);
});

it('names every unreachable currency, in a stable order', function (): void {
    $total = app(CrossCurrencyTotal::class)->of(
        [Currency::Usd->value => 1, Currency::Jpy->value => 1, Currency::Gbp->value => 1],
        Currency::Eur->value,
    );

    expect($total->minor)->toBe(0)
        ->and($total->unconverted)->toBe([Currency::Gbp->value, Currency::Jpy->value, Currency::Usd->value]);
});

it('reads the reader’s own currency as the one that needs no rate', function (): void {
    crossRate($this->db, Currency::Usd->value, '2.0');

    $total = app(CrossCurrencyTotal::class)->of(
        [Currency::Usd->value => 10_000, Currency::Eur->value => 10_000],
        Currency::Usd->value,
    );

    expect($total->minor)->toBe(30_000)
        ->and($total->currency)->toBe(Currency::Usd->value)
        ->and($total->isPartial())->toBeFalse();
});

// The rate table is read once per currency, not once per bucket: a roll-up
// with twelve monthly buckets in two currencies must not issue twenty-four
// reads of the whole exchange_rates table.
it('fetches one rate per currency however many buckets share it', function (): void {
    crossRate($this->db, Currency::Usd->value, '2.0');

    $service = app(CrossCurrencyTotal::class);
    $rates = $service->ratesTo([Currency::Usd->value, Currency::Usd->value, Currency::Eur->value], Currency::Eur->value);

    $queries = 0;
    $this->db->connection()->listen(function () use (&$queries): void {
        $queries++;
    });

    $months = 0;
    foreach (range(1, 12) as $ignored) {
        $months += $service->withRates(
            [Currency::Eur->value => 100, Currency::Usd->value => 100],
            Currency::Eur->value,
            $rates,
        )->minor;
    }

    expect($rates)->toBe([Currency::Usd->value => '0.50000000'])
        ->and($months)->toBe(12 * 150)
        ->and($queries)->toBe(0);
});
