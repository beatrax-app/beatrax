<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Spatie\LaravelData\Data;

final class RecurringSeriesDto extends Data
{
    /**
     * @param  Money  $latestAmount  denominated in the original transaction currency
     * @param  Money|null  $eurEquivalent  $latestAmount in the reader's reporting currency:
     *                                     null when it is already denominated in it, and null again when the rate
     *                                     table cannot reach the pair, so a renderer never prints an unconverted
     *                                     figure under the reader's sign
     * @param  Money  $monthlyEquivalent  denominated in the series' own latest_currency —
     *                                    the detector derives it from latest_amount_minor, so a dollar series'
     *                                    integer is dollar cents. A total across series converts each first
     * @param  Money|null  $monthlyEquivalentInBase  $monthlyEquivalent in the reader's
     *                                               reporting currency, null when the rate table cannot reach the pair
     * @param  string|null  $displayNameOverride  user-supplied override; see displayName()
     */
    public function __construct(
        public readonly int $seriesId,
        public readonly string $direction,
        public readonly string $detectedName,
        public readonly ?string $displayNameOverride,
        public readonly string $state,
        public readonly SeriesCadence $cadence,
        public readonly Money $latestAmount,
        public readonly ?Money $eurEquivalent,
        public readonly Money $monthlyEquivalent,
        public readonly ?int $latestFundingChainLinkId,
        public readonly ?CarbonImmutable $nextExpectedAt,
        public readonly bool $nextExpectedConfidenceLow,
        public readonly int $varianceTolerancePercent,
        public readonly ?CarbonImmutable $snoozedUntil,
        public readonly ?float $latestFxRateUsed = null,
        public readonly ?Money $monthlyEquivalentInBase = null,
    ) {}

    public function displayName(): string
    {
        return $this->displayNameOverride ?? $this->detectedName;
    }
}
