<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

use Carbon\CarbonImmutable;

/**
 * Dispatched after the `AcknowledgeAnomalyAlert` action successfully
 * transitions an anomaly_alerts row from `open` (or `snoozed`) to
 * `acknowledged`. The user has reviewed the unusual charge and confirmed
 * it needs no further action; the alert moves to the History tab.
 */
final readonly class AnomalyAlertAcknowledged
{
    public function __construct(
        public int $userId,
        public int $anomalyAlertId,
        public CarbonImmutable $acknowledgedAt,
    ) {}
}
