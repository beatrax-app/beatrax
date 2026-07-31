<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Enums;

// The lifecycle of a drift_alerts row. The column, its DTOs and the state
// machine's transition() signature stay string; this enum is the one
// canonical spelling every caller maps through, and it owns the transition
// graph the SQLite trigger pair and the state machine both enforce.
/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
enum DriftAlertState: string
{
    case Open = 'open';

    case Acknowledged = 'acknowledged';

    case Snoozed = 'snoozed';

    case DismissedCancelled = 'dismissed_cancelled';

    // No "any -> any" escape hatch and no same-state re-entry (idempotent
    // no-ops live in the Public actions, never here); acknowledged and
    // dismissed_cancelled are terminal (no legal successor).
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::Snoozed, self::DismissedCancelled],
            self::Acknowledged => [],
            self::Snoozed => [self::Open, self::Acknowledged, self::DismissedCancelled],
            self::DismissedCancelled => [],
        };
    }
}
