<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Support;

use Modules\Recurring\Public\Enums\SeriesCadence;

// `recurring_series.monthly_equivalent_minor` denormalises this, and the
// column, `latest_amount_minor` and `cadence` merge as three independent
// fields -- so a reader that trusts the stored copy can be handed a figure the
// amount beside it disagrees with. One source, asked at both ends.
final class MonthlyEquivalent
{
    // 52/12 is the exact weeks-per-month conversion; the rounded literal 4.33
    // drifted by about 0.07% on every weekly row, so a weekly ten projects to
    // 43.33 a month rather than 43.30.
    public static function forCadence(int $latestAmountMinor, SeriesCadence $cadence): ?int
    {
        return match ($cadence) {
            SeriesCadence::Weekly => (int) round($latestAmountMinor * 52 / 12),
            SeriesCadence::Monthly => $latestAmountMinor,
            SeriesCadence::Quarterly => (int) round($latestAmountMinor / 3),
            SeriesCadence::Yearly => (int) round($latestAmountMinor / 12),
            SeriesCadence::Irregular => null,
        };
    }

    // The fallback every reader of a stored row makes: an irregular series has
    // no monthly figure to derive, and a cadence spelled by a newer peer has no
    // case here, so both keep whatever the detector last wrote in the column.
    public static function orStored(int $latestAmountMinor, ?SeriesCadence $cadence, int $storedMinor): int
    {
        return ($cadence === null ? null : self::forCadence($latestAmountMinor, $cadence)) ?? $storedMinor;
    }

    // The same derivation asked of SQL, for an ORDER BY and a keyset cursor
    // that have to rank rows on the figure those rows print. The divisors are
    // floats so the quotient is not an integer division, and SQLite's ROUND()
    // rounds half away from zero exactly as PHP's round() does.
    /**
     * @return array{literal-string, list<string>} expression over `recurring_series`, and its cadence bindings
     */
    public static function sqlMinor(): array
    {
        return [
            'CASE cadence'
                .' WHEN ? THEN CAST(ROUND(latest_amount_minor * 52.0 / 12.0) AS INTEGER)'
                .' WHEN ? THEN latest_amount_minor'
                .' WHEN ? THEN CAST(ROUND(latest_amount_minor / 3.0) AS INTEGER)'
                .' WHEN ? THEN CAST(ROUND(latest_amount_minor / 12.0) AS INTEGER)'
                .' ELSE COALESCE(monthly_equivalent_minor, 0) END',
            [
                SeriesCadence::Weekly->value,
                SeriesCadence::Monthly->value,
                SeriesCadence::Quarterly->value,
                SeriesCadence::Yearly->value,
            ],
        ];
    }
}
