<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

it('defaults institution-imported rows to cleared', function (): void {
    $today = CarbonImmutable::parse('2026-07-04');

    $canonical = new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: 'expense',
        postedAt: $today,
        bookedAt: $today,
        valueDate: $today,
        amountMinor: -1000,
        currency: 'EUR',
        settledAmountMinor: -1000,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: 'Test merchant',
        counterpartyIban: null,
        counterpartyNormalized: 'test merchant',
        normalizationVersion: 1,
        description: null,
        categoryId: null,
        sourceFormat: 'camt053',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: null,
    );

    expect($canonical->toAttributes()['status'])->toBe('cleared');
});

it('defaults manual cash-book rows to uncleared', function (): void {
    $today = CarbonImmutable::parse('2026-07-04');

    $canonical = new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: 'expense',
        postedAt: $today,
        bookedAt: $today,
        valueDate: $today,
        amountMinor: -1000,
        currency: 'EUR',
        settledAmountMinor: -1000,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: 'Cash purchase',
        counterpartyIban: null,
        counterpartyNormalized: 'cash purchase',
        normalizationVersion: 1,
        description: null,
        categoryId: null,
        sourceFormat: 'manual',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: null,
    );

    expect($canonical->toAttributes()['status'])->toBe('uncleared');
});
