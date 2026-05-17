<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

use Carbon\CarbonImmutable;

/**
 * Dispatched after the `SnoozeDriftAlert` action transitions a
 * drift_alerts row to `snoozed` with a future `snoozed_until`. A
 * revival sweep flips the row back to `open` once that timestamp
 * passes. Listeners that need to defer the alert from short-term
 * projections during the snooze window can subscribe without re-reading
 * the drift_alerts row.
 */
final readonly class DriftAlertSnoozed
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public CarbonImmutable $snoozedUntil,
    ) {}
}
