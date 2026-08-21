<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @param  array<string, string>  $rates  Map of quote_currency => rate string
 */
function seedRates(string $date, array $rates, string $source = 'ecb'): void
{
    foreach ($rates as $quote => $rate) {
        DB::table('exchange_rates')->insert([
            'base_currency' => 'EUR',
            'quote_currency' => $quote,
            'rate_date' => $date,
            'rate' => $rate,
            'source' => $source,
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }
}

describe('ExchangeRateService', function (): void {
    beforeEach(function (): void {
        $this->service = new ExchangeRateService(app(DatabaseManager::class));
    });

    it('returns passthrough (isPassthrough=true) with zero DB queries when currencies match (D-03)', function (): void {
        $money = Money::ofMinor(10000, 'EUR');

        DB::enableQueryLog();
        $result = $this->service->convertToBase($money, 'EUR');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($result->isPassthrough)->toBeTrue()
            ->and($result->original->toMinor())->toBe(10000)
            ->and($result->converted->toMinor())->toBe(10000)
            ->and($result->rate)->toBeNull()
            ->and($result->source)->toBeNull()
            ->and($result->asOf)->toBeNull()
            ->and($result->isStale)->toBeFalse()
            ->and($queries)->toBeEmpty();
    });

    it('converts EUR to USD at the latest stored rate', function (): void {
        seedRates('2026-06-05', ['USD' => '1.1359', 'GBP' => '0.83895']);

        $money = Money::ofMinor(10000, 'EUR'); // EUR 100.00

        $result = $this->service->convertToBase($money, 'USD');

        expect($result->isPassthrough)->toBeFalse()
            ->and($result->original->currency())->toBe('EUR')
            ->and($result->converted->currency())->toBe('USD')
            ->and($result->converted->toMinor())->toBeGreaterThan(0)
            ->and($result->rate)->toBeString()
            ->and($result->source)->toBe('ecb')
            ->and($result->asOf)->toBeInstanceOf(CarbonImmutable::class);

        // EUR 100.00 at rate 1.1359 = USD 113.59
        expect($result->converted->toMinor())->toBe(11359);
    });

    it('derives cross-rate USD→GBP via EUR base', function (): void {
        seedRates('2026-06-05', ['USD' => '1.1359', 'GBP' => '0.83895']);

        $money = Money::ofMinor(11359, 'USD');

        $result = $this->service->convertToBase($money, 'GBP');

        expect($result->isPassthrough)->toBeFalse()
            ->and($result->converted->currency())->toBe('GBP');

        // USD→GBP cross-rate via EUR: (EUR/GBP) / (EUR/USD) = 0.83895 / 1.1359
        // USD 113.59 × (0.83895 / 1.1359) ≈ GBP 83.89 (minor = 8389 or 8390)
        expect($result->converted->toMinor())->toBeGreaterThan(8380)
            ->and($result->converted->toMinor())->toBeLessThan(8400);
    });

    it('sets isStale=true when the latest rate_date is older than 3 days', function (): void {
        $staleDate = CarbonImmutable::now()->subDays(4)->toDateString();
        seedRates($staleDate, ['USD' => '1.1359']);

        $money = Money::ofMinor(10000, 'EUR');

        $result = $this->service->convertToBase($money, 'USD');

        expect($result->isStale)->toBeTrue();
    });

    it('does not set isStale when the rate is within 3 days', function (): void {
        $freshDate = CarbonImmutable::now()->subDays(2)->toDateString();
        seedRates($freshDate, ['USD' => '1.1359']);

        $money = Money::ofMinor(10000, 'EUR');

        $result = $this->service->convertToBase($money, 'USD');

        expect($result->isStale)->toBeFalse();
    });

    it('convertAtDate uses the supplied knownRate string for the conversion', function (): void {
        // No exchange_rates rows seeded: knownRate has to be used directly.
        $money = Money::ofMinor(10000, 'EUR');

        $result = $this->service->convertAtDate($money, 'USD', '2026-01-15', '1.20000000');

        expect($result->isPassthrough)->toBeFalse()
            ->and($result->converted->currency())->toBe('USD')
            ->and($result->source)->toBe('transaction')
            ->and($result->converted->toMinor())->toBe(12000); // EUR 100 × 1.2 = USD 120
    });

    it('convertAtDate falls back to dated exchange_rates row when knownRate is null', function (): void {
        seedRates('2026-01-15', ['USD' => '1.1500']);

        $money = Money::ofMinor(10000, 'EUR');

        $result = $this->service->convertAtDate($money, 'USD', '2026-01-15');

        expect($result->isPassthrough)->toBeFalse()
            ->and($result->converted->currency())->toBe('USD')
            ->and($result->converted->toMinor())->toBe(11500);
    });

    it('rate on ConversionResult is a string, never a float', function (): void {
        seedRates('2026-06-05', ['USD' => '1.1359']);

        $money = Money::ofMinor(10000, 'EUR');
        $result = $this->service->convertToBase($money, 'USD');

        expect($result->rate)->toBeString();
    });
});
