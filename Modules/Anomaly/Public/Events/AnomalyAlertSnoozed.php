<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

use Carbon\CarbonImmutable;

// A scheduled sweep revives the alert once `snoozedUntil` passes; a listener
// never has to schedule that itself.
final readonly class AnomalyAlertSnoozed
{
    public function __construct(
        public int $userId,
        public int $anomalyAlertId,
        public CarbonImmutable $snoozedUntil,
    ) {}
}
