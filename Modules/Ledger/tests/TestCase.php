<?php

declare(strict_types=1);

namespace Modules\Ledger\Tests;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Tests\TestCase as RootTestCase;

/**
 * Ledger module-local TestCase. Provides a `canonical()` factory that returns
 * a CanonicalTransaction filled with sane defaults so individual tests can
 * override only the field they exercise.
 */
abstract class TestCase extends RootTestCase
{
    /**
     * Build a CanonicalTransaction with sensible defaults. Callers override
     * only the keys they care about.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function canonical(array $overrides = []): CanonicalTransaction
    {
        $defaults = [
            'userId' => null,
            'accountId' => 1,
            'type' => 'expense',
            'postedAt' => CarbonImmutable::parse('2026-05-03'),
            'bookedAt' => CarbonImmutable::parse('2026-05-03 12:00:00'),
            'valueDate' => CarbonImmutable::parse('2026-05-03'),
            'amountMinor' => -1299,
            'currency' => 'EUR',
            'settledAmountMinor' => -1299,
            'settledCurrency' => 'EUR',
            'fxRateUsed' => null,
            'counterpartyName' => 'AH Amsterdam',
            'counterpartyIban' => null,
            'counterpartyNormalized' => 'ah amsterdam',
            'normalizationVersion' => 1,
            'description' => 'Albert Heijn weekly groceries',
            'categoryId' => null,
            'sourceFormat' => 'asn-csv',
            'importRunId' => 1,
            'sourceRowIndex' => 0,
            'sourceRef' => 'ASN-REF-001',
        ];

        $merged = array_merge($defaults, $overrides);

        return new CanonicalTransaction(
            userId: $merged['userId'],
            accountId: $merged['accountId'],
            type: $merged['type'],
            postedAt: $merged['postedAt'],
            bookedAt: $merged['bookedAt'],
            valueDate: $merged['valueDate'],
            amountMinor: $merged['amountMinor'],
            currency: $merged['currency'],
            settledAmountMinor: $merged['settledAmountMinor'],
            settledCurrency: $merged['settledCurrency'],
            fxRateUsed: $merged['fxRateUsed'],
            counterpartyName: $merged['counterpartyName'],
            counterpartyIban: $merged['counterpartyIban'],
            counterpartyNormalized: $merged['counterpartyNormalized'],
            normalizationVersion: $merged['normalizationVersion'],
            description: $merged['description'],
            categoryId: $merged['categoryId'],
            sourceFormat: $merged['sourceFormat'],
            importRunId: $merged['importRunId'],
            sourceRowIndex: $merged['sourceRowIndex'],
            sourceRef: $merged['sourceRef'],
        );
    }
}
