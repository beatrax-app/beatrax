<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Enums;

// How often a user wants the position digest, or that they do not want it.
// Distinct from the recurring series cadence in C2 despite sharing the word
// and the value weekly: a series is never off, and a digest is never
// quarterly. C8-R22 requires the two never share a type.
/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
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
