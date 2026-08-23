<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Support;

use Carbon\CarbonImmutable;

// Which expected dates of one series a set of booked rows has already paid for.
// A booked row is one payment and may retire one occurrence, so the pairing is
// one-to-one: nearest first, and an expected date left unpaired survives.
/**
 * @link ../../../../.docs/features/forecasting/architecture.md#which-wins-where-a-booked-row-and-a-projected-occurrence-are-the-same-payment
 */
final class OccurrenceSupersession
{
    private const int ADJACENT_DAYS = 1;

    /**
     * @param  list<CarbonImmutable>  $bookedDates
     * @param  list<CarbonImmutable>  $expectedDates
     * @return array<string, true> superseded date => true, keyed by Y-m-d
     */
    public static function supersededDates(array $bookedDates, array $expectedDates): array
    {
        $expected = self::sortedDays($expectedDates);
        $booked = self::sortedDays($bookedDates);
        if ($expected === [] || $booked === []) {
            return [];
        }

        $superseded = [];
        foreach (self::pairOneToOne($booked, $expected) as $index) {
            foreach (self::occurrenceRun($expected, $index) as $day) {
                $superseded[$day->toDateString()] = true;
            }
        }

        ksort($superseded);

        return $superseded;
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     * @return list<CarbonImmutable> distinct days, ascending
     */
    private static function sortedDays(array $dates): array
    {
        $days = [];
        foreach ($dates as $date) {
            $day = $date->startOfDay();
            $days[$day->toDateString()] = $day;
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * @param  list<CarbonImmutable>  $booked
     * @param  list<CarbonImmutable>  $expected
     * @return list<int> claimed indexes into $expected
     */
    private static function pairOneToOne(array $booked, array $expected): array
    {
        $candidates = [];
        foreach ($expected as $expectedIndex => $day) {
            foreach ($booked as $bookedIndex => $bookedDay) {
                $distance = self::daysApart($bookedDay, $day);
                if ($distance <= MatchWindow::DAYS) {
                    $candidates[] = [$distance, $expectedIndex, $bookedIndex];
                }
            }
        }

        // Both lists are in date order, so ranking on the distance and then on
        // those two positions settles a tie the same way every run.
        usort($candidates, static fn (array $a, array $b): int => $a <=> $b);

        $claimed = [];
        $spent = [];
        foreach ($candidates as [, $expectedIndex, $bookedIndex]) {
            if (isset($claimed[$expectedIndex]) || isset($spent[$bookedIndex])) {
                continue;
            }
            $claimed[$expectedIndex] = true;
            $spent[$bookedIndex] = true;
        }

        return array_keys($claimed);
    }

    /**
     * @param  list<CarbonImmutable>  $expected
     * @return list<CarbonImmutable>
     */
    private static function occurrenceRun(array $expected, int $index): array
    {
        // CadenceJitter smears one occurrence over consecutive days, each
        // carrying a fraction of it, so the run around the claimed day is still
        // the one payment. A gap says the next day is a separate occurrence,
        // and the window bounds a run that never breaks.
        $anchor = $expected[$index];
        $run = [$anchor];

        foreach ([-1, 1] as $step) {
            for ($i = $index + $step; isset($expected[$i]); $i += $step) {
                if (self::daysApart($expected[$i - $step], $expected[$i]) !== self::ADJACENT_DAYS) {
                    break;
                }
                if (self::daysApart($anchor, $expected[$i]) > MatchWindow::DAYS) {
                    break;
                }
                $run[] = $expected[$i];
            }
        }

        return $run;
    }

    private static function daysApart(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) abs($from->diffInDays($to));
    }
}
