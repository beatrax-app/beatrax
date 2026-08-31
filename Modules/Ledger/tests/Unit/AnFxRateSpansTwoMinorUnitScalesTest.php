<?php

declare(strict_types=1);

use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\Rate;

it('reads a yen leg at the yen scale, not the hundredth every other pair shares', function (): void {
    // 10,000 yen settled at 58 euro. The pair holds 10000 and 5800 minor
    // units, whose ratio is 0.58 -- a hundred times the rate a reader is
    // owed, because a yen has no minor unit and a euro has two.
    $rate = Rate::between(Money::ofMinor(-5800, 'EUR'), Money::ofMinor(-10000, 'JPY'));

    expect((string) $rate)->toBe('0.00580000');
});

it('reads the same pair inverted', function (): void {
    $rate = Rate::between(Money::ofMinor(-10000, 'JPY'), Money::ofMinor(-5800, 'EUR'));

    expect((string) $rate)->toBe('172.41379310');
});

it('leaves a pair that does share one scale exactly where it was', function (): void {
    $rate = Rate::between(Money::ofMinor(-460, 'EUR'), Money::ofMinor(-500, 'USD'));

    expect((string) $rate)->toBe('0.92000000');
});

it('has no rate to give for a zero denominator', function (): void {
    expect(Rate::between(Money::ofMinor(-5800, 'EUR'), Money::ofMinor(0, 'JPY')))->toBeNull();
});

it('keeps a small rate legible instead of rounding it to three decimals', function (): void {
    $rate = Rate::between(Money::ofMinor(-5800, 'EUR'), Money::ofMinor(-10000, 'JPY'));

    expect($rate?->forDisplay())->toBe('0.00580');
});

it('leaves a rate near one at three decimals', function (): void {
    $rate = Rate::between(Money::ofMinor(-460, 'EUR'), Money::ofMinor(-500, 'USD'));

    expect($rate?->forDisplay())->toBe('0.920');
});

it('leaves a rate above one at three decimals', function (): void {
    $rate = Rate::between(Money::ofMinor(-10000, 'JPY'), Money::ofMinor(-5800, 'EUR'));

    expect($rate?->forDisplay())->toBe('172.414');
});
