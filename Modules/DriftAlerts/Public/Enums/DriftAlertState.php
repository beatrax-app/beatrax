<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Enums;

// The column and the DTOs stay string; this enum is the canonical spelling
// they map through, and it owns the graph the SQLite triggers also enforce.
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
