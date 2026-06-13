<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

use Carbon\CarbonImmutable;

/**
 * Dispatched after the `SnoozeAnomalyAlert` action transitions an
 * anomaly_alerts row to `snoozed` with a future `snoozed_until`. A
 * revival sweep flips the row back to `open` once that timestamp passes.
 * Listeners that need to defer the alert from short-term projections
 * during the snooze window can subscribe without re-reading the
 * anomaly_alerts row.
 */
final readonly class AnomalyAlertSnoozed
{
    public function __construct(
        public int $userId,
        public int $anomalyAlertId,
        public CarbonImmutable $snoozedUntil,
    ) {}
}
