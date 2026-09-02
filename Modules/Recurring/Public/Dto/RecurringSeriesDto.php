<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
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
        public readonly ?Money $monthlyEquivalentInBase = null,
        public readonly ?CarbonImmutable $latestObservedAt = null,
        public readonly ?int $billingDay = null,
    ) {}

    public function displayName(): string
    {
        return $this->displayNameOverride ?? $this->detectedName;
    }

    // The dashboard pill printed this column raw, so a Dutch reader saw
    // "expense" in the same line as "uitgaven". Bounded by the enum rather than
    // interpolated straight in, so a value outside it cannot render a lang key
    // at the reader instead of a word.
    public function directionLabel(): string
    {
        return match (Direction::tryFrom($this->direction)) {
            Direction::Expense => Lang::get('recurring::fixed_payments.direction.expense'),
            Direction::Income => Lang::get('recurring::fixed_payments.direction.income'),
            default => $this->direction,
        };
    }

    // A rendered, enabled control whose transition the state graph forbids is a
    // 500 waiting for a click: cadence_changed has no snoozed edge, and the
    // review row offered Snooze on that tab anyway.
    public function allows(RecurringSeriesState $target): bool
    {
        return in_array($target, RecurringSeriesState::tryFrom($this->state)?->allowedNext() ?? [], true);
    }

    // The expected date itself is one cadence step past the most recent
    // occurrence and stays that. What has to change once that day goes by with
    // nothing landing is the word in front of it: a list still calling it
    // "next" is naming a day that is already behind the reader.
    public function expectedChargeIsLate(CarbonImmutable $today): bool
    {
        return $this->nextExpectedAt !== null
            && $this->nextExpectedAt->startOfDay()->lessThan($today->startOfDay())
            && ($this->latestObservedAt === null
                || $this->latestObservedAt->startOfDay()->lessThan($this->nextExpectedAt->startOfDay()));
    }
}
