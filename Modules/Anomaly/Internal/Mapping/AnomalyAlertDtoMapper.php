<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Mapping;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Internal\Enums\DismissedAs;
use Modules\Anomaly\Public\Dto\AnomalyAlertDto;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

// A null baseline stays null: a duplicate-only or first-time-only alert has no
// comparison amount, and hydrating one to zero is how the row came to state a
// EUR 0.00 baseline against a real charge.
final class AnomalyAlertDtoMapper
{
    use CoercesScalars;

    /**
     * @param  stdClass  $row  raw anomaly_alerts row
     * @param  string|null  $displayName  resolved merchant display string
     *                                    supplied by the query layer
     * @param  string  $baseCurrency  BaseCurrency::code(), supplied by the caller
     *                                because a static mapper cannot inject it
     */
    public static function hydrate(stdClass $row, ?string $displayName, string $baseCurrency): AnomalyAlertDto
    {
        $currency = self::toCurrency($row->currency ?? null, $baseCurrency);
        $rawBaseline = $row->baseline_amount_minor ?? null;
        $baselineAmount = $rawBaseline === null ? null : Money::ofMinor(self::toInt($rawBaseline), $currency);
        $latestAmount = Money::ofMinor(self::toInt($row->latest_amount_minor ?? null), $currency);

        // The schema marks `detected_at` non-null; a corrupted row that is not
        // fails loud with the row id, rather than as a bare
        // InvalidFormatException from parse('').
        $rawDetected = $row->detected_at ?? null;
        if (! is_string($rawDetected) || $rawDetected === '') {
            $rowId = isset($row->id) && is_numeric($row->id) ? (string) $row->id : '?';
            throw new InvalidArgumentException(
                "AnomalyAlertDtoMapper: anomaly_alerts row {$rowId} has missing or non-string detected_at.",
            );
        }
        $detectedAt = CarbonImmutable::parse($rawDetected);

        return new AnomalyAlertDto(
            anomalyAlertId: self::toInt($row->id ?? null),
            transactionId: self::toInt($row->transaction_id ?? null),
            reasons: self::decodeReasons($row->reasons ?? null),
            displayName: $displayName ?? '',
            direction: self::toString($row->direction ?? null),
            state: self::toString($row->state ?? null),
            baselineAmount: $baselineAmount,
            latestAmount: $latestAmount,
            currency: $currency,
            sensitivityPercentUsed: self::toInt($row->sensitivity_percent_used ?? null),
            dismissedAs: self::toDismissedAsOrNull($row->dismissed_as ?? null),
            detectedAt: $detectedAt,
            actionedAt: self::toDateOrNull($row->actioned_at ?? null),
            snoozedUntil: self::toDateOrNull($row->snoozed_until ?? null),
        );
    }

    /**
     * @return list<AnomalyDetector>
     */
    private static function decodeReasons(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        return AnomalyDetector::listFrom(json_decode($value, true));
    }

    private static function toDateOrNull(mixed $value): ?CarbonImmutable
    {
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    private static function toCurrency(mixed $value, string $baseCurrency): string
    {
        return is_string($value) && $value !== '' ? $value : $baseCurrency;
    }

    // An empty or unrecognised `dismissed_as` means the alert was never
    // dismissed by a build that knows this vocabulary, so it reads as null
    // rather than as a word the caller would have to re-check.
    private static function toDismissedAsOrNull(mixed $value): ?DismissedAs
    {
        return is_string($value) ? DismissedAs::tryFrom($value) : null;
    }
}
