<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;

function seedRateOn(string $date, string $quote, string $rate, string $source = 'ecb'): void
{
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

describe('a historical conversion', function (): void {
    beforeEach(function (): void {
        $this->service = new ExchangeRateService(app(DatabaseManager::class));
    });

    it('converts a Sunday balance at the Friday rate instead of dropping the whole line', function (): void {
        seedRateOn('2026-06-05', 'USD', '1.1359');

        $result = $this->service->convertAtDate(Money::ofMinor(10000, 'USD'), 'EUR', '2026-06-07');

        expect($result->converted->currency())->toBe('EUR')
            ->and($result->converted->toMinor())->toBe(8804)
            ->and($result->asOf?->toDateString())->toBe('2026-06-05');
    });

    it('converts a date older than every stored rate rather than leaving the account out', function (): void {
        seedRateOn('2026-06-05', 'USD', '1.1359');

        $result = $this->service->convertAtDate(Money::ofMinor(10000, 'USD'), 'EUR', '2026-01-15');

        expect($result->converted->currency())->toBe('EUR')
            ->and($result->asOf?->toDateString())->toBe('2026-06-05');
    });

    it('prefers the rate already in effect over a newer one published after the date', function (): void {
        seedRateOn('2026-06-05', 'USD', '1.1000');
        seedRateOn('2026-06-10', 'USD', '1.5000');

        $result = $this->service->convertAtDate(Money::ofMinor(11000, 'USD'), 'EUR', '2026-06-07');

        expect($result->converted->toMinor())->toBe(10000)
            ->and($result->asOf?->toDateString())->toBe('2026-06-05');
    });

    it('keeps a pair whose newest row predates the date the other pair already covers', function (): void {
        seedRateOn('2026-06-02', 'USD', '1.1359');
        seedRateOn('2026-05-28', 'GBP', '0.83895');

        $result = $this->service->convertAtDate(Money::ofMinor(10000, 'GBP'), 'EUR', '2026-06-03');

        expect($result->converted->currency())->toBe('EUR')
            ->and($result->asOf?->toDateString())->toBe('2026-05-28');
    });

    it('reads the rate table once for a date it has already been asked about', function (): void {
        seedRateOn('2026-06-05', 'USD', '1.1359');
        seedRateOn('2026-06-05', 'GBP', '0.83895');

        DB::enableQueryLog();
        $this->service->convertAtDate(Money::ofMinor(10000, 'USD'), 'EUR', '2026-06-07');
        $this->service->convertAtDate(Money::ofMinor(20000, 'GBP'), 'EUR', '2026-06-07');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1);
    });
});
