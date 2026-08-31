<?php

declare(strict_types=1);

use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

function icsFailMatcher(): IcsReceiptMatcher
{
    return new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

function icsEml(string $contentType, string $body): string
{
    return "From: noreply@ics.nl\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Aankoopnotificatie\r\n"
        ."Date: Sun, 12 Apr 2026 10:15:00 +0200\r\n"
        ."Message-ID: <ics-fail-1@ics.nl>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: {$contentType}; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 7bit\r\n"
        ."\r\n"
        .$body;
}

it('returns unmatched for an empty body (no text and no html to resolve)', function (): void {
    $outcome = icsFailMatcher()->match(icsEml('text/plain', ''));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});

it('returns unmatched when a text-only body carries an amount but no merchant', function (): void {
    // No Verkoper:/Merchant: label, and a text body leaves no html to fall back on.
    $body = "Beste kaarthouder,\r\nBedrag: \u{20AC} 12,34\r\nReferentienummer: XYZ999\r\n";
    $outcome = icsFailMatcher()->match(icsEml('text/plain', $body));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});

it('falls back to the first non-empty <td> cell when the merchant label is absent', function (): void {
    // No Verkoper:/Merchant: label, so extractMerchant drops into the
    // loose firstTableCell() path over the html body.
    $body = '<html><body><table>'
        .'<tr><td>  </td><td>ACME TABLE STORE</td></tr>'
        .'<tr><td>Bedrag: &euro; 9,99</td></tr>'
        .'</table></body></html>';
    $outcome = icsFailMatcher()->match(icsEml('text/html', $body));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed?->merchantName)->toBe('ACME TABLE STORE');
    expect($outcome->parsed?->amountMinor)->toBe(-999);
});

it('returns unmatched when a merchant is present but no amount anchor is found', function (): void {
    $body = "Verkoper: ACME NO AMOUNT\r\nReferentienummer: XYZ111\r\n";
    $outcome = icsFailMatcher()->match(icsEml('text/plain', $body));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});

it('returns unmatched when the amount matches the anchor but is not a parseable number', function (): void {
    // "1.2.3" satisfies the amount anchor's character class but Brick's
    // BigDecimal rejects it, so negatedAmountMinor() returns null.
    $body = "Verkoper: ACME BAD AMOUNT\r\nBedrag: \u{20AC} 1.2.3\r\n";
    $outcome = icsFailMatcher()->match(icsEml('text/plain', $body));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});
