<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One row on the Subscription Drift Watch overview: an approved recurring
 * expense series (a subscription) with the price it started at, the price it
 * charges now, the cumulative drift between them, and a sparkline of every
 * observed amount in between.
 *
 * `deltaMinor` is latest − baseline (positive = crept up); `deltaPercent` is
 * that as a fraction of the baseline × 100. `points` is the chronological
 * amount history (oldest first) for the sparkline. `hasOpenAlert` flags
 * subscriptions with an unresolved drift alert so the row can deep-link to it.
 */
final class SubscriptionDriftRow extends Data
{
    /**
     * @param  list<array{date: string, amount_minor: int}>  $points
     */
    public function __construct(
        public readonly int $seriesId,
        public readonly string $name,
        public readonly int $baselineMinor,
        public readonly int $latestMinor,
        public readonly int $deltaMinor,
        public readonly float $deltaPercent,
        public readonly int $monthlyEquivalentMinor,
        public readonly string $currency,
        public readonly array $points,
        public readonly bool $hasOpenAlert,
    ) {}

    public function direction(): string
    {
        return match (true) {
            $this->deltaMinor > 0 => 'up',
            $this->deltaMinor < 0 => 'down',
            default => 'flat',
        };
    }
}
