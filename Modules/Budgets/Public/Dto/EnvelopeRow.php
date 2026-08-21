<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Spatie\LaravelData\Data;

final class EnvelopeRow extends Data
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryName,
        public readonly int $assignedMinor,
        public readonly int $spentMinor,
        public readonly int $carriedInMinor,
        public readonly int $netMovedMinor,
        public readonly int $availableMinor,
        public readonly string $overspendMode,
        public readonly string $currency,
        public readonly int $nonEurSpentMinor = 0,
        public readonly int $notifyThresholdPercent = 90,
    ) {}
}
