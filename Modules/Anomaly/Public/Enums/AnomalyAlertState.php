<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Enums;

// The lifecycle of an anomaly_alerts row. The column, its DTOs and the
// state machine's transition() signature stay string; this enum is the one
// canonical spelling every caller maps through, and it owns the transition
// graph the SQLite trigger pair and the state machine both enforce.
/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
enum AnomalyAlertState: string
{
    case Open = 'open';

    case Acknowledged = 'acknowledged';

    case Snoozed = 'snoozed';

    case Dismissed = 'dismissed';

    // `dismissed => [Open]` is the one diverging undo edge: a user who
    // dismissed an anomaly "as expected" can re-open it. `acknowledged` is
    // terminal (no legal successor).
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::Snoozed, self::Dismissed],
            self::Acknowledged => [],
            self::Snoozed => [self::Open, self::Acknowledged, self::Dismissed],
            self::Dismissed => [self::Open],
        };
    }
}
