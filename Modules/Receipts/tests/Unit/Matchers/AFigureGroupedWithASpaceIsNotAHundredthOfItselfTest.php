<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Receipts\Internal\Matchers\GooglePlayReceiptMatcher;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// MoneyInput reads a plain space, a non-breaking space and French's narrow
// no-break space as group marks, because thirteen of the shipped locales write
// a figure's thousands with one. Every anchor in front of it captured the digits
// with a class of '.' and ',' alone, so it handed the parse the first group and
// nothing else: `Bedrag: EUR 1 234,56` was booked as EUR 1,00 — not a miss, a
// transaction, a thousandth of the charge, with no reason recorded against it.
//
// The three senders failed it three different ways: PayPal booked the fragment,
// ICS lost the direction marker standing behind it and withheld the charge, and
// Google Play settled a converted receipt at its native dollars.

function groupedEml(string $sender, string $body, string $subject = 'Receipt', string $contentType = 'text/plain'): string
{
    return sprintf("From: %s\r\n", $sender)
        ."To: kaarthouder@example.test\r\n"
        .sprintf("Subject: %s\r\n", $subject)
        ."Date: Sun, 17 May 2026 09:42:13 +0200\r\n"
        ."Message-ID: <grouped-figure-probe@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        .sprintf("Content-Type: %s; charset=UTF-8\r\n", $contentType)
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .$body;
}

dataset('groupMarks', [
    'a plain space' => ' ',
    'a non-breaking space' => "\u{00A0}",
    'a narrow no-break space' => "\u{202F}",
]);

// The mark list is the invariant, not the three literals: a mark MoneyInput
// reads and the anchor does not is exactly how this defect was shipped.
it('reads a figure at every group mark MoneyInput reads one at', function (string $mark): void {
    expect(MoneyInput::tryToMinor('1'.$mark.'234,56', Currency::Eur->value))->toBe(123456);

    $matcher = new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml('service@paypal.com', implode("\n", [
        'Aan: Etsy',
        'Bedrag: EUR 1'.$mark.'234,56',
        'Transaction ID: PAYPALTXNGROUP0001',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-123456)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value);
})->with('groupMarks');

it('reads a glyph a no-break space separates from its figure', function (): void {
    $matcher = new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml('service@paypal.com', implode("\n", [
        'Aan: Etsy',
        "Bedrag: €\u{00A0}12,99",
        'Transaction ID: PAYPALTXNGROUP0002',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299);
});

it('reads both legs of a PayPal conversion at the grouping its sender used', function (): void {
    $matcher = new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml('service@paypal.com', implode("\n", [
        'Merchant: Etsy LLC',
        'Amount: $ 1 299.00 USD',
        'Conversion to EUR: EUR 1 182,00',
        'Transaction ID: PAYPALTXNGROUP0003',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-129900)
        ->and($outcome->parsed?->currency)->toBe(Currency::Usd->value)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(-118200)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Eur->value);
});

// The direction marker stands behind the figure, so a capture stopping at the
// first group left `Af` unreachable and the charge was withheld outright.
it('keeps an ICS direction marker reachable behind a grouped figure', function (): void {
    $matcher = new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml('noreply@ics.nl', implode("\n", [
        'Verkoper: DE BIJENKORF',
        'Bedrag: € 2 500,00 Af',
        'Referentienummer: XYZ900',
    ]), 'Aankoopnotificatie'));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-250000)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value);
});

it('settles a foreign ICS charge at a euro leg its sender grouped', function (): void {
    $matcher = new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml('noreply@ics.nl', implode("\n", [
        'Verkoper: SYNTHETIC ICS FOREIGN',
        'Bedrag: USD 5 000,00 Af',
        "Bedrag in euro's: € 4 371,00 Af",
        'Referentienummer: XYZ901',
    ]), 'Aankoopnotificatie'));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-500000)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(-437100)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Eur->value);
});

// The html arm decodes `&nbsp;` to the mark itself, so a marked-up receipt
// carries one wherever its sender left a space it did not want broken.
it('reads a figure an html receipt spells with entities', function (): void {
    $matcher = new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml(
        'noreply@ics.nl',
        '<html><body><table>'
        .'<tr><td>Verkoper:</td><td>DE BIJENKORF</td></tr>'
        .'<tr><td>Bedrag:</td><td>&euro;&nbsp;2&nbsp;500,00 Af</td></tr>'
        .'<tr><td>Referentienummer:</td><td>XYZ902</td></tr>'
        .'</table></body></html>',
        'Aankoopnotificatie',
        'text/html',
    ));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-250000);
});

it('books a grouped Google Play total rather than missing the receipt', function (): void {
    $matcher = new GooglePlayReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml('googleplay-noreply@google.com', implode("\n", [
        'Order Number: GPA.7777-8888-9999-12345',
        'Item: Augment Code (1 month)',
        'Total: $1 234.56 USD',
    ]), 'Your Google Play Order Receipt'));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-123456);
});

// The settled leg is what every balance, budget and forecast sums, and an
// unreadable one is not withheld: it silently falls back to the native figure.
it('settles a Google Play receipt at a grouped conversion, not at its dollars', function (): void {
    $matcher = new GooglePlayReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml('googleplay-noreply@google.com', implode("\n", [
        'Order Number: GPA.7777-8888-9999-12345',
        'Item: Augment Code (1 month)',
        'Total: $1 299.00 USD (€1 182,00 EUR)',
    ]), 'Your Google Play Order Receipt'));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-129900)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(-118200)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Eur->value);
});

// A space is a group mark only in front of exactly three digits. Widening it
// past that would read the count, the year or the reference standing beside a
// total as part of the figure.
it('stops a figure at the space that is not grouping it', function (string $tail): void {
    $matcher = new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);

    $outcome = $matcher->match(groupedEml('service@paypal.com', implode("\n", [
        'Aan: Etsy',
        'Bedrag: EUR 12,99 '.$tail,
        'Transaction ID: PAYPALTXNGROUP0004',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299);
})->with([
    'a three-digit count' => '100 punten',
    'a year' => '2026',
    'a reference' => 'XYZ123',
]);
