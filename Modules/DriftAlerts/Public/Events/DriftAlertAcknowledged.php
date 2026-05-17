<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

use Carbon\CarbonImmutable;

/**
 * Dispatched after the `AcknowledgeDriftAlert` action successfully
 * transitions a drift_alerts row from `open` (or `snoozed`) to
 * `acknowledged`. The user has reviewed the drift; the alert is
 * resolved and the underlying recurring series is unaffected.
 */
final readonly class DriftAlertAcknowledged
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public CarbonImmutable $acknowledgedAt,
    ) {}
}
