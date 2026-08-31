<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Parsers\Csv\PositionalCsvPaymentTypeHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

function asnCsvRow(string $description, string $sourceFormat = 'asn-csv'): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-05-15'),
        bookedAt: CarbonImmutable::parse('2026-05-15 00:00:00'),
        valueDate: CarbonImmutable::parse('2026-05-15'),
        amountMinor: -1234,
        currency: 'EUR',
        settledAmountMinor: -1234,
        settledCurrency: 'EUR',
        counterpartyName: 'Albert Heijn',
        counterpartyIban: null,
        counterpartyNormalized: 'albert heijn',
        normalizationVersion: 3,
        description: $description,
        categoryId: null,
        sourceFormat: $sourceFormat,
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: null,
    );
}

it('maps Betaalautomaat descriptions to PaymentType::Pin at confidence 90', function (): void {
    $hinter = new PositionalCsvPaymentTypeHinter;
    $hint = $hinter->hint(asnCsvRow('Betaalautomaat Albert Heijn 1245'), 'asn-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Pin);
    expect($hint?->confidence)->toBe(90);
});

it('maps iDEAL descriptions to PaymentType::Online at confidence 80', function (): void {
    $hinter = new PositionalCsvPaymentTypeHinter;
    $hint = $hinter->hint(asnCsvRow('iDEAL Bestelling Bol.com'), 'asn-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Online);
    expect($hint?->confidence)->toBe(80);
});

it('maps SEPA incasso descriptions to PaymentType::DirectDebit at confidence 85', function (): void {
    $hinter = new PositionalCsvPaymentTypeHinter;
    $hint = $hinter->hint(asnCsvRow('SEPA Incasso Vattenfall'), 'asn-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::DirectDebit);
    expect($hint?->confidence)->toBe(85);
});

it('maps Overboeking descriptions to PaymentType::Transfer at confidence 70', function (): void {
    $hinter = new PositionalCsvPaymentTypeHinter;
    $hint = $hinter->hint(asnCsvRow('Overboeking aan partner'), 'asn-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Transfer);
    expect($hint?->confidence)->toBe(70);
});

it('returns null when the row originated from a different source format', function (): void {
    $hinter = new PositionalCsvPaymentTypeHinter;
    $hint = $hinter->hint(asnCsvRow('iDEAL Bestelling', 'paypal-csv'), 'paypal-csv');

    expect($hint)->toBeNull();
});

it('returns null when the description carries no recognised keyword', function (): void {
    $hinter = new PositionalCsvPaymentTypeHinter;
    $hint = $hinter->hint(asnCsvRow('Pizzeria Roma — eten besteld'), 'asn-csv');

    expect($hint)->toBeNull();
});

it('returns null when the description is missing entirely', function (): void {
    $row = new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-05-15'),
        bookedAt: CarbonImmutable::parse('2026-05-15 00:00:00'),
        valueDate: CarbonImmutable::parse('2026-05-15'),
        amountMinor: -100,
        currency: 'EUR',
        settledAmountMinor: -100,
        settledCurrency: 'EUR',
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'unknown',
        normalizationVersion: 3,
        description: null,
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: null,
    );

    $hinter = new PositionalCsvPaymentTypeHinter;
    expect($hinter->hint($row, 'asn-csv'))->toBeNull();
});

it('prefers automatische incasso over the shorter incasso keyword (longest-match-wins ordering)', function (): void {
    $hinter = new PositionalCsvPaymentTypeHinter;
    $hint = $hinter->hint(asnCsvRow('Automatische Incasso Eneco'), 'asn-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::DirectDebit);
    expect($hint?->sourceHint)->toBe('automatische incasso');
});
