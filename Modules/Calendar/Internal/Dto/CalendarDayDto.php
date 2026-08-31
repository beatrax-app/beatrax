<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class CalendarDayDto extends Data
{
    /**
     * @param  list<CalendarEntryDto>  $entries
     * @param  list<string>  $unconvertedCurrencies  codes left out of the balance
     *                                               figures for want of a rate, named once above the grid
     * @param  bool  $hasBalanceFigure  false when nothing on the day could be priced,
     *                                  so $eodBalanceMinor is no balance rather than a small one
     * @param  list<string>  $uncountedAccounts  names of accounts this day's entries sit on that
     *                                           the balance figures do not sum, empty where the
     *                                           day states no balance to be read against
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly bool $isToday,
        public readonly bool $isPast,
        public readonly bool $isRisk,
        // Null = unknown: nothing upstream carried a computed balance for the
        // day before (a gap in the forecast points, a computing forecast, a
        // grid the actuals overlay never reaches), so the view renders "—"
        // rather than a fake 0.
        public readonly ?int $sodBalanceMinor,
        public readonly int $eodBalanceMinor,
        public readonly string $currency,
        public readonly bool $isComputing,
        public readonly array $entries,
        public readonly array $unconvertedCurrencies = [],
        public readonly bool $hasBalanceFigure = true,
        public readonly array $uncountedAccounts = [],
    ) {}

    public function showsBalance(): bool
    {
        return ! $this->isComputing && $this->hasBalanceFigure;
    }
}
