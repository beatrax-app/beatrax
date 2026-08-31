<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Receipts\Internal\Matchers\GooglePlayReceiptMatcher;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// Each matcher named its currency twice — once as a literal inside the regex
// that found the figure, once as the code the figure was then parsed at — and
// the glyph lists predate JPY. A yen figure was either read at a hundredth of
// itself, denominated at whatever the READER's base happened to be, or not
// recognised at all.

function currencyEml(string $sender, string $body): string
{
    return "From: $sender\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Receipt\r\n"
        ."Date: Sun, 17 May 2026 09:42:13 +0200\r\n"
        ."Message-ID: <currency-probe@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .$body;
}

it('settles a PayPal receipt converted into yen at the yen it names', function (): void {
    $matcher = new PaypalReceiptMatcher(new EmlMimeReader, app(BaseCurrency::class), new ReceiptBodyText);

    $outcome = $matcher->match(currencyEml('service@paypal.com', implode("\n", [
        'Merchant: Nintendo',
        'Amount: $ 12.99 USD',
        'Conversion to JPY: ¥ 1250',
        'Transaction ID: PAYPALTXNJPY00001',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Jpy->value)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(-1250);
});

it('reads a PayPal figure marked with a glyph in that glyph\'s money, not the reader\'s base', function (): void {
    $matcher = new PaypalReceiptMatcher(new EmlMimeReader, app(BaseCurrency::class), new ReceiptBodyText);

    $outcome = $matcher->match(currencyEml('service@paypal.com', implode("\n", [
        'Aan: Nintendo',
        'Bedrag: ¥ 1250',
        'Transaction ID: PAYPALTXNJPY00002',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->currency)->toBe(Currency::Jpy->value)
        ->and($outcome->parsed?->amountMinor)->toBe(-1250);
});

it('reads an ICS figure at the currency the mail marks it with', function (): void {
    $matcher = new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(currencyEml('noreply@icscards.nl', implode("\n", [
        'Verkoper: NINTENDO',
        'Bedrag: JPY 1250',
        'Referentienummer: ABC123',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->currency)->toBe(Currency::Jpy->value)
        ->and($outcome->parsed?->amountMinor)->toBe(-1250);
});

it('keeps reading an unmarked ICS reference as a reference, never as an amount', function (): void {
    $matcher = new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(currencyEml('noreply@icscards.nl', implode("\n", [
        'Verkoper: NINTENDO',
        'Referentienummer: ABC123',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});

it('settles a Google Play receipt at the currency inside its own parentheses', function (): void {
    $matcher = new GooglePlayReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(currencyEml('googleplay-noreply@google.com', implode("\n", [
        'Item: Pikmin Bloom',
        'GPA.1234-5678-9012-34567',
        'Total: $12.99 USD (¥1,250 JPY)',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Jpy->value)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(-1250);
});
