<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Parsers\Ics\IcsPdfPaymentTypeHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// The descriptions below are the Dutch CAPS tokens the Mijn ICS consumer-portal
// PDF layout actually emits.
function icsRow(string $description, string $sourceFormat = 'ics-pdf'): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: 1,
        accountId: 2,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-01-23'),
        bookedAt: CarbonImmutable::parse('2026-01-23 00:00:00'),
        valueDate: CarbonImmutable::parse('2026-01-23'),
        amountMinor: -6000,
        currency: 'EUR',
        settledAmountMinor: -6000,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'unknown',
        normalizationVersion: 3,
        description: $description,
        categoryId: null,
        sourceFormat: $sourceFormat,
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: null,
    );
}

it('maps KOSTEN KASOPNAME rows to PaymentType::Fee at confidence 90', function (): void {
    $hinter = new IcsPdfPaymentTypeHinter;
    $hint = $hinter->hint(icsRow('KOSTEN KASOPNAME'), 'ics-pdf');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Fee);
    expect($hint?->confidence)->toBe(90);
});

it('maps GELDMAAT ATM rows to PaymentType::Pin', function (): void {
    $hinter = new IcsPdfPaymentTypeHinter;
    $hint = $hinter->hint(icsRow('GELDMAAT ROELANTDREEF 239                         UTRECHT                         NL'), 'ics-pdf');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Pin);
});

it('maps IDEAL BETALING rows to PaymentType::Online', function (): void {
    $hinter = new IcsPdfPaymentTypeHinter;
    $hint = $hinter->hint(icsRow('IDEAL BETALING, DANK U'), 'ics-pdf');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Online);
});

it('returns null when no source-specific keyword matches so the description-keyword fallback can take a shot at the row', function (): void {
    $hinter = new IcsPdfPaymentTypeHinter;
    $hint = $hinter->hint(icsRow('CLAUDE.AI SUBSCRIPTION                            ANTHROPIC.COM'), 'ics-pdf');

    expect($hint)->toBeNull();
});

it('returns null when the row originated from a different source format', function (): void {
    $hinter = new IcsPdfPaymentTypeHinter;
    $hint = $hinter->hint(icsRow('GELDMAAT', sourceFormat: 'asn-csv'), 'asn-csv');

    expect($hint)->toBeNull();
});
