<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;

// The three matchers each carried a byte-identical copy of this pair, and each
// copy sat under a comment describing MoneyInput's separator handling rather
// than the currency gate the method actually is.

it('decodes entities, strips markup and collapses runs of spaces', function (): void {
    $text = new ReceiptBodyText;

    expect($text->plainText('<p>Domino&#039;s   <b>pizza</b>&nbsp;&amp; cola</p>'))
        ->toBe("Domino's pizza\u{00A0}& cola");
});

it('refuses an amount whose currency token this ledger cannot price', function (): void {
    $text = new ReceiptBodyText;

    expect($text->amountMinor('12,99', 'XYZ'))->toBeNull();
    expect($text->amountMinor('12,99', ''))->toBeNull();
});

it('parses a well-formed amount for a currency the ledger knows', function (): void {
    $text = new ReceiptBodyText;

    expect($text->amountMinor('12,99', Currency::Eur->value))->toBe(1299);
    expect($text->amountMinor('1,234.56', Currency::Usd->value))->toBe(123456);
});

it('returns null for a currency it knows and digits it does not', function (): void {
    $text = new ReceiptBodyText;

    expect($text->amountMinor('twelve', Currency::Eur->value))->toBeNull();
});

// `??` is right-associative, so `a ?? b->value ?? c` parses as `a ?? (b->value
// ?? c)` and the read sits in the left-operand position PHP suppresses. The
// fallback is the branch that runs, measured under the booted handler at
// error_reporting -1, and a null-safe arrow would say nothing it does not.
it('falls back to the given currency for a mark it cannot name', function (): void {
    $text = new ReceiptBodyText;

    expect($text->currencyMarked('ABC', Currency::Eur->value))->toBe(Currency::Eur->value)
        ->and($text->currencyMarked('  ', Currency::Usd->value))->toBe(Currency::Usd->value)
        ->and($text->currencyMarked('usd', Currency::Eur->value))->toBe(Currency::Usd->value)
        ->and($text->currencyMarked('€', Currency::Usd->value))->toBe(Currency::Eur->value);
});
