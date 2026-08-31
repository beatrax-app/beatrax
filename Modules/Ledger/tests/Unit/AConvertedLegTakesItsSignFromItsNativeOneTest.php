<?php

declare(strict_types=1);

use Modules\Ledger\Public\ValueObjects\TransactionAmount;

// The shape a PayPal activity export hands over for a foreign-currency payment:
// a native debit and a settled credit, because PayPal books each leg by the
// balance it moved. Every adapter reaches the columns through here, so the
// contradiction has to die at construction rather than at each source.

it('turns a settled credit against a native debit into a settled debit', function (): void {
    $amount = TransactionAmount::relate(-2250, 'USD', 2080, 'EUR');

    expect($amount->amountMinor)->toBe(-2250);
    expect($amount->settledAmountMinor)->toBe(-2080);
    expect($amount->fxRateUsed)->toBe('0.92444444');
});

it('turns a settled debit against a native credit into a settled credit', function (): void {
    $amount = TransactionAmount::relate(2250, 'USD', -2080, 'EUR');

    expect($amount->settledAmountMinor)->toBe(2080);
    expect($amount->fxRateUsed)->toBe('0.92444444');
});

it('leaves a pair that already agrees exactly where it was', function (): void {
    $amount = TransactionAmount::relate(-1046, 'USD', -927, 'EUR');

    expect($amount->settledAmountMinor)->toBe(-927);
    expect($amount->fxRateUsed)->toBe('0.88623327');
});

it('leaves a same-currency settled leg free to differ from its native one', function (): void {
    // Net of a fee is not a conversion: the settled leg is the same currency and
    // its own arithmetic, and it carries no rate to invert.
    $amount = TransactionAmount::relate(10, 'EUR', -90, 'EUR');

    expect($amount->settledAmountMinor)->toBe(-90);
    expect($amount->fxRateUsed)->toBeNull();
});

it('has no direction to lend from a zero native amount', function (): void {
    $amount = TransactionAmount::relate(0, 'USD', -2080, 'EUR');

    expect($amount->settledAmountMinor)->toBe(-2080);
    expect($amount->fxRateUsed)->toBeNull();
});

it('keeps the pair agreeing when a reader flips the sign of the native leg', function (): void {
    // A conflict resolution editing the amount keeps the bank's own conversion,
    // so a refund re-signed from an expense must carry its settled leg over.
    $amount = TransactionAmount::relate(-2250, 'USD', -2080, 'EUR')->withAmountMinor(2250);

    expect($amount->amountMinor)->toBe(2250);
    expect($amount->settledAmountMinor)->toBe(2080);
    expect($amount->fxRateUsed)->toBe('0.92444444');
});
