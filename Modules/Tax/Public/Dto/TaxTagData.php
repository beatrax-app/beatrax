<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

// transactionSplitId is null for a whole-transaction tag and carries the leg's
// id for a leg-scoped one.
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
