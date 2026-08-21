<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Enums;

// Deliberately a separate type from the recurring-series cadence despite both
// carrying "weekly": a series is never off, and a digest is never quarterly.
enum DigestCadence: string
{
    case Daily = 'daily';

    case Weekly = 'weekly';

    case Off = 'off';

    // The values the column accepts, for the CHECK constraint that keeps a
    // hand-written UPDATE from storing something no case can represent.
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isOff(): bool
    {
        return $this === self::Off;
    }
}
