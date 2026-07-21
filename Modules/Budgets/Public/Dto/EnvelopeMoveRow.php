<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Spatie\LaravelData\Data;

// direction is 'in' | 'out'; amountMinor is signed (positive for 'in',
// negative for 'out'). counterpartCategoryId/Name are always non-null --
// every envelope move has a real counterpart category.
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
