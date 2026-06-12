<?php

declare(strict_types=1);

namespace Modules\Calendar\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Represents a single calendar day with its entries and projected balance.
 *
 * The balance fields (sodBalanceMinor, eodBalanceMinor) are sourced from
 * ForecastQuery summed across the user's balance-included accounts and
 * FX-converted to the base reporting currency (D-05, D-13). When
 * isComputing=true the ForecastDto.isComputing sentinel is active for at
 * least one balance account — the view renders "—" in the balance corner
 * and still renders available entries.
 *
 * isRisk=true indicates the projected end-of-day balance is below zero
 * (after FX conversion); the view applies a rose/amber cell tint (D-09).
 */
final class CalendarDayDto extends Data
{
    /**
     * @param  list<CalendarEntryDto>  $entries
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly bool $isToday,
        public readonly bool $isPast,
        /** Projected eodBalanceMinor < 0 after FX conversion (D-09) */
        public readonly bool $isRisk,
        /** Start-of-day balance in minor units of the base reporting currency */
        public readonly int $sodBalanceMinor,
        /** End-of-day projected balance in minor units of the base reporting currency */
        public readonly int $eodBalanceMinor,
        public readonly string $currency,
        /** True if ForecastDto.isComputing for any balance-included account */
        public readonly bool $isComputing,
        public readonly array $entries,
    ) {}
}
