<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Dto;

use Spatie\LaravelData\Data;

final class GoalAttributionRow extends Data
{
    public function __construct(
        public readonly int $goalId,
        public readonly string $goalName,
    ) {}
}
