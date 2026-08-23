<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Enums;

use Carbon\CarbonImmutable;
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

    private const int DAYS_PER_WEEK = 7;

    private const int MONTHS_PER_QUARTER = 3;

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

    // The k-th occurrence is always anchor + k periods, never k chained steps.
    // A chained no-overflow step loses an end-of-month anchor for good: once
    // February clamps the 31st to the 28th, every later step inherits the 28th.
    // Multiplying from the anchor restores it, and it reads negative k as well.
    public function occurrenceAt(CarbonImmutable $anchor, int $k): ?CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $anchor->addDays(self::DAYS_PER_WEEK * $k),
            self::Monthly => $anchor->addMonthsNoOverflow($k),
            self::Quarterly => $anchor->addMonthsNoOverflow(self::MONTHS_PER_QUARTER * $k),
            self::Yearly => $anchor->addYearsNoOverflow($k),
            self::Irregular => null,
        };
    }

    public function label(): string
    {
        return Lang::get('recurring::cadence.'.$this->value);
    }
}
