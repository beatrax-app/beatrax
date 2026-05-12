<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * One row in the dashboard's "top spending" panel: a single category, the
 * Money spent against it in the current period, and the share of the panel's
 * total that this row represents.
 *
 * `name` is the category's fully-qualified path ("Subscriptions / Streaming")
 * so the UI never has to traverse parents at render time. `percentageOfTotal`
 * is a fraction in [0, 1] — the dashboard multiplies by 100 for the bar width.
 */
final class TopCategoryRow extends Data
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $name,
        public readonly Money $spend,
        public readonly float $percentageOfTotal,
    ) {}
}
