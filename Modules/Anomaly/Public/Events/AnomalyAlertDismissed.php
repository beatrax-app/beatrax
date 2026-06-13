<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

/**
 * Dispatched after an anomaly_alerts row transitions to `dismissed`. The
 * `dismissedAs` discriminator records the user's intent:
 *   - `dismissed` — a plain dismiss (no suppression rule), from
 *     `DismissAnomalyAlert`.
 *   - `expected` — dismiss-as-expected, from
 *     `DismissAnomalyAlertAsExpected`, which ALSO creates a suppression
 *     rule (D-17). Listeners can distinguish the two without re-reading
 *     the anomaly_alerts row.
 */
final readonly class AnomalyAlertDismissed
{
    public function __construct(
        public int $userId,
        public int $anomalyAlertId,
        public string $dismissedAs,
    ) {}
}
