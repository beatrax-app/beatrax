<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

use Carbon\CarbonImmutable;

// A scheduled revival sweep flips the row back to `open` once
// `snoozedUntil` passes — no listener needs to schedule that itself,
// only react to the state change when it happens.
final readonly class AnomalyAlertSnoozed
{
    public function __construct(
        public int $userId,
        public int $anomalyAlertId,
        public CarbonImmutable $snoozedUntil,
    ) {}
}
