<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// name is the fully-qualified path ("Subscriptions / Streaming") so the UI
// never traverses parents at render time; percentageOfTotal is a fraction.
final class TopCategoryRow extends Data
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $name,
        public readonly Money $spend,
        public readonly float $percentageOfTotal,
    ) {}
}
