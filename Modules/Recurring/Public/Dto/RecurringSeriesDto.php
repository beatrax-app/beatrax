<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class RecurringSeriesDto extends Data
{
    /**
     * @param  Money  $latestAmount  denominated in the original transaction currency
     * @param  Money|null  $eurEquivalent  null when the original amount is already EUR;
     *                                     otherwise the settled-EUR amount (Google Play in USD, ICS cross-currency settlements)
     * @param  Money  $monthlyEquivalent  always EUR so the dashboard "this month" total sums
     *                                    cleanly across mixed currencies
     * @param  string|null  $displayNameOverride  user-supplied override; see displayName()
     */
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
