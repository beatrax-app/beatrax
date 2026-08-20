<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

final class MigrationBatch extends Data
{
    /**
     * @param  'ynab4'|'nynab'|'actual'  $sourceProduct
     * @param  Collection<int, MigrationCategoryDto>  $categories
     * @param  Collection<int, MigrationAccountDto>  $accounts
     * @param  Collection<int, MigrationPayeeDto>  $payees
     * @param  Collection<int, MigrationBudgetAssignmentDto>  $budgetAssignments
     * @param  Collection<int, MigrationGoalDto>  $goals
     * @param  Collection<int, MigrationScheduleDto>  $schedules
     * @param  Collection<int, UnmappedItemDto>  $unmapped
     * @param  iterable<int, MigrationTransactionDto>  $transactions  A lazy Generator — never materialized by parser or pipeline.
     */
    public function __construct(
        public readonly string $sourceProduct,
        public readonly string $budgetCurrency,
        public readonly Collection $categories,
        public readonly Collection $accounts,
        public readonly Collection $payees,
        public readonly Collection $budgetAssignments,
        public readonly Collection $goals,
        public readonly Collection $schedules,
        public readonly Collection $unmapped,
        public readonly iterable $transactions,
    ) {}
}
