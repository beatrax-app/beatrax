<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Mapping;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\SeriesCadence;
use stdClass;

// Shared by RecurringSeriesQuery and FixedPaymentsViewQuery. Chain-link
// resolution differs between them, so the caller supplies that one field.
final class RecurringSeriesDtoMapper
{
    use CoercesScalars;

    /**
     * @param  stdClass  $row  raw recurring_series row
     * @param  int|null  $resolvedChainLinkId  the chain link the caller
     *                                         wants on the DTO. RecurringSeriesQuery passes the raw column
     *                                         value; FixedPaymentsViewQuery passes the result of its
     *                                         occurrence-walk fallback.
     */
    public static function hydrate(stdClass $row, ?int $resolvedChainLinkId): RecurringSeriesDto
    {
        $latestCurrency = self::toString($row->latest_currency);
        $latestAmount = Money::ofMinor(self::toInt($row->latest_amount_minor), $latestCurrency);

        $eurEquivalent = null;
        if ($latestCurrency !== 'EUR' && isset($row->monthly_equivalent_minor)) {
            $eurEquivalent = Money::ofMinor(self::toInt($row->monthly_equivalent_minor), 'EUR');
        }

        $monthlyEquivalent = Money::ofMinor(
            isset($row->monthly_equivalent_minor) ? self::toInt($row->monthly_equivalent_minor) : 0,
            $latestCurrency !== '' ? $latestCurrency : 'EUR',
        );

        $nextExpectedAt = null;
        $rawNext = $row->next_expected_at ?? null;
        if (is_string($rawNext) && $rawNext !== '') {
            $nextExpectedAt = CarbonImmutable::parse($rawNext);
        }

        $snoozedUntil = null;
        $rawSnooze = $row->snoozed_until ?? null;
        if (is_string($rawSnooze) && $rawSnooze !== '') {
            $snoozedUntil = CarbonImmutable::parse($rawSnooze);
        }

        $displayNameOverride = $row->display_name_override ?? null;

        $rawFxRate = $row->latest_fx_rate_used ?? null;
        $latestFxRateUsed = null;
        if (is_numeric($rawFxRate)) {
            $latestFxRateUsed = (float) $rawFxRate;
        }

        return new RecurringSeriesDto(
            seriesId: self::toInt($row->id),
            direction: self::toString($row->direction),
            detectedName: self::toString($row->detected_name),
            displayNameOverride: is_string($displayNameOverride) && $displayNameOverride !== ''
                ? $displayNameOverride
                : null,
            state: self::toString($row->state),
            // from() rather than tryFrom(): a trigger built from
            // SeriesCadence::values() constrains the column, so an unmapped value
            // is a broken database and a silent fallback would hide it.
            cadence: SeriesCadence::from(self::toString($row->cadence)),
            latestAmount: $latestAmount,
            eurEquivalent: $eurEquivalent,
            monthlyEquivalent: $monthlyEquivalent,
            latestFundingChainLinkId: $resolvedChainLinkId,
            nextExpectedAt: $nextExpectedAt,
            nextExpectedConfidenceLow: (bool) ($row->next_expected_confidence_low ?? false),
            varianceTolerancePercent: self::toInt($row->variance_tolerance_percent ?? 25),
            snoozedUntil: $snoozedUntil,
            latestFxRateUsed: $latestFxRateUsed,
        );
    }
}
