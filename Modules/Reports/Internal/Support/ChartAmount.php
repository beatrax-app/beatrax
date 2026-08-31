<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Reports\Internal\Dto\ReportResultRow;

// A chart series has to be a number in major units, and the divisor is not the
// same for every currency: JPY has no minor unit, so the hardcoded hundred drew
// a ¥1.000 row as 10 beside a table still reading ¥1.000. The scale comes from
// the money value object, which is the only thing in the repo that knows it.
final class ChartAmount
{
    /**
     * @param  list<ReportResultRow>  $rows
     * @return list<float>
     */
    public static function series(array $rows): array
    {
        return array_map(static fn (ReportResultRow $row): float => self::majorUnits($row->amountMinor, $row->currency), $rows);
    }

    // Magnitudes only, for the donut: ApexCharts draws a slice from a size, and
    // a report total is signed. Only ever fed rows that already move the way
    // the total does -- abs() over the rest drew a credit as spending.
    /**
     * @param  list<ReportResultRow>  $rows
     * @return list<float>
     */
    public static function magnitudes(array $rows): array
    {
        return array_map(static fn (ReportResultRow $row): float => abs(self::majorUnits($row->amountMinor, $row->currency)), $rows);
    }

    // Raw minor units of different currencies share no scale, so a chart axis
    // can only carry one of them: ¥1,000 was drawn at 1000 on a euro axis,
    // beside a real EUR bar a fifth of its height.
    /**
     * @param  list<ReportResultRow>  $rows
     * @return list<int> positions in $rows, in order
     */
    public static function positionsInCurrency(array $rows, string $currency): array
    {
        $positions = [];
        foreach ($rows as $index => $row) {
            if ($row->currency === $currency) {
                $positions[] = $index;
            }
        }

        return $positions;
    }

    // A ring is built from sizes, so it can only show one direction at a time.
    // The one it shows is the total's own: an Income/Refunds slice sat in the
    // "where the money went" ring while the table beneath it printed the same
    // row as a credit in red.
    /**
     * @param  list<ReportResultRow>  $rows
     * @return list<int> positions in $rows, in order
     */
    public static function positionsTowards(array $rows, int $totalMinor): array
    {
        $positions = [];
        foreach ($rows as $index => $row) {
            if (($row->amountMinor >= 0) === ($totalMinor >= 0)) {
                $positions[] = $index;
            }
        }

        return $positions;
    }

    public static function majorUnits(int $minor, string $currency): float
    {
        return Money::majorUnits($minor, $currency);
    }
}
