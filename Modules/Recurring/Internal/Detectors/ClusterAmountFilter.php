<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Modules\Core\Public\Concerns\CoercesScalars;
use stdClass;

// A row carries its magnitude and its direction in one signed integer, so a
// sign that disagrees with the rest of the cluster is a different event, not an
// outlier: compared on abs() alone a refund became the newest row of an expense
// series and the fixed-payments monthly total subtracted it.
final class ClusterAmountFilter
{
    use CoercesScalars;

    /**
     * @param  list<stdClass>  $rows  ascending by posted_at; the order is preserved so the
     *                                caller's "newest kept row" is still the last element
     * @return list<stdClass>
     */
    public static function keep(array $rows, int $tolerancePercent): array
    {
        return self::withinTolerance(self::dominantSignOnly($rows), $tolerancePercent);
    }

    // The dominant sign is read off the cluster rather than off a direction
    // constant: a ledger that stored a whole merchant the other way round then
    // loses one series' amounts instead of every series it has.
    /**
     * @param  list<stdClass>  $rows
     * @return list<stdClass>
     */
    private static function dominantSignOnly(array $rows): array
    {
        $signed = [];
        foreach ($rows as $row) {
            $signed[] = (float) self::toInt($row->amount_minor);
        }

        $dominant = self::median($signed) <=> 0.0;
        if ($dominant === 0) {
            return $rows;
        }

        $kept = [];
        foreach ($rows as $row) {
            if ((self::toInt($row->amount_minor) <=> 0) !== -$dominant) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<stdClass>
     */
    private static function withinTolerance(array $rows, int $tolerancePercent): array
    {
        $absolutes = [];
        foreach ($rows as $row) {
            $absolutes[] = (float) abs(self::toInt($row->amount_minor));
        }

        $median = self::median($absolutes);
        if ($median <= 0.0) {
            return $rows;
        }

        $lower = $median * (100 - $tolerancePercent) / 100;
        $upper = $median * (100 + $tolerancePercent) / 100;

        $kept = [];
        foreach ($rows as $row) {
            $abs = abs(self::toInt($row->amount_minor));
            if ($abs >= $lower && $abs <= $upper) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * @param  list<float>  $values
     */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
