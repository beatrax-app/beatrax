<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

use Illuminate\Support\Carbon;
use Modules\Forecasting\Internal\Pipeline\ShortfallDetector;

/**
 * @see ShortfallDetector
 */
final readonly class ForecastShortfallDetected
{
    public function __construct(
        public int $userId,
        public int $accountId,
        public ?int $scenarioId,
        public Carbon $startsAt,
        public Carbon $endsAt,
        public int $lowestBalanceMinor,
        public string $currency,
        public int $bufferUsedMinor,
    ) {}
}
