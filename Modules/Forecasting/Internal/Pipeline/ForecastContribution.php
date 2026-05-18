<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;

/**
 * One per-occurrence projected contribution emitted by `RangeProjector`
 * and consumed by `DailyFold`.
 *
 * The triple `(lowMinor, pointMinor, highMinor)` is signed and in the
 * contribution's native `currency` (an expense series carries negative
 * values; an income series carries positive ones). The daily fold
 * converts to the account's default currency using `fxRateUsed` before
 * combining the contributions per day.
 *
 * Pure value object — no behaviour beyond auto-generated constructor.
 */
final readonly class ForecastContribution
{
    public function __construct(
        public CarbonImmutable $date,
        public int $pointMinor,
        public int $lowMinor,
        public int $highMinor,
        public string $currency,
        public ?float $fxRateUsed,
        public int $seriesId,
        public int $accountId,
    ) {}
}
