<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Parsers\DescriptionKeywordFallbackHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

function fallbackRow(string $description, string $sourceFormat, ?string $counterpartyName = null): CanonicalTransaction
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
        counterpartyName: $counterpartyName,
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

it('emits a hint for every recognised keyword regardless of source format', function (): void {
    $hinter = new DescriptionKeywordFallbackHinter;

    $cases = [
        ['Betaalautomaat AH', 'asn-csv'],
        ['Betaalautomaat AH', 'google-play-receipt'],
        ['Betaalautomaat AH', 'some-future-format'],
    ];
    foreach ($cases as [$description, $sourceFormat]) {
        $hint = $hinter->hint(fallbackRow($description, $sourceFormat), $sourceFormat);
        expect($hint)->not->toBeNull();
        expect($hint?->type)->toBe(PaymentType::Pin);
    }
});

it('always emits hints at confidence 40 so source-specific hinters win when both fire', function (): void {
    $hinter = new DescriptionKeywordFallbackHinter;
    $hint = $hinter->hint(fallbackRow('iDEAL bestelling', 'asn-csv'), 'asn-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->confidence)->toBe(40);
});

it('maps SEPA direct debit lexemes to PaymentType::DirectDebit', function (): void {
    $hinter = new DescriptionKeywordFallbackHinter;
    $hint = $hinter->hint(fallbackRow('SEPA Direct Debit Eneco', 'asn-csv'), 'asn-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::DirectDebit);
});

it('maps Refund anchor to PaymentType::Refund', function (): void {
    $hinter = new DescriptionKeywordFallbackHinter;
    $hint = $hinter->hint(fallbackRow('refund issued by paypal', 'paypal-csv'), 'paypal-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Refund);
});

it('returns null when the description carries none of the recognised lexemes', function (): void {
    $hinter = new DescriptionKeywordFallbackHinter;
    $hint = $hinter->hint(fallbackRow('Pizzeria Roma — diner besteld', 'asn-csv'), 'asn-csv');

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

    $hinter = new DescriptionKeywordFallbackHinter;
    expect($hinter->hint($row, 'asn-csv'))->toBeNull();
});

it('does not read a lexeme out of the middle of a longer word', function (): void {
    $hinter = new DescriptionKeywordFallbackHinter;

    expect($hinter->hint(fallbackRow('Coffee Company', 'revolut-csv'), 'revolut-csv'))->toBeNull();
    expect($hinter->hint(fallbackRow('Feenstra Verwarming', 'revolut-csv'), 'revolut-csv'))->toBeNull();
    expect($hinter->hint(fallbackRow('Idealo Internet GmbH', 'revolut-csv'), 'revolut-csv'))->toBeNull();
});

it('still reads a lexeme a bank glued to a number', function (): void {
    $hinter = new DescriptionKeywordFallbackHinter;
    $hint = $hinter->hint(fallbackRow('Betaalautomaat12:34 AH 1042', 'asn-csv'), 'asn-csv');

    expect($hint?->type)->toBe(PaymentType::Pin);
});

it('reads the lexeme wherever the adapter put the row\'s only text', function (): void {
    $hinter = new DescriptionKeywordFallbackHinter;
    $hint = $hinter->hint(fallbackRow('', 'revolut-csv', 'Amazon.com refund'), 'revolut-csv');

    expect($hint?->type)->toBe(PaymentType::Refund);
});
