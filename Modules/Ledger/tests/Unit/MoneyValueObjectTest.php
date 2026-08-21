<?php

declare(strict_types=1);

use Modules\Ledger\Public\Exceptions\CurrencyMismatchException;
use Modules\Ledger\Public\ValueObjects\Money;

it('round-trips an integer minor amount', function (): void {
    $m = Money::ofMinor(1234, 'EUR');

    expect($m->toMinor())->toBe(1234);
    expect($m->currency())->toBe('EUR');
});

it('adds two same-currency Moneys', function (): void {
    $sum = Money::ofMinor(100, 'EUR')->plus(Money::ofMinor(50, 'EUR'));

    expect($sum->toMinor())->toBe(150);
});

it('subtracts two same-currency Moneys', function (): void {
    $diff = Money::ofMinor(100, 'EUR')->minus(Money::ofMinor(30, 'EUR'));

    expect($diff->toMinor())->toBe(70);
});

it('throws when adding mixed currencies', function (): void {
    expect(fn () => Money::ofMinor(100, 'EUR')->plus(Money::ofMinor(50, 'USD')))
        ->toThrow(CurrencyMismatchException::class);
});

it('reports negative amounts as negative', function (): void {
    expect(Money::ofMinor(-100, 'EUR')->isNegative())->toBeTrue();
    expect(Money::ofMinor(100, 'EUR')->isNegative())->toBeFalse();
    expect(Money::ofMinor(0, 'EUR')->isNegative())->toBeFalse();
});

it('exposes no float-based factory entrypoint', function (): void {
    $reflection = new ReflectionClass(Money::class);
    $factoryMethods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
    );

    expect($factoryMethods)->toContain('ofMinor');
    expect($factoryMethods)->not->toContain('ofFloat');
    expect($factoryMethods)->not->toContain('fromString');
});
