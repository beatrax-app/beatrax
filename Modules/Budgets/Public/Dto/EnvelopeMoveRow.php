<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Spatie\LaravelData\Data;

// direction and amountMinor carry the same fact twice: 'in' always pairs
// with a positive amountMinor, 'out' with a negative one.
final class EnvelopeMoveRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $direction,
        public readonly int $amountMinor,
        public readonly int $counterpartCategoryId,
        public readonly string $counterpartCategoryName,
        public readonly ?string $memo,
        public readonly string $createdAt,
    ) {}
}
