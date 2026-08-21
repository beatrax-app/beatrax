<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Spatie\LaravelData\Data;

final class StatementSettlement extends Data
{
    public function __construct(
        public readonly int $statementId,
        public readonly int $previousOpenMinor,
        public readonly int $newOpenMinor,
        public readonly string $newState,
    ) {}
}
