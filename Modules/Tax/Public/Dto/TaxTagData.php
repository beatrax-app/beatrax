<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Per-tag DTO consumed by badge surfaces and TaxPage.
 *
 * Produced by TaxTagQuery (Plan 02) and written by TagTransaction.
 * Downstream consumers must use this DTO shape — the fields and
 * nullability are the contract.
 *
 * `transactionSplitId` (Phase 13.1 D-06a): null for a whole-transaction tag
 * (every existing forTransactionIds() caller); set to the leg's id for a
 * leg-scoped tag returned by TaxTagQuery::forTransactionIdsWithLegs().
 */
final class TaxTagData extends Data
{
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $deductionCategoryId,
        public readonly ?string $deductionCategoryShortName,
        public readonly ?string $note,
        public readonly ?int $taxYearOverride,
        public readonly ?int $transactionSplitId = null,
    ) {}
}
