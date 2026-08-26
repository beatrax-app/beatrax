<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Enums;

// The column and the DTOs stay string; this enum is the canonical spelling
// they map through, and it owns the graph the SQLite triggers also enforce.
enum RecurringSeriesState: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case CadenceChanged = 'cadence_changed';

    case Snoozed = 'snoozed';

    case Rejected = 'rejected';

    // A projection walks these two and no others, so they are also the only two
    // a booked row can be reconciled against.
    /** @return list<string> */
    public static function projectableValues(): array
    {
        return [self::Approved->value, self::CadenceChanged->value];
    }

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
