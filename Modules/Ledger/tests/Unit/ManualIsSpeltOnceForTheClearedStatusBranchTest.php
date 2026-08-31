<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\TransactionType;

// A hand-entered row lands uncleared because nobody's statement has confirmed
// it yet. The branch that decides so read a bare 'manual' literal, so the
// enum could be renamed and this would keep marking rows cleared in silence.

function canonicalWithSourceFormat(string $sourceFormat): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: TransactionType::Expense->value,
        postedAt: CarbonImmutable::parse('2026-04-01'),
        bookedAt: CarbonImmutable::parse('2026-04-01 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-04-01'),
        amountMinor: -2500,
        currency: 'EUR',
        settledAmountMinor: -2500,
        settledCurrency: 'EUR',
        counterpartyName: 'Fixture Merchant',
        counterpartyIban: null,
        counterpartyNormalized: 'fixture merchant',
        normalizationVersion: 3,
        description: 'Fixture row',
        categoryId: null,
        sourceFormat: $sourceFormat,
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: null,
    );
}

it('leaves a hand-entered row uncleared, reading the format off the enum rather than a bare literal', function (): void {
    $attributes = canonicalWithSourceFormat(SyntheticSourceFormat::Manual->value)->toAttributes();

    expect($attributes['status'])->toBe(ClearedStatus::Uncleared->value);
});

it('marks a parsed row cleared, because a statement is what confirmed it', function (): void {
    $attributes = canonicalWithSourceFormat(SourceFormat::Camt053->value)->toAttributes();

    expect($attributes['status'])->toBe(ClearedStatus::Cleared->value);
});

it('keeps the manual spelling out of the parsed-format enum, so the two vocabularies cannot collide', function (): void {
    expect(SourceFormat::tryFrom(SyntheticSourceFormat::Manual->value))->toBeNull();

    $parsed = array_column(SourceFormat::cases(), 'value');
    $synthetic = array_column(SyntheticSourceFormat::cases(), 'value');

    expect(array_intersect($parsed, $synthetic))->toBe([]);
});
