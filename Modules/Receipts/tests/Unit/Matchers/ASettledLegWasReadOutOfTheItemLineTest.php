<?php

declare(strict_types=1);

use Modules\Receipts\Internal\Matchers\GooglePlayReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// The settled leg was anchored on parentheses plus a bare [A-Z]{3} under /i,
// which is the shape of an item line as much as of a denominated figure.
// `Item: Strava Premium (30 day)` therefore answered first and the real
// `(€12,07 EUR)` below it was never reached: the receipt settled -3000 USD,
// which is neither the figure, the currency, nor a leg the mail states.
// ReceiptBodyText::currencyMarkers() was closed for exactly this reason.
function trialLengthMatcher(): GooglePlayReceiptMatcher
{
    return new GooglePlayReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

it('reads the settled leg past an item line whose trial length looks like money', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/googleplay/trial-length-receipt.eml');

    $outcome = trialLengthMatcher()->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed?->referenceId)->toBe('GPA.2468-1357-9024-68135');
    expect($outcome->parsed?->amountMinor)->toBe(-1299);
    expect($outcome->parsed?->currency)->toBe('USD');
    expect($outcome->parsed?->settledAmountMinor)->toBe(-1207);
    expect($outcome->parsed?->settledCurrency)->toBe('EUR');
});

it('mirrors the native leg when the only parenthesis in the mail is a subscription length', function (): void {
    // Dutch stores abbreviate the term to three letters, which is the same
    // width as a currency code and carried no conversion with it at all.
    $raw = <<<'EML'
        From: googleplay-noreply@google.com
        To: user@example.test
        Subject: Your Google Play Order Receipt
        Date: Tue, 19 May 2026 08:15:00 +0000
        MIME-Version: 1.0
        Content-Type: text/plain; charset=UTF-8

        Order Number: GPA.1111-2222-3333-44444
        Item: Videoland Premium (1 mnd)
        Total: $9.99 USD
        EML;

    $outcome = trialLengthMatcher()->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed?->amountMinor)->toBe(-999);
    expect($outcome->parsed?->settledAmountMinor)->toBe(-999);
    expect($outcome->parsed?->settledCurrency)->toBe('USD');
});

it('still reads a settled leg a store wrote in yen', function (): void {
    $raw = <<<'EML'
        From: googleplay-noreply@google.com
        To: user@example.test
        Subject: Your Google Play Order Receipt
        Date: Tue, 19 May 2026 08:15:00 +0000
        MIME-Version: 1.0
        Content-Type: text/plain; charset=UTF-8

        Order Number: GPA.5555-6666-7777-88888
        Item: Manga Reader (12 mnd)
        Total: $12.99 USD (¥1,250 JPY)
        EML;

    $outcome = trialLengthMatcher()->match($raw);

    expect($outcome->parsed?->settledAmountMinor)->toBe(-1250);
    expect($outcome->parsed?->settledCurrency)->toBe('JPY');
});
