<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Mapping;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use stdClass;

/**
 * Shared hydrator for `recurring_series` rows → `RecurringSeriesDto`.
 *
 * RecurringSeriesQuery and FixedPaymentsViewQuery used to carry
 * near-identical `toDto` methods; bugs found in one (e.g. the
 * eur-equivalent currency mis-label when `latest_currency` is empty)
 * had to be fixed twice. The chain-link resolution differs between
 * the two callsites — RecurringSeriesQuery reads the raw column,
 * FixedPaymentsViewQuery applies an occurrence-walk fallback — so
 * the chain link id is the only field the caller resolves
 * separately and supplies to the mapper.
 *
 * Static-only: no constructor dependencies. The mapper does not read
 * services or touch the DB; it is pure-data transformation.
 */
final class RecurringSeriesDtoMapper
{
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
            cadence: self::toString($row->cadence),
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
