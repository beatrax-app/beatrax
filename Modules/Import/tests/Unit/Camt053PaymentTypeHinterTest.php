<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Parsers\Banking\Camt053PaymentTypeHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * @param  array<int|string, mixed>|null  $rawPayload
 */
function camt053Row(?string $description, ?string $counterpartyName = null, ?array $rawPayload = null): CanonicalTransaction
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
        counterpartyNormalized: 'counterparty',
        normalizationVersion: 3,
        description: $description,
        categoryId: null,
        sourceFormat: 'camt053',
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: null,
        rawPayload: $rawPayload,
    );
}

it('reads the authoritative BkTxCd tuple before any narrative keyword', function (): void {
    $hinter = new Camt053PaymentTypeHinter;
    $hint = $hinter->hint(
        camt053Row('Overboeking spaarrekening', null, ['sepa' => ['btc' => ['domain' => 'PMNT', 'family' => 'CCRD', 'subFamily' => 'POSD']]]),
        'camt053',
    );

    expect($hint?->type)->toBe(PaymentType::Pin);
    expect($hint?->confidence)->toBe(95);
});

it('falls back to the narrative scan when the BkTxCd tuple is unrecognised', function (): void {
    $hinter = new Camt053PaymentTypeHinter;
    $hint = $hinter->hint(camt053Row('SEPA Incasso Vattenfall'), 'camt053');

    expect($hint?->type)->toBe(PaymentType::DirectDebit);
    expect($hint?->confidence)->toBe(85);
});

it('declines a row from another source format', function (): void {
    $hinter = new Camt053PaymentTypeHinter;

    expect($hinter->hint(camt053Row('iDEAL Bestelling'), 'mt940'))->toBeNull();
});

it('does not read Idealo as the iDEAL lexeme', function (): void {
    $hinter = new Camt053PaymentTypeHinter;
    $hint = $hinter->hint(camt053Row('Idealo Internet GmbH bestelling'), 'camt053');

    expect($hint)->toBeNull();
});

it('scans the counterparty name when the entry carries no description', function (): void {
    $hinter = new Camt053PaymentTypeHinter;
    $hint = $hinter->hint(camt053Row(null, 'Betaalautomaat Albert Heijn 1245'), 'camt053');

    expect($hint?->type)->toBe(PaymentType::Pin);
    expect($hint?->confidence)->toBe(90);
});
