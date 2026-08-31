<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\WeekStart;

// The strip a month is rendered as, first cell to last. A month's grid runs
// past its own last day, so a caller answering endOfMonth() is answering a
// different question from the one the reader is looking at.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-window-recomputed-instead-of-derived
 */
final readonly class CalendarGrid
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public static function range(int $year, int $month): array
    {
        // parse() over create(), whose CarbonImmutable|null return would need
        // a null branch PHPStan cannot see is unreachable.
        $firstOfMonth = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month))->startOfDay();

        return self::rangeFor($firstOfMonth);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public static function rangeFor(CarbonImmutable $anyDayInMonth): array
    {
        $firstOfMonth = $anyDayInMonth->startOfMonth()->startOfDay();
        $lastOfMonth = $firstOfMonth->endOfMonth()->startOfDay();

        return [
            'start' => WeekStart::of($firstOfMonth),
            'end' => WeekStart::endOfWeekFor($lastOfMonth)->startOfDay(),
        ];
    }

    public static function endFor(CarbonImmutable $anyDayInMonth): CarbonImmutable
    {
        return self::rangeFor($anyDayInMonth)['end'];
    }

    // The column headings, in the order the strip actually runs. Spelled out
    // Mon-first in the template, they were a fourth answer to where a week
    // opens and the one a reader compares the cells against.
    /**
     * @return list<string> seven translation keys, the week's own first day first
     */
    public static function weekdayLabelKeys(): array
    {
        $abbreviations = [
            CarbonImmutable::MONDAY => 'mon',
            CarbonImmutable::TUESDAY => 'tue',
            CarbonImmutable::WEDNESDAY => 'wed',
            CarbonImmutable::THURSDAY => 'thu',
            CarbonImmutable::FRIDAY => 'fri',
            CarbonImmutable::SATURDAY => 'sat',
            CarbonImmutable::SUNDAY => 'sun',
        ];

        $keys = [];
        $cursor = WeekStart::of(CarbonImmutable::parse('2026-01-05')->startOfDay());
        for ($i = 0; $i < WeekStart::DAYS_IN_WEEK; $i++) {
            $keys[] = 'calendar::messages.weekdays.'.$abbreviations[$cursor->dayOfWeek];
            $cursor = $cursor->addDay();
        }

        return $keys;
    }

    /**
     * @return list<CarbonImmutable>
     */
    public static function days(CarbonImmutable $gridStart, CarbonImmutable $gridEnd): array
    {
        $days = [];
        $cursor = $gridStart;
        while ($cursor->lte($gridEnd)) {
            $days[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $days;
    }
}
