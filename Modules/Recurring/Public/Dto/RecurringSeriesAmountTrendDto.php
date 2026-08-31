<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Spatie\LaravelData\Data;

final class RecurringSeriesAmountTrendDto extends Data
{
    /**
     * @param  list<array{date: string, amount_minor: int, currency: string, settled_amount_minor: int|null, settled_currency: string|null}>  $points
     * @param  int  $maxPoints  caps the rendered series; the chart truncates older occurrences
     *                          once the limit is reached. Default 24 covers two years of monthly data, the
     *                          documented zoom band
     */
    public function __construct(
        public readonly int $seriesId,
        public readonly string $currency,
        public readonly array $points,
        public readonly int $maxPoints = 24,
    ) {}
}
