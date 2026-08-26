<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Dto;

// The six facts about one recurring series that decide whether it becomes a
// savings insight. They travelled as six positional arguments, which is what a
// single subject looks like when it has not been named.
final readonly class InsightCandidate
{
    public function __construct(
        public int $seriesId,
        public string $name,
        public int $monthlyMinor,
        public string $currency,
        // Null where no rate reaches the reader's currency, in which case the
        // review floor cannot be applied to this series at all.
        public ?int $monthlyInBaseMinor,
        public string $counterpartySlug,
    ) {}
}
