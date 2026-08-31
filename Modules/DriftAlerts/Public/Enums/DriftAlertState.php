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

    // No "any -> any" escape hatch; idempotent no-ops live in the Public
    // actions. `snoozed => [Snoozed]` is the one same-state edge and a real
    // move: the Open tab lists a lapsed snooze, so re-snoozing is reachable.
    // Acknowledged and dismissed_cancelled are terminal.
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::Snoozed, self::DismissedCancelled],
            self::Acknowledged => [],
            self::Snoozed => [self::Open, self::Acknowledged, self::DismissedCancelled, self::Snoozed],
            self::DismissedCancelled => [],
        };
    }

    // Asked by every Public action before it transitions: a row the other tab
    // or the paired device already closed answers no, and the action returns
    // instead of letting the machine raise into the reader's face.
    public function allows(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }
}
