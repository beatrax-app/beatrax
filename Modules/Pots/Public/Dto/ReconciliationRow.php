<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Spatie\LaravelData\Data;

final class ReconciliationRow extends Data
{
    /**
     * @param  list<string>  $unconverted  codes the account holds that no pot figure counts
     */
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountName,
        public readonly string $currency,
        public readonly int $realBalanceMinor,
        public readonly int $allocatedMinor,
        public readonly int $unallocatedMinor,
        public readonly bool $isOverAllocated,
        public readonly array $unconverted = [],
    ) {}

    public function isPartial(): bool
    {
        return $this->unconverted !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconverted);
    }
}
