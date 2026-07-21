<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/goals/architecture.md
 */
final class GoalProgressRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $accountId,
        public readonly ?string $accountName,
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

    public function remainingMinor(): int
    {
        return max(0, $this->targetMinor - $this->contributedMinor);
    }
}
