<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Dto;

use Modules\Goals\Public\Enums\GoalStatus;
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
        public readonly bool $projectionStalled = false,
    ) {}

    // Floored as well as capped: an attributed spend is a withdrawal, so the sum
    // can go negative, and a progressbar declaring aria-valuemin="0" may not then
    // report -4. The money line carries the negative instead.
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

    public function isCompleted(): bool
    {
        return $this->status === GoalStatus::Completed->value;
    }

    // A real but tiny share still draws a sliver, while a zero draws nothing.
    // Both goal lists applied that rule at the call site, under two names, and
    // a third list would have had to know to apply it again.
    public function barWidth(): int
    {
        $percent = $this->percentComplete();

        return $percent === 0 ? 0 : max(2, $percent);
    }
}
