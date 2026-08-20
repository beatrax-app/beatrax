<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Dto;

use Spatie\LaravelData\Data;

final class GoalProgressRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $targetMinor,
        public readonly int $contributedMinor,
        public readonly string $currency,
        public readonly float $fractionComplete,
        public readonly string $targetDate,
        public readonly string $status,
        public readonly string $progressState,
        public readonly ?string $projectedFinishDate,
        public readonly bool $projectionBeyondHorizon,
    ) {}

    // Floors at zero as well as capping at 100: an attributed spend is a
    // withdrawal, so the sum can go below zero, and a progressbar declaring
    // aria-valuemin="0" may not then report -4. The money line carries the
    // negative; a bar has no way to draw one.
    public function percentComplete(): int
    {
        if ($this->targetMinor <= 0) {
            return 0;
        }

        return (int) round(max(0.0, min(1.0, $this->fractionComplete)) * 100);
    }

    public function remainingMinor(): int
    {
        return max(0, $this->targetMinor - $this->contributedMinor);
    }
}
