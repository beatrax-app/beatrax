<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// The marked-amount anchor was three letters and some digits, matched anywhere
// in the body. Every receipt these matchers read carries a reference — PayPal a
// 17-character transaction id, ICS a Referentienummer — and a reference holding
// one of the four codes the app names, followed by digits, IS that shape. The
// figure the reader was charged was never looked at.

function referenceEml(string $sender, string $body): string
{
    return "From: $sender\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Receipt\r\n"
        ."Date: Sun, 17 May 2026 09:42:13 +0200\r\n"
        ."Message-ID: <reference-probe@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .$body;
}

it('reads the PayPal figure the message labelled, not the digits inside its transaction id', function (): void {
    $matcher = new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(referenceEml('service@paypal.com', implode("\n", [
        'Merchant: Nintendo',
        'Amount: EUR 12,99',
        'Transaction ID: PAYPALTXNUSD00001',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value);
});

it('does not fall back to the transaction id when the message denominates nothing', function (): void {
    $matcher = new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(referenceEml('service@paypal.com', implode("\n", [
        'Merchant: Nintendo',
        'Amount: 12.99',
        'Transaction ID: PAYPALTXNUSD00001',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->parsed)->toBeNull();
});

it('reads the ICS figure the message marked, not the digits inside its reference', function (): void {
    $matcher = app(IcsReceiptMatcher::class);

    $outcome = $matcher->match(referenceEml('noreply@icscards.nl', implode("\n", [
        'Verkoper: Albert Heijn',
        'Referentienummer: ABCEUR123456',
        'Bedrag: EUR 42,50 Af',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-4250)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value);
});
