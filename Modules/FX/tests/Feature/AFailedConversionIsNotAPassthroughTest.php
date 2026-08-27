<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\FX\Public\Enums\ConversionOutcome;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;

describe('a conversion that found no rate', function (): void {
    beforeEach(function (): void {
        $this->service = new ExchangeRateService(app(DatabaseManager::class));
    });

    it('is not reported as a figure that was already in the base currency', function (): void {
        $result = $this->service->convertToBase(Money::ofMinor(10000, 'SAR'), 'EUR');

        expect($result->outcome)->toBe(ConversionOutcome::NoRate)
            ->and($result->isPassthrough)->toBeFalse()
            ->and($result->converted->currency())->toBe('SAR');
    });

    it('is not reported as a passthrough when the pair is missing at a date', function (): void {
        $result = $this->service->convertAtDate(Money::ofMinor(10000, 'SAR'), 'EUR', '2026-06-07');

        expect($result->outcome)->toBe(ConversionOutcome::NoRate)
            ->and($result->isPassthrough)->toBeFalse();
    });

    it('is not reported as a passthrough when the rate table is empty', function (): void {
        DB::table('exchange_rates')->delete();

        $result = $this->service->convertToBase(Money::ofMinor(10000, 'USD'), 'EUR');

        expect($result->outcome)->toBe(ConversionOutcome::NoRate)
            ->and($result->isPassthrough)->toBeFalse();
    });

    it('still reports a base-currency figure as a passthrough', function (): void {
        $result = $this->service->convertToBase(Money::ofMinor(10000, 'EUR'), 'EUR');

        expect($result->outcome)->toBe(ConversionOutcome::Passthrough)
            ->and($result->isPassthrough)->toBeTrue();
    });

    it('reports a conversion that used a rate as converted', function (): void {
        $result = $this->service->convertToBase(Money::ofMinor(10000, 'USD'), 'EUR');

        expect($result->outcome)->toBe(ConversionOutcome::Converted)
            ->and($result->isPassthrough)->toBeFalse();
    });
});
