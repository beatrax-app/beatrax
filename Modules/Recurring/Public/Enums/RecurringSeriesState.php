<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Enums;

// The lifecycle of a recurring_series row. The column, its DTOs and the
// state machine's transition() signature stay string; this enum is the one
// canonical spelling every caller maps through, and it owns the transition
// graph the SQLite trigger pair and the state machine both enforce.
/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
enum RecurringSeriesState: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case CadenceChanged = 'cadence_changed';

    case Snoozed = 'snoozed';

    case Rejected = 'rejected';

    // No "any -> any" escape hatch and no same-state re-entry (idempotent
    // no-ops live in the Public actions, never here).
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Snoozed],
            self::Approved => [self::CadenceChanged, self::Rejected],
            self::CadenceChanged => [self::Approved, self::Rejected],
            self::Snoozed => [self::Pending, self::Approved, self::Rejected],
            self::Rejected => [self::Pending],
        };
    }
}
