<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class MigrationBudgetAssignmentDto extends Data
{
    public function __construct(
        public readonly string $sourceCategoryExternalId,
        public readonly CarbonImmutable $periodStart,
        public readonly Money $budgeted,
    ) {}
}
