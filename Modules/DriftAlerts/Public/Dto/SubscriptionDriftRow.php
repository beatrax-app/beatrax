<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Dto;

use Spatie\LaravelData\Data;

final class SubscriptionDriftRow extends Data
{
    /**
     * @param  int  $deltaMinor  latest minus baseline (positive = crept up)
     * @param  float  $deltaPercent  $deltaMinor as a fraction of the baseline x 100
     * @param  list<array{date: string, amount_minor: int}>  $points  chronological amount
     *                                                                history (oldest first) for the sparkline
     * @param  bool  $hasOpenAlert  true when an unresolved drift alert exists, so the row can
     *                              deep-link to it
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
