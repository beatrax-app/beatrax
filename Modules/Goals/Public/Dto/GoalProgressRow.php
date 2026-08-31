<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Dto;

use Modules\Goals\Public\Enums\GoalStatus;
use Spatie\LaravelData\Data;

final class GoalProgressRow extends Data
{
    // One format for every goal surface. The dashboard tile printed
    // "24 Feb '27" where /goals printed "24 Feb 2027" for the same fact, and
    // two literals in two blades is how they came to disagree.
    public const string DATE_FORMAT = 'D MMM YYYY';

    /**
     * @param  list<string>  $unconverted  codes left out of $contributedMinor for want of a rate
     */
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
        public readonly bool $hasContributions = false,
        public readonly array $unconverted = [],
    ) {}

    // Floored, not rounded, and floored as well as capped: rounding reached 100
    // from below, drawing a full bar and announcing "100% complete" five euro
    // short. An attributed spend is a withdrawal, so the sum can go negative and
    // aria-valuemin="0" may not report -4; the money line carries that instead.
    public function percentComplete(): int
    {
        if ($this->targetMinor <= 0) {
            return 0;
        }

        return (int) floor(max(0.0, min(1.0, $this->fractionComplete)) * 100);
    }

    public function remainingMinor(): int
    {
        return max(0, $this->targetMinor - $this->contributedMinor);
    }

    public function isCompleted(): bool
    {
        return $this->status === GoalStatus::Completed->value;
    }

    public function isPartial(): bool
    {
        return $this->unconverted !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconverted);
    }

    // A tiny share still draws a sliver, a zero draws nothing. Decided on the
    // fraction, not the percentage: a share under half a percent floors to 0,
    // so asking the percentage answered no for every goal below 0.5%. Both goal
    // lists applied that rule at the call site, under two names.
    public function barWidth(): int
    {
        $percent = $this->percentComplete();
        if ($percent > 0) {
            return max(2, $percent);
        }

        return ($this->targetMinor > 0 && $this->fractionComplete > 0.0) ? 2 : 0;
    }
}
