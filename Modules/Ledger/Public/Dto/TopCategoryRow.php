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

    // Clamped here rather than at the call site, because a bar drawn at one
    // number and announced at another is the failure this row was carrying:
    // max(2, min(100, 1600)) drew EUR80.00 of EUR130.00 as a full bar and told
    // a screen reader "100". Goals answers its own bar for the same reason.
    public function percentOfTotal(): int
    {
        return (int) floor(max(0.0, min(1.0, $this->percentageOfTotal)) * 100);
    }

    // A tiny share still draws a sliver, a zero draws nothing. Decided on the
    // fraction, since a share under half a percent floors to nought.
    public function barWidth(): int
    {
        $percent = $this->percentOfTotal();

        if ($percent > 0) {
            return max(2, $percent);
        }

        return $this->percentageOfTotal > 0.0 ? 2 : 0;
    }
}
