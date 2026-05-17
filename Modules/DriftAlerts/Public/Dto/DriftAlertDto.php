<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of a single drift_alerts row for the drift page,
 * the dashboard badge composer, and any future drill-in surface.
 *
 * `baselineAmount`, `latestAmount`, `delta`, and `annualizedImpact` are
 * all denominated in the recurring series's original transaction
 * currency (preserved verbatim on `drift_alerts.currency`).
 * `eurEquivalent` is null when the original currency is already EUR;
 * otherwise it carries the settled-EUR amount the renderer uses for
 * the dashboard sidebar's monthly fixed-payments total so the figure
 * sums cleanly across mixed currencies.
 *
 * `displayName` is resolved at the query layer — the underlying
 * recurring_series row may carry a user-supplied
 * `display_name_override`, or the renderer may compose the name from
 * the detector's `detected_name`. The DTO stores the already-resolved
 * string so call sites do not have to repeat the override fallback.
 *
 * `thresholdPercentUsed` + `thresholdSource` are captured at alert-
 * open time so subsequent changes to the user-global or per-series
 * thresholds never rewrite the historical audit trail.
 */
final class DriftAlertDto extends Data
{
    public function __construct(
        public readonly int $driftAlertId,
        public readonly int $recurringSeriesId,
        public readonly string $direction,
        public readonly string $displayName,
        public readonly string $state,
        public readonly Money $baselineAmount,
        public readonly Money $latestAmount,
        public readonly Money $delta,
        public readonly Money $annualizedImpact,
        public readonly ?Money $eurEquivalent,
        public readonly int $thresholdPercentUsed,
        public readonly string $thresholdSource,
        public readonly CarbonImmutable $detectedAt,
        public readonly ?CarbonImmutable $actionedAt,
        public readonly ?CarbonImmutable $snoozedUntil,
    ) {}
}
