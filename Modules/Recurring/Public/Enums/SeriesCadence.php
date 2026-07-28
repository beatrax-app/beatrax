<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Enums;

// What the interval between a series' occurrences turned out to be.
// Irregular is a real outcome rather than an absence: such a series is
// detected and kept, then excluded from drift comparison and the calendar.
// Distinct from the digest and report vocabularies that also say weekly.
/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
enum SeriesCadence: string
{
    case Weekly = 'weekly';

    case Monthly = 'monthly';

    case Quarterly = 'quarterly';

    case Yearly = 'yearly';

    case Irregular = 'irregular';

    // The values the column accepts, for the trigger pair that keeps a
    // hand-written UPDATE from storing something no case can represent.
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    // A series with no discernible interval still exists; it just cannot be
    // projected forward, which is what every caller is really asking.
    public function isRegular(): bool
    {
        return $this !== self::Irregular;
    }
}
