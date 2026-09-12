<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// ICS and Google Play both run an html-only body through
// ReceiptBodyText::plainText() before they read it. PayPal takes
// `textBody ?? htmlBody` and parses the MARKUP: the entity `&euro;` is not the
// glyph its anchor looks for, and a tag standing between a label and its value
// breaks every one of its anchors. So a receipt PayPal delivered as html only
// is a miss with no reason recorded against it — the charge reaches no ledger
// and nothing on the audit row says why.

function htmlOnlyPaypal(): PaypalReceiptMatcher
{
    return new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

function htmlOnlyPaypalEml(string $html): string
{
    return "From: service@paypal.com\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Je ontvangstbewijs van Netflix BV\r\n"
        ."Date: Sun, 17 May 2026 09:42:13 +0200\r\n"
        ."Message-ID: <paypal-html-only@paypal.com>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/html; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .$html;
}

it('reads a PayPal receipt whose only part is html', function (): void {
    $html = "<html><body>\n"
        ."<p>Aan: Netflix BV</p>\n"
        ."<p>Bedrag: &euro; 12,99</p>\n"
        ."<p>Transaction ID: PAYPALTXN17052026</p>\n"
        .'</body></html>';

    $outcome = htmlOnlyPaypal()->match(htmlOnlyPaypalEml($html));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value)
        ->and($outcome->parsed?->merchantName)->toBe('Netflix BV');
});

it('reads a PayPal html table whose cells carry no whitespace between them', function (): void {
    $html = '<html><body><table>'
        .'<tr><td>Aan:</td><td>Netflix BV</td></tr>'
        .'<tr><td>Bedrag:</td><td>&euro; 12,99</td></tr>'
        .'<tr><td>Transaction ID:</td><td>PAYPALTXN17052026</td></tr>'
        .'</table></body></html>';

    $outcome = htmlOnlyPaypal()->match(htmlOnlyPaypalEml($html));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value)
        ->and($outcome->parsed?->merchantName)->toBe('Netflix BV');
});

it('records an html-only PayPal total the markup denominated with nothing as a miss', function (): void {
    $html = '<html><body><table>'
        .'<tr><td>Aan:</td><td>Netflix BV</td></tr>'
        .'<tr><td>Bedrag:</td><td>1250</td></tr>'
        .'<tr><td>Transaction ID:</td><td>PAYPALTXN17052026</td></tr>'
        .'</table></body></html>';

    $outcome = htmlOnlyPaypal()->match(htmlOnlyPaypalEml($html));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('unmarked_total')
        ->and($outcome->parsed)->toBeNull();
});
