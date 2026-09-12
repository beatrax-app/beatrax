<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Dto\RateUsed;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;

// The bundled snapshot ships dated 2026-06-05 and quotes EUR/JPY at 159.10,
// which is a euro-per-yen rate of 0.00628536. On 2026-09-12 that snapshot is
// ninety-nine days old, and it is the rate a fresh install converts at for as
// long as online fetching stays off — which is the default. Planting a
// ¥480,000 expense moved a dashboard tile from EUR 2,101.82 to EUR 5,118.79
// and the tile named neither the rate, nor the snapshot, nor the ninety-nine
// days, because ConvertedTotal carried none of the three.

// Named for this file: a bare crossRate() is already owned by another case in
// this module's suite, and two files in one Pest namespace cannot both declare
// it.
if (! function_exists('crossRateToday')) {
    function crossRateToday(string $quote, string $rate): void
    {
        app(DatabaseManager::class)->connection()->table('exchange_rates')->updateOrInsert(
            [
                'base_currency' => Currency::Eur->value,
                'quote_currency' => $quote,
                'rate_date' => CarbonImmutable::now()->toDateString(),
                'source' => 'ecb',
            ],
            [
                'rate' => $rate,
                'created_at' => CarbonImmutable::now()->toDateTimeString(),
                'updated_at' => CarbonImmutable::now()->toDateTimeString(),
            ],
        );
    }
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-09-12 09:00:00'));

afterEach(fn () => CarbonImmutable::setTestNow(null));

it('carries the rate, its source and its as-of date into the figure they made', function (): void {
    $total = app(CrossCurrencyTotal::class)->of(
        [Currency::Eur->value => 210_182, Currency::Jpy->value => 480_000],
        Currency::Eur->value,
    );

    expect($total->minor)->toBe(511_879)
        ->and($total->isPartial())->toBeFalse();

    $used = $total->rates->usedFor(Currency::Jpy->value);

    expect($used)->not->toBeNull()
        ->and($used->from)->toBe(Currency::Jpy->value)
        ->and($used->to)->toBe(Currency::Eur->value)
        ->and($used->rate)->toBe('0.00628536')
        ->and($used->source)->toBe(BundledRates::SOURCE)
        ->and($used->asOf?->toDateString())->toBe('2026-06-05')
        ->and($used->isStale)->toBeTrue()
        ->and($used->ageInDaysAt(CarbonImmutable::now()))->toBe(99);
});

// A three-day threshold makes "stale" true of a rate published on Friday and
// read on Wednesday, and true of this one. The reader cannot act on the
// difference without the day itself.
it('separates a snapshot ninety-nine days old from a rate fetched this morning', function (): void {
    $fx = app(CrossCurrencyTotal::class);

    $bundled = $fx->of([Currency::Jpy->value => 480_000], Currency::Eur->value)->disclosure();

    expect($bundled->ageInDaysAt(CarbonImmutable::now()))->toBe(99)
        ->and($bundled->isStale())->toBeTrue()
        ->and($bundled->sourceLabel())->toBe(Lang::get('core::fx.source_bundled'));

    crossRateToday(Currency::Jpy->value, '159.10000000');

    $fresh = app(CrossCurrencyTotal::class)->of([Currency::Jpy->value => 480_000], Currency::Eur->value)->disclosure();

    expect($fresh->ageInDaysAt(CarbonImmutable::now()))->toBe(0)
        ->and($fresh->isStale())->toBeFalse()
        ->and($fresh->staleNote(null))->toBeNull()
        ->and($fresh->sourceLabel())->toBe(Lang::get('core::fx.source_ecb'));
});

it('names the oldest leg, so one fresh pair cannot date a figure the other half of which is stale', function (): void {
    crossRateToday(Currency::Usd->value, '1.08000000');

    $disclosure = app(CrossCurrencyTotal::class)->of([
        Currency::Eur->value => 100_00,
        Currency::Usd->value => 100_00,
        Currency::Jpy->value => 480_000,
    ], Currency::Eur->value)->disclosure();

    expect($disclosure->rates)->toHaveCount(2)
        ->and($disclosure->asOf()?->toDateString())->toBe('2026-06-05')
        ->and($disclosure->source())->toBe(BundledRates::SOURCE)
        ->and($disclosure->isStale())->toBeTrue();
});

// The buckets a figure was built from, not every pair the render happened to
// read: six cards share one batched rate lookup.
it('discloses only the rates the figure was actually built from', function (): void {
    $fx = app(CrossCurrencyTotal::class);
    $rates = $fx->ratesTo([Currency::Jpy->value, Currency::Usd->value], Currency::Eur->value);

    expect($rates->codes())->toBe([Currency::Jpy->value, Currency::Usd->value]);

    $disclosure = $fx->withRates([Currency::Jpy->value => 480_000], Currency::Eur->value, $rates)->disclosure();

    expect(array_map(static fn (RateUsed $used): string => $used->from, $disclosure->rates))
        ->toBe([Currency::Jpy->value]);
});

it('says nothing at all about a figure that converted nothing', function (): void {
    $total = app(CrossCurrencyTotal::class)->of([Currency::Eur->value => 210_182], Currency::Eur->value);

    expect($total->rates->isEmpty())->toBeTrue()
        ->and($total->disclosure()->isEmpty())->toBeTrue()
        ->and(ConversionDisclosure::none()->isEmpty())->toBeTrue();
});

// AED and not ZAR: the bundled snapshot quotes thirty currencies and the rand
// is one of them, so a rand bucket converts and names nothing.
it('still names what it left out when no rate reached a bucket', function (): void {
    $total = app(CrossCurrencyTotal::class)->of(
        [Currency::Eur->value => 210_182, 'AED' => 111_100],
        Currency::Eur->value,
    );

    $disclosure = $total->disclosure();

    expect($disclosure->unconverted)->toBe(['AED'])
        ->and($disclosure->isPartial())->toBeTrue()
        ->and($disclosure->hasRates())->toBeFalse()
        ->and($disclosure->isEmpty())->toBeFalse();
});
