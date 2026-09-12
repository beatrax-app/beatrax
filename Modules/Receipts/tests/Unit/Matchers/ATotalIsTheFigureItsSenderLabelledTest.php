<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Internal\Matchers\GooglePlayReceiptMatcher;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// Every one of the three anchors that reads a receipt's total is matched
// against the WHOLE body and takes whatever denominated figure comes first.
// A receipt prints more than one: a subtotal, a tax line, a card limit, a
// wallet balance, an item price in another currency. `preg_match` returns the
// first match, so the figure booked is whichever of those the sender happened
// to print above the total — and the reader is charged it.
//
// It also bypasses the `unmarked_total` miss outright. That guard is only
// reached when NO figure in the body is denominated, so one marked line
// anywhere is enough to have a total the message marked with nothing replaced
// by a figure that is not it, at a currency it never named.

function labelledTotalEml(string $sender, string $contentType, string $body): string
{
    return "From: {$sender}\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Receipt\r\n"
        ."Date: Sun, 17 May 2026 09:42:13 +0200\r\n"
        ."Message-ID: <labelled-total@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: {$contentType}; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .$body;
}

function labelledTotalPaypal(): PaypalReceiptMatcher
{
    return new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

function labelledTotalIcs(): IcsReceiptMatcher
{
    return new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

function labelledTotalGooglePlay(): GooglePlayReceiptMatcher
{
    return new GooglePlayReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

it('books the PayPal total and not the subtotal printed above it', function (): void {
    $outcome = labelledTotalPaypal()->match(labelledTotalEml('service@paypal.com', 'text/plain', implode("\n", [
        'Aan: Etsy NL',
        'Subtotaal: EUR 10,74',
        'Verzendkosten: EUR 2,25',
        'Bedrag: EUR 12,99',
        'Transaction ID: PAYPALTXN17052026',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value);
});

it('does not read an English Subtotal as the PayPal total', function (): void {
    $outcome = labelledTotalPaypal()->match(labelledTotalEml('service@paypal.com', 'text/plain', implode("\n", [
        'Merchant: Etsy LLC',
        'Subtotal: EUR 10,74',
        'Total: EUR 12,99',
        'Transaction ID: PAYPALTXN17052026',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value);
});

it('books the PayPal total in its own currency and not an item price quoted in another', function (): void {
    $outcome = labelledTotalPaypal()->match(labelledTotalEml('service@paypal.com', 'text/plain', implode("\n", [
        'Merchant: Etsy LLC',
        'Item price: $ 5.00 USD',
        'Bedrag: EUR 12,99',
        'Transaction ID: PAYPALTXN17052026',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value);
});

it('still records an unmarked PayPal total as a miss when another line is denominated', function (): void {
    $outcome = labelledTotalPaypal()->match(labelledTotalEml('service@paypal.com', 'text/plain', implode("\n", [
        'Aan: Nintendo',
        'Je PayPal-saldo: EUR 0,00',
        'Bedrag: 1250',
        'Transaction ID: PAYPALTXN17052026',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('unmarked_total')
        ->and($outcome->parsed)->toBeNull();
});

it('books the ICS charge and not the spending limit printed above it', function (): void {
    $outcome = labelledTotalIcs()->match(labelledTotalEml('noreply@ics.nl', 'text/plain', implode("\n", [
        'Uw bestedingslimiet is EUR 2.500,00',
        'Verkoper: AMAZON.COM',
        'Bedrag: EUR 46,20 Af',
        'Referentienummer: XYZ123',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-4620)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value);
});

it('reads an ICS table whose cells carry no whitespace between them', function (): void {
    $html = '<html><body><table>'
        .'<tr><td>Verkoper:</td><td>AMAZON.COM</td></tr>'
        .'<tr><td>Bedrag:</td><td>&euro; 46,20 Af</td></tr>'
        .'<tr><td>Referentienummer:</td><td>XYZ123</td></tr>'
        .'</table></body></html>';

    $outcome = labelledTotalIcs()->match(labelledTotalEml('noreply@ics.nl', 'text/html', $html));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-4620)
        ->and($outcome->parsed?->merchantName)->toBe('AMAZON.COM');
});

it('books the Google Play total and not the tax line printed above it', function (): void {
    $outcome = labelledTotalGooglePlay()->match(labelledTotalEml('googleplay-noreply@google.com', 'text/plain', implode("\n", [
        'Order Number: GPA.1234-5678-9012-34567',
        'Item: Spotify Premium',
        'Tax: $1.00 USD',
        'Total: $12.99 USD',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299)
        ->and($outcome->parsed?->currency)->toBe(Currency::Usd->value);
});

it('takes the Google Play settled leg off the total line rather than the first bracket in the body', function (): void {
    $outcome = labelledTotalGooglePlay()->match(labelledTotalEml('googleplay-noreply@google.com', 'text/plain', implode("\n", [
        'Order Number: GPA.1234-5678-9012-34567',
        'Item: Spotify Premium',
        'Price: $11.99 USD (€11,14 EUR)',
        'Tax: $1.00 USD (€0,93 EUR)',
        'Total: $12.99 USD (€12,07 EUR)',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299)
        ->and($outcome->parsed?->currency)->toBe(Currency::Usd->value)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(-1207)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Eur->value);
});
