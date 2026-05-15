<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * One per-currency totals row for the dashboard's original-currency view.
 *
 * Built by `ThisPeriodAtAGlanceQuery::forByCurrency()` and consumed by the
 * dashboard Blade. The dashboard renders one tile-row per currency present
 * in the period with non-zero activity; currencies that saw no movement in
 * the period are filtered out at query time so the view never paints empty
 * rows. EUR-only periods collapse to a single tile-row that renders
 * identically to the EUR-only mode output.
 *
 * `inflow` / `outflow` / `net` are Money values denominated in the same
 * currency as `$currency`; the SQL aggregator groups by `settled_currency`
 * before constructing each Money so the integer SUMs are guaranteed to be
 * single-currency.
 */
final class PerCurrencyTile extends Data
{
    public function __construct(
        public readonly string $currency,
        public readonly Money $inflow,
        public readonly Money $outflow,
        public readonly Money $net,
    ) {}
}
