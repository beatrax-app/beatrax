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
