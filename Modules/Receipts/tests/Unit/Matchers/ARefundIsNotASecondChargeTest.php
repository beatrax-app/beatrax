<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// Both matchers negated whatever figure they read, unconditionally. A credit
// notification therefore booked as a SECOND outgoing charge: the card gave the
// money back and the ledger recorded it going out again, so the reader was
// charged twice for one purchase and every balance, budget and forecast over
// that row was out by twice the refund.
//
// ICS states its direction beside the figure, `Af` for owed and `Bij` for
// credit, which is the grammar the statement PDF has always been read with.
// PayPal publishes nothing that separates a refund the reader RECEIVED from
// one they ISSUED, and those move money opposite ways, so it withholds.

function directionEml(string $sender, string $subject, string $body): string
{
    return "From: {$sender}\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: {$subject}\r\n"
        ."Date: Sun, 12 Apr 2026 10:15:00 +0200\r\n"
        ."Message-ID: <direction@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .$body;
}

function directionIcsMatcher(): IcsReceiptMatcher
{
    return new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

function directionPaypalMatcher(): PaypalReceiptMatcher
{
    return new PaypalReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

it('books an ICS credit as money coming back', function (): void {
    $outcome = directionIcsMatcher()->match(directionEml('noreply@ics.nl', 'Transactienotificatie', implode("\n", [
        'Verkoper: AMAZON.COM',
        'Bedrag: EUR 46,20 Bij',
        'Referentienummer: XYZ125',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(4620)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(4620);
});

it('pairs an ICS credit to the purchase it names as the original', function (): void {
    $outcome = directionIcsMatcher()->match(directionEml('noreply@ics.nl', 'Transactienotificatie', implode("\n", [
        'Verkoper: AMAZON.COM',
        'Bedrag: EUR 46,20 Bij',
        'Referentienummer: XYZ125',
        'Oorspronkelijke transactie: XYZ123',
    ])));

    expect($outcome->parsed?->chainHints)->toHaveCount(1)
        ->and($outcome->parsed?->chainHints[0])->toBeInstanceOf(RefundOfPayload::class)
        ->and($outcome->parsed?->chainHints[0]->originalReferenceId)->toBe('XYZ123');
});

it('reads the shipped ICS credit notification as a credit with both its hints', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/ics/refund-receipt.eml');

    $outcome = directionIcsMatcher()->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(100)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(100)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Eur->value)
        ->and($outcome->parsed?->referenceId)->toBe('XYZ125')
        ->and($outcome->parsed?->chainHints)->toHaveCount(2)
        ->and($outcome->parsed?->chainHints[0])->toBeInstanceOf(FundedByCardPayload::class)
        ->and($outcome->parsed?->chainHints[1])->toBeInstanceOf(RefundOfPayload::class)
        ->and($outcome->parsed?->chainHints[1]->originalReferenceId)->toBe('XYZ123');
});

it('still books an ICS purchase as money going out', function (): void {
    $outcome = directionIcsMatcher()->match(directionEml('noreply@ics.nl', 'Aankoopnotificatie', implode("\n", [
        'Verkoper: AMAZON.COM',
        'Bedrag: EUR 46,20 Af',
        'Referentienummer: XYZ123',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-4620);
});

it('does not read the Dutch preposition bij as a credit marker', function (): void {
    $outcome = directionIcsMatcher()->match(directionEml('noreply@ics.nl', 'Aankoopnotificatie', implode("\n", [
        'Er is een aankoop gedaan met uw Card.',
        'Verkoper: Albert Heijn',
        'Bedrag: EUR 12,00 bij Albert Heijn Utrecht',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1200);
});

it('withholds an ICS message that states a credit in words but prints no marker', function (): void {
    $outcome = directionIcsMatcher()->match(directionEml('noreply@ics.nl', 'Terugbetaling ontvangen', implode("\n", [
        'De terugbetaling van uw aankoop is verwerkt.',
        'Verkoper: AMAZON.COM',
        'Bedrag: EUR 46,20',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('ics_direction_unstated');
});

it('withholds an ICS message whose direction is stated nowhere', function (): void {
    $outcome = directionIcsMatcher()->match(directionEml('noreply@ics.nl', 'Transactienotificatie', implode("\n", [
        'Een transactie is verwerkt op uw Card.',
        'Verkoper: AMAZON.COM',
        'Bedrag: EUR 46,20',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('ics_direction_unstated');
});

it('withholds a PayPal refund named in its subject', function (): void {
    $outcome = directionPaypalMatcher()->match(directionEml('service@paypal.com', 'Je terugbetaling van Etsy NL', implode("\n", [
        'Aan: Etsy NL',
        'Bedrag: EUR 12,99',
        'Transaction ID: PAYPALTXN17052026',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('paypal_direction_unstated');
});

it('withholds a PayPal refund named only in its body', function (): void {
    $outcome = directionPaypalMatcher()->match(directionEml('service@paypal.com', 'Notification', implode("\n", [
        'Etsy LLC has issued a refund to your PayPal account.',
        'Merchant: Etsy LLC',
        'Amount: EUR 12,99',
        'Transaction ID: PAYPALTXN17052026',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('paypal_direction_unstated');
});

it('withholds a PayPal chargeback the same way', function (): void {
    $outcome = directionPaypalMatcher()->match(directionEml('service@paypal.com', 'Terugvordering ontvangen', implode("\n", [
        'Aan: Etsy NL',
        'Bedrag: EUR 12,99',
        'Transaction ID: PAYPALTXN17052026',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('paypal_direction_unstated');
});

it('still books an ordinary PayPal payment as money going out', function (): void {
    $outcome = directionPaypalMatcher()->match(directionEml('service@paypal.com', 'Je ontvangstbewijs van Netflix BV', implode("\n", [
        'Aan: Netflix BV',
        'Bedrag: EUR 12,99',
        'Transaction ID: PAYPALTXN17052026',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299);
});
