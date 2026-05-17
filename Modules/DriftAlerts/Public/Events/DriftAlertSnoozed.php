<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

use Carbon\CarbonImmutable;

/**
 * Dispatched after the `SnoozeDriftAlert` action transitions a
 * drift_alerts row to `snoozed` with a future `snoozed_until`. A
 * revival sweep flips the row back to `open` once that timestamp
 * passes; Phase 10 forecasting may subscribe to this event to defer
 * the alert from short-term projections during the snooze window.
 */
final readonly class DriftAlertSnoozed
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public CarbonImmutable $snoozedUntil,
    ) {}
}
