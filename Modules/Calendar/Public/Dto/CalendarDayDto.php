<?php

declare(strict_types=1);

namespace Modules\Calendar\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/calendar/architecture.md
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
        public readonly bool $isRisk,
        // Null = unknown: the prior grid day carried no computed balance
        // (past day, first grid day, computing forecast), so no honest SoD
        // figure exists — the view renders "—", never a fake 0.
        public readonly ?int $sodBalanceMinor,
        public readonly int $eodBalanceMinor,
        public readonly string $currency,
        public readonly bool $isComputing,
        public readonly array $entries,
    ) {}
}
