<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

// Per-tag DTO consumed by badge surfaces and TaxPage, produced by
// TaxTagQuery and written by TagTransaction. transactionSplitId is null
// for a whole-transaction tag (every forTransactionIds() caller); set to
// the leg's id for a leg-scoped tag from forTransactionIdsWithLegs().
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
