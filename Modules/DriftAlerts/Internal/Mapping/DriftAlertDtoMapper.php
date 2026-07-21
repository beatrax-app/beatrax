<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Mapping;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\DriftAlerts\Public\Dto\DriftAlertDto;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
final class DriftAlertDtoMapper
{
    /**
     * @param  stdClass  $row  raw drift_alerts row
     * @param  string|null  $seriesDisplayName  resolved series display
     *                                          string supplied by the
     *                                          query layer
     * @param  int|null  $eurEquivalentMinor  EUR-equivalent of the
     *                                        delta when original
     *                                        currency is non-EUR
     */
    public static function hydrate(stdClass $row, ?string $seriesDisplayName = null, ?int $eurEquivalentMinor = null): DriftAlertDto
    {
        $currency = self::toString($row->currency);
        $baselineAmount = Money::ofMinor(self::toInt($row->baseline_amount_minor), $currency);
        $latestAmount = Money::ofMinor(self::toInt($row->latest_amount_minor), $currency);
        $delta = Money::ofMinor(self::toInt($row->delta_minor), $currency);
        $annualizedImpact = Money::ofMinor(self::toInt($row->annualized_impact_minor), $currency);

        $eurEquivalent = null;
        if ($eurEquivalentMinor !== null && $currency !== 'EUR') {
            $eurEquivalent = Money::ofMinor($eurEquivalentMinor, 'EUR');
        }

        // Fail loud (see the class @link) rather than letting Carbon raise
        // a bare InvalidFormatException out of an unscoped parse('').
        $rawDetected = $row->detected_at ?? null;
        if (! is_string($rawDetected) || $rawDetected === '') {
            $rowId = isset($row->id) && is_numeric($row->id) ? (string) $row->id : '?';
            throw new InvalidArgumentException(
                "DriftAlertDtoMapper: drift_alerts row {$rowId} has missing or non-string detected_at.",
            );
        }
        $detectedAt = CarbonImmutable::parse($rawDetected);

        $snoozedUntil = null;
        $rawSnooze = $row->snoozed_until ?? null;
        if (is_string($rawSnooze) && $rawSnooze !== '') {
            $snoozedUntil = CarbonImmutable::parse($rawSnooze);
        }

        $actionedAt = null;
        $rawActioned = $row->actioned_at ?? null;
        if (is_string($rawActioned) && $rawActioned !== '') {
            $actionedAt = CarbonImmutable::parse($rawActioned);
        }

        return new DriftAlertDto(
            driftAlertId: self::toInt($row->id),
            recurringSeriesId: self::toInt($row->recurring_series_id),
            direction: self::toString($row->direction),
            displayName: $seriesDisplayName ?? '',
            state: self::toString($row->state),
            baselineAmount: $baselineAmount,
            latestAmount: $latestAmount,
            delta: $delta,
            annualizedImpact: $annualizedImpact,
            eurEquivalent: $eurEquivalent,
            thresholdPercentUsed: self::toInt($row->threshold_percent_used),
            thresholdSource: self::toString($row->threshold_source),
            detectedAt: $detectedAt,
            actionedAt: $actionedAt,
            snoozedUntil: $snoozedUntil,
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
