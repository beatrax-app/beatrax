<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

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
}
