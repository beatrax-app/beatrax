<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final class MinorAmountFormatter
{
    /**
     * @return string Unsigned decimal string with exactly 2 fraction digits, e.g. "75.00".
     */
    public static function toUnsignedDecimalString(int $minorAmount): string
    {
        $absMinor = abs($minorAmount);
        $whole = intdiv($absMinor, 100);
        $fraction = $absMinor % 100;

        return sprintf('%d.%02d', $whole, $fraction);
    }
}
