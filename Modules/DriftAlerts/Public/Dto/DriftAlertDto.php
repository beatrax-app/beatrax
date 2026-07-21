<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class DriftAlertDto extends Data
{
    /**
     * @param  string  $displayName  resolved at the query layer (user-supplied
     *                               display_name_override, or composed from detected_name) so call sites don't repeat
     *                               the override fallback
     * @param  Money  $baselineAmount  denominated in the recurring series's original
     *                                 transaction currency (drift_alerts.currency), like $latestAmount/$delta/$annualizedImpact
     * @param  Money|null  $eurEquivalent  null when the original currency is already EUR;
     *                                     otherwise the settled-EUR amount the renderer uses for the dashboard sidebar's
     *                                     monthly fixed-payments total
     * @param  int  $thresholdPercentUsed  captured at alert-open time so later changes to the
     *                                     user-global or per-series threshold never rewrite the historical audit trail
     */
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
