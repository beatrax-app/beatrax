<?php

declare(strict_types=1);

namespace Modules\Calendar\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Represents a single recurring-series entry on a calendar day.
 *
 * The contract is consumed by CalendarQuery (Plan 02) and the calendar
 * view (Plan 03). It is intentionally read-only: the calendar surface
 * never writes; it only exposes entries sourced from the Recurring module.
 *
 * Security note (T-06-02): counterpartyId and counterpartySlug are
 * resolved via RecurringSeriesQuery scoped to the authenticated user.
 * No foreign user's counterparty data can appear here.
 */
final class CalendarEntryDto extends Data
{
    public function __construct(
        public readonly int $seriesId,
        public readonly string $name,
        public readonly int $amountMinor,
        public readonly string $currency,
        /** 'expense' | 'income' — mirrors RecurringSeries::$direction */
        public readonly string $direction,
        public readonly ?int $accountId,
        public readonly string $accountName,
        public readonly ?int $counterpartyId,
        public readonly ?string $counterpartySlug,
        /** Past day: a matching RecurringSeriesOccurrence was found within the tolerance window */
        public readonly bool $isPaid,
        /** Past day: expected occurrence date passed but no matching occurrence was found */
        public readonly bool $isMissed,
        /** nextExpectedConfidenceLow=true — render with "~" / dotted styling (D-15) */
        public readonly bool $isApproximate,
    ) {}
}
