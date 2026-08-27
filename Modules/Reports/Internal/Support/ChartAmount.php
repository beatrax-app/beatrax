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
    // a report total is signed.
    /**
     * @param  list<ReportResultRow>  $rows
     * @return list<float>
     */
    public static function magnitudes(array $rows): array
    {
        return array_map(static fn (ReportResultRow $row): float => abs(self::majorUnits($row->amountMinor, $row->currency)), $rows);
    }

    public static function majorUnits(int $minor, string $currency): float
    {
        $money = Money::tryOfMinor($minor, $currency);

        if ($money === null) {
            // A code no currency table knows cannot say how it scales; two
            // decimals is what every other boundary in the repo assumes.
            return $minor / Money::MINOR_UNITS_PER_MAJOR;
        }

        return $money->toMajorFloat();
    }
}
