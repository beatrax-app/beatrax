<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

final readonly class RecurringSeriesDetected
{
    public function __construct(
        public int $seriesId,
        public int $userId,
        public string $direction,
        public string $detectedName,
        public string $cadence,
    ) {}
}
