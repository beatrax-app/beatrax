<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Modules\Ledger\Models\Transaction;

// brick/math 0.18 turns a float argument into an int, signalled only by a
// deprecation notice production log levels never show. SQLite hands REAL
// columns back as floats, so every rate derived from fx_rate_used collapsed to
// zero and the detail page rendered "€0.000 / USD" against a stored 0.92917629.

it('keeps the stored rate a string, not a float', function (): void {
    $model = new Transaction;

    // Read off the model rather than a fixture: the cast is the fix, and a
    // fixture that happened to hold a string would pass without it.
    expect($model->getCasts())->toHaveKey('fx_rate_used')
        ->and($model->getCasts()['fx_rate_used'])->toBe('string');
});

it('shows what a float argument costs, so the cast is not mistaken for ceremony', function (): void {
    expect((string) BigDecimal::of('0.92917629')->toScale(3, RoundingMode::HalfUp))->toBe('0.929');

    // BigNumber::of() does not accept float at all; PHP coerces the argument to
    // int on the way in, which is why the precision vanishes without raising.
    // Read off the signature so the runtime's deprecation handling cannot skew it.
    $accepted = (string) (new ReflectionMethod(BigNumber::class, 'of'))->getParameters()[0]->getType();

    expect($accepted)->not->toContain('float')
        ->and($accepted)->toContain('string');
});
