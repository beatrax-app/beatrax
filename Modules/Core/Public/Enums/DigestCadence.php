<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// How often the position digest is raised. Deliberately a separate type from
// the recurring-series cadence: a series is never off, a digest never
// quarterly. It sits in Core, not beside the preference row it is stored in,
// because Position raises the digest and may not import Notifications.
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
