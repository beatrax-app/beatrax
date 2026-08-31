<?php

declare(strict_types=1);

use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

function paypalFailMatcher(): PaypalReceiptMatcher
{
    return new PaypalReceiptMatcher(new EmlMimeReader, app(BaseCurrency::class), new ReceiptBodyText);
}

function paypalPlainEml(string $body): string
{
    return "From: service@paypal.com\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Je ontvangstbewijs\r\n"
        ."Date: Sun, 17 May 2026 09:42:13 +0200\r\n"
        ."Message-ID: <paypal-fail-1@paypal.com>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 7bit\r\n"
        ."\r\n"
        .$body;
}

it('returns unmatched when the body has no transaction id', function (): void {
    $body = "Aan: Netflix BV\r\nBedrag: EUR 12,99\r\n";
    $outcome = paypalFailMatcher()->match(paypalPlainEml($body));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});

it('returns unmatched when no amount anchor matches (USD, EUR and labelled all miss)', function (): void {
    $body = "Aan: Netflix BV\r\nTransaction ID: PAYPALNOAMOUNT001\r\n";
    $outcome = paypalFailMatcher()->match(paypalPlainEml($body));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});

it('returns unmatched when a charge is present but no merchant line is found', function (): void {
    $body = "Transaction ID: PAYPALNOMERCH0001\r\nAmount: EUR 5,00\r\n";
    $outcome = paypalFailMatcher()->match(paypalPlainEml($body));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});

it('parses the native leg from a bare labelled amount (no USD/EUR anchor)', function (): void {
    // "Total: 25,00" hits neither the USD nor the bare-EUR anchor, so the amount
    // comes from nativeFromLabelled(), which defaults the currency to EUR.
    $body = "Merchant: Labelled Store\r\nTotal: 25,00\r\nTransaction ID: PAYPALLABEL000001\r\n";
    $outcome = paypalFailMatcher()->match(paypalPlainEml($body));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed?->merchantName)->toBe('Labelled Store');
    expect($outcome->parsed?->amountMinor)->toBe(-2500);
    expect($outcome->parsed?->currency)->toBe('EUR');
});
