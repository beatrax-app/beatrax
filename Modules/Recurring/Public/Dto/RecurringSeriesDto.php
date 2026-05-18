<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of a single recurring_series row for the review
 * surface, the fixed-payments view, and the drill-in detail page.
 *
 * `latestAmount` is denominated in the original transaction currency;
 * `eurEquivalent` is null when the original already is EUR and
 * carries the settled-EUR amount otherwise (Google Play in USD, ICS
 * cross-currency settlements). `monthlyEquivalent` is always EUR so
 * the dashboard "this month" total sums cleanly across mixed
 * currencies.
 *
 * `displayName()` returns the user-supplied override when set and
 * falls back to the detector-emitted `detectedName`. Read-site
 * formatters always go through this helper so a future override path
 * (the review surface's "Rename series" affordance) lights up without
 * touching the DTO's call sites.
 */
final class RecurringSeriesDto extends Data
{
    public function __construct(
        public readonly int $seriesId,
        public readonly string $direction,
        public readonly string $detectedName,
        public readonly ?string $displayNameOverride,
        public readonly string $state,
        public readonly string $cadence,
        public readonly Money $latestAmount,
        public readonly ?Money $eurEquivalent,
        public readonly Money $monthlyEquivalent,
        public readonly ?int $latestFundingChainLinkId,
        public readonly ?CarbonImmutable $nextExpectedAt,
        public readonly bool $nextExpectedConfidenceLow,
        public readonly int $varianceTolerancePercent,
        public readonly ?CarbonImmutable $snoozedUntil,
        public readonly ?float $latestFxRateUsed = null,
    ) {}

    public function displayName(): string
    {
        return $this->displayNameOverride ?? $this->detectedName;
    }
}
