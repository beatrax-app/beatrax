<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Spatie\LaravelData\Data;

final class ReconciliationRow extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountName,
        public readonly string $currency,
        public readonly int $realBalanceMinor,
        public readonly int $allocatedMinor,
        public readonly int $unallocatedMinor,
        public readonly bool $isOverAllocated,
    ) {}
}
