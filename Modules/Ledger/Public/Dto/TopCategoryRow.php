<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// name is the category's fully-qualified path ("Subscriptions /
// Streaming") so the UI never has to traverse parents at render time.
// percentageOfTotal is a fraction in [0, 1].
final class TopCategoryRow extends Data
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $name,
        public readonly Money $spend,
        public readonly float $percentageOfTotal,
    ) {}
}
