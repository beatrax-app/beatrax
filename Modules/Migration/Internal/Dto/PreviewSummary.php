<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Dto;

use Spatie\LaravelData\Data;

final class PreviewSummary extends Data
{
    /**
     * @param  array<string, array{items: list<array{id: int, label: string, reason: string, resolution: string}>, count: int}>  $unmapped
     */
    public function __construct(
        public readonly int $categoriesCount,
        public readonly int $accountsCount,
        public readonly int $counterpartiesCount,
        public readonly int $transactionsCount,
        public readonly int $budgetMonthsCount,
        public readonly array $unmapped,
    ) {}

    // "Nothing is unmapped" is true of a run that staged nothing at all, so the
    // all-clean line told a reader whose export parsed to zero rows to go ahead
    // and confirm an import of nothing.
    public function stagedNothing(): bool
    {
        return $this->categoriesCount === 0
            && $this->accountsCount === 0
            && $this->counterpartiesCount === 0
            && $this->transactionsCount === 0
            && $this->budgetMonthsCount === 0
            && $this->unmappedCount() === 0;
    }

    public function unmappedCount(): int
    {
        $total = 0;
        foreach ($this->unmapped as $group) {
            $total += $group['count'];
        }

        return $total;
    }
}
