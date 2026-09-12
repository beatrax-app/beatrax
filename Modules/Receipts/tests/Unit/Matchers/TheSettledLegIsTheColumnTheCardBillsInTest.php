<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// An ICS card bills in euros, and the statement PDF is read that way: the
// foreign column is the figure the merchant asked for, the euro column is the
// movement the account made, and the adapter stores the second as the settled
// leg. The notification matcher stored the FOREIGN figure as both legs, so one
// charge was a different amount of money depending on which source imported
// it -- and the settled leg is the one every balance, budget and forecast sums.

function settledLegEml(string $body): string
{
    return "From: noreply@ics.nl\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Aankoopnotificatie\r\n"
        ."Date: Sun, 12 Apr 2026 10:15:00 +0200\r\n"
        ."Message-ID: <settled-leg@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .$body;
}

function settledLegIcsMatcher(): IcsReceiptMatcher
{
    return new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

it('settles a foreign ICS charge in the euros the statement prints', function (): void {
    $outcome = settledLegIcsMatcher()->match(settledLegEml(implode("\n", [
        'Verkoper: AUGMENT CODE',
        'Bedrag: USD 50,00 Af',
        "Bedrag in euro's: EUR 43,71",
        'Referentienummer: XYZ124',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->amountMinor)->toBe(-5000)
        ->and($outcome->parsed?->currency)->toBe(Currency::Usd->value)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(-4371)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Eur->value);
});

it('withholds a foreign ICS charge whose euro leg the message never states', function (): void {
    $outcome = settledLegIcsMatcher()->match(settledLegEml(implode("\n", [
        'Verkoper: AUGMENT CODE',
        'Bedrag: USD 50,00 Af',
        'Referentienummer: XYZ124',
    ])));

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('ics_euro_leg_unstated');
});

it('carries the direction of the charge onto its euro leg', function (): void {
    $outcome = settledLegIcsMatcher()->match(settledLegEml(implode("\n", [
        'Verkoper: AUGMENT CODE',
        'Bedrag: USD 50,00 Bij',
        "Bedrag in euro's: EUR 43,71",
    ])));

    expect($outcome->parsed?->amountMinor)->toBe(5000)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(4371);
});

it('leaves a domestic ICS charge settled in the one currency it names', function (): void {
    $outcome = settledLegIcsMatcher()->match(settledLegEml(implode("\n", [
        'Verkoper: Flink BV',
        'Bedrag: EUR 62,82 Af',
    ])));

    expect($outcome->parsed?->amountMinor)->toBe(-6282)
        ->and($outcome->parsed?->currency)->toBe(Currency::Eur->value)
        ->and($outcome->parsed?->settledAmountMinor)->toBe(-6282)
        ->and($outcome->parsed?->settledCurrency)->toBe(Currency::Eur->value);
});
