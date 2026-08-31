<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

// The one reader of how many minor units a currency counts to the major, and
// of the decimal count that follows from it. Four classes asked Brick the same
// question and three of them turned the answer into decimals with their own
// log10; a reader that drifts renders a yen at a hundredth of itself.
/**
 * @link ../../../../.docs/features/ledger/minor-units-and-zero-decimal-currencies.md#where-the-scale-comes-from
 */
final class CurrencyScale
{
    // A code no currency table knows falls back to the two-decimal assumption
    // every parse and format boundary in the repo makes, as does no code at all.
    public static function minorUnitsPerMajor(?string $currencyCode): int
    {
        $money = $currencyCode === null ? null : Money::tryOfMinor(0, $currencyCode);

        return $money?->minorUnitsPerMajor() ?? Money::MINOR_UNITS_PER_MAJOR;
    }

    public static function decimals(?string $currencyCode): int
    {
        return self::decimalsOfScale(self::minorUnitsPerMajor($currencyCode));
    }

    // For a caller that already holds the scale and would otherwise pay a
    // second currency lookup for the same figure it is about to write.
    public static function decimalsOfScale(int $minorUnitsPerMajor): int
    {
        return (int) round(log10((float) $minorUnitsPerMajor));
    }
}
