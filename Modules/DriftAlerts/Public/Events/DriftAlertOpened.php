<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

final readonly class DriftAlertOpened
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public int $recurringSeriesId,
        public string $direction,
        public int $deltaMinor,
        public int $annualizedImpactMinor,
        public string $currency,
    ) {}
}
