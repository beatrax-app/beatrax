<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Enums;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\WeekStart;

// Irregular is an outcome, not an absence: such a series is detected and kept,
// then excluded from drift comparison and from the calendar.
enum SeriesCadence: string
{
    case Weekly = 'weekly';

    case Monthly = 'monthly';

    case Quarterly = 'quarterly';

    case Yearly = 'yearly';

    case Irregular = 'irregular';

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

    // The k-th occurrence is always anchor + k periods, never k chained steps:
    // a chained no-overflow step loses an end-of-month anchor for good. The
    // anchor is next_expected_at, which is itself clamped when it lands in a
    // short month, so the series' own billing day restores what it dropped.
    public function occurrenceAt(CarbonImmutable $anchor, int $k, ?int $billingDay = null): ?CarbonImmutable
    {
        $stepped = match ($this) {
            self::Weekly => $anchor->addDays(WeekStart::DAYS_IN_WEEK * $k),
            self::Monthly => $anchor->addMonthsNoOverflow($k),
            self::Quarterly => $anchor->addMonthsNoOverflow(self::MONTHS_PER_QUARTER * $k),
            self::Yearly => $anchor->addYearsNoOverflow($k),
            self::Irregular => null,
        };

        if ($stepped === null || $billingDay === null || $this === self::Weekly) {
            return $stepped;
        }

        return self::onBillingDay($stepped, $billingDay);
    }

    // February clamps a bill charged on the 31st to the 28th, and a stepped
    // date never recovers the 31st from there, so every step out of a short
    // month restores the series' own billing day rather than carrying it.
    public static function onBillingDay(CarbonImmutable $stepped, int $billingDay): CarbonImmutable
    {
        return $stepped->setDay(min($billingDay, $stepped->daysInMonth));
    }

    public function label(): string
    {
        return Lang::get('recurring::cadence.'.$this->value);
    }
}
