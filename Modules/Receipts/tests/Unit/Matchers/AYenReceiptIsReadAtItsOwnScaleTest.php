<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;

it('reads a whole-yen receipt figure as whole yen', function (): void {
    $text = new ReceiptBodyText;

    expect($text->amountMinor('1250', Currency::Jpy->value))->toBe(1250);
});

it('reads a yen figure carrying its group mark as whole yen', function (): void {
    $text = new ReceiptBodyText;

    expect($text->amountMinor('12.800', Currency::Jpy->value))->toBe(12800);
});
