<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Enums;

use Modules\Core\Public\Support\Lang;

// Irregular is an outcome, not an absence: such a series is detected and kept,
// then excluded from drift comparison and from the calendar.
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

    public function isRegular(): bool
    {
        return $this !== self::Irregular;
    }

    public function label(): string
    {
        return Lang::get('recurring::cadence.'.$this->value);
    }
}
