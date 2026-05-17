<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Dataset payload for the drill-in amount-over-time chart. Each point
 * carries the observation date plus both the native-currency and the
 * settled-EUR amount; the chart renders the native series and overlays
 * the EUR trend when the original currency is not EUR.
 *
 * `maxPoints` caps the rendered series — the chart truncates older
 * occurrences once the limit is reached so a multi-year history does
 * not overwhelm the small drill-in canvas. Default 24 covers two
 * years of monthly data, which is the documented zoom band.
 */
final class RecurringSeriesAmountTrendDto extends Data
{
    /**
     * @param  list<array{date: string, amount_minor: int, eur_amount_minor: int|null}>  $points
     */
    public function __construct(
        public readonly int $seriesId,
        public readonly string $currency,
        public readonly array $points,
        public readonly int $maxPoints = 24,
    ) {}
}
