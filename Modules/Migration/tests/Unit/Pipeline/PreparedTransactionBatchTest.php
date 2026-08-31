<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Migration\Internal\Pipeline\PreparedTransactionBatch;

function preparedBatchCanonical(int $accountId): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: 1,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-01-01'),
        bookedAt: CarbonImmutable::parse('2026-01-01'),
        valueDate: CarbonImmutable::parse('2026-01-01'),
        amountMinor: -500,
        currency: 'EUR',
        settledAmountMinor: -500,
        settledCurrency: 'EUR',
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'no_counterparty',
        normalizationVersion: 1,
        description: null,
        categoryId: null,
        sourceFormat: 'migration_ynab4',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: 'migration:ynab4:tx-1',
    );
}

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(PreparedTransactionBatch::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('carries the rows and index-aligned canonicals it was constructed with', function (): void {
    $rowA = (object) ['source_external_id' => 'tx-1'];
    $rowB = (object) ['source_external_id' => 'tx-2'];
    $canonicalA = preparedBatchCanonical(10);
    $canonicalB = preparedBatchCanonical(20);

    $batch = new PreparedTransactionBatch([$rowA, $rowB], [$canonicalA, $canonicalB]);

    expect($batch->rows)->toBe([$rowA, $rowB]);
    expect($batch->canonicals)->toBe([$canonicalA, $canonicalB]);
    expect($batch->canonicals[0]->accountId)->toBe(10);
    expect($batch->canonicals[1]->accountId)->toBe(20);
});
