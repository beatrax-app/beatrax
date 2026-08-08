<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Modules\Ledger\Models\Transaction;

/*
 * brick/math 0.18 converts a float argument to `BigNumber::of()` into an int.
 * `0.92917629` becomes `0` — and the only signal is a deprecation notice, which
 * production log levels never show. SQLite returns REAL columns as PHP floats,
 * so `fx_rate_used` reached BigDecimal as a float and every rate derived from
 * it collapsed to zero: the transaction detail page rendered "€0.000 / USD"
 * against a stored rate of 0.92917629.
 *
 * The model casts the column to string so the value is a decimal string end to
 * end. These tests pin both halves — the cast, and the reason it matters.
 */

it('keeps the stored rate a string, not a float', function (): void {
    $model = new Transaction;

    // Read off the model rather than a fixture: the cast is the fix, and a
    // fixture that happened to hold a string would pass without it.
    expect($model->getCasts())->toHaveKey('fx_rate_used')
        ->and($model->getCasts()['fx_rate_used'])->toBe('string');
});

it('shows what a float argument costs, so the cast is not mistaken for ceremony', function (): void {
    expect((string) BigDecimal::of('0.92917629')->toScale(3, RoundingMode::HalfUp))->toBe('0.929');

    // BigNumber::of() does not accept float at all — PHP coerces the argument
    // to int on the way in, which is why the precision vanishes silently
    // rather than raising. Asserted off the signature so this stays true
    // regardless of how the runtime treats the deprecation notice.
    $accepted = (string) (new ReflectionMethod(BigNumber::class, 'of'))->getParameters()[0]->getType();

    expect($accepted)->not->toContain('float')
        ->and($accepted)->toContain('string');
});
