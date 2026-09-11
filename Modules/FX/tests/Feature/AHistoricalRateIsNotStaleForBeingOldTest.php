<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;

uses(RefreshDatabase::class);

// Staleness answers "could this device not get the rate it needed", and the
// rate a historical figure needs is the one that priced its own day. Measured
// against today instead, every point of a net-worth series reports stale for
// the sole reason that the past is not the present.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
function stalenessRate(string $rateDate, string $quote = 'USD', string $rate = '1.1000'): void
{
    app(DatabaseManager::class)->connection()->table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => $quote,
        'rate' => $rate,
        'rate_date' => $rateDate,
        'source' => 'test-feed',
        'created_at' => $rateDate.' 00:00:00',
        'updated_at' => $rateDate.' 00:00:00',
    ]);
}

it('does not call a rate stale that priced the very day it is asked about', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 12:00:00'));

    stalenessRate('2026-07-03');

    $converted = app(ExchangeRateService::class)->convertAtDate(
        Money::ofMinor(10000, 'USD'),
        'EUR',
        '2026-07-03',
    );

    expect($converted->asOf?->toDateString())->toBe('2026-07-03')
        ->and($converted->isStale)->toBeFalse();

    CarbonImmutable::setTestNow();
});

// The other half: a rate genuinely too far from the day it is pricing is still
// stale, so the fix narrows what counts rather than silencing the signal.
it('still calls a rate stale when it is far from the day it prices', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 12:00:00'));

    stalenessRate('2026-06-05');

    $converted = app(ExchangeRateService::class)->convertAtDate(
        Money::ofMinor(10000, 'USD'),
        'EUR',
        '2026-09-07',
    );

    expect($converted->asOf?->toDateString())->toBe('2026-06-05')
        ->and($converted->isStale)->toBeTrue();

    CarbonImmutable::setTestNow();
});

// A figure priced for today keeps the old meaning exactly: the newest rate held
// is compared against today, because today is the day it is pricing.
it('measures a figure priced for today against today', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 12:00:00'));

    stalenessRate('2026-09-10');

    $converted = app(ExchangeRateService::class)->convertToBase(Money::ofMinor(10000, 'USD'), 'EUR');

    expect($converted->isStale)->toBeFalse();

    CarbonImmutable::setTestNow();
});
