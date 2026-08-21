<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;

// Amounts are signed (expense negative, income positive) and in the contribution's
// own currency; DailyFold applies fxRateUsed to reach the account's default
// currency before combining per day.
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
