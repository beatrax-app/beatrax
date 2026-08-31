<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Modules\Budgets\Public\Enums\OverspendMode;
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
        public readonly OverspendMode $overspendMode,
        public readonly string $currency,
        public readonly int $unconvertedSpentMinor = 0,
        public readonly int $notifyThresholdPercent = 90,
        // $categoryName is already resolved for whoever asked. These two carry
        // the provenance behind it, so a nudge built in a queue worker can
        // resolve the name again in the language its recipient reads.
        public readonly string $categorySlug = '',
        public readonly bool $categoryNameIsDefault = false,
        // The group in front of the leaf, which is what the grid and both
        // move-money pickers render: two envelopes can share $categoryName.
        public readonly string $categoryPath = '',
    ) {}
}
