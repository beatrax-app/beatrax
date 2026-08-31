<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

use Carbon\CarbonImmutable;
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
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $lowestBalanceMinor,
        public string $currency,
        public int $bufferUsedMinor,
    ) {}
}
